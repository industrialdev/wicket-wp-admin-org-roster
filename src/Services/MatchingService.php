<?php

declare(strict_types=1);

namespace WicketAORM\Services;

/**
 * MDP duplicate-detection and scoring for staged roster records.
 *
 * Implements the additive scoring model defined in AORM-7 for a single row,
 * allowing IndividualController (AORM-5.6) to run matching synchronously
 * rather than via the background batch job used for bulk uploads.
 *
 * Additive scoring signals (see ScoringService for full details):
 *   email_exact      → 50
 *   phone_exact      → 40
 *   last_name_exact  → 25
 *   first_name_exact → 25
 *   email_domain     → 10  (skipped when email_exact fires)
 *   title_exact      →  5
 *   org_overlap      →  5  (applied post-roster-check for best candidate only)
 *   first_initial    →  5  (skipped when first_name_exact fires)
 *   Cap              → 100
 *
 * Thresholds:
 *   80–100 → probable_match
 *   30–79  → possible_match
 *   0–29   → ready_to_sync  (new_record)
 *
 * Exact-match detection: email + first + last all exact → ready_to_sync
 * (exact_match), bypassing human review.
 *
 * "Already on Roster" check: when the top-scoring candidate (score ≥ possible
 * threshold) is already a member of the target org membership roster, the
 * record_status is set to 'already_on_roster' and category to 'ready_to_sync'.
 * The org_overlap weight is also added to that candidate's score.
 *
 * Scoring is delegated to ScoringService (AORM-7.3), which holds the
 * configurable weights and thresholds. The constants below are kept for
 * backward compatibility with existing call-sites.
 */
class MatchingService
{
    // ── Score signal keys (mirrors ScoringService) ─────────────────────────

    public const WEIGHT_EMAIL_EXACT      = ScoringService::WEIGHT_EMAIL_EXACT;
    public const WEIGHT_PHONE_EXACT      = ScoringService::WEIGHT_PHONE_EXACT;
    public const WEIGHT_LAST_NAME_EXACT  = ScoringService::WEIGHT_LAST_NAME_EXACT;
    public const WEIGHT_FIRST_NAME_EXACT = ScoringService::WEIGHT_FIRST_NAME_EXACT;
    public const WEIGHT_EMAIL_DOMAIN     = ScoringService::WEIGHT_EMAIL_DOMAIN;
    public const WEIGHT_TITLE_EXACT      = ScoringService::WEIGHT_TITLE_EXACT;
    public const WEIGHT_ORG_OVERLAP      = ScoringService::WEIGHT_ORG_OVERLAP;
    public const WEIGHT_FIRST_INITIAL    = ScoringService::WEIGHT_FIRST_INITIAL;

    public const SCORE_CAP = ScoringService::SCORE_CAP;

    // ── Threshold constants ────────────────────────────────────────────────

    /** Minimum score to classify as Probable Match (score 80–100). */
    public const THRESHOLD_PROBABLE = 80;

    /** Minimum score to classify as Possible Match (score 30–79). */
    public const THRESHOLD_POSSIBLE = 30;

    // ── Category / record-status constants ────────────────────────────────

    public const CATEGORY_READY_TO_SYNC  = 'ready_to_sync';
    public const CATEGORY_PROBABLE_MATCH = 'probable_match';
    public const CATEGORY_POSSIBLE_MATCH = 'possible_match';

    public const STATUS_NEW_RECORD        = 'new_record';
    public const STATUS_EXACT_MATCH       = 'exact_match';
    public const STATUS_ALREADY_ON_ROSTER = 'already_on_roster';

    public function __construct(
        private readonly ?MdpClient $mdpClient = null,
        private readonly ?ScoringService $scoringService = null,
    ) {
    }

    // ── Scorer accessor ────────────────────────────────────────────────────

    /**
     * Return the active ScoringService instance.
     *
     * Uses the injected instance when provided; otherwise instantiates one
     * from default weights and thresholds so that call-sites that construct
     * MatchingService without arguments continue to work unchanged.
     */
    private function scorer(): ScoringService
    {
        return $this->scoringService ?? ScoringService::fromDefaults();
    }

    // ── Public API ─────────────────────────────────────────────────────────

