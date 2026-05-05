<?php

declare(strict_types=1);

namespace WicketAORM\Services;

/**
 * MDP duplicate-detection and scoring for staged roster records.
 *
 * Implements the scoring matrix defined in AORM-7 for a single row,
 * allowing IndividualController (AORM-5.6) to run matching synchronously
 * rather than via the background batch job used for bulk uploads.
 *
 * Scoring matrix (highest applicable score wins):
 *   100 — email exact  + first exact  + last exact  → Exact Match
 *    80 — email exact  + name differs               → Probable Match
 *    60 — first exact  + last exact   + diff email  → Probable Match
 *    40 — email domain + last exact                 → Possible Match
 *    30 — last exact   + first partial (first char) → Possible Match
 *    20 — last exact   only                         → Possible Match
 *     0 — no match                                  → New Record
 *
 * Thresholds:
 *   = 100  → ready_to_sync   (exact_match)
 *   80–99  → probable_match
 *   20–79  → possible_match
 *   0      → ready_to_sync   (new_record)
 *
 * "Already on Roster" check: when the top-scoring candidate (score ≥ 20)
 * is already a member of the target org membership roster, the record_status
 * is set to 'already_on_roster' and category to 'ready_to_sync'.
 */
class MatchingService
{
    // ── Score constants ────────────────────────────────────────────────────

    public const SCORE_EXACT_MATCH         = 100;
    public const SCORE_EMAIL_NAME_MISMATCH = 80;
    public const SCORE_NAME_DIFF_EMAIL     = 60;
    public const SCORE_DOMAIN_LAST         = 40;
    public const SCORE_LAST_PARTIAL_FIRST  = 30;
    public const SCORE_LAST_ONLY           = 20;

    // ── Threshold constants ────────────────────────────────────────────────

    /** Minimum score to classify as Probable Match (score 80–99). */
    public const THRESHOLD_PROBABLE = 80;

    /** Minimum score to classify as Possible Match (score 20–79). */
    public const THRESHOLD_POSSIBLE = 20;

    // ── Category / record-status constants ────────────────────────────────

    public const CATEGORY_READY_TO_SYNC  = 'ready_to_sync';
    public const CATEGORY_PROBABLE_MATCH = 'probable_match';
    public const CATEGORY_POSSIBLE_MATCH = 'possible_match';

    public const STATUS_NEW_RECORD        = 'new_record';
    public const STATUS_EXACT_MATCH       = 'exact_match';
    public const STATUS_ALREADY_ON_ROSTER = 'already_on_roster';

    public function __construct(
        private readonly ?MdpClient $mdpClient = null,
    ) {
    }

    // ── Public API ─────────────────────────────────────────────────────────

