<?php

declare(strict_types=1);

namespace WicketAORM\Services;

/**
 * Writes audit log entries to wp_wicket_aorm_logs and keeps
 * wp_wicket_aorm_roster_meta current after roster assignment actions.
 *
 * Called by RosterController after each successful (or partially successful)
 * assignment operation so there is always a record of who changed the roster,
 * when, and what the outcome was.
 *
 * @see AORM-4.15
 */
class ActivityLogger
{
    /**
     * @param object|null $wpdb  Injected wpdb instance; defaults to the global $wpdb.
     */
    public function __construct(private readonly ?object $wpdb = null)
    {
    }

    /**
     * Log a roster assignment action and update the matching roster_meta row.
     *
     * Writes one row to wp_wicket_aorm_logs recording the actor, action, and
     * outcome context. Then issues an INSERT … ON DUPLICATE KEY UPDATE against
     * wp_wicket_aorm_roster_meta so the list view always has the latest
     * roster_status, actor, and timestamp without scanning the full log table.
     *
     * @param string               $orgUuid        Organisation UUID.
     * @param string               $membershipUuid Membership UUID (natural key for roster_meta).
     * @param string               $action         Short action slug, e.g. 'members_removed', 'roles_added'.
     * @param string               $message        Human-readable summary written to the log message column.
     * @param array<string, mixed> $context        Arbitrary payload stored as JSON in the context column.
     * @param string               $rosterStatus   ENUM value to write to roster_status ('idle', 'has_failures', …).
     * 
     * @return void
     */
    public function logRosterAction(
        string $orgUuid,
        string $membershipUuid,
        string $action,
        string $message,
        array $context = [],
        string $rosterStatus = 'idle',
    ): void {
        $db     = $this->wpdb ?? $GLOBALS['wpdb'];
        $userId = get_current_user_id();
        $now    = current_time('mysql', true);
        $actor  = $this->resolveActorName($userId);

        // ── 1. Append to audit log ────────────────────────────────────────
        $logsTable = $db->prefix . 'wicket_aorm_logs';

        $db->insert(
            $logsTable,
            [
                'org_uuid'    => $orgUuid,
                'user_id'     => $userId,
                'level'       => 'info',
                'action'      => $action,
                'object_type' => 'roster',
                'object_id'   => $membershipUuid,
                'message'     => $message,
                'context'     => json_encode($context),
                'created_at'  => $now,
            ],
            [
                '%s', // org_uuid
                '%d', // user_id
                '%s', // level
                '%s', // action
                '%s', // object_type
                '%s', // object_id
                '%s', // message
                '%s', // context
                '%s', // created_at
            ],
        );

        // ── 2. Upsert roster_meta ─────────────────────────────────────────
        // INSERT … ON DUPLICATE KEY UPDATE is the most efficient way to keep
        // a single row per membership_uuid current without a SELECT first.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $metaTable = $db->prefix . 'wicket_aorm_roster_meta';

        $db->query(
            $db->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "INSERT INTO {$metaTable}
                   (org_uuid, membership_uuid, roster_status, last_updated_at, last_updated_by)
                 VALUES (%s, %s, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE
                   roster_status   = VALUES(roster_status),
                   last_updated_at = VALUES(last_updated_at),
                   last_updated_by = VALUES(last_updated_by)",
                $orgUuid,
                $membershipUuid,
                $rosterStatus,
                $now,
                $actor,
            ),
        );
    }

