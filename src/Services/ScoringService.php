<?php

declare(strict_types=1);

namespace WicketAORM\Services;

/**
 * ScoringService — configurable matching-score engine for AORM-7.
 *
 * Encapsulates the scoring matrix, threshold comparisons, and categorisation
 * logic that determines how well an MDP candidate matches a staged roster row.
 * All numeric weights and thresholds are constructor-injectable so that
 * administrators can tune the matching sensitivity without modifying code.
 *
 * Default scoring matrix:
 *   100 — email exact  + first exact  + last exact  → Exact Match
 *    80 — email exact  + name has any discrepancy   → Probable Match
 *    60 — first exact  + last exact   + diff email  → Probable Match
 *    40 — email domain + last exact                 → Possible Match
 *    30 — last exact   + first initial match        → Possible Match
 *    20 — last exact   only                         → Possible Match
 *     0 — no match                                  → New Record
 *
 * Default thresholds:
 *   probable → 80  (score ≥ 80 → probable_match)
 *   possible → 20  (score ≥ 20 → possible_match)
 *
 * Customisation — WordPress filter:
 *   add_filter( 'wicket_aorm_scoring_config', function ( array $config ): array {
 *       $config['weights']['email_name_mismatch'] = 75;
 *       $config['thresholds']['probable']         = 75;
 *       return $config;
 *   } );
 *
 * Or inject directly:
 *   $scorer = new ScoringService(
 *       weights:    ['exact_match' => 100, 'email_name_mismatch' => 70, ...],
 *       thresholds: ['probable' => 70, 'possible' => 20],
 *   );
 */
class ScoringService
{
    // ── Weight keys ────────────────────────────────────────────────────────

    public const WEIGHT_EXACT_MATCH         = 'exact_match';
    public const WEIGHT_EMAIL_NAME_MISMATCH = 'email_name_mismatch';
    public const WEIGHT_NAME_DIFF_EMAIL     = 'name_diff_email';
    public const WEIGHT_DOMAIN_LAST         = 'domain_last';
    public const WEIGHT_LAST_PARTIAL_FIRST  = 'last_partial_first';
    public const WEIGHT_LAST_ONLY           = 'last_only';

    // ── Threshold keys ─────────────────────────────────────────────────────

    public const THRESHOLD_KEY_PROBABLE = 'probable';
    public const THRESHOLD_KEY_POSSIBLE = 'possible';

    // ── Category values ────────────────────────────────────────────────────

    public const CATEGORY_READY_TO_SYNC  = 'ready_to_sync';
    public const CATEGORY_PROBABLE_MATCH = 'probable_match';
    public const CATEGORY_POSSIBLE_MATCH = 'possible_match';

    // ── Record-status values ───────────────────────────────────────────────

    public const STATUS_NEW_RECORD        = 'new_record';
    public const STATUS_EXACT_MATCH       = 'exact_match';
    public const STATUS_ALREADY_ON_ROSTER = 'already_on_roster';

    // ── Default config ─────────────────────────────────────────────────────

    /** @var array<string, int> */
    public const DEFAULT_WEIGHTS = [
        self::WEIGHT_EXACT_MATCH         => 100,
        self::WEIGHT_EMAIL_NAME_MISMATCH => 80,
        self::WEIGHT_NAME_DIFF_EMAIL     => 60,
        self::WEIGHT_DOMAIN_LAST         => 40,
        self::WEIGHT_LAST_PARTIAL_FIRST  => 30,
        self::WEIGHT_LAST_ONLY           => 20,
    ];

    /** @var array<string, int> */
    public const DEFAULT_THRESHOLDS = [
        self::THRESHOLD_KEY_PROBABLE => 80,
        self::THRESHOLD_KEY_POSSIBLE => 20,
    ];

    // ── WordPress filter name ──────────────────────────────────────────────

    /** WordPress filter tag used to override weights and thresholds at runtime. */
    public const FILTER_CONFIG = 'wicket_aorm_scoring_config';

    // ── Constructor ────────────────────────────────────────────────────────

    /**
     * @param array<string, int> $weights    Override individual weight values.
     *                                        Missing keys fall back to DEFAULT_WEIGHTS.
     * @param array<string, int> $thresholds Override individual threshold values.
     *                                        Missing keys fall back to DEFAULT_THRESHOLDS.
     */
    public function __construct(
        private readonly array $weights = self::DEFAULT_WEIGHTS,
        private readonly array $thresholds = self::DEFAULT_THRESHOLDS,
    ) {
    }

    // ── Factory methods ────────────────────────────────────────────────────

    /** Create a scorer using the built-in default weights and thresholds. */
    public static function fromDefaults(): static
    {
        return new static();
    }

    /**
     * Create a scorer whose config is sourced from the WordPress filter
     * 'wicket_aorm_scoring_config'.
     *
     * Filter receives and should return an array with optional keys:
     *   - 'weights'    → array<string, int>  (merged over DEFAULT_WEIGHTS)
     *   - 'thresholds' → array<string, int>  (merged over DEFAULT_THRESHOLDS)
     *
     * Any key omitted by the filter keeps its default value.
     */
    public static function fromWordPressFilter(): static
    {
        /** @var array{weights?: array<string,int>, thresholds?: array<string,int>} $config */
        $config = apply_filters(self::FILTER_CONFIG, [
            'weights'    => self::DEFAULT_WEIGHTS,
            'thresholds' => self::DEFAULT_THRESHOLDS,
        ]);

        $weights    = array_merge(self::DEFAULT_WEIGHTS, (array) ($config['weights'] ?? []));
        $thresholds = array_merge(self::DEFAULT_THRESHOLDS, (array) ($config['thresholds'] ?? []));

        return new static($weights, $thresholds);
    }

