<?php

declare(strict_types=1);

namespace WicketAORM\Services;

use WicketAORM\Database\StagedRecordsTable;

/**
 * SyncJobRunner — background job handler for MDP sync.
 *
 * Invoked by Action Scheduler (or WP-Cron via SchedulerService) with the
 * upload_session_id and the set of record IDs to sync.  Processes records in
 * configurable batches and re-dispatches itself when a full batch is returned,
 * guaranteeing that large uploads are handled in successive lightweight
 * invocations rather than a single long-running PHP process.
 *
 * Dispatching this job is done via SchedulerService::dispatch():
 *
 *   $scheduler->dispatch(
 *       SyncJobRunner::HOOK,
 *       [
 *           'upload_session_id' => $sessionId,
 *           'ids'               => $ids,   // 'all' or int[]
 *       ],
 *   );
 *
 * Action Scheduler serialises the $args array and passes each value as an
 * individual positional argument to the hook callback, so:
 *
 *   do_action('wicket_aorm_run_sync', $uploadSessionId, $ids)
 *
 * maps directly to handle(string $uploadSessionId, string|array $ids).
 *
 * Milestone coverage:
 *   AORM-9.3  — hook constant, handle() signature, batching, re-dispatch,
 *               per-record processing stub, completion logging
 *   AORM-9.26 — configurable batch size (wicket_aorm_settings[sync_batch_size],
 *               default 50); per-row sync_status updates ('synced' on success,
 *               'failed' + error_details truncated to 255 chars on exception)
 *   AORM-9.4+ — per-record sync logic (processRecord stub filled in by subsequent tickets)
 */
class SyncJobRunner
{
    /**
     * The WordPress action hook name fired by Action Scheduler / WP-Cron.
     *
     * Referenced in:
     *   - Main::registerHooks()  — add_action() registration (AORM-9.3)
     *   - CommitController       — dispatch on commit (AORM-9.2)
     */
    public const HOOK = 'wicket_aorm_run_sync';

    /**
     * WordPress option key for plugin settings.
     *
     * The 'sync_batch_size' key within this option controls how many staged
     * records are processed per job invocation.
     */
    private const SETTINGS_OPTION = 'wicket_aorm_settings';

    /**
     * Default number of staged records processed per job invocation.
     *
     * Overridden by wicket_aorm_settings[sync_batch_size].
     */
    public const DEFAULT_BATCH_SIZE = 50;

    public function __construct(
        private readonly ?SchedulerService $schedulerService = null,
        private readonly ?StagedRecordsTable $stagedRecordsTable = null,
        private readonly ?SyncService $syncService = null,
        private readonly ?ActivityLogger $activityLogger = null,
    ) {
    }

    // ── Public entry point ────────────────────────────────────────────────

    /**
     * Entry point invoked by Action Scheduler / WP-Cron.
     *
     * Fetches the next batch of ready_to_sync staged records for the given
     * session (optionally restricted to specific IDs), delegates each record
     * to SyncService::syncRecord(), and writes the outcome (synced / failed)
     * back to the database.
     *
     * Batch scheduling / re-dispatch: reads sync_batch_size from
     * wicket_aorm_settings (default 50). After processing the batch, if it
     * was full the job re-dispatches itself so the next batch is processed in
     * a subsequent Action Scheduler / WP-Cron invocation.
     *
     * @param string       $uploadSessionId UUID of the upload session to process.
     * @param string|int[] $ids             'all' to sync every ready_to_sync record
     *                                      in the session, or an array of specific
     *                                      staged record IDs to sync.
     */
    public function handle(string $uploadSessionId, string|array $ids = 'all'): void
    {
        if ($uploadSessionId === '') {
            return;
        }

        $table     = $this->stagedRecordsTable ?? new StagedRecordsTable();
        $sync      = $this->syncService ?? new SyncService();
        $scheduler = $this->schedulerService ?? new SchedulerService();
        $logger    = $this->activityLogger ?? new ActivityLogger();

        // Read configurable batch size (default 50).
        $settings  = (array) get_option(self::SETTINGS_OPTION, []);
        $batchSize = isset($settings['sync_batch_size']) && (int) $settings['sync_batch_size'] > 0
            ? (int) $settings['sync_batch_size']
            : self::DEFAULT_BATCH_SIZE;

        // Resolve session context once — used for logging and meta updates
        // throughout this invocation (both the syncing marker and completion log).
        $context        = $table->getSessionContext($uploadSessionId);
        $orgUuid        = (string) ($context['org_uuid'] ?? '');
        $membershipUuid = (string) ($context['membership_uuid'] ?? '');
        $uploadedBy     = (int) ($context['uploaded_by'] ?? 0);

        // Mark roster as 'syncing' before any records are processed.
        // Idempotent on re-dispatch — safe to call on every batch.
        if ($orgUuid !== '' && $membershipUuid !== '') {
            $logger->markRosterSyncing($orgUuid, $membershipUuid, $uploadedBy);
        }

        $records = $table->getPendingSyncRecords($uploadSessionId, $ids, $batchSize);

        foreach ($records as $record) {
            $recordId = (int) $record['id'];
            $now      = current_time('mysql');

            try {
                $sync->syncRecord($record);

                $table->updateRecord($recordId, [
                    'sync_status' => 'synced',
                    'updated_at'  => $now,
                ]);

                $logger->logSyncRecord($uploadSessionId, $orgUuid, $recordId, 'synced', '', $uploadedBy);
            } catch (\Throwable $e) {
                $errorDetails = substr($e->getMessage(), 0, 255);

                $table->updateRecord($recordId, [
                    'sync_status'   => 'failed',
                    'error_details' => $errorDetails,
                    'updated_at'    => $now,
                ]);

                $logger->logSyncRecord($uploadSessionId, $orgUuid, $recordId, 'failed', $errorDetails, $uploadedBy);
            }
        }

        // If we processed a full batch, there may be more records waiting.
        // Re-dispatch so the next batch is handled in a separate invocation.
        if (count($records) >= $batchSize) {
            $scheduler->dispatch(
                self::HOOK,
                [
                    'upload_session_id' => $uploadSessionId,
                    'ids'               => $ids,
                ],
            );

            return;
        }

        // The batch was partial (or empty): all eligible records have been
        // processed. Read the full session progress to get accurate totals
        // across all batches, then write the completion log entry.
        $progress = $table->getSyncProgress($uploadSessionId);

        $logger->logSyncComplete(
            $uploadSessionId,
            $orgUuid,
            $membershipUuid,
            $uploadedBy,
            $progress['synced'],
            $progress['failed'],
        );
    }
}
