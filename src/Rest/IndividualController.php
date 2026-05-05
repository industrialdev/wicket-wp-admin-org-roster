<?php

declare(strict_types=1);

namespace WicketAORM\Rest;

use WicketAORM\Database\StagedRecordsTable;
use WicketAORM\Services\ValidationService;

/**
 * Individual add endpoint.
 *
 * Routes registered under the wicket-aorm/v1 namespace:
 *   POST /rosters/{org_uuid}/{membership_uuid}/individual — add a single person (AORM-5)
 *
 * Processing order enforced by add_individual():
 *   1. Gate-check for an active staged-records session (AORM-5.3) — 409 if found.
 *   2. Validate submitted fields against the shared roster row rules (AORM-5.4) — 422 if invalid.
 *   3. Generate a new upload session UUID, insert the staged record (AORM-5.5) — 200 with session_id + record_id.
 */
class IndividualController extends RestController
{
    /**
     * UUID v4 route parameter pattern (lowercase hex with dashes).
     */
    private const UUID_PATTERN = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    public function __construct(
        private readonly ?StagedRecordsTable $stagedRecordsTable = null,
        private readonly ?ValidationService $validationService = null,
    ) {
    }

    /**
     * Register the individual add route.
     */
    public function register_routes(): void
    {
        register_rest_route(
            $this->namespace,
            '/rosters/(?P<org_uuid>' . self::UUID_PATTERN . ')/(?P<membership_uuid>' . self::UUID_PATTERN . ')/individual',
            [
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => [$this, 'add_individual'],
                    'permission_callback' => [$this, 'add_individual_permissions_check'],
                    'args'                => [
                        'org_uuid'        => [
                            'required'          => true,
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'membership_uuid' => [
                            'required'          => true,
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'first_name'      => [
                            'required'          => true,
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'last_name'       => [
                            'required'          => true,
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'email'           => [
                            'required'          => true,
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'mobile_phone'    => [
                            'required'          => false,
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'title'           => [
                            'required'          => false,
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                    ],
                ],
            ],
        );
    }

    /**
     * Permission check for POST /rosters/{org_uuid}/{membership_uuid}/individual.
     *
     * @param \WP_REST_Request $request
     */
    public function add_individual_permissions_check($request): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * Handle POST /rosters/{org_uuid}/{membership_uuid}/individual.
     *
     * Processing order:
     *   1. Gate-check for an active staged-records session (AORM-5.3) — 409 if found.
     *   2. Validate submitted fields against the shared roster row rules (AORM-5.4) — 422 if invalid.
     *   3. Generate a new upload session UUID, insert the staged record (AORM-5.5) — 200 with session_id + record_id.
     *
     * @param \WP_REST_Request $request
     */
    public function add_individual($request): \WP_REST_Response
    {
        $orgUuid        = (string) $request->get_param('org_uuid');
        $membershipUuid = (string) $request->get_param('membership_uuid');

        // AORM-5.3: reject when an active upload session already exists.
        $table = $this->stagedRecordsTable ?? new StagedRecordsTable();

        if ($table->hasActiveSession($orgUuid, $membershipUuid)) {
            return new \WP_REST_Response(
                [
                    'message' => 'An active upload session already exists for this roster. '
                        . 'Please resolve the pending session before adding individual records.',
                ],
                409,
            );
        }

        // AORM-5.4: validate fields using the same rules applied to CSV rows.
        $validator = $this->validationService ?? new ValidationService();

        $errors = $validator->validateRow([
            'first_name'   => (string) ($request->get_param('first_name') ?? ''),
            'last_name'    => (string) ($request->get_param('last_name') ?? ''),
            'email'        => (string) ($request->get_param('email') ?? ''),
            'mobile_phone' => (string) ($request->get_param('mobile_phone') ?? ''),
        ]);

        if (! empty($errors)) {
            return new \WP_REST_Response(['errors' => $errors], 422);
        }

        // AORM-5.5: create a new upload session and insert the staged record.
        $sessionId = wp_generate_uuid4();
        $now       = current_time('mysql');
        $userId    = get_current_user_id();

        $rawData = json_encode([
            'first_name'   => (string) ($request->get_param('first_name') ?? ''),
            'last_name'    => (string) ($request->get_param('last_name') ?? ''),
            'email'        => (string) ($request->get_param('email') ?? ''),
            'mobile_phone' => (string) ($request->get_param('mobile_phone') ?? ''),
            'title'        => (string) ($request->get_param('title') ?? ''),
        ]);

        $recordId = $table->insertRecord([
            'upload_session_id' => $sessionId,
            'action_type'       => 'add',
            'org_uuid'          => $orgUuid,
            'membership_uuid'   => $membershipUuid,
            'raw_data'          => $rawData,
            'validation_status' => 'valid',
            'category'          => 'ready_to_sync',
            'record_status'     => 'new_record',
            'match_count'       => 0,
            'sync_status'       => 'pending',
            'uploaded_by'       => $userId,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);

        return new \WP_REST_Response(
            [
                'session_id' => $sessionId,
                'record_id'  => $recordId,
            ],
            200,
        );
    }
}
