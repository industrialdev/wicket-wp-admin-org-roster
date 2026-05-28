<?php

declare(strict_types=1);

namespace WicketAORM\Rest;

use WicketAORM\Database\StagedRecordsTable;

/**
 * Staged record review and categorisation endpoints.
 *
 * Routes:
 *   GET  /wicket-aorm/v1/staged-records/{session_id}
 *       Returns all staged records for the given upload session, ordered by
 *       insertion order. Used by CsvValidationStep (AORM-6.18) to render
 *       the per-row validation results screen.
 *
 *   PATCH /wicket-aorm/v1/staged-records/{id}
 *       Move a single staged record to a new category. Stores the current
 *       category as previous_category before writing the new value.
 *       Built to support per-row and bulk Discard / Reinstate / Remove
 *       actions across multiple accordion panels (AORM-8B.1, 8B.6, 8B.7).
 */
class StagedRecordController extends RestController
{
    /**
     * Valid target categories accepted by the PATCH endpoint.
     * Any value outside this set is rejected with a 400 response.
     */
    private const VALID_CATEGORIES = [
        'ready_to_sync',
        'possible_match',
        'probable_match',
        'manual_update',
        'discard',
    ];

    public function __construct(
        private readonly ?StagedRecordsTable $stagedRecordsTable = null,
    ) {
    }

    /**
     * Register routes for staged records.
     */
    public function register_routes(): void
    {
        // GET /staged-records/{session_id} — session-level review listing
        register_rest_route(
            $this->namespace,
            '/staged-records/(?P<session_id>[0-9a-fA-F\-]{36})',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [$this, 'get_records'],
                    'permission_callback' => [$this, 'get_records_permissions_check'],
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

        // PATCH /staged-records/{id} — re-categorise a single record (AORM-8B.8)
        register_rest_route(
            $this->namespace,
            '/staged-records/(?P<id>\d+)',
            [
                [
                    'methods'             => \WP_REST_Server::EDITABLE,
                    'callback'            => [$this, 'patch_record'],
                    'permission_callback' => [$this, 'patch_record_permissions_check'],
                    'args'                => [
                        'id' => [
                            'required'          => true,
                            'sanitize_callback' => 'absint',
                        ],
                        'category' => [
                            'required'          => true,
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                    ],
                ],
            ],
        );
    }

    /**
     * Permission check for GET /staged-records/{session_id}.
     *
     * @param \WP_REST_Request $request
     */
    public function get_records_permissions_check($request): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * Permission check for PATCH /staged-records/{id}.
     *
     * @param \WP_REST_Request $request
     */
    public function patch_record_permissions_check($request): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * Handle GET /staged-records/{session_id}.
     *
     * Returns an ordered array of staged record objects, each containing the
     * fields needed by the React validation review screen (AORM-6.18):
     *   - id
     *   - validation_status  ('valid' | 'invalid' | 'duplicate')
     *   - validation_message (string|null)
     *   - raw_data           (decoded object with parsed CSV field values)
     *   - category           (e.g. 'ready_to_sync', 'discard')
     *   - sync_status        (e.g. 'pending')
     *
     * Returns an empty array when no records are found for the given session.
     *
     * @param \WP_REST_Request $request
     */
    public function get_records($request): \WP_REST_Response
    {
        $sessionId = (string) $request->get_param('session_id');
        $table     = $this->stagedRecordsTable ?? new StagedRecordsTable();
        $rows      = $table->getRecordsBySessionId($sessionId);

        $formatted = array_map(
            static function (array $row): array {
                return [
                    'id'                 => (int) $row['id'],
                    'validation_status'  => $row['validation_status'],
                    'validation_message' => $row['validation_message'] ?? null,
                    'raw_data'           => json_decode((string) ($row['raw_data'] ?? '{}'), true) ?? [],
                    'category'           => $row['category'],
                    'sync_status'        => $row['sync_status'],
                ];
            },
            $rows,
        );

        return new \WP_REST_Response($formatted, 200);
    }

    /**
     * Handle PATCH /staged-records/{id}.
     *
     * Moves a single staged record to the requested category. The current
     * category is preserved as previous_category to support the
     * PreviousCategory display column in the Discard and Manual Update panels.
     *
     * Returns 400 when the requested category is not one of the allowed values.
     * Returns 404 when no record exists for the given ID.
     * Returns 200 with {id, category, previous_category} on success.
     *
     * @param \WP_REST_Request $request
     */
    public function patch_record($request): \WP_REST_Response
    {
        $id       = (int) $request->get_param('id');
        $category = (string) $request->get_param('category');

        if (! in_array($category, self::VALID_CATEGORIES, true)) {
            return new \WP_REST_Response(
                ['message' => sprintf('Invalid category: %s.', $category)],
                400,
            );
        }

        $table  = $this->stagedRecordsTable ?? new StagedRecordsTable();
        $record = $table->getRecordById($id);

        if ($record === null) {
            return new \WP_REST_Response(
                ['message' => 'Staged record not found.'],
                404,
            );
        }

        $previousCategory = isset($record['category']) && $record['category'] !== ''
            ? (string) $record['category']
            : null;

        $table->updateRecord($id, [
            'category'          => $category,
            'previous_category' => $previousCategory,
            'updated_at'        => current_time('mysql'),
        ]);

        return new \WP_REST_Response(
            [
                'id'                => $id,
                'category'          => $category,
                'previous_category' => $previousCategory,
            ],
            200,
        );
    }
}
