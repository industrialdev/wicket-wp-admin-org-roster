<?php

declare(strict_types=1);

namespace WicketAORM\Rest;

use WicketAORM\Database\StagedRecordsTable;

/**
 * Upload staged-records categorised-review endpoint.
 *
 * Routes:
 *   GET /wicket-aorm/v1/uploads/{session_id}/staged
 *       Returns all valid staged records for the given upload session,
 *       grouped into the five review categories with per-category counts.
 *       Consumed by the Upload Validation view (AORM-8) to render the
 *       five accordion panels (Ready to Sync, Possible Match, Probable
 *       Match, Manual Update, Discard).
 *
 * Only rows with validation_status = 'valid' are included; invalid and
 * duplicate rows are never surfaced here.
 *
 * Response shape (200):
 *   {
 *     "session_id":  string,       // echoed back for client correlation
 *     "action_type": string,       // "add" | "replace"
 *     "categories": {
 *       "ready_to_sync":  { "count": int, "records": [...] },
 *       "possible_match": { "count": int, "records": [...] },
 *       "probable_match": { "count": int, "records": [...] },
 *       "manual_update":  { "count": int, "records": [...] },
 *       "discard":        { "count": int, "records": [...] }
 *     }
 *   }
 *
 * Each record within a category contains:
 *   - id                (int)          — auto-increment row ID
 *   - record_status     (string)       — new_record | exact_match |
 *                                        merging_to_record | already_on_roster |
 *                                        remove_existing
 *   - sync_status       (string)       — pending | ready_to_sync | synced | failed
 *   - raw_data          (object)       — decoded parsed CSV field values
 *   - match_count       (int)          — number of MDP candidates found
 *   - previous_category (string|null)  — prior category when record was moved
 *   - matched_persons   (array|null)   — list of MDP candidate objects (uuid, name,
 *                                        email, given_name, family_name); null when
 *                                        no matches were stored (e.g. remove_existing)
 *
 * Returns 404 when no rows exist for the given session_id.
 */
class UploadStagedController extends RestController
{
    public function __construct(
        private readonly ?StagedRecordsTable $stagedRecordsTable = null,
    ) {
    }

    /**
     * Register routes for the upload staged-records endpoint.
     */
    public function register_routes(): void
    {
        register_rest_route(
            $this->namespace,
            '/uploads/(?P<session_id>[0-9a-fA-F\-]{36})/staged',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [$this, 'get_staged'],
                    'permission_callback' => [$this, 'get_staged_permissions_check'],
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
     * Permission check for GET /uploads/{session_id}/staged.
     *
     * @param \WP_REST_Request $request
     */
    public function get_staged_permissions_check($request): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * Handle GET /uploads/{session_id}/staged.
     *
     * @param \WP_REST_Request $request
     */
    public function get_staged($request): \WP_REST_Response
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

        $categories = $table->getGroupedByCategory($sessionId);

        return new \WP_REST_Response(
            [
                'session_id'  => $sessionId,
                'action_type' => $context['action_type'],
                'categories'  => $categories,
            ],
            200,
        );
    }
}
