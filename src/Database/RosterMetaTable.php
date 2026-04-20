<?php

declare(strict_types=1);

namespace WicketAORM\Database;

/**
 * Read operations for the wp_wicket_aorm_roster_meta table.
 *
 * Provides a single indexed lookup per row so the org roster list view can
 * enrich MDP data with locally-stored roster state without scanning
 * staged_records or logs for each row.
 *
 * @see AORM-3.3
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
}
