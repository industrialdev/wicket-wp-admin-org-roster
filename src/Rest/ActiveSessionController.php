<?php

declare(strict_types=1);

namespace WicketAORM\Rest;

use WicketAORM\Database\StagedRecordsTable;

/**
 * Active session lookup endpoint.
 *
 * Routes:
 *   GET /wicket-aorm/v1/rosters/{org_uuid}/{membership_uuid}/session
 *       Returns the in-progress upload session (if any) for the given
 *       org + membership combination, together with its current matching
 *       progress.  Used by the React wizard on mount to detect a session
 *       started by any user — not just the one in the current browser —
 *       so that a page reload or a second admin opening the same roster
 *       page both land on the correct wizard step.
 *
 * Response shape (200 — active session found):
 *   {
 *     "session_id":  string,   // the active upload session UUID
 *     "total":       int,      // rows that need matching
 *     "processed":   int,      // rows the job has already handled
 *     "percentage":  int,      // 0–100 (rounded); 100 when total = 0
 *     "is_complete": bool      // true when processed === total (or total = 0)
 *   }
 *
 * Returns 404 when no active session exists for the roster.
 *
 * The response shape is intentionally identical to UploadStatusController
 * (AORM-7.11) so the client can handle both responses with the same logic.
 */
class ActiveSessionController extends RestController
{
    public function __construct(
        private readonly ?StagedRecordsTable $stagedRecordsTable = null,
    ) {
    }

    /**
     * Register routes for the active session lookup endpoint.
     */
    public function register_routes(): void
    {
        register_rest_route(
            $this->namespace,
            '/rosters/(?P<org_uuid>[0-9a-fA-F\-]{36})/(?P<membership_uuid>[0-9a-fA-F\-]{36})/session',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [$this, 'get_active_session'],
                    'permission_callback' => [$this, 'get_active_session_permissions_check'],
                    'args'                => [
                        'org_uuid'        => [
                            'required'          => true,
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'membership_uuid' => [
                            'required'          => true,
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                    ],
                ],
            ],
        );
    }

    /**
     * Permission check for GET /rosters/{org_uuid}/{membership_uuid}/session.
     *
     * @param \WP_REST_Request $request
     */
    public function get_active_session_permissions_check($request): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * Handle GET /rosters/{org_uuid}/{membership_uuid}/session.
     *
     * @param \WP_REST_Request $request
     */
    public function get_active_session(\WP_REST_Request $request): \WP_REST_Response
    {
        $orgUuid        = (string) $request->get_param('org_uuid');
        $membershipUuid = (string) $request->get_param('membership_uuid');
        $table          = $this->stagedRecordsTable ?? new StagedRecordsTable();

        $sessionId = $table->getActiveSessionId($orgUuid, $membershipUuid);

        if ($sessionId === null) {
            return new \WP_REST_Response(
                ['message' => 'No active upload session for this roster.'],
                404,
            );
        }

        $progress  = $table->getMatchingProgress($sessionId);
        $total     = $progress['total'];
        $processed = $progress['processed'];

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