    /**
     * Log a matching-complete event to wp_wicket_aorm_logs.
     *
     * Written by MatchingJobRunner when the final batch of an upload session has
     * been processed (i.e. the batch was partial). The entry uses object_type =
     * 'session' so it is associated with the upload session rather than a
     * specific roster or staged record. No roster_meta update is performed — this
     * is an informational audit trail entry only.
     *
     * The human-readable $message is built from the summary counts so an admin
     * reading the log table can understand the outcome at a glance. The full
     * summary array is stored as JSON in the context column for downstream
     * consumption (e.g. reporting queries).
     *
     * @param string               $uploadSessionId UUID of the completed upload session.
     * @param string               $orgUuid         Organisation UUID from the session context.
     * @param int                  $uploadedBy      WordPress user ID that initiated the upload (0 = system).
     * @param array{
     *   total: int,
     *   by_category: array<string, int>,
     *   by_status: array<string, int>
     * }                           $summary         Counts returned by StagedRecordsTable::getSummaryByCategory().
     *
     * @see AORM-7.14
     */
    public function logMatchingComplete(
        string $uploadSessionId,
        string $orgUuid,
        int $uploadedBy,
        array $summary,
    ): void {
        $db  = $this->wpdb ?? $GLOBALS['wpdb'];
        $now = current_time('mysql', true);

        $total    = (int) ($summary['total'] ?? 0);
        $byCat    = (array) ($summary['by_category'] ?? []);
        $byStatus = (array) ($summary['by_status'] ?? []);

        $ready    = (int) ($byCat['ready_to_sync'] ?? 0);
        $possible = (int) ($byCat['possible_match'] ?? 0);
        $probable = (int) ($byCat['probable_match'] ?? 0);
        $manual   = (int) ($byCat['manual_update'] ?? 0);
        $discard  = (int) ($byCat['discard'] ?? 0);
        $remove   = (int) ($byStatus['remove_existing'] ?? 0);

        $message = sprintf(
            'Matching complete: %d record%s processed — %d ready to sync, %d possible match%s, %d probable match%s, %d manual update%s, %d discard%s, %d to remove.',
            $total,
            $total === 1 ? '' : 's',
            $ready,
            $possible,
            $possible === 1 ? '' : 'es',
            $probable,
            $probable === 1 ? '' : 'es',
            $manual,
            $manual === 1 ? '' : 's',
            $discard,
            $discard === 1 ? '' : 's',
            $remove,
        );

        $logsTable = $db->prefix . 'wicket_aorm_logs';

        // staged_record_id is intentionally omitted — it defaults to NULL in the
        // schema and there is no single staged_record associated with a session-level log.
        $db->insert(
            $logsTable,
            [
                'upload_session_id' => $uploadSessionId,
                'org_uuid'          => $orgUuid,
                'user_id'           => $uploadedBy,
                'level'             => 'info',
                'action'            => 'matching_complete',
                'object_type'       => 'session',
                'object_id'         => $uploadSessionId,
                'message'           => $message,
                'context'           => json_encode($summary),
                'created_at'        => $now,
            ],
            [
                '%s', // upload_session_id
                '%s', // org_uuid
                '%d', // user_id
                '%s', // level
                '%s', // action
                '%s', // object_type
                '%s', // object_id
                '%s', // message
                '%s', // context
                '%s', // created_at
            ],
        );
    }

