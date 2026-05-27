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

    /**
     * Return the upload_session_id of the most-recently-created active session
     * for the given org + membership combination, or null if no active session
     * exists.
     *
     * "Active" uses the same definition as hasActiveSession(): at least one row
     * with a sync_status of 'pending' or 'ready_to_sync'.  The method returns
     * the session UUID so callers can include it in a 409 response body, letting
     * the client surface a direct link to the in-progress session.
     *
     * @param string $orgUuid        The organisation UUID.
     * @param string $membershipUuid The membership UUID.
     * @return string|null The upload_session_id UUID, or null if no active session.
     */
    public function getActiveSessionId(string $orgUuid, string $membershipUuid): ?string
    {
        global $wpdb;

        $table      = $wpdb->prefix . 'wicket_aorm_staged_records';
        $statusList = implode(
            ', ',
            array_map(static fn (string $s): string => "'{$s}'", self::ACTIVE_SYNC_STATUSES),
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT upload_session_id FROM {$table}
             WHERE org_uuid = %s
               AND membership_uuid = %s
               AND sync_status IN ({$statusList})
             ORDER BY created_at DESC
             LIMIT 1",
            $orgUuid,
            $membershipUuid,
        );

        $result = $wpdb->get_var($sql);

        return is_string($result) && $result !== '' ? $result : null;
    }

    /**
     * Update an existing row in wp_wicket_aorm_staged_records.
     *
     * Used by IndividualController (AORM-5.6) to persist MDP match results
     * back onto the staged record after synchronous matching has completed.
     * The `$data` array keys must correspond to actual column names.
     *
     * @param int                  $recordId The auto-increment ID of the row to update.
     * @param array<string, mixed> $data     Column → value map for the UPDATE.
     */
    public function updateRecord(int $recordId, array $data): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'wicket_aorm_staged_records';

        $wpdb->update($table, $data, ['id' => $recordId]);
    }

    /**
     * Insert a new row into wp_wicket_aorm_staged_records.
     *
     * Accepts a pre-built data array whose keys match the table columns.
     * The caller is responsible for providing all NOT NULL columns that do
     * not have database-level defaults.
     *
     * Returns the auto-incremented ID of the newly inserted row so the
     * REST handler can include it in the response as `record_id`.  Returns 0
     * if the insert fails (mirrors $wpdb->insert_id behaviour on failure).
     *
     * @param array<string, mixed> $data  Column → value map for the INSERT.
     * @return int  The new row's auto-increment ID; 0 on failure.
     */
    public function insertRecord(array $data): int
    {
        global $wpdb;

        $table = $wpdb->prefix . 'wicket_aorm_staged_records';

        $wpdb->insert($table, $data);

        return (int) $wpdb->insert_id;
    }

    /**
     * Delete all staged records for a given upload session.
     *
     * Used by the abandon endpoint (DELETE /wicket-aorm/v1/uploads/{session_id})
     * to permanently remove rows so the active-session gate no longer blocks
     * new uploads for the same org + membership.
     *
     * @param string $sessionId  The upload_session_id UUID.
     * @return int  Number of rows deleted; 0 if none matched or on failure.
     */
    public function deleteRecordsBySessionId(string $sessionId): int
    {
        global $wpdb;

        $table  = $wpdb->prefix . 'wicket_aorm_staged_records';
        $result = $wpdb->delete($table, ['upload_session_id' => $sessionId]);

        return is_int($result) ? $result : 0;
    }

    /**
     * Return staged records that are ready for background MDP matching.
     *
     * A row is "pending matching" when:
     *   - It belongs to the given upload session.
     *   - Its validation_status is 'valid' (invalid/duplicate rows are skipped).
     *   - Its sync_status is 'pending' (not yet categorised by the matching job).
     *
     * Used by MatchingJobRunner::handle() (AORM-7.6) to build the per-batch
     * work list. Rows are returned in insertion order so that partial runs
     * (batching added in AORM-7.10) are deterministic.
     *
     * @param string $sessionId  The upload_session_id UUID.
     * @param int    $limit      Maximum number of rows to return. 0 means no limit (default).
     * @return array<int, array<string, mixed>>  Rows as associative arrays; empty array when none found.
     */
    public function getPendingMatchingRecords(string $sessionId, int $limit = 0): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'wicket_aorm_staged_records';

        // $limit is a typed int — sprintf is safe and avoids a nested prepare() call.
        $limitClause = $limit > 0 ? sprintf('LIMIT %d', $limit) : '';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT * FROM {$table}
                 WHERE upload_session_id = %s
                   AND validation_status = 'valid'
                   AND sync_status = 'pending'
                   AND record_status != 'remove_existing'
                 ORDER BY id ASC
                 {$limitClause}",
                $sessionId,
            ),
            \ARRAY_A,
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * Return all staged records for a given upload session, ordered by
     * insertion order (id ASC).
     *
     * Used by the REST staged-records endpoint (AORM-6.18) to supply the
     * CSV validation review screen with the full list of rows, their
     * validation status, and their raw parsed data.
     *
     * @param string $sessionId  The upload_session_id UUID.
     * @return array<int, array<string, mixed>>  Rows as associative arrays; empty array when none found.
     */
    public function getRecordsBySessionId(string $sessionId): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'wicket_aorm_staged_records';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT * FROM {$table} WHERE upload_session_id = %s ORDER BY id ASC",
                $sessionId,
            ),
            \ARRAY_A,
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * Return session-level context from any row in the given session.
     *
     * All rows in a session share the same action_type, org_uuid,
     * membership_uuid, and uploaded_by values. Returns null when no rows
     * exist for the session.
     *
     * Used by MatchingJobRunner (AORM-7.9) to detect replace mode and
     * resolve the org + membership context without requiring callers to
     * carry this data through the call stack.
     *
     * @param string $sessionId  The upload_session_id UUID.
     * @return array{action_type: string, org_uuid: string, membership_uuid: string, uploaded_by: int}|null
     */
    public function getSessionContext(string $sessionId): ?array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'wicket_aorm_staged_records';

        $row = $wpdb->get_row(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT action_type, org_uuid, membership_uuid, uploaded_by
                 FROM {$table}
                 WHERE upload_session_id = %s
                 LIMIT 1",
                $sessionId,
            ),
            \ARRAY_A,
        );

        if (! is_array($row)) {
            return null;
        }

        return [
            'action_type'     => (string) ($row['action_type'] ?? ''),
            'org_uuid'        => (string) ($row['org_uuid'] ?? ''),
            'membership_uuid' => (string) ($row['membership_uuid'] ?? ''),
            'uploaded_by'     => (int)    ($row['uploaded_by'] ?? 0),
        ];
    }

    /**
     * Return all email addresses from valid, non-removal rows in a session.
     *
     * Reads the `raw_data` JSON column for each valid, non-remove_existing
     * row and extracts the `email` field. Emails are lowercased and
     * deduplicated. Used by MatchingJobRunner (AORM-7.9) to build the
     * "uploaded" email set for replace-mode diff.
     *
     * Rows with record_status = 'remove_existing' are excluded so that
     * synthetic removal rows injected by AORM-7.9 do not pollute the set.
     *
     * @param string $sessionId  The upload_session_id UUID.
     * @return list<string>  Lowercased, unique email addresses; empty list when none found.
     */
    public function getValidRowEmailsForSession(string $sessionId): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'wicket_aorm_staged_records';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT raw_data FROM {$table}
                 WHERE upload_session_id = %s
                   AND validation_status = 'valid'
                   AND record_status != 'remove_existing'",
                $sessionId,
            ),
            \ARRAY_A,
        );

        $emails = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $data  = json_decode((string) ($row['raw_data'] ?? ''), true);
            $email = strtolower(trim((string) ($data['email'] ?? '')));

            if ($email !== '') {
                $emails[] = $email;
            }
        }

        return array_values(array_unique($emails));
    }

    /**
     * Return matching-job progress counts for the given upload session.
     *
     * Only valid, non-remove_existing rows are counted: remove_existing rows
     * are synthetic records injected by the replace-mode diff (AORM-7.9) and
     * are never processed by the matching job, so they must not skew the
     * progress calculation.
     *
     * Returns:
     *   - 'total'     — count of valid, matchable rows in the session.
     *   - 'processed' — count of those rows whose sync_status is no longer
     *                   'pending' (i.e. the matching job has finished with them).
     *
     * Used by UploadStatusController (AORM-7.11) to compute the percentage
     * displayed by the React polling component (AORM-7.13).
     *
     * @param string $sessionId  The upload_session_id UUID.
     * @return array{total: int, processed: int}
     */
    public function getMatchingProgress(string $sessionId): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'wicket_aorm_staged_records';

        $total = (int) $wpdb->get_var(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT COUNT(*) FROM {$table}
                 WHERE upload_session_id = %s
                   AND validation_status = 'valid'
                   AND record_status != 'remove_existing'",
                $sessionId,
            ),
        );

        $processed = (int) $wpdb->get_var(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT COUNT(*) FROM {$table}
                 WHERE upload_session_id = %s
                   AND validation_status = 'valid'
                   AND record_status != 'remove_existing'
                   AND sync_status != 'pending'",
                $sessionId,
            ),
        );

        return [
            'total'     => $total,
            'processed' => $processed,
        ];
    }

    /**
     * Return a matching summary for a completed upload session.
     *
     * Counts every valid staged record (including synthetic remove_existing rows)
     * grouped by category and by record_status. Used by MatchingJobRunner to
     * build the context payload of the 'matching_complete' audit log entry
     * (AORM-7.14).
     *
     * Returned shape:
     * [
     *   'total'       => int,
     *   'by_category' => [
     *     'ready_to_sync'  => int,
     *     'possible_match' => int,
     *     'probable_match' => int,
     *     'manual_update'  => int,
     *     'discard'        => int,
     *   ],
     *   'by_status' => [
     *     'new_record'        => int,
     *     'exact_match'       => int,
     *     'merging_to_record' => int,
     *     'already_on_roster' => int,
     *     'remove_existing'   => int,
     *   ],
     * ]
     *
     * @param string $sessionId  The upload_session_id UUID.
     * @return array{total: int, by_category: array<string, int>, by_status: array<string, int>}
     */
    public function getSummaryByCategory(string $sessionId): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'wicket_aorm_staged_records';

        $total = (int) $wpdb->get_var(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT COUNT(*) FROM {$table} WHERE upload_session_id = %s AND validation_status = 'valid'",
                $sessionId,
            ),
        );

        $categoryRows = (array) $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT category, COUNT(*) AS cnt FROM {$table}
                 WHERE upload_session_id = %s AND validation_status = 'valid'
                 GROUP BY category",
                $sessionId,
            ),
            ARRAY_A,
        );

        $statusRows = (array) $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT record_status, COUNT(*) AS cnt FROM {$table}
                 WHERE upload_session_id = %s AND validation_status = 'valid'
                 GROUP BY record_status",
                $sessionId,
            ),
            ARRAY_A,
        );

        $byCategory = [
            'ready_to_sync'  => 0,
            'possible_match' => 0,
            'probable_match' => 0,
            'manual_update'  => 0,
            'discard'        => 0,
        ];

        foreach ($categoryRows as $row) {
            $key = (string) ($row['category'] ?? '');

            if (array_key_exists($key, $byCategory)) {
                $byCategory[$key] = (int) $row['cnt'];
            }
        }

        $byStatus = [
            'new_record'        => 0,
            'exact_match'       => 0,
            'merging_to_record' => 0,
            'already_on_roster' => 0,
            'remove_existing'   => 0,
        ];

        foreach ($statusRows as $row) {
            $key = (string) ($row['record_status'] ?? '');

            if (array_key_exists($key, $byStatus)) {
                $byStatus[$key] = (int) $row['cnt'];
            }
        }

        return [
            'total'       => $total,
            'by_category' => $byCategory,
            'by_status'   => $byStatus,
        ];
    }

    /**
     * Return all valid staged records for a session grouped by category.
     *
     * Only rows with validation_status = 'valid' are included. Records are
     * ordered by insertion order (id ASC) within each category. Used by
     * UploadStagedController (AORM-8.1) to power the categorised review
     * view built in AORM-8.
     *
     * Returned shape:
     * [
     *   'ready_to_sync'  => ['count' => int, 'records' => [...]],
     *   'possible_match' => ['count' => int, 'records' => [...]],
     *   'probable_match' => ['count' => int, 'records' => [...]],
     *   'manual_update'  => ['count' => int, 'records' => [...]],
     *   'discard'        => ['count' => int, 'records' => [...]],
     * ]
     *
     * Each record array contains:
     *   - 'id'                (int)          — auto-increment row ID
     *   - 'record_status'     (string)       — e.g. 'new_record', 'exact_match'
     *   - 'sync_status'       (string)       — e.g. 'pending', 'ready_to_sync'
     *   - 'raw_data'          (array)        — decoded parsed CSV field values
     *   - 'match_count'       (int)          — number of MDP candidates found
     *   - 'previous_category' (string|null)  — set when record was moved from
     *                                          another category (manual_update/discard)
     *   - 'matched_persons'   (array|null)   — decoded matched_persons JSON (list of
     *                                          candidate objects with uuid, name, email,
     *                                          given_name, family_name); null when no
     *                                          matches were stored for this record
     *
     * @param string $sessionId  The upload_session_id UUID.
     * @return array{
     *   ready_to_sync: array{count: int, records: list<array<string, mixed>>},
     *   possible_match: array{count: int, records: list<array<string, mixed>>},
     *   probable_match: array{count: int, records: list<array<string, mixed>>},
     *   manual_update: array{count: int, records: list<array<string, mixed>>},
     *   discard: array{count: int, records: list<array<string, mixed>>},
     * }
     */
    public function getGroupedByCategory(string $sessionId): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'wicket_aorm_staged_records';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT id, category, record_status, sync_status, raw_data, match_count, previous_category, matched_persons
                 FROM {$table}
                 WHERE upload_session_id = %s
                   AND validation_status = 'valid'
                 ORDER BY id ASC",
                $sessionId,
            ),
            \ARRAY_A,
        );

        $grouped = [
            'ready_to_sync'  => ['count' => 0, 'records' => []],
            'possible_match' => ['count' => 0, 'records' => []],
            'probable_match' => ['count' => 0, 'records' => []],
            'manual_update'  => ['count' => 0, 'records' => []],
            'discard'        => ['count' => 0, 'records' => []],
        ];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $category = (string) ($row['category'] ?? '');

            if (! array_key_exists($category, $grouped)) {
                continue;
            }

            $prevCat = isset($row['previous_category']) && $row['previous_category'] !== ''
                ? (string) $row['previous_category']
                : null;

            $matchedPersonsRaw = isset($row['matched_persons']) && $row['matched_persons'] !== ''
                ? $row['matched_persons']
                : null;

            $matchedPersons = null;

            if ($matchedPersonsRaw !== null) {
                $decoded = json_decode((string) $matchedPersonsRaw, true);
                $matchedPersons = is_array($decoded) ? $decoded : null;
            }

            $grouped[$category]['records'][] = [
                'id'                => (int) $row['id'],
                'record_status'     => (string) ($row['record_status'] ?? ''),
                'sync_status'       => (string) ($row['sync_status'] ?? ''),
                'raw_data'          => json_decode((string) ($row['raw_data'] ?? '{}'), true) ?? [],
                'match_count'       => (int) ($row['match_count'] ?? 0),
                'previous_category' => $prevCat,
                'matched_persons'   => $matchedPersons,
            ];

            $grouped[$category]['count']++;
        }

        return $grouped;
    }

    /**
     * Return all remove_existing staged records for the given session.
     *
     * Used by ReplacementDiffController (AORM-8B.4) to power the dedicated
     * GET /uploads/{session_id}/replacements endpoint. Each record represents
     * a current roster member who would be removed in replace mode.
     *
     * @param  string $sessionId  The upload_session_id UUID.
     * @return list<array{
     *   id:                int,
     *   record_status:     string,
     *   sync_status:       string,
     *   raw_data:          array<string, mixed>,
     *   previous_category: string|null,
     *   matched_persons:   array<int, array<string, mixed>>|null,
     * }>
     */
    public function getRemoveExistingRecords(string $sessionId): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'wicket_aorm_staged_records';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT id, record_status, sync_status, raw_data, previous_category, matched_persons
                 FROM {$table}
                 WHERE upload_session_id = %s
                   AND validation_status  = 'valid'
                   AND record_status      = 'remove_existing'
                 ORDER BY id ASC",
                $sessionId,
            ),
            \ARRAY_A,
        );

        $records = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $prevCat = isset($row['previous_category']) && $row['previous_category'] !== ''
                ? (string) $row['previous_category']
                : null;

            $matchedPersonsRaw = isset($row['matched_persons']) && $row['matched_persons'] !== ''
                ? $row['matched_persons']
                : null;

            $matchedPersons = null;

            if ($matchedPersonsRaw !== null) {
                $decoded = json_decode((string) $matchedPersonsRaw, true);
                $matchedPersons = is_array($decoded) ? $decoded : null;
            }

            $records[] = [
                'id'                => (int) $row['id'],
                'record_status'     => (string) ($row['record_status'] ?? ''),
                'sync_status'       => (string) ($row['sync_status'] ?? ''),
                'raw_data'          => json_decode((string) ($row['raw_data'] ?? '{}'), true) ?? [],
                'previous_category' => $prevCat,
                'matched_persons'   => $matchedPersons,
            ];
        }

        return $records;
    }

    /**
     * Return true when at least one row with record_status = 'remove_existing'
     * already exists for the given session.
     *
     * Used by MatchingJobRunner (AORM-7.9) as an idempotency guard: when
     * removal rows have already been injected (e.g. on a job retry), the
     * replace-mode diff is skipped so duplicates are never created.
     *
     * @param string $sessionId  The upload_session_id UUID.
     */
    public function hasRemoveExistingRecords(string $sessionId): bool
    {
        global $wpdb;

        $table = $wpdb->prefix . 'wicket_aorm_staged_records';

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT COUNT(*) FROM {$table}
                 WHERE upload_session_id = %s
                   AND record_status = 'remove_existing'",
                $sessionId,
            ),
        ) > 0;
    }
}
