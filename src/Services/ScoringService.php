<?php

declare(strict_types=1);

namespace WicketAORM\Services;

/**
 * ScoringService — configurable matching-score engine for AORM-7.
 *
 * Encapsulates the additive scoring model, threshold comparisons, and
 * categorisation logic that determines how well an MDP candidate matches a
 * staged roster row.  All numeric weights and thresholds are
 * constructor-injectable so that administrators can tune the matching
 * sensitivity without modifying code.
 *
 * Additive scoring model:
 *   Each signal that fires contributes its weight independently.  The raw sum
 *   is capped at SCORE_CAP (100) so no single over-determining combination
 *   inflates the result beyond the maximum.
 *
 *   Signal weights (defaults):
 *     email_exact      → 50   (exact email address match)
 *     phone_exact      → 40   (exact phone after stripping non-digits)
 *     last_name_exact  → 25   (exact last-name match)
 *     first_name_exact → 25   (exact first-name match)
 *     email_domain     → 10   (domain-only match; skipped when email_exact fires)
 *     title_exact      →  5   (exact job-title match)
 *     org_overlap      →  5   (candidate already on target org's roster)
 *     first_initial    →  5   (first-initial match; skipped when first_name_exact fires)
 *
 *   Design rationale: at least two corroborating signals are required to reach
 *   the Probable Match threshold (80).  No single signal alone is sufficient,
 *   protecting against false merges on common names, shared emails, or
 *   reassigned phone numbers.
 *
 * Default thresholds:
 *   probable → 80  (score 80–100 → probable_match)
 *   possible → 30  (score 30–79 → possible_match)
 *                  (score  0–29 → ready_to_sync / new_record)
 *
 * Exact-match detection:
 *   A dedicated isExactMatch() helper checks email + first + last all exact.
 *   When true the record bypasses human review (ready_to_sync / exact_match).
 *
 * Customisation — WordPress filter:
 *   add_filter( 'wicket_aorm_scoring_config', function ( array $config ): array {
 *       $config['weights']['phone_exact'] = 35;
 *       $config['thresholds']['probable'] = 75;
 *       return $config;
 *   } );
 *
 * Or inject directly:
 *   $scorer = new ScoringService(
 *       weights:    ['email_exact' => 50, 'phone_exact' => 35, ...],
 *       thresholds: ['probable' => 75, 'possible' => 30],
 *   );
 */
class ScoringService
{
    // ── Weight keys ────────────────────────────────────────────────────────

    public const WEIGHT_EMAIL_EXACT      = 'email_exact';
    public const WEIGHT_PHONE_EXACT      = 'phone_exact';
    public const WEIGHT_LAST_NAME_EXACT  = 'last_name_exact';
    public const WEIGHT_FIRST_NAME_EXACT = 'first_name_exact';
    public const WEIGHT_EMAIL_DOMAIN     = 'email_domain';
    public const WEIGHT_TITLE_EXACT      = 'title_exact';
    public const WEIGHT_ORG_OVERLAP      = 'org_overlap';
    public const WEIGHT_FIRST_INITIAL    = 'first_initial';

    // ── Score cap ──────────────────────────────────────────────────────────