    /**
     * Mark a roster as actively syncing in wp_wicket_aorm_roster_meta.
     *
     * Called by SyncJobRunner at the start of each batch invocation so the
     * roster list view shows "syncing" while the background job is running.
     * Idempotent: safe to call on every batch of a multi-batch sync (the
     * ON DUPLICATE KEY UPDATE is a no-op when the row is already 'syncing').
     * Does NOT write a log entry — per-record and sync_complete entries
     * provide the full audit trail.
     *
     * @param string $orgUuid        Organisation UUID.
     * @param string $membershipUuid Membership UUID.
     * @param int    $userId         WordPress user ID that triggered the sync (0 = system).
     *
     * @see AORM-9.30
     */
    public function markRosterSyncing(
        string $orgUuid,
        string $membershipUuid,
        int $userId,
    ): void {
        $db        = $this->wpdb ?? $GLOBALS['wpdb'];
        $now       = current_time('mysql', true);
        $actor     = $this->resolveActorName($userId);
        $metaTable = $db->prefix . 'wicket_aorm_roster_meta';

        $db->query(
            $db->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "INSERT INTO {$metaTable}
                   (org_uuid, membership_uuid, roster_status, last_updated_at, last_updated_by)
                 VALUES (%s, %s, 'syncing', %s, %s)
                 ON DUPLICATE KEY UPDATE
                   roster_status   = VALUES(roster_status),
                   last_updated_at = VALUES(last_updated_at),
                   last_updated_by = VALUES(last_updated_by)",
                $orgUuid,
                $membershipUuid,
                $now,
                $actor,
            ),
        );
    }

    /**
     * Log a single staged record's sync outcome to wp_wicket_aorm_logs.
     *
     * Called by SyncJobRunner inside the record loop for both successes and
     * failures to provide a full per-record audit trail in the logs table.
     *
     * @param string $uploadSessionId Upload session UUID.
     * @param string $orgUuid         Organisation UUID.
     * @param int    $stagedRecordId  Staged record DB ID.
     * @param string $outcome         'synced' or 'failed'.
     * @param string $errorDetails    Non-empty only when outcome is 'failed'.
     * @param int    $userId          WordPress user ID that triggered the sync (0 = system).
     *
     * @see AORM-9.30
     */
    public function logSyncRecord(
        string $uploadSessionId,
        string $orgUuid,
        int $stagedRecordId,
        string $outcome,
        string $errorDetails,
        int $userId,
    ): void {
        $db  = $this->wpdb ?? $GLOBALS['wpdb'];
        $now = current_time('mysql', true);

        $isSynced = $outcome === 'synced';
        $action   = $isSynced ? 'record_synced' : 'record_failed';
        $level    = $isSynced ? 'info' : 'error';
        $message  = $isSynced
            ? sprintf('Record %d synced successfully.', $stagedRecordId)
            : sprintf('Record %d failed to sync: %s', $stagedRecordId, $errorDetails);

        $context = ['staged_record_id' => $stagedRecordId];

        if ($errorDetails !== '') {
            $context['error_details'] = $errorDetails;
        }

        $logsTable = $db->prefix . 'wicket_aorm_logs';

        $db->insert(
            $logsTable,
            [
                'upload_session_id' => $uploadSessionId,
                'org_uuid'          => $orgUuid,
                'staged_record_id'  => $stagedRecordId,
                'user_id'           => $userId,
                'level'             => $level,
                'action'            => $action,
                'object_type'       => 'staged_record',
                'object_id'         => (string) $stagedRecordId,
                'message'           => $message,
                'context'           => json_encode($context),
                'created_at'        => $now,
            ],
            [
                '%s', // upload_session_id
                '%s', // org_uuid
                '%d', // staged_record_id
                '%d', // user_id
                '%s', // level
                '%s', // action
                '%s', // object_type
                '%s', // object_id
                '%s', // message
                '%s', // context
                '%s', // created_at
            ],
        );
    }

    /**
     * Log a sync-complete event to wp_wicket_aorm_logs and update roster_meta.
     *
     * Written by SyncJobRunner when the final batch of a sync session has been
     * processed (i.e. the batch was partial or empty). Sets roster_meta to
     * 'synced' when all records succeeded, or 'has_failures' when any failed.
     * last_synced_at is only recorded on a clean (zero-failure) run.
     *
     * @param string $uploadSessionId UUID of the completed upload session.
     * @param string $orgUuid         Organisation UUID from the session context.
     * @param string $membershipUuid  Membership UUID from the session context.
     * @param int    $uploadedBy      WordPress user ID that initiated the upload (0 = system).
     * @param int    $synced          Total records successfully synced across all batches.
     * @param int    $failed          Total records that failed to sync across all batches.
     *
     * @see AORM-9.3
     * @see AORM-9.30
     */
    public function logSyncComplete(
        string $uploadSessionId,
        string $orgUuid,
        string $membershipUuid,
        int $uploadedBy,
        int $synced = 0,
        int $failed = 0,
    ): void {
        $db           = $this->wpdb ?? $GLOBALS['wpdb'];
        $now          = current_time('mysql', true);
        $rosterStatus = $failed > 0 ? 'has_failures' : 'synced';

        $message = sprintf(
            'Sync complete: %d synced, %d failed.',
            $synced,
            $failed,
        );

        $logsTable = $db->prefix . 'wicket_aorm_logs';

        $db->insert(
            $logsTable,
            [
                'upload_session_id' => $uploadSessionId,
                'org_uuid'          => $orgUuid,
                'user_id'           => $uploadedBy,
                'level'             => 'info',
                'action'            => 'sync_complete',
                'object_type'       => 'session',
                'object_id'         => $uploadSessionId,
                'message'           => $message,
                'context'           => json_encode([
                    'upload_session_id' => $uploadSessionId,
                    'membership_uuid'   => $membershipUuid,
                    'synced'            => $synced,
                    'failed'            => $failed,
                ]),
                'created_at'        => $now,
            ],
            [
                '%s', // upload_session_id
                '%s', // org_uuid
                '%d', // user_id
                '%s', // level
                '%s', // action
                '%s', // object_type
                '%s', // object_id
                '%s', // message
                '%s', // context
                '%s', // created_at
            ],
        );

        // Upsert roster_meta. Only update last_synced_at on a clean run.
        $metaTable  = $db->prefix . 'wicket_aorm_roster_meta';
        $actor      = $this->resolveActorName($uploadedBy);
        $syncedAt   = $failed === 0 ? $now : null;
        $syncedAtSql = $syncedAt !== null
            ? $db->prepare('%s', $syncedAt)
            : 'NULL';

        $db->query(
            $db->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "INSERT INTO {$metaTable}
                   (org_uuid, membership_uuid, roster_status, last_updated_at, last_updated_by, last_synced_at)
                 VALUES (%s, %s, %s, %s, %s, {$syncedAtSql})
                 ON DUPLICATE KEY UPDATE
                   roster_status   = VALUES(roster_status),
                   last_updated_at = VALUES(last_updated_at),
                   last_updated_by = VALUES(last_updated_by),
                   last_synced_at  = VALUES(last_synced_at)",
                $orgUuid,
                $membershipUuid,
                $rosterStatus,
                $now,
                $actor,
            ),
        );
    }

    /**
     * Resolve a display name for the acting WordPress user.
     *
     * Preference order: user_email → "user:{id}" → "system" (unauthenticated).
     *
     * @param int $userId WordPress user ID (0 means no logged-in user).
     */
    private function resolveActorName(int $userId): string
    {
        if ($userId <= 0) {
            return 'system';
        }

        $user = get_userdata($userId);

        if ($user !== false && ! empty($user->user_email)) {
            return (string) $user->user_email;
        }

        return "user:{$userId}";
    }
}