    // ── Config accessors ───────────────────────────────────────────────────

    /**
     * Return the configured numeric weight for a given weight key.
     *
     * Returns 0 for unknown keys so that any score comparison is safely
     * a no-match result rather than a fatal error.
     */
    public function getWeight(string $key): int
    {
        return $this->weights[$key] ?? 0;
    }

    /**
     * Return the configured numeric threshold for a given threshold key.
     *
     * Returns 0 for unknown keys (treated as "no minimum").
     */
    public function getThreshold(string $key): int
    {
        return $this->thresholds[$key] ?? 0;
    }

    /**
     * Return the full weights array (keyed by WEIGHT_* constants).
     *
     * @return array<string, int>
     */
    public function getWeights(): array
    {
        return $this->weights;
    }

    /**
     * Return the full thresholds array (keyed by THRESHOLD_KEY_* constants).
     *
     * @return array<string, int>
     */
    public function getThresholds(): array
    {
        return $this->thresholds;
    }

    // ── Scoring ────────────────────────────────────────────────────────────

    /**
     * Score a single MDP candidate against submitted row fields.
     *
     * Comparisons are case-insensitive and whitespace-trimmed.
     * The highest-scoring rule that applies wins (decision-tree order).
     *
     * @param array{uuid: string, name: string, email: string, given_name: string, family_name: string} $candidate
     * @param array{first_name: string, last_name: string, email: string} $input
     */
    public function scoreCandidate(array $candidate, array $input): int
    {
        $emailExact = $candidate['email'] !== ''
            && strtolower(trim($candidate['email'])) === strtolower(trim($input['email']));

        $firstExact = strtolower(trim($candidate['given_name'] ?? '')) === strtolower(trim($input['first_name']));
        $lastExact  = strtolower(trim($candidate['family_name'] ?? '')) === strtolower(trim($input['last_name']));

        // Rule 1 — email + first + last all exact.
        if ($emailExact && $firstExact && $lastExact) {
            return $this->getWeight(self::WEIGHT_EXACT_MATCH);
        }

        // Rule 2 — email exact but name has at least one discrepancy.
        if ($emailExact) {
            return $this->getWeight(self::WEIGHT_EMAIL_NAME_MISMATCH);
        }

        // Rule 3 — both first and last exact but email is different.
        if ($firstExact && $lastExact) {
            return $this->getWeight(self::WEIGHT_NAME_DIFF_EMAIL);
        }

        $inputDomain     = $this->extractEmailDomain($input['email']);
        $candidateDomain = $this->extractEmailDomain((string) ($candidate['email'] ?? ''));
        $domainMatch     = $inputDomain !== '' && $inputDomain === $candidateDomain;

        // Rule 4 — email domain + last name match.
        if ($domainMatch && $lastExact) {
            return $this->getWeight(self::WEIGHT_DOMAIN_LAST);
        }

        // Rule 5 — last name + first initial match.
        $givenName    = (string) ($candidate['given_name'] ?? '');
        $firstPartial = $input['first_name'] !== ''
            && $givenName !== ''
            && strtolower($givenName[0]) === strtolower($input['first_name'][0]);

        if ($lastExact && $firstPartial) {
            return $this->getWeight(self::WEIGHT_LAST_PARTIAL_FIRST);
        }

        // Rule 6 — last name only.
        if ($lastExact) {
            return $this->getWeight(self::WEIGHT_LAST_ONLY);
        }

        return 0;
    }

    // ── Categorisation ─────────────────────────────────────────────────────

    /**
     * Map a match score (and already-on-roster flag) to a category string.
     *
     * @param int  $score           Best score across all candidates.
     * @param bool $alreadyOnRoster Whether the top candidate is already rostered.
     */
    public function categorizeScore(int $score, bool $alreadyOnRoster = false): string
    {
        if ($alreadyOnRoster) {
            return self::CATEGORY_READY_TO_SYNC;
        }

        if ($score === $this->getWeight(self::WEIGHT_EXACT_MATCH)) {
            return self::CATEGORY_READY_TO_SYNC;
        }

        if ($score >= $this->getThreshold(self::THRESHOLD_KEY_PROBABLE)) {
            return self::CATEGORY_PROBABLE_MATCH;
        }

        if ($score >= $this->getThreshold(self::THRESHOLD_KEY_POSSIBLE)) {
            return self::CATEGORY_POSSIBLE_MATCH;
        }

        return self::CATEGORY_READY_TO_SYNC;
    }

    /**
     * Determine the record_status value from a match score and roster-membership flag.
     *
     * @param int  $score           Best score across all candidates.
     * @param bool $alreadyOnRoster Whether the top candidate is already rostered.
     */
    public function resolveRecordStatus(int $score, bool $alreadyOnRoster): string
    {
        if ($alreadyOnRoster) {
            return self::STATUS_ALREADY_ON_ROSTER;
        }

        if ($score === $this->getWeight(self::WEIGHT_EXACT_MATCH)) {
            return self::STATUS_EXACT_MATCH;
        }

        return self::STATUS_NEW_RECORD;
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * Extract the domain part of an email address (everything after '@').
     *
     * Returns an empty string when the address is malformed or empty.
     */
    public function extractEmailDomain(string $email): string
    {
        $atPos = strrpos($email, '@');

        if ($atPos === false) {
            return '';
        }

        return strtolower(substr($email, $atPos + 1));
    }
}
