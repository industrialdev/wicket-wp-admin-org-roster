<?php

declare(strict_types=1);

namespace WicketAORM\Rest;

use WicketAORM\Database\StagedRecordsTable;

/**
 * Staged record review endpoints.
 *
 * Routes:
 *   GET /wicket-aorm/v1/staged-records/{session_id}
 *       Returns all staged records for the given upload session, ordered by
 *       insertion order. Used by CsvValidationStep (AORM-6.18) to render
 *       the per-row validation results screen.
 */
class StagedRecordController extends RestController
{
    public function __construct(
        private readonly ?StagedRecordsTable $stagedRecordsTable = null,
    ) {
    }

    /**
     * Register routes for staged records.
     */
    public function register_routes(): void
    {
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
}