    /** Raw signal sum is capped at this value before categorisation. */
    public const SCORE_CAP = 100;

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
        self::WEIGHT_EMAIL_EXACT      => 50,
        self::WEIGHT_PHONE_EXACT      => 40,
        self::WEIGHT_LAST_NAME_EXACT  => 25,
        self::WEIGHT_FIRST_NAME_EXACT => 25,
        self::WEIGHT_EMAIL_DOMAIN     => 10,
        self::WEIGHT_TITLE_EXACT      =>  5,
        self::WEIGHT_ORG_OVERLAP      =>  5,
        self::WEIGHT_FIRST_INITIAL    =>  5,
    ];

    /** @var array<string, int> */
    public const DEFAULT_THRESHOLDS = [
        self::THRESHOLD_KEY_PROBABLE => 80,
        self::THRESHOLD_KEY_POSSIBLE => 30,
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
     * Score a single MDP candidate against submitted row fields using the
     * additive signal model.
     *
     * Each matching signal independently contributes its weight.  The raw sum
     * is capped at SCORE_CAP.  Two mutual-exclusivity rules apply:
     *   - email_domain is skipped when email_exact already fired (same signal,
     *     weaker form).
     *   - first_initial is skipped when first_name_exact already fired.
     *
     * Comparisons are case-insensitive and whitespace-trimmed.  Phone numbers
     * are normalised by stripping all non-digit characters before comparison.
     *
     * @param array{
     *   uuid: string,
     *   name: string,
     *   email: string,
     *   given_name: string,
     *   family_name: string,
     *   mobile_phone?: string,
     *   title?: string,
     * } $candidate  MDP candidate record.
     * @param array{
     *   first_name: string,
     *   last_name: string,
     *   email: string,
     *   mobile_phone?: string,
     *   title?: string,
     * } $input       Submitted person data from the staged record.
     */
    public function scoreCandidate(array $candidate, array $input): int
    {
        $score = 0;

        // ── Email exact (50) ───────────────────────────────────────────────
        $candidateEmail = (string) ($candidate['email'] ?? '');
        $emailExact     = $candidateEmail !== ''
            && strtolower(trim($candidateEmail)) === strtolower(trim($input['email']));

        if ($emailExact) {
            $score += $this->getWeight(self::WEIGHT_EMAIL_EXACT);
        }

        // ── Phone exact (40) ──────────────────────────────────────────────
        $candidatePhone = $this->normalizePhone((string) ($candidate['mobile_phone'] ?? ''));
        $inputPhone     = $this->normalizePhone((string) ($input['mobile_phone'] ?? ''));

        if ($candidatePhone !== '' && $inputPhone !== '' && $candidatePhone === $inputPhone) {
            $score += $this->getWeight(self::WEIGHT_PHONE_EXACT);
        }

        // ── Last name exact (25) ───────────────────────────────────────────
        $lastExact = strtolower(trim((string) ($candidate['family_name'] ?? '')))
            === strtolower(trim($input['last_name']));

        if ($lastExact) {
            $score += $this->getWeight(self::WEIGHT_LAST_NAME_EXACT);
        }

        // ── First name exact (25) / first initial (5) ─────────────────────
        $candidateFirst = (string) ($candidate['given_name'] ?? '');
        $firstExact     = strtolower(trim($candidateFirst)) === strtolower(trim($input['first_name']));

        if ($firstExact) {
            $score += $this->getWeight(self::WEIGHT_FIRST_NAME_EXACT);
        } elseif (
            $input['first_name'] !== ''
            && $candidateFirst !== ''
            && strtolower($candidateFirst[0]) === strtolower($input['first_name'][0])
        ) {
            // First-initial only fires when first-name-exact did not.
            $score += $this->getWeight(self::WEIGHT_FIRST_INITIAL);
        }

        // ── Email domain (10) — skipped when email_exact already fired ─────
        if (! $emailExact) {
            $inputDomain     = $this->extractEmailDomain($input['email']);
            $candidateDomain = $this->extractEmailDomain($candidateEmail);
            $domainMatch     = $inputDomain !== '' && $inputDomain === $candidateDomain;

            if ($domainMatch) {
                $score += $this->getWeight(self::WEIGHT_EMAIL_DOMAIN);
            }
        }

        // ── Title exact (5) ───────────────────────────────────────────────
        $candidateTitle = strtolower(trim((string) ($candidate['title'] ?? '')));
        $inputTitle     = strtolower(trim((string) ($input['title'] ?? '')));

        if ($candidateTitle !== '' && $inputTitle !== '' && $candidateTitle === $inputTitle) {
            $score += $this->getWeight(self::WEIGHT_TITLE_EXACT);
        }

        return min($score, self::SCORE_CAP);
    }

    /**
     * Determine whether a candidate is an exact match for the submitted input.
     *
     * An exact match requires email, first name, and last name to all match
     * exactly (case-insensitive, whitespace-trimmed).  Records that satisfy
     * this check bypass human review and are routed directly to ready_to_sync.
     *
     * @param array{email: string, given_name: string, family_name: string} $candidate
     * @param array{first_name: string, last_name: string, email: string}   $input
     */
    public function isExactMatch(array $candidate, array $input): bool
    {
        $candidateEmail = (string) ($candidate['email'] ?? '');

        if ($candidateEmail === '') {
            return false;
        }

        return strtolower(trim($candidateEmail)) === strtolower(trim($input['email']))
            && strtolower(trim((string) ($candidate['given_name'] ?? ''))) === strtolower(trim($input['first_name']))
            && strtolower(trim((string) ($candidate['family_name'] ?? ''))) === strtolower(trim($input['last_name']));
    }

    // ── Categorisation ─────────────────────────────────────────────────────

    /**
     * Map a match score (and already-on-roster / exact-match flags) to a category string.
     *
     * Score ranges (defaults):
     *   80–100 → probable_match
     *   30–79  → possible_match
     *   0–29   → ready_to_sync  (treated as new record)
     *
     * already_on_roster=true and isExactMatch=true both short-circuit to
     * ready_to_sync, as those records bypass human review.
     *
     * @param int  $score           Best score across all candidates (post-cap).
     * @param bool $alreadyOnRoster Whether the top candidate is already rostered.
     * @param bool $isExactMatch    Whether email + first + last all matched exactly.
     */
    public function categorizeScore(int $score, bool $alreadyOnRoster = false, bool $isExactMatch = false): string
    {
        if ($alreadyOnRoster || $isExactMatch) {
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
     * Determine the record_status value from a match score, roster-membership
     * flag, and exact-match determination.
     *
     * Priority:
     *   1. already_on_roster → always_on_roster status.
     *   2. isExactMatch      → exact_match status (bypasses human review).
     *   3. Otherwise         → new_record.
     *
     * @param int  $score           Best score across all candidates (post-cap).
     * @param bool $alreadyOnRoster Whether the top candidate is already rostered.
     * @param bool $isExactMatch    Whether email + first + last all matched exactly.
     */
    public function resolveRecordStatus(int $score, bool $alreadyOnRoster, bool $isExactMatch = false): string
    {
        if ($alreadyOnRoster) {
            return self::STATUS_ALREADY_ON_ROSTER;
        }

        if ($isExactMatch) {
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

    /**
     * Normalise a phone number string for comparison by stripping every
     * character that is not a digit (0–9).
     *
     * Examples:
     *   '+1 (555) 123-4567' → '15551234567'
     *   '555.123.4567'      → '5551234567'
     *   ''                  → ''
     */
    public function normalizePhone(string $phone): string
    {
        return preg_replace('/\D/', '', $phone) ?? '';
    }
}