    /**
     * Run MDP matching for a single staged-record row and return the data
     * needed to update that row in the database.
     *
     * Steps:
     *   1. Search MDP via a single POST people/query OR group (email, phone,
     *      first name, last name) to gather candidates.
     *   2. Score each candidate additively; sort descending.
     *   3. When the best score ≥ THRESHOLD_POSSIBLE, check if the candidate
     *      is already on the target roster.
     *   4. If already on roster, add org_overlap weight to best score (capped).
     *   5. Determine if best candidate is an exact match (email+first+last).
     *   6. Determine category and record_status from adjusted score + flags.
     *   7. Build matched_persons / match_details JSON payloads.
     *
     * @param array{
     *   first_name:    string,
     *   last_name:     string,
     *   email:         string,
     *   phone?: string,
     *   title?:        string,
     * } $fields         Submitted person data (already validated).
     * @param string $orgUuid        Organisation UUID (not used in MDP search but
     *                               kept for symmetry with AORM-7 batch matching).
     * @param string $membershipUuid Org-membership UUID, used to check roster
     *                               membership for "Already on Roster" detection
     *                               and the org_overlap scoring signal.
     *
     * @return array{
     *   match_count:     int,
     *   matched_persons: string,
     *   match_details:   string,
     *   record_status:   string,
     *   category:        string,
     *   updated_at:      string,
     * }
     */
    public function matchRow(array $fields, string $orgUuid, string $membershipUuid): array
    {
        $client = $this->mdpClient ?? new MdpClient();

        // 1. Gather candidates from MDP in a single OR query.
        $candidates = $client->searchPersons($fields);

        $scorer = $this->scorer();

        // 4. Score every candidate additively and sort descending.
        $scored = [];

        foreach ($candidates as $candidate) {
            $scored[] = array_merge($candidate, ['_score' => $scorer->scoreCandidate($candidate, $fields)]);
        }

        usort($scored, static fn (array $a, array $b): int => $b['_score'] <=> $a['_score']);

        $best      = $scored[0] ?? null;
        $bestScore = $best !== null ? (int) $best['_score'] : 0;

        // 5. Already-on-roster check for meaningful matches.
        $alreadyOnRoster = false;

        if ($best !== null && $bestScore >= $scorer->getThreshold(ScoringService::THRESHOLD_KEY_POSSIBLE)) {
            $alreadyOnRoster = $client->isPersonOnRoster(
                (string) ($best['uuid'] ?? ''),
                $membershipUuid,
            );
        }

        // 6. Apply org_overlap signal to best candidate score when on roster.
        //    The org_overlap weight is configurable; apply it here rather than
        //    inside scoreCandidate() because roster membership is only checked
        //    for the top candidate (avoiding N extra MDP calls).
        if ($alreadyOnRoster) {
            $bestScore = min(
                $bestScore + $scorer->getWeight(ScoringService::WEIGHT_ORG_OVERLAP),
                ScoringService::SCORE_CAP,
            );
        }

        // 7. Categorise (score=100 auto-routes to ready_to_sync / exact_match).
        $category     = $scorer->categorizeScore($bestScore, $alreadyOnRoster);
        $recordStatus = $scorer->resolveRecordStatus($bestScore, $alreadyOnRoster);

        // 9. Build JSON payloads — only for candidates at or above the threshold.
        $aboveThreshold = array_values(array_filter(
            $scored,
            static fn (array $c): bool => $c['_score'] >= self::THRESHOLD_POSSIBLE,
        ));

        $matchCount = count($aboveThreshold);

        $matchedPersons = (string) json_encode(
            array_map(
                static fn (array $c): array => [
                    'uuid'  => (string) ($c['uuid'] ?? ''),
                    'name'  => (string) ($c['name'] ?? ''),
                    'email' => (string) ($c['email'] ?? ''),
                ],
                $aboveThreshold,
            ),
        );

        $matchDetails = (string) json_encode(
            array_map(
                fn (array $c): array => [
                    'uuid'     => (string) ($c['uuid'] ?? ''),
                    'score'    => $c['_score'],
                    'category' => $scorer->categorizeScore($c['_score'], false),
                ],
                $aboveThreshold,
            ),
        );

        return [
            'match_count'     => $matchCount,
            'matched_persons' => $matchedPersons,
            'match_details'   => $matchDetails,
            'record_status'   => $recordStatus,
            'category'        => $category,
            'updated_at'      => current_time('mysql'),
        ];
    }

    /**
     * Score a single MDP candidate against the submitted row fields.
     *
     * Delegates to the active ScoringService instance so that any custom
     * weights injected via the constructor or WordPress filter are honoured.
     *
     * @param array{uuid: string, name: string, email: string, given_name: string, family_name: string} $candidate
     * @param array{first_name: string, last_name: string, email: string} $input
     */
    public function scoreCandidate(array $candidate, array $input): int
    {
        return $this->scorer()->scoreCandidate($candidate, $input);
    }

    /**
     * Map a match score (and already-on-roster flag) to a category string.
     *
     * @param int  $score           Best score across all candidates.
     * @param bool $alreadyOnRoster Whether the top candidate is already rostered.
     */
    public function categorizeScore(int $score, bool $alreadyOnRoster = false): string
    {
        return $this->scorer()->categorizeScore($score, $alreadyOnRoster);
    }

    /**
     * Determine the record_status value from a match score and roster-membership flag.
     *
     * @param int  $score           Best score across all candidates.
     * @param bool $alreadyOnRoster Whether the top candidate is already rostered.
     */
    public function resolveRecordStatus(int $score, bool $alreadyOnRoster): string
    {
        return $this->scorer()->resolveRecordStatus($score, $alreadyOnRoster);
    }

    /**
     * Extract the domain part of an email address (everything after '@').
     *
     * Returns an empty string when the address is malformed or empty.
     */
    public function extractEmailDomain(string $email): string
    {
        return $this->scorer()->extractEmailDomain($email);
    }

}