    /**
     * Run MDP matching for a single staged-record row and return the data
     * needed to update that row in the database.
     *
     * Steps:
     *   1. Search MDP by email to find candidates.
     *   2. Search MDP by first+last name to find additional candidates.
     *   3. Merge and deduplicate candidates by UUID.
     *   4. Score each candidate; pick the highest-scoring one.
     *   5. When the best score ≥ THRESHOLD_POSSIBLE, check if the candidate
     *      is already on the target roster.
     *   6. Determine category and record_status from score + roster membership.
     *   7. Build matched_persons / match_details JSON payloads.
     *
     * @param array{
     *   first_name:    string,
     *   last_name:     string,
     *   email:         string,
     *   mobile_phone?: string,
     *   title?:        string,
     * } $fields         Submitted person data (already validated).
     * @param string $orgUuid        Organisation UUID (not used in MDP search but
     *                               kept for symmetry with AORM-7 batch matching).
     * @param string $membershipUuid Org-membership UUID, used to check roster
     *                               membership for "Already on Roster" detection.
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

        // 1 & 2. Gather candidates from MDP.
        $byEmail = $client->searchPersonsByEmail($fields['email']);
        $byName  = $client->searchPersonsByName(
            $fields['first_name'],
            $fields['last_name'],
        );

        // 3. Merge + deduplicate by UUID.
        $candidates = $this->mergeCandidates($byEmail, $byName);

        // 4. Score every candidate and sort descending.
        $scored = [];

        foreach ($candidates as $candidate) {
            $scored[] = array_merge($candidate, ['_score' => $this->scoreCandidate($candidate, $fields)]);
        }

        usort($scored, static fn (array $a, array $b): int => $b['_score'] <=> $a['_score']);

        $best      = $scored[0] ?? null;
        $bestScore = $best !== null ? (int) $best['_score'] : 0;

        // 5. Already-on-roster check for meaningful matches.
        $alreadyOnRoster = false;

        if ($best !== null && $bestScore >= self::THRESHOLD_POSSIBLE) {
            $alreadyOnRoster = $client->isPersonOnRoster(
                (string) ($best['uuid'] ?? ''),
                $membershipUuid,
            );
        }

        // 6. Categorise.
        $category     = $this->categorizeScore($bestScore, $alreadyOnRoster);
        $recordStatus = $this->resolveRecordStatus($bestScore, $alreadyOnRoster);

        // 7. Build JSON payloads — only for candidates at or above the threshold.
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
                    'category' => $this->categorizeScore($c['_score'], false),
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

        // 100 — email + first + last all exact.
        if ($emailExact && $firstExact && $lastExact) {
            return self::SCORE_EXACT_MATCH;
        }

        // 80 — email exact but name has at least one discrepancy.
        if ($emailExact) {
            return self::SCORE_EMAIL_NAME_MISMATCH;
        }

        // 60 — both first and last exact but email is different.
        if ($firstExact && $lastExact) {
            return self::SCORE_NAME_DIFF_EMAIL;
        }

        $inputDomain     = $this->extractEmailDomain($input['email']);
        $candidateDomain = $this->extractEmailDomain((string) ($candidate['email'] ?? ''));
        $domainMatch     = $inputDomain !== '' && $inputDomain === $candidateDomain;

        // 40 — email domain + last name match.
        if ($domainMatch && $lastExact) {
            return self::SCORE_DOMAIN_LAST;
        }

        // 30 — last name + first initial match.
        $givenName    = (string) ($candidate['given_name'] ?? '');
        $firstPartial = $input['first_name'] !== ''
            && $givenName !== ''
            && strtolower($givenName[0]) === strtolower($input['first_name'][0]);

        if ($lastExact && $firstPartial) {
            return self::SCORE_LAST_PARTIAL_FIRST;
        }

        // 20 — last name only.
        if ($lastExact) {
            return self::SCORE_LAST_ONLY;
        }

        return 0;
    }

    /**
     * Map a match score (and already-on-roster flag) to a staged-records category.
     *
     * @param int  $score           Best score across all candidates.
     * @param bool $alreadyOnRoster Whether the top candidate is already rostered.
     */
    public function categorizeScore(int $score, bool $alreadyOnRoster = false): string
    {
        if ($alreadyOnRoster) {
            return self::CATEGORY_READY_TO_SYNC;
        }

        if ($score === self::SCORE_EXACT_MATCH) {
            return self::CATEGORY_READY_TO_SYNC;
        }

        if ($score >= self::THRESHOLD_PROBABLE) {
            return self::CATEGORY_PROBABLE_MATCH;
        }

        if ($score >= self::THRESHOLD_POSSIBLE) {
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

        if ($score === self::SCORE_EXACT_MATCH) {
            return self::STATUS_EXACT_MATCH;
        }

        return self::STATUS_NEW_RECORD;
    }

    // ── Private helpers ────────────────────────────────────────────────────

    /**
     * Merge two candidate lists (email search + name search) and deduplicate
     * by UUID, keeping the first occurrence encountered.
     *
     * @param list<array<string,mixed>> $a
     * @param list<array<string,mixed>> $b
     *
     * @return list<array<string,mixed>>
     */
    private function mergeCandidates(array $a, array $b): array
    {
        $seen   = [];
        $merged = [];

        foreach (array_merge($a, $b) as $candidate) {
            $uuid = (string) ($candidate['uuid'] ?? '');

            if ($uuid === '' || isset($seen[$uuid])) {
                continue;
            }

            $seen[$uuid] = true;
            $merged[]    = $candidate;
        }

        return $merged;
    }

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
