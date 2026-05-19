<?php

declare(strict_types=1);

namespace WicketAORM\Services;

use WicketAORM\Database\StagedRecordsTable;

/**
 * MatchingJobRunner — background job handler for MDP duplicate detection.
 *
 * This class is the WordPress action hook callback registered under HOOK.
 * Action Scheduler (or WP-Cron via the fallback path in SchedulerService)
 * invokes handle() with the upload_session_id of the batch to process.
 *
 * Dispatching this job is done via SchedulerService::dispatch():
 *
 *   $scheduler->dispatch(
 *       MatchingJobRunner::HOOK,
 *       ['upload_session_id' => $sessionId],
 *   );
 *
 * Action Scheduler serialises the $args array and passes each value as an
 * individual argument to the hook callback. WP-Cron follows the same
 * convention. Both paths therefore call:
 *
 *   do_action('wicket_aorm_run_matching', $uploadSessionId)
 *
 * which maps directly to handle(string $uploadSessionId).
 *
 * Milestone coverage:
 *   AORM-7.2  — hook constant + handle() signature
 *   AORM-7.6  — categorise each row from its highest MDP match score
 *   AORM-7.7  — build and persist match payload (match_count, matched_persons, match_details)
 *   AORM-7.8  — already-on-roster check (isPersonOnRoster → record_status=already_on_roster)
 *   AORM-7.9  — replace mode: inject remove_existing rows for absent roster members
 *               (batch scheduling in AORM-7.10)
 */
class MatchingJobRunner
{
    /**
     * The WordPress action hook name fired by Action Scheduler / WP-Cron.
     *
     * Referenced in:
     *   - Main::registerHooks()  — add_action() registration
     *   - UploadController       — dispatch after CSV parse (AORM-7.2)
     *   - MatchingJobRunner      — re-dispatch for next batch (AORM-7.10)
     */
    public const HOOK = 'wicket_aorm_run_matching';

    public function __construct(
        private readonly ?SchedulerService $schedulerService = null,
        private readonly ?StagedRecordsTable $stagedRecordsTable = null,
        private readonly ?MdpClient $mdpClient = null,
        private readonly ?ScoringService $scoringService = null,
    ) {
    }

    // ── Public entry point ────────────────────────────────────────────────

    /**
     * Entry point invoked by Action Scheduler / WP-Cron.
     *
     * Fetches the next batch of unprocessed valid staged records for the
     * given session, scores each against MDP candidates, categorises by the
     * highest score, and writes category, record_status, and the full match
     * payload (match_count, matched_persons, match_details) back to the DB.
     *
     * Scoring rules (default weights — override via wicket_aorm_scoring_config filter):
     *   100 — email + first + last exact  → ready_to_sync  (exact_match)
     *    80 — email exact, name differs   → probable_match
     *    60 — first + last exact, diff email → probable_match
     *    40 — email domain + last exact   → possible_match
     *    30 — last + first initial        → possible_match
     *    20 — last name only              → possible_match
     *     0 — no match                   → ready_to_sync  (new_record)
     *
     * Thresholds: 80+ → probable_match, 20–79 → possible_match, 0 → new_record.
     *
     * Already-on-roster check (AORM-7.8): the top candidate's UUID is checked
     * against the roster via MdpClient::isPersonOnRoster(); when true the record
     * gets category=ready_to_sync and record_status=already_on_roster.
     * Batch scheduling / re-dispatch: AORM-7.10.
     *
     * @param string $uploadSessionId UUID of the upload session to process.
     */
    public function handle(string $uploadSessionId): void
    {
        if ($uploadSessionId === '') {
            return;
        }

        $table  = $this->stagedRecordsTable ?? new StagedRecordsTable();
        $client = $this->mdpClient ?? new MdpClient();
        $scorer = $this->scoringService ?? ScoringService::fromWordPressFilter();

        $records = $table->getPendingMatchingRecords($uploadSessionId);

        foreach ($records as $record) {
            $fields = $this->extractFields($record);

            // Search MDP by email, then by name (AORM-7.4).
            $byEmail = $client->searchPersonsByEmail($fields['email']);
            $byName  = $client->searchPersonsByName($fields['first_name'], $fields['last_name']);

            // Merge and deduplicate candidates by UUID (AORM-7.5).
            $candidates = $this->mergeCandidates($byEmail, $byName);

            // Score every candidate and sort descending (AORM-7.5).
            $scored = [];

            foreach ($candidates as $candidate) {
                $scored[] = array_merge($candidate, ['_score' => $scorer->scoreCandidate($candidate, $fields)]);
            }

            usort($scored, static fn (array $a, array $b): int => $b['_score'] <=> $a['_score']);

            $bestScore = isset($scored[0]) ? (int) $scored[0]['_score'] : 0;

            // AORM-7.8: Check if the top candidate is already on the roster.
            // Only perform the API call when at least one candidate was scored —
            // a bestScore of 0 means no candidates matched, so there is no UUID
            // to look up and alreadyOnRoster stays false.
            $alreadyOnRoster = false;

            if ($bestScore > 0 && isset($scored[0]['uuid']) && (string) $scored[0]['uuid'] !== '') {
                $alreadyOnRoster = $client->isPersonOnRoster(
                    (string) $scored[0]['uuid'],
                    (string) ($record['membership_uuid'] ?? ''),
                );
            }

            // Categorise from the highest score (AORM-7.6, AORM-7.8).
            $category     = $scorer->categorizeScore($bestScore, $alreadyOnRoster);
            $recordStatus = $scorer->resolveRecordStatus($bestScore, $alreadyOnRoster);

            // AORM-7.7: Build match payload for all candidates at or above the
            // possible-match threshold.  Candidates below threshold are noise
            // and are not stored.
            $possibleThreshold = $scorer->getThreshold(ScoringService::THRESHOLD_KEY_POSSIBLE);

            $aboveThreshold = array_values(array_filter(
                $scored,
                static fn (array $c): bool => (int) $c['_score'] >= $possibleThreshold,
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
                        'score'    => (int) $c['_score'],
                        'category' => $scorer->categorizeScore((int) $c['_score']),
                    ],
                    $aboveThreshold,
                ),
            );

            $table->updateRecord((int) $record['id'], [
                'category'        => $category,
                'record_status'   => $recordStatus,
                'match_count'     => $matchCount,
                'matched_persons' => $matchedPersons,
                'match_details'   => $matchDetails,
                'updated_at'      => current_time('mysql'),
            ]);
        }

