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
