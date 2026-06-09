<?php

declare(strict_types=1);

namespace WicketAORM\Rest;

use WicketAORM\Database\StagedRecordsTable;
use WicketAORM\Services\ActivityLogger;
use WicketAORM\Services\SchedulerService;
use WicketAORM\Services\SyncJobRunner;

/**
 * Commit endpoint — triggers a background MDP sync for a staged session.
 *
 * Routes:
 *   POST /wicket-aorm/v1/uploads/{session_id}/commit
 *
 * Request body (JSON):
 *   { "ids": "all" }                    — sync every ready_to_sync record in the session
 *   { "ids": [1, 2, 3] }               — sync only the specified staged record IDs
 *
 * Responses:
 *   202 { accepted: true, session_id, ids: "all"|[...] }
 *   400 { message }  — ids is an empty array
 *   404 { message }  — session not found
 *   422 { message }  — ids param is missing or of invalid type
 *
 * @see AORM-9.2
 */
class CommitController extends RestController
{
    public function __construct(
        private readonly ?StagedRecordsTable $stagedRecordsTable = null,
        private readonly ?SchedulerService $schedulerService = null,
        private readonly ?ActivityLogger $activityLogger = null,
    ) {
    }

    /**
     * Register routes.
     */
    public function register_routes(): void
    {
        register_rest_route(
            $this->namespace,
            '/uploads/(?P<session_id>[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/commit',
            [
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => [$this, 'commit'],
                    'permission_callback' => [$this, 'get_items_permissions_check'],
                    'args'                => [
                        'session_id' => [
                            'required'          => true,
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                    ],
                ],
            ],
        );
    }

    /**
     * Handle POST /uploads/{session_id}/commit.
     *
     * @param \WP_REST_Request $request
     */
    public function commit(\WP_REST_Request $request): \WP_REST_Response
    {
        $sessionId = (string) $request->get_param('session_id');
        $table     = $this->stagedRecordsTable ?? new StagedRecordsTable();

        // Verify the session exists.
        $context = $table->getSessionContext($sessionId);

        if ($context === null) {
            return new \WP_REST_Response(
                ['message' => sprintf('No staged records found for session "%s".', $sessionId)],
                404,
            );
        }

        // Validate the ids parameter.
        $ids = $request->get_json_params()['ids'] ?? $request->get_param('ids');

        if ($ids === null) {
            return new \WP_REST_Response(
                ['message' => 'The "ids" parameter is required. Pass "all" or an array of record IDs.'],
                422,
            );
        }

        if ($ids !== 'all' && ! is_array($ids)) {
            return new \WP_REST_Response(
                ['message' => 'The "ids" parameter must be "all" or an array of integer record IDs.'],
                422,
            );
        }

        if (is_array($ids) && count($ids) === 0) {
            return new \WP_REST_Response(
                ['message' => 'The "ids" array must not be empty.'],
                400,
            );
        }

        // Normalise IDs to integers when an explicit list is provided.
        $normalizedIds = $ids;

        if (is_array($ids)) {
            $normalizedIds = array_values(array_map('absint', $ids));
        }

        // Dispatch the background sync job.
        $scheduler = $this->schedulerService ?? new SchedulerService();
        $scheduler->dispatch(
            SyncJobRunner::HOOK,
            [
                'upload_session_id' => $sessionId,
                'ids'               => $normalizedIds,
            ],
        );

        // Mark the roster as syncing immediately so that if the admin navigates
        // away before the background job starts, the page reload can detect the
        // in-progress sync and resume at the sync-progress wizard step.
        // markRosterSyncing() is idempotent, so re-dispatch is safe.
        $logger = $this->activityLogger ?? new ActivityLogger();
        $logger->markRosterSyncing(
            $context['org_uuid'],
            $context['membership_uuid'],
            get_current_user_id(),
        );

        return new \WP_REST_Response(
            [
                'accepted'   => true,
                'session_id' => $sessionId,
                'ids'        => $normalizedIds,
            ],
            202,
        );
    }
}
