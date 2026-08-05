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
     * Per-request memoization of upload_session_id → membership_uuid lookups
     * performed by resolveMembershipUuidForSession(). Several log rows in the
     * same result page commonly share an upload_session_id (e.g. every
     * record_synced/record_failed row from one sync run), so this avoids
     * issuing the same staged_records lookup query repeatedly.
     *
     * @var array<string, string>
     */
    private array $sessionMembershipCache = [];

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
     * Fetch paginated, filtered activity log entries across ALL rosters.
     *
     * Powers the AORM-10 Global Logs admin page. Unlike getEntries(), this is
     * not scoped to a single org/membership — org_uuid and membership_uuid
     * are optional filters instead of mandatory WHERE conditions, and every
     * object_type is included (not just 'roster').
     *
     * wp_wicket_aorm_logs has no membership_uuid column, so it is resolved
     * per row from fields that already exist, without any schema change:
     *   - object_type = 'roster' rows: object_id IS the membership_uuid
     *     (every ActivityLogger::logRosterAction() call writes it that way).
     *   - all other rows carry upload_session_id, which
     *     resolveMembershipUuidForSession() resolves via a lookup against
     *     wp_wicket_aorm_staged_records.membership_uuid (existing column on
     *     an existing table, keyed by an already-indexed upload_session_id).
     *
     * The optional membership_uuid filter uses the same two resolution paths
     * inside the WHERE clause via a subquery against staged_records, so
     * filtering stays consistent with per-row resolution without needing a
     * JOIN (which would fan out rows for sessions with many staged records).
     *
     * Supported $args keys:
     *   - page            (int)    Page number, 1-based. Default: 1.
     *   - per_page        (int)    Rows per page. Default: 20.
     *   - org_uuid        (string) Exact match on the `org_uuid` column.
     *   - membership_uuid (string) Match on the resolved membership UUID (see above).
     *   - action          (string) Exact match on the `action` column.
     *   - level           (string) Exact match on the `level` ENUM column.
     *   - date_from       (string) YYYY-MM-DD lower bound on created_at (00:00:00).
     *   - date_to         (string) YYYY-MM-DD upper bound on created_at (23:59:59).
     *   - orderby         (string) One of 'created_at', 'action', 'level'. Default: 'created_at'.
     *   - order           (string) 'asc' or 'desc'. Default: 'desc'.
     *
     * @param  array<string,mixed> $args Pagination, filter, and sort arguments.
     * @return array{entries: list<array<string,mixed>>, total: int, total_pages: int, page: int, per_page: int}
     *
     * @see AORM-10.1
     * @see AORM-10.2
     */
    public function getGlobalEntries(array $args = []): array
    {
        $page    = max(1, (int) ($args['page'] ?? 1));
        $perPage = max(1, (int) ($args['per_page'] ?? 20));
        $offset  = ($page - 1) * $perPage;

        $logsTable   = $this->wpdb->prefix . 'wicket_aorm_logs';
        $stagedTable = $this->wpdb->prefix . 'wicket_aorm_staged_records';

        // ── Build WHERE conditions (all optional) ──────────────────────────
        $conditions = [];

        if (! empty($args['org_uuid'])) {
            $conditions[] = $this->wpdb->prepare('org_uuid = %s', (string) $args['org_uuid']);
        }

        if (! empty($args['membership_uuid'])) {
            $membershipUuid = (string) $args['membership_uuid'];

            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $conditions[] = $this->wpdb->prepare(
                "((object_type = 'roster' AND object_id = %s) OR upload_session_id IN (
                    SELECT DISTINCT upload_session_id FROM {$stagedTable} WHERE membership_uuid = %s
                ))",
                $membershipUuid,
                $membershipUuid,
            );
        }

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

        $where = empty($conditions) ? '' : 'WHERE ' . implode(' AND ', $conditions);

        // ── Count total matching rows ──────────────────────────────────────
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $total = (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$logsTable} {$where}");

        // ── Fetch one page of rows ─────────────────────────────────────────
        $sortColumn  = $this->mapGlobalSortColumn((string) ($args['orderby'] ?? 'created_at'));
        $sortOrder   = strtolower((string) ($args['order'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
        $limitClause = $this->wpdb->prepare('LIMIT %d OFFSET %d', $perPage, $offset);

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = "SELECT id, upload_session_id, org_uuid, staged_record_id, user_id, level, action,
                       object_type, object_id, message, context, created_at
                FROM {$logsTable}
                {$where}
                ORDER BY {$sortColumn} {$sortOrder}
                {$limitClause}";

        $rows    = (array) $this->wpdb->get_results($sql, ARRAY_A);
        $entries = array_map(
            fn (array|object $row): array => $this->normalizeGlobalEntry((array) $row),
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
     * Return every distinct non-empty org_uuid present in wp_wicket_aorm_logs.
     *
     * Used to populate the Global Logs page's "Organization" filter dropdown
     * (AORM-10.4) with only organizations that actually have log activity,
     * rather than the full MDP org catalog.
     *
     * @return list<string>
     */
    public function getDistinctOrgUuids(): array
    {
        $table = $this->wpdb->prefix . 'wicket_aorm_logs';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = (array) $this->wpdb->get_results(
            "SELECT DISTINCT org_uuid FROM {$table} WHERE org_uuid IS NOT NULL AND org_uuid != '' ORDER BY org_uuid",
            ARRAY_A,
        );

        return $this->pluckNonEmptyColumn($rows, 'org_uuid');
    }

    /**
     * Return every distinct membership_uuid resolvable from wp_wicket_aorm_logs.
     *
     * Combines both resolution paths used by resolveMembershipUuidForEntry():
     * object_id on object_type='roster' rows, and membership_uuid looked up
     * from wp_wicket_aorm_staged_records for every upload_session_id that
     * appears in the logs. Used to populate the "Membership Tier" filter
     * dropdown (AORM-10.4).
     *
     * @return list<string>
     */
    public function getDistinctMembershipUuids(): array
    {
        $logsTable   = $this->wpdb->prefix . 'wicket_aorm_logs';
        $stagedTable = $this->wpdb->prefix . 'wicket_aorm_staged_records';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = "SELECT DISTINCT object_id AS membership_uuid
                FROM {$logsTable}
                WHERE object_type = 'roster' AND object_id IS NOT NULL AND object_id != ''
                UNION
                SELECT DISTINCT sr.membership_uuid
                FROM {$stagedTable} sr
                INNER JOIN (
                    SELECT DISTINCT upload_session_id
                    FROM {$logsTable}
                    WHERE upload_session_id IS NOT NULL AND upload_session_id != ''
                ) l ON l.upload_session_id = sr.upload_session_id";

        $rows = (array) $this->wpdb->get_results($sql, ARRAY_A);

        return $this->pluckNonEmptyColumn($rows, 'membership_uuid');
    }

    /**
     * Return every distinct `action` slug present in wp_wicket_aorm_logs.
     *
     * Used to populate the "Activity Type" filter dropdown (AORM-10.4).
     * Label mapping (slug → human-readable text) is a display concern
     * handled by LogsListTable, not this repository class.
     *
     * @return list<string>
     */
    public function getDistinctActions(): array
    {
        $table = $this->wpdb->prefix . 'wicket_aorm_logs';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = (array) $this->wpdb->get_results(
            "SELECT DISTINCT action FROM {$table} ORDER BY action",
            ARRAY_A,
        );

        return $this->pluckNonEmptyColumn($rows, 'action');
    }

    /**
     * Extract a single column's non-empty string values from a get_results() row set.
     *
     * @param  array<int, array<string,mixed>|object> $rows
     * @return list<string>
     */
    private function pluckNonEmptyColumn(array $rows, string $column): array
    {
        $values = array_map(
            static fn (array|object $row): string => (string) ((array) $row)[$column],
            $rows,
        );

        return array_values(array_filter($values, static fn (string $v): bool => $v !== ''));
    }

    /**
     * Map a getGlobalEntries() `orderby` argument to a safe SQL column name.
     *
     * Restricted to an allow-list of three plain DB columns — org_name and
     * membership_tier are deliberately absent since they are resolved via an
     * MDP lookup after the query runs and cannot be sorted server-side.
     */
    private function mapGlobalSortColumn(string $orderby): string
    {
        return match ($orderby) {
            'action' => 'action',
            'level'  => 'level',
            default  => 'created_at',
        };
    }

    /**
     * Normalise a raw DB row into the Global Logs entry shape.
     *
     * Extends normalizeEntry()'s shape with org_uuid (read directly from the
     * row) and membership_uuid + actor_type (both resolved/derived, since
     * neither is a column on wp_wicket_aorm_logs).
     *
     * @param  array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeGlobalEntry(array $row): array
    {
        $userId = (int) ($row['user_id'] ?? 0);
        $entry  = $this->normalizeEntry($row);

        $entry['org_uuid']        = (string) ($row['org_uuid'] ?? '');
        $entry['membership_uuid'] = $this->resolveMembershipUuidForEntry($row);
        $entry['actor_type']      = $userId > 0 ? 'admin' : 'system';

        return $entry;
    }

    /**
     * Resolve a log row's membership_uuid from fields that already exist on
     * wp_wicket_aorm_logs (and, transitively, wp_wicket_aorm_staged_records) —
     * see getGlobalEntries() docblock for the full rationale.
     *
     * @param  array<string, mixed> $row
     */
    private function resolveMembershipUuidForEntry(array $row): string
    {
        $objectType = (string) ($row['object_type'] ?? '');
        $objectId   = (string) ($row['object_id'] ?? '');

        if ($objectType === 'roster' && $objectId !== '') {
            return $objectId;
        }

        $sessionId = (string) ($row['upload_session_id'] ?? '');

        if ($sessionId === '') {
            return '';
        }

        return $this->resolveMembershipUuidForSession($sessionId);
    }

    /**
     * Look up the membership_uuid associated with an upload_session_id via
     * wp_wicket_aorm_staged_records (every staged record in a session shares
     * the same membership_uuid, so the first match is sufficient). Result is
     * memoized per session for the lifetime of this instance.
     */
    private function resolveMembershipUuidForSession(string $sessionId): string
    {
        if (array_key_exists($sessionId, $this->sessionMembershipCache)) {
            return $this->sessionMembershipCache[$sessionId];
        }

        $stagedTable = $this->wpdb->prefix . 'wicket_aorm_staged_records';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $membershipUuid = (string) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT membership_uuid FROM {$stagedTable} WHERE upload_session_id = %s LIMIT 1",
                $sessionId,
            ),
        );

        $this->sessionMembershipCache[$sessionId] = $membershipUuid;

        return $membershipUuid;
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
