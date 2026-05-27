<?php

declare(strict_types=1);

namespace WicketAORM\Rest;

use WicketAORM\Database\StagedRecordsTable;

/**
 * Replace-mode diff endpoint — AORM-8B.4.
 *
 * Routes:
 *   GET /wicket-aorm/v1/uploads/{session_id}/replacements
 *       Returns the pre-computed replace-mode diff for the given upload session:
 *       all staged records with record_status = 'remove_existing'. These rows
 *       represent current roster members who will be removed when the session is
 *       synced in replace mode.
 *
 *       The rows are inserted by the background matching job (AORM-7.9) which
 *       fetches the current MDP roster and injects a synthetic staged record for
 *       every member absent from the uploaded CSV. This endpoint exposes those
 *       records via a dedicated route so the React removal table in
 *       ReadyToSyncPanel can fetch them independently (rather than filtering from
 *       the broader GET /uploads/{session_id}/staged response).
 *
 * Response shape (200):
 *   {
 *     "session_id":  string,   // echoed back for client correlation
 *     "action_type": string,   // always "replace" when records.length > 0
 *     "records": [
 *       {
 *         "id":                int,          — staged record row ID
 *         "record_status":     string,       — always "remove_existing"
 *         "sync_status":       string,       — pending | ready_to_sync | synced | failed
 *         "raw_data":          object,       — decoded parsed-data fields (person_uuid, email …)
 *         "previous_category": string|null,  — prior category if record was moved
 *         "matched_persons":   null          — always null for remove_existing rows
 *       },
 *       …
 *     ]
 *   }
 *
 * Returns 404 when no rows exist for the given session_id.
 * Returns an empty records array (with action_type) when the session exists but
 * is in add mode (no removal rows exist by design).
 */
class ReplacementDiffController extends RestController
{
    public function __construct(
        private readonly ?StagedRecordsTable $stagedRecordsTable = null,
    ) {
    }

    /**
     * Register routes for the replacement diff endpoint.
     */
    public function register_routes(): void
    {
        register_rest_route(
            $this->namespace,
            '/uploads/(?P<session_id>[0-9a-fA-F\-]{36})/replacements',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [$this, 'get_replacements'],
                    'permission_callback' => [$this, 'get_replacements_permissions_check'],
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
     * Permission check for GET /uploads/{session_id}/replacements.
     *
     * @param \WP_REST_Request $request
     */
    public function get_replacements_permissions_check($request): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * Handle GET /uploads/{session_id}/replacements.
     *
     * @param \WP_REST_Request $request
     */
    public function get_replacements($request): \WP_REST_Response
    {
        $sessionId = (string) $request->get_param('session_id');
        $table     = $this->stagedRecordsTable ?? new StagedRecordsTable();

        $context = $table->getSessionContext($sessionId);

        if ($context === null) {
            return new \WP_REST_Response(
                ['message' => 'Upload session not found.'],
                404,
            );
        }

        $records = $table->getRemoveExistingRecords($sessionId);

        return new \WP_REST_Response(
            [
                'session_id'  => $sessionId,
                'action_type' => $context['action_type'],
                'records'     => $records,
            ],
            200,
        );
    }
}
