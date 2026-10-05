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
 *   AORM-7.10 — process in configurable batch size (default 50), re-schedule for next batch
 *   Touchpoints — on the final batch, write the "Roster in Progress" MDP
 *                 touchpoint (RosterTouchpointService::logProcessingReady())
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

    /**
     * WordPress option key for plugin settings.
     *
     * The 'sync_batch_size' key within this option controls how many
     * staged records are processed per job invocation (AORM-7.10, AORM-11.12).
     * Shared with SyncJobRunner — one batch size setting covers all background jobs.
     */
    private const SETTINGS_OPTION = 'wicket_aorm_settings';

    /**
     * Default number of staged records processed per job invocation.
     *
     * Overridden by wicket_aorm_settings[sync_batch_size] (AORM-11.12).
     */
    public const DEFAULT_BATCH_SIZE = 50;

    public function __construct(
        private readonly ?SchedulerService $schedulerService = null,
        private readonly ?StagedRecordsTable $stagedRecordsTable = null,
        private readonly ?MdpClient $mdpClient = null,
        private readonly ?ScoringService $scoringService = null,
        private readonly ?ActivityLogger $activityLogger = null,
        private readonly ?RosterTouchpointService $rosterTouchpointService = null,
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
     * Batch scheduling / re-dispatch (AORM-7.10, AORM-11.12): reads sync_batch_size from
     * wicket_aorm_settings (default 50). After processing the batch, if the batch
     * was full the job re-dispatches itself so the next batch is processed in a
     * subsequent Action Scheduler / WP-Cron invocation.  Each processed record
     * has its sync_status advanced to 'ready_to_sync' so it is not re-fetched
     * by subsequent batches.
     *
     * @param string $uploadSessionId UUID of the upload session to process.
     */
    public function handle(string $uploadSessionId): void
    {
        if ($uploadSessionId === '') {
            return;
        }

        $table     = $this->stagedRecordsTable ?? new StagedRecordsTable();
        $client    = $this->mdpClient ?? new MdpClient();
        $scorer    = $this->scoringService ?? ScoringService::fromWordPressFilter();
        $scheduler = $this->schedulerService ?? new SchedulerService();
        $logger    = $this->activityLogger ?? new ActivityLogger();

        // AORM-7.10 / AORM-11.12: Read configurable batch size (default 50).
        // Uses the shared sync_batch_size setting (registered in SettingsPage Section 7).
        $settings  = (array) get_option(self::SETTINGS_OPTION, []);
        $batchSize = isset($settings['sync_batch_size']) && (int) $settings['sync_batch_size'] > 0
            ? (int) $settings['sync_batch_size']
            : self::DEFAULT_BATCH_SIZE;

        $records = $table->getPendingMatchingRecords($uploadSessionId, $batchSize);

        foreach ($records as $record) {
            $fields = $this->extractFields($record);

            // Search MDP via a single OR query (email, phone, first name, last name).
            $candidates = $client->searchPersons($fields);

            // Score every candidate and sort descending (AORM-7.5).
            $scored = [];

            foreach ($candidates as $candidate) {
                $scored[] = array_merge($candidate, ['_score' => $scorer->scoreCandidate($candidate, $fields)]);
            }

            usort($scored, static fn (array $a, array $b): int => $b['_score'] <=> $a['_score']);

            $bestScore = isset($scored[0]) ? (int) $scored[0]['_score'] : 0;

            // AORM-7.8: Check if the top candidate is already on the roster and
            // whether they have any relationship with the target org.
            //
            // alreadyOnRoster — true when the person is on *this specific* membership
            // roster; drives the already_on_roster status / ready_to_sync override.
            //
            // hasOrgOverlap — true when the candidate's org_uuids list (populated
            // inline by MdpClient::searchPersons() from relationships.organizations)
            // contains the target org UUID, OR when already on this roster.
            // No extra API call is required.
            //
            // Only perform API calls when at least one candidate scored > 0;
            // a zero score means no candidates matched, so there is nothing to look up.
            $alreadyOnRoster = false;
            $hasOrgOverlap   = false;

            if ($bestScore > 0 && isset($scored[0]['uuid']) && (string) $scored[0]['uuid'] !== '') {
                $personUuid = (string) $scored[0]['uuid'];

                $alreadyOnRoster = $client->isPersonOnRoster(
                    $personUuid,
                    (string) ($record['membership_uuid'] ?? ''),
                );

                // org_uuids comes free from the searchPersons() response —
                // no extra round-trip needed to check the org relationship.
                $hasOrgOverlap = $alreadyOnRoster
                    || in_array(
                        (string) ($record['org_uuid'] ?? ''),
                        (array) ($scored[0]['org_uuids'] ?? []),
                        true,
                    );
            }

            // Apply org_overlap signal when the person has any org relationship.
            if ($hasOrgOverlap) {
                $bestScore = min(
                    $bestScore + $scorer->getWeight(ScoringService::WEIGHT_ORG_OVERLAP),
                    ScoringService::SCORE_CAP,
                );
            }

            // Categorise from the highest score (AORM-7.6, AORM-7.8).
            // score=100 auto-routes to ready_to_sync / exact_match.
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

            // AORM-7.10: advance sync_status to 'ready_to_sync' so this record
            // is not re-fetched by subsequent batch invocations.
            $table->updateRecord((int) $record['id'], [
                'category'        => $category,
                'record_status'   => $recordStatus,
                'match_count'     => $matchCount,
                'matched_persons' => $matchedPersons,
                'match_details'   => $matchDetails,
                'sync_status'     => 'ready_to_sync',
                'updated_at'      => current_time('mysql'),
            ]);
        }

        // AORM-7.9: After scoring all rows in the batch, inject synthetic
        // "Remove Existing Record" rows for roster members absent from the
        // upload (replace mode only).  The method is idempotent so it is safe
        // to call on every handle() invocation — if removal rows already exist
        // (e.g. on a job retry) it exits immediately.
        $this->maybeInsertReplaceModeRemovals($uploadSessionId, $table, $client);

        // AORM-7.10: If we processed a full batch, there may be more records
        // waiting.  Re-dispatch the job so the next batch is handled in a
        // separate Action Scheduler / WP-Cron invocation.
        if (count($records) >= $batchSize) {
            $scheduler->dispatch(
                self::HOOK,
                ['upload_session_id' => $uploadSessionId],
            );

            return;
        }

        // AORM-7.14: The batch was partial (or empty), so all records have now
        // been processed.  Log a matching_complete summary entry so admins can
        // see the final category breakdown in the audit log without querying the
        // staged_records table directly.
        $context    = $table->getSessionContext($uploadSessionId);
        $orgUuid    = (string) ($context['org_uuid'] ?? '');
        $uploadedBy = (int) ($context['uploaded_by'] ?? 0);
        $summary    = $table->getSummaryByCategory($uploadSessionId);

        $logger->logMatchingComplete($uploadSessionId, $orgUuid, $uploadedBy, $summary);

        // Roster activity touchpoint: staging + matching complete, roster is
        // ready for admin review. Never throws.
        $this->writeProcessingReadyTouchpoint($uploadSessionId, $context, $summary, $client);
    }

    /**
     * Write the "Roster in Progress" touchpoint from the matching summary.
     *
     * Counts (summary only covers validation_status='valid' rows):
     *   - removal rows  = record_status 'remove_existing' (replace-mode synthetic rows,
     *                     not part of the uploaded file)
     *   - valid rows    = total − removal rows
     *   - ready rows    = ready_to_sync − removal rows
     *   - review rows   = possible_match + probable_match + manual_update
     *   - rejected rows = 0 — matching only runs when the file had no
     *                     invalid/duplicate rows (see UploadController)
     *
     * @param array<string, mixed>|null $context Session context.
     * @param array<string, mixed>      $summary getSummaryByCategory() result.
     */
    private function writeProcessingReadyTouchpoint(
        string $uploadSessionId,
        ?array $context,
        array $summary,
        MdpClient $client,
    ): void {
        $membershipUuid = (string) ($context['membership_uuid'] ?? '');

        if ($membershipUuid === '') {
            return;
        }

        $byCategory = (array) ($summary['by_category'] ?? []);
        $byStatus   = (array) ($summary['by_status'] ?? []);
        $removal    = (int) ($byStatus['remove_existing'] ?? 0);
        $review     = (int) ($byCategory['possible_match'] ?? 0)
            + (int) ($byCategory['probable_match'] ?? 0)
            + (int) ($byCategory['manual_update'] ?? 0);

        $touchpoints = $this->rosterTouchpointService ?? new RosterTouchpointService($client);
        $touchpoints->logProcessingReady(
            $uploadSessionId,
            (string) ($context['org_uuid'] ?? ''),
            $membershipUuid,
            (int) ($context['uploaded_by'] ?? 0),
            max(0, (int) ($summary['total'] ?? 0) - $removal),
            max(0, (int) ($byCategory['ready_to_sync'] ?? 0) - $removal),
            $review,
            0,
            $removal,
        );
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
     * @return array{first_name: string, last_name: string, email: string, phone: string, title: string}
     */
    private function extractFields(array $record): array
    {
        $raw = json_decode((string) ($record['raw_data'] ?? ''), true);
        $raw = is_array($raw) ? $raw : [];

        return [
            'first_name'   => (string) ($raw['first_name'] ?? ''),
            'last_name'    => (string) ($raw['last_name'] ?? ''),
            'email'        => (string) ($raw['email'] ?? ''),
            'phone' => (string) ($raw['phone'] ?? ''),
            'title'        => (string) ($raw['title'] ?? ''),
        ];
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
     * first_name/last_name are populated from the roster member's MDP
     * given_name/family_name attributes (surfaced via
     * MdpClient::getAllRosterMembers() → normalizeRosterMembers()) so the
     * synthetic removal row renders a name in the "Records being removed"
     * UI table, consistent with rows sourced from the uploaded file.
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
                    'first_name'  => (string) ($member['given_name'] ?? ''),
                    'last_name'   => (string) ($member['family_name'] ?? ''),
                    'email'       => (string) ($member['email'] ?? ''),
                ]),
                'validation_status'  => 'valid',
                'validation_message' => null,
                'category'           => 'ready_to_sync',
                'record_status'      => 'remove_existing',
                'match_count'        => 0,
                'matched_persons'    => null,
                'match_details'      => null,
                // Must match the 'ready_to_sync' value set for regular records
                // once matching completes (see updateRecord() above) — otherwise
                // getPendingSyncRecords()/getSyncProgress() never pick these rows
                // up and the sync job + UI progress poll stall indefinitely.
                'sync_status'        => 'ready_to_sync',
                'uploaded_by'        => $uploadedBy,
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);
        }
    }
}
