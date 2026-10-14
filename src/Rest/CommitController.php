<?php

declare(strict_types=1);

namespace WicketAORM\Rest;

use WicketAORM\Database\StagedRecordsTable;
use WicketAORM\Services\ActivityLogger;
use WicketAORM\Services\SchedulerService;
use WicketAORM\Services\SeatCapacityService;
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
 *   202 { accepted: true, session_id, ids: "all"|[...] }  — ids echoes the request
 *   400 { message }  — ids is an empty array, or nothing in it is waiting to sync
 *   404 { message }  — session not found
 *   409 { message, code: 'seat_limit_exceeded', capacity }  — the batch needs
 *                    more seats than the roster has free (see below)
 *   422 { message }  — ids param is missing or of invalid type
 *
 * Multi-visit sessions: before dispatching, failed rows in the requested set
 * are reset to 'ready_to_sync' (so retries actually re-sync), and the request
 * is resolved to the concrete IDs still waiting to sync. Those IDs — not the
 * raw "all" — go to the SyncJobRunner dispatch and to the committed-IDs
 * transient, so progress and the completion log cover only this batch.
 *
 * Seat capacity (bugfix, over-capacity uploads): before anything is reset or
 * dispatched, the requested batch is planned with
 * SeatCapacityService::getSessionCapacity(). When it needs more new seats than
 * the roster has free (counting seats released by remove_existing rows in the
 * same batch), the commit is refused with 409 so the MDP never decides who
 * gets a seat. The admin discards rows or syncs a smaller selection instead.
 * Fails open (no block) when the MDP can't be reached.
 *
 * @see AORM-9.2
 */
class CommitController extends RestController
{
    /** Error code returned with the 409 when a batch exceeds the seat limit. */
    public const SEAT_LIMIT_ERROR_CODE = 'seat_limit_exceeded';

    public function __construct(
        private readonly ?StagedRecordsTable $stagedRecordsTable = null,
        private readonly ?SchedulerService $schedulerService = null,
        private readonly ?ActivityLogger $activityLogger = null,
        private readonly ?SeatCapacityService $seatCapacityService = null,
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

        // Seat capacity: refuse a batch that doesn't fit before touching any
        // rows. Checked against the live MDP seat count, so it also catches
        // seats taken elsewhere since the review screen was loaded.
        $seats    = $this->seatCapacityService ?? new SeatCapacityService(null, $table);
        $capacity = $seats->getSessionCapacity($sessionId, $context['membership_uuid'], $normalizedIds);

        if ($capacity !== null && ! empty($capacity['is_over_capacity'])) {
            $capacity['records'] = (object) ($capacity['records'] ?? []);

            return new \WP_REST_Response(
                [
                    'code'     => self::SEAT_LIMIT_ERROR_CODE,
                    'message'  => sprintf(
                        /* translators: 1: seats needed, 2: seats available */
                        __('These records need %1$d seat(s) but only %2$d are available on this roster. Discard records or sync a smaller selection.', 'wicket-aorm'),
                        (int) $capacity['seats_needed'],
                        (int) $capacity['available_seats'] + (int) $capacity['seats_freed'],
                    ),
                    'capacity' => $capacity,
                ],
                409,
            );
        }

        // Bugfix ("Retry Failed Records" was a no-op): failed rows in the
        // committed set go back to 'ready_to_sync' so SyncJobRunner picks them
        // up again. With ids = "all" this also lets "Sync All" retry rows that
        // failed on an earlier visit to the session.
        $table->resetFailedForSync($sessionId, $normalizedIds);

        // Multi-visit sessions: resolve the request into the concrete IDs that
        // are still waiting to sync. A partially-synced session keeps its
        // synced rows (category stays 'ready_to_sync'), so passing "all"
        // through as-is would make the progress screen and the completion log
        // count records synced on earlier visits as part of this batch.
        $batchIds = $table->getPendingSyncRecordIds($sessionId, $normalizedIds);

        if (count($batchIds) === 0) {
            return new \WP_REST_Response(
                ['message' => 'None of the requested records are waiting to be synced.'],
                400,
            );
        }

        // Dispatch the background sync job.
        $scheduler = $this->schedulerService ?? new SchedulerService();
        $scheduler->dispatch(
            SyncJobRunner::HOOK,
            [
                'upload_session_id' => $sessionId,
                'ids'               => $batchIds,
            ],
        );

        // Store the batch IDs so the sync-status endpoint can scope its
        // progress counts to exactly the records being synced in this batch.
        // Expires after 24 h — long enough for any foreseeable sync job.
        set_transient(
            'wicket_aorm_committed_ids_' . $sessionId,
            $batchIds,
            DAY_IN_SECONDS,
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
