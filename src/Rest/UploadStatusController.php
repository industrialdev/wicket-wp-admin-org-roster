<?php

declare(strict_types=1);

namespace WicketAORM\Rest;

use WicketAORM\Database\StagedRecordsTable;

/**
 * Upload matching-progress status endpoint.
 *
 * Routes:
 *   GET /wicket-aorm/v1/uploads/{session_id}/status
 *       Returns the MDP-matching progress for the given upload session.
 *       Polled by the React matching-progress component (AORM-7.13) to
 *       drive the progress bar shown while the background job runs.
 *
 * Progress is computed over valid, non-remove_existing rows only (the set
 * that the MatchingJobRunner actually processes).  remove_existing rows are
 * synthetic records created by replace-mode diff (AORM-7.9) and do not go
 * through the matching job, so they are excluded from the denominator.
 *
 * Response shape (200):
 *   {
 *     "session_id":  string,   // echoed back for client correlation
 *     "total":       int,      // rows that need matching
 *     "processed":   int,      // rows the job has already handled
 *     "percentage":  int,      // 0–100 (rounded); 100 when total = 0
 *     "is_complete": bool      // true when processed === total (or total = 0)
 *   }
 *
 * Returns 404 when no rows exist for the given session_id.
 */
class UploadStatusController extends RestController
{
    public function __construct(
        private readonly ?StagedRecordsTable $stagedRecordsTable = null,
    ) {
    }

    /**
     * Register routes for the upload status endpoint.
     */
    public function register_routes(): void
    {
        register_rest_route(
            $this->namespace,
            '/uploads/(?P<session_id>[0-9a-fA-F\-]{36})/status',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [$this, 'get_status'],
                    'permission_callback' => [$this, 'get_status_permissions_check'],
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
     * Permission check for GET /uploads/{session_id}/status.
     *
     * @param \WP_REST_Request $request
     */
    public function get_status_permissions_check($request): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * Handle GET /uploads/{session_id}/status.
     *
     * @param \WP_REST_Request $request
     */
    public function get_status($request): \WP_REST_Response
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

        $progress  = $table->getMatchingProgress($sessionId);
        $total     = $progress['total'];
        $processed = $progress['processed'];

        // When there are no matchable rows (all rows invalid, or session has
        // only remove_existing records), treat matching as complete immediately.
        $percentage = $total > 0
            ? (int) round(($processed / $total) * 100)
            : 100;
        $isComplete = $total === 0 || $processed === $total;

        return new \WP_REST_Response(
            [
                'session_id'  => $sessionId,
                'total'       => $total,
                'processed'   => $processed,
                'percentage'  => $percentage,
                'is_complete' => $isComplete,
            ],
            200,
        );
    }
}
