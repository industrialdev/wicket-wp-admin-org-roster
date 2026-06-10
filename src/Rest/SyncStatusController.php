<?php

declare(strict_types=1);

namespace WicketAORM\Rest;

use WicketAORM\Database\StagedRecordsTable;

/**
 * Sync progress endpoint.
 *
 * Routes:
 *   GET /wicket-aorm/v1/uploads/{session_id}/sync-status
 *       Returns MDP sync progress counts for the given upload session.
 *       Polled by the React sync progress component (AORM-9.29) to drive
 *       the progress indicator and results screen.
 *
 * Only rows with category = 'ready_to_sync' are counted — records moved
 * to 'discard' or 'manual_update' by the admin are not part of the sync.
 *
 * Response shape (200):
 *   {
 *     "session_id":     string,  // echoed back for client correlation
 *     "total":          int,     // rows eligible for sync
 *     "synced":         int,     // rows with sync_status = 'synced'
 *     "failed":         int,     // rows with sync_status = 'failed'
 *     "pending":        int,     // rows still waiting (sync_status = 'ready_to_sync')
 *     "percentage":     int,     // 0–100 (rounded); 100 when total = 0
 *     "is_complete":    bool,    // true when pending = 0 (all done or failed)
 *     "failed_records": [        // one entry per failed row
 *       {
 *         "id":            int,
 *         "error_details": string,
 *         "raw_data":      object
 *       },
 *       ...
 *     ]
 *   }
 *
 * Returns 404 when no rows exist for the given session_id.
 *
 * @ticket AORM-9.28
 */
class SyncStatusController extends RestController
{
    public function __construct(
        private readonly ?StagedRecordsTable $stagedRecordsTable = null,
    ) {
    }

    /**
     * Register routes for the sync status endpoint.
     */
    public function register_routes(): void
    {
        register_rest_route(
            $this->namespace,
            '/uploads/(?P<session_id>[0-9a-fA-F\-]{36})/sync-status',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [$this, 'get_sync_status'],
                    'permission_callback' => [$this, 'get_sync_status_permissions_check'],
                    'args'                => [
                        'session_id' => [
                            'required'          => true,
                            'sanitize_callback' => 'sanitize_text_field',
                            'validate_callback' => static fn ($value): bool =>
                                (bool) preg_match(
                                    '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/',
                                    (string) $value,
                                ),
                        ],
                    ],
                ],
            ],
        );
    }

    /**
     * Permission check for GET /uploads/{session_id}/sync-status.
     *
     * @param \WP_REST_Request $request
     */
    public function get_sync_status_permissions_check($request): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * Handle GET /uploads/{session_id}/sync-status.
     *
     * @param \WP_REST_Request $request
     */
    public function get_sync_status($request): \WP_REST_Response
    {
        $sessionId = (string) $request->get_param('session_id');
        $table     = $this->stagedRecordsTable ?? new StagedRecordsTable();

        // Return 404 when the session does not exist.
        if ($table->getSessionContext($sessionId) === null) {
            return new \WP_REST_Response(
                ['message' => 'Upload session not found.'],
                404,
            );
        }

        // Retrieve the committed IDs stored by CommitController so progress
        // counts are scoped to the current batch.  false = transient absent
        // (e.g. expired or legacy session) → fall back to counting all rows.
        $committedIds = get_transient('wicket_aorm_committed_ids_' . $sessionId);
        $idsFilter    = ($committedIds !== false) ? $committedIds : 'all';

        $progress = $table->getSyncProgress($sessionId, $idsFilter);
        $total    = $progress['total'];
        $synced   = $progress['synced'];
        $failed   = $progress['failed'];
        $pending  = $progress['pending'];

        // percentage reflects how many rows have been settled (synced or failed).
        $settled    = $synced + $failed;
        $percentage = $total > 0
            ? (int) round(($settled / $total) * 100)
            : 100;

        // Sync is complete when nothing is pending (all rows have settled).
        $isComplete = $pending === 0;

        return new \WP_REST_Response(
            [
                'session_id'     => $sessionId,
                'total'          => $total,
                'synced'         => $synced,
                'failed'         => $failed,
                'pending'        => $pending,
                'percentage'     => $percentage,
                'is_complete'    => $isComplete,
                'failed_records' => $progress['failed_records'],
            ],
            200,
        );
    }
}
