<?php

declare(strict_types=1);

namespace WicketAORM\Database;

/**
 * CRUD operations for the wp_wicket_aorm_staged_records table.
 */
class StagedRecordsTable
{
    /**
     * Sync statuses that indicate an in-progress (active) session.
     *
     * A session is "active" when it has at least one staged record that has not
     * yet been synced or permanently failed — i.e. work is still pending.
     *
     * @var list<string>
     */
    private const ACTIVE_SYNC_STATUSES = ['pending', 'ready_to_sync'];

    /**
     * Check whether an active staged-records session already exists for the
     * given org + membership combination.
     *
     * "Active" means at least one row exists in wp_wicket_aorm_staged_records
     * with a sync_status of 'pending' or 'ready_to_sync'.  Such a session
     * blocks both new individual adds (AORM-5) and new bulk uploads (AORM-6)
     * from starting until the existing session is resolved.
     *
     * @param string $orgUuid        The organisation UUID.
     * @param string $membershipUuid The membership UUID.
     * @return bool True if an active session is found; false otherwise.
     */
    public function hasActiveSession(string $orgUuid, string $membershipUuid): bool
    {
        global $wpdb;

        $table       = $wpdb->prefix . 'wicket_aorm_staged_records';
        $statusList  = implode(
            ', ',
            array_map(static fn (string $s): string => "'{$s}'", self::ACTIVE_SYNC_STATUSES),
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT COUNT(*) FROM {$table}
             WHERE org_uuid = %s
               AND membership_uuid = %s
               AND sync_status IN ({$statusList})",
            $orgUuid,
            $membershipUuid,
        );

        return (int) $wpdb->get_var($sql) > 0;
    }
}
