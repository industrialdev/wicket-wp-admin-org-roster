<?php

declare(strict_types=1);

namespace WicketAORM\Database;

/**
 * Read operations for the wp_wicket_aorm_logs table.
 *
 * Provides paginated, filterable queries for the roster activity log endpoint.
 * Data is returned in a normalised shape suitable for direct JSON serialisation
 * by the REST layer.
 *
 * @see AORM-4.18
 */
class ActivityLogTable
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
     * Fetch paginated, filtered activity log entries for a specific roster.
     *
     * Only rows where object_type = 'roster', org_uuid matches, and
     * object_id = $membershipUuid are returned. Results are ordered by
     * created_at descending (newest first).
     *
     * Supported $args keys:
     *   - page       (int)    Page number, 1-based. Default: 1.
     *   - per_page   (int)    Rows per page. Default: 20.
     *   - action     (string) Exact match on the `action` column.
     *   - level      (string) Exact match on the `level` ENUM column.
     *   - date_from  (string) YYYY-MM-DD lower bound on created_at (00:00:00).
     *   - date_to    (string) YYYY-MM-DD upper bound on created_at (23:59:59).
     *
     * @param  string              $orgUuid        Organisation UUID.
     * @param  string              $membershipUuid Membership UUID (stored in the `object_id` column).
     * @param  array<string,mixed> $args           Pagination and filter arguments.
     * @return array{entries: list<array<string,mixed>>, total: int, total_pages: int, page: int, per_page: int}
     */
    public function getEntries(string $orgUuid, string $membershipUuid, array $args = []): array
    {
        $page    = max(1, (int) ($args['page'] ?? 1));
        $perPage = max(1, (int) ($args['per_page'] ?? 20));
        $offset  = ($page - 1) * $perPage;

        $table = $this->wpdb->prefix . 'wicket_aorm_logs';

        // ── Build WHERE conditions ─────────────────────────────────────────
        // Each condition is already prepared (safe for interpolation below).
        $conditions = [
            $this->wpdb->prepare('org_uuid = %s', $orgUuid),
            $this->wpdb->prepare('object_id = %s', $membershipUuid),
            "object_type = 'roster'",
        ];

        if (! empty($args['action'])) {
            $conditions[] = $this->wpdb->prepare('action = %s', (string) $args['action']);
        }

        if (! empty($args['level'])) {
            $conditions[] = $this->wpdb->prepare('level = %s', (string) $args['level']);
        }

        if (! empty($args['date_from'])) {
            $conditions[] = $this->wpdb->prepare('created_at >= %s', (string) $args['date_from'] . ' 00:00:00');
        }

        if (! empty($args['date_to'])) {
            $conditions[] = $this->wpdb->prepare('created_at <= %s', (string) $args['date_to'] . ' 23:59:59');
        }

        $where = 'WHERE ' . implode(' AND ', $conditions);

        // ── Count total matching rows ──────────────────────────────────────
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $total = (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$table} {$where}");

        // ── Fetch one page of rows ─────────────────────────────────────────
        $limitClause = $this->wpdb->prepare('LIMIT %d OFFSET %d', $perPage, $offset);

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = "SELECT id, user_id, level, action, message, context, created_at
                FROM {$table}
                {$where}
                ORDER BY created_at DESC
                {$limitClause}";

        $rows    = (array) $this->wpdb->get_results($sql, ARRAY_A);
        $entries = array_map(
            fn (array|object $row): array => $this->normalizeEntry((array) $row),
            $rows,
        );

        return [
            'entries'     => $entries,
            'total'       => $total,
            'total_pages' => (int) ceil($total / $perPage),
            'page'        => $page,
            'per_page'    => $perPage,
        ];
    }

    /**
     * Normalise a raw DB row into the API entry shape.
     *
     * @param  array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeEntry(array $row): array
    {
        $userId  = (int) ($row['user_id'] ?? 0);
        $context = [];

        if (! empty($row['context'])) {
            $decoded = json_decode((string) $row['context'], true);
            $context = is_array($decoded) ? $decoded : [];
        }

        return [
            'id'         => (int) ($row['id'] ?? 0),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'actor'      => $this->resolveActorName($userId),
            'action'     => (string) ($row['action'] ?? ''),
            'message'    => (string) ($row['message'] ?? ''),
            'level'      => (string) ($row['level'] ?? 'info'),
            'context'    => $context,
        ];
    }

    /**
     * Resolve a display identifier for the acting WordPress user.
     *
     * Preference order: user_email → "user:{id}" → "system" (unauthenticated / cron).
     *
     * @param int $userId WordPress user ID (0 = no logged-in user).
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