        // AORM-7.9: After scoring all rows in the batch, inject synthetic
        // "Remove Existing Record" rows for roster members absent from the
        // upload (replace mode only).  The method is idempotent so it is safe
        // to call on every handle() invocation — if removal rows already exist
        // (e.g. on a job retry) it exits immediately.
        $this->maybeInsertReplaceModeRemovals($uploadSessionId, $table, $client);
    }

    // ── Private helpers ───────────────────────────────────────────────────

    /**
     * Extract the person fields needed for MDP matching from a staged record row.
     *
     * The raw_data column stores the parsed CSV row as a JSON object.
     * Returns empty strings for any missing keys so scoring rules degrade
     * gracefully rather than throwing.
     *
     * @param array<string, mixed> $record  A row from wp_wicket_aorm_staged_records.
     *
     * @return array{first_name: string, last_name: string, email: string}
     */
    private function extractFields(array $record): array
    {
        $raw = json_decode((string) ($record['raw_data'] ?? ''), true);
        $raw = is_array($raw) ? $raw : [];

        return [
            'first_name' => (string) ($raw['first_name'] ?? ''),
            'last_name'  => (string) ($raw['last_name'] ?? ''),
            'email'      => (string) ($raw['email'] ?? ''),
        ];
    }

    /**
     * Merge two candidate lists from MDP search and deduplicate by UUID.
     *
     * The first occurrence of each UUID is kept; subsequent duplicates are
     * discarded. Candidates with an empty UUID are dropped silently.
     *
     * @param list<array<string,mixed>> $a  Results from searchPersonsByEmail().
     * @param list<array<string,mixed>> $b  Results from searchPersonsByName().
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
     * Inject synthetic "Remove Existing Record" staged rows for replace-mode sessions.
     *
     * For replace-mode uploads (action_type = 'replace') only, this method:
     *   1. Reads all valid uploaded email addresses from the session.
     *   2. Fetches all current roster members from MDP (all pages).
     *   3. For each roster member whose email is absent from the uploaded set,
     *      inserts a staged record with category = 'ready_to_sync' and
     *      record_status = 'remove_existing' so that SyncService knows to
     *      end-date that person's org relationship during the sync phase.
     *
     * Idempotent: exits immediately when any remove_existing rows already exist
     * for the session, so retries and re-runs never create duplicates.
     *
     * Email comparison is case-insensitive (both sides lowercased).
     *
     * @param string             $uploadSessionId Upload session UUID.
     * @param StagedRecordsTable $table           DB table accessor.
     * @param MdpClient          $client          MDP API client.
     */
    private function maybeInsertReplaceModeRemovals(
        string $uploadSessionId,
        StagedRecordsTable $table,
        MdpClient $client,
    ): void {
        // Idempotency guard — skip if removal rows already exist.
        if ($table->hasRemoveExistingRecords($uploadSessionId)) {
            return;
        }

        // Read session context to determine action_type and org/membership UUIDs.
        $context = $table->getSessionContext($uploadSessionId);

        if ($context === null || $context['action_type'] !== 'replace') {
            return;
        }

        $orgUuid        = $context['org_uuid'];
        $membershipUuid = $context['membership_uuid'];
        $uploadedBy     = $context['uploaded_by'];

        // Build a lookup set of uploaded emails (lowercased) for O(1) lookup.
        $uploadedEmails = array_flip(
            $table->getValidRowEmailsForSession($uploadSessionId),
        );

        // Fetch every current roster member (all MDP pages).
        $currentMembers = $client->getAllRosterMembers($orgUuid, $membershipUuid);

        $now = current_time('mysql');

        foreach ($currentMembers as $member) {
            $memberEmail = strtolower(trim((string) ($member['email'] ?? '')));

            // Skip members with no email or whose email appears in the upload.
            if ($memberEmail === '' || isset($uploadedEmails[$memberEmail])) {
                continue;
            }

            // This roster member was not in the upload — schedule removal.
            $table->insertRecord([
                'upload_session_id'  => $uploadSessionId,
                'action_type'        => 'replace',
                'org_uuid'           => $orgUuid,
                'membership_uuid'    => $membershipUuid,
                'raw_data'           => (string) json_encode([
                    'person_uuid' => (string) ($member['person_uuid'] ?? ''),
                    'first_name'  => '',
                    'last_name'   => '',
                    'email'       => (string) ($member['email'] ?? ''),
                    'name'        => (string) ($member['name'] ?? ''),
                ]),
                'validation_status'  => 'valid',
                'validation_message' => null,
                'category'           => 'ready_to_sync',
                'record_status'      => 'remove_existing',
                'match_count'        => 0,
                'matched_persons'    => null,
                'match_details'      => null,
                'sync_status'        => 'pending',
                'uploaded_by'        => $uploadedBy,
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);
        }
    }
}
