<?php

declare(strict_types=1);

namespace WicketAORM\Rest;

use WicketAORM\Database\StagedRecordsTable;

/**
 * Review-modal resolve endpoint — AORM-8B.18.
 *
 * Routes:
 *   PATCH /wicket-aorm/v1/staged/{id}/resolve
 *       Saves the admin's action selection from the ReviewMatchModal.
 *
 *       Accepted `action` values:
 *         - 'create_new_record'  → category: ready_to_sync, record_status: new_record
 *         - 'manual_update'      → category: manual_update  (record_status unchanged)
 *         - 'merge_to_existing'  → category: ready_to_sync, record_status: merging_to_record,
 *                                  merge_target_uuid required
 *         - 'discard'            → category: discard        (record_status unchanged)
 *
 *       The current category is preserved as previous_category before the update.
 *       merge_target_uuid is stored when action = merge_to_existing; cleared otherwise.
 *
 * Response (200):
 *   {
 *     "id":                  int,
 *     "action":              string,
 *     "category":            string,
 *     "record_status":       string,
 *     "previous_category":   string|null,
 *     "merge_target_uuid":   string|null
 *   }
 *
 * Returns 400 when action is invalid or merge_target_uuid is missing for merge.
 * Returns 404 when no staged record exists for the given ID.
 */
class StagedResolveController extends RestController
{
    /**
     * Valid action values accepted by the resolve endpoint.
     */
    public const VALID_ACTIONS = [
        'create_new_record',
        'manual_update',
        'merge_to_existing',
        'discard',
    ];

    /**
     * Map of action → target category.
     */
    private const ACTION_CATEGORY_MAP = [
        'create_new_record' => 'ready_to_sync',
        'manual_update'     => 'manual_update',
        'merge_to_existing' => 'ready_to_sync',
        'discard'           => 'discard',
    ];

    /**
     * Actions that change the record_status column.
     *
     * Keys are action values; values are the target record_status.
     * Actions absent from this map leave record_status unchanged.
     */
    private const ACTION_RECORD_STATUS_MAP = [
        'create_new_record' => 'new_record',
        'merge_to_existing' => 'merging_to_record',
    ];

    public function __construct(
        private readonly ?StagedRecordsTable $stagedRecordsTable = null,
    ) {
    }

    /**
     * Register the resolve route.
     */
    public function register_routes(): void
    {
        register_rest_route(
            $this->namespace,
            '/staged/(?P<id>\d+)/resolve',
            [
                [
                    'methods'             => \WP_REST_Server::EDITABLE,
                    'callback'            => [$this, 'resolve'],
                    'permission_callback' => [$this, 'resolve_permissions_check'],
                    'args'                => [
                        'id' => [
                            'required'          => true,
                            'sanitize_callback' => 'absint',
                        ],
                        'action' => [
                            'required'          => true,
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'merge_target_uuid' => [
                            'required'          => false,
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                    ],
                ],
            ],
        );
    }

    /**
     * Permission check — manage_options required.
     *
     * @param \WP_REST_Request $request
     */
    public function resolve_permissions_check($request): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * Handle PATCH /staged/{id}/resolve.
     *
     * @param \WP_REST_Request $request
     */
    public function resolve($request): \WP_REST_Response
    {
        $id     = (int) $request->get_param('id');
        $action = (string) $request->get_param('action');

        if (! in_array($action, self::VALID_ACTIONS, true)) {
            return new \WP_REST_Response(
                ['message' => sprintf('Invalid action: %s.', $action)],
                400,
            );
        }

        $mergeTargetUuid = null;

        if ($action === 'merge_to_existing') {
            $raw = (string) ($request->get_param('merge_target_uuid') ?? '');

            if ($raw === '') {
                return new \WP_REST_Response(
                    ['message' => 'merge_target_uuid is required when action is merge_to_existing.'],
                    400,
                );
            }

            $mergeTargetUuid = $raw;
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

        $newCategory = self::ACTION_CATEGORY_MAP[$action];

        $update = [
            'category'          => $newCategory,
            'previous_category' => $previousCategory,
            'merge_target_uuid' => $mergeTargetUuid,
            'updated_at'        => current_time('mysql'),
        ];

        if (array_key_exists($action, self::ACTION_RECORD_STATUS_MAP)) {
            $update['record_status'] = self::ACTION_RECORD_STATUS_MAP[$action];
        }

        $table->updateRecord($id, $update);

        $newRecordStatus = $update['record_status'] ?? (string) ($record['record_status'] ?? '');

        return new \WP_REST_Response(
            [
                'id'                => $id,
                'action'            => $action,
                'category'          => $newCategory,
                'record_status'     => $newRecordStatus,
                'previous_category' => $previousCategory,
                'merge_target_uuid' => $mergeTargetUuid,
            ],
            200,
        );
    }
}
