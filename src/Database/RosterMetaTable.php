<?php

declare(strict_types=1);

namespace WicketAORM\Database;

/**
 * Read/write operations for the wp_wicket_aorm_roster_meta table.
 *
 * Provides a single indexed lookup per row so the org roster list view can
 * enrich MDP data with locally-stored roster state without scanning
 * staged_records or logs for each row.
 *
 * Also exposes upsertRosterStatus() so any workflow that changes the roster's
 * lifecycle state (individual add, bulk upload, sync) can keep the meta row
 * current in a single round-trip.
 *
 * @see AORM-3.3
 * @see AORM-5.9
 */
class RosterMetaTable
{
    private object $wpdb;

    /**
     * @param object|null $wpdb  Injected wpdb instance; defaults to the global $wpdb.
     */
    public function __construct(?object $wpdb = null)
    {
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
    }

    /**
     * Fetch roster meta rows for a set of org membership UUIDs.
     *
     * Issues a single IN query against the unique `membership_uuid` index so
     * callers pay one round-trip regardless of how many UUIDs are passed.
     * Returns an associative array keyed by membership_uuid for O(1) lookup
     * when merging with a page of MDP results.
     *
     * Each row contains the raw DB values:
     *   - roster_status    (ENUM string or null)
     *   - last_updated_at  (DATETIME string)
     *   - last_updated_by  (email / username string)
     *   - last_synced_at   (DATETIME string or null)
     *
     * @param  list<string>               $uuids  Membership UUID strings to look up.
     * @return array<string, array<string, mixed>>  Rows indexed by membership_uuid.
     */
    public function getByMembershipUuids(array $uuids): array
    {
        if (empty($uuids)) {
            return [];
        }

        $table        = $this->wpdb->prefix . 'wicket_aorm_roster_meta';
        $placeholders = implode(',', array_fill(0, count($uuids), '%s'));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql     = "SELECT * FROM {$table} WHERE membership_uuid IN ({$placeholders})";
        $prepared = $this->wpdb->prepare($sql, ...$uuids);
        $rows    = (array) $this->wpdb->get_results($prepared, ARRAY_A);

        $indexed = [];

        foreach ($rows as $row) {
            $row  = (array) $row;
            $uuid = (string) ($row['membership_uuid'] ?? '');

            if ($uuid !== '') {
                $indexed[$uuid] = $row;
            }
        }

        return $indexed;
    }

    /**
     * Create or update the roster meta row for a given org + membership.
     *
     * Issues an INSERT … ON DUPLICATE KEY UPDATE so callers do not need to
     * check whether a row already exists.  The unique key on `membership_uuid`
     * guarantees at most one meta row per roster.
     *
     * Called by IndividualController (AORM-5.9) immediately after a staged
     * record is inserted so the roster list view reflects the new status
     * without waiting for a separate logging call.
     *
     * @param string $orgUuid        Organisation UUID.
     * @param string $membershipUuid Membership UUID (natural key for the table).
     * @param string $status         ENUM value to write to roster_status
     *                               ('idle', 'in_progress', 'syncing', 'has_failures', 'synced').
     * @param string $actor          Display name of the acting user (email, "user:{id}", or "system").
     * @param string $timestamp      MySQL DATETIME string for last_updated_at.
     */
    public function upsertRosterStatus(
        string $orgUuid,
        string $membershipUuid,
        string $status,
        string $actor,
        string $timestamp,
    ): void {
        $table = $this->wpdb->prefix . 'wicket_aorm_roster_meta';

        $this->wpdb->query(
            $this->wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "INSERT INTO {$table}
                   (org_uuid, membership_uuid, roster_status, last_updated_at, last_updated_by)
                 VALUES (%s, %s, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE
                   roster_status   = VALUES(roster_status),
                   last_updated_at = VALUES(last_updated_at),
                   last_updated_by = VALUES(last_updated_by)",
                $orgUuid,
                $membershipUuid,
                $status,
                $timestamp,
                $actor,
            ),
        );
    }
}
