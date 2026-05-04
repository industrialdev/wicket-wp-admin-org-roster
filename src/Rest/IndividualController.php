<?php

declare(strict_types=1);

namespace WicketAORM\Rest;

use WicketAORM\Database\StagedRecordsTable;

/**
 * Individual add endpoint.
 *
 * Routes registered under the wicket-aorm/v1 namespace:
 *   POST /rosters/{org_uuid}/{membership_uuid}/individual — add a single person (AORM-5)
 *
 * The handler gate-checks for an active staged-records session first (AORM-5.3).
 * Subsequent subtasks add validation (AORM-5.4) and DB insertion (AORM-5.5).
 */
class IndividualController extends RestController
{
    /**
     * UUID v4 route parameter pattern (lowercase hex with dashes).
     */
    private const UUID_PATTERN = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    public function __construct(
        private readonly ?StagedRecordsTable $stagedRecordsTable = null,
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
     * Gate-checks for an active staged-records session (AORM-5.3).  If one
     * already exists for this org + membership, the request is rejected with
     * 409 Conflict so the admin resolves the pending session first.
     *
     * Validation (AORM-5.4) and record insertion (AORM-5.5) are added in
     * subsequent subtasks.
     *
     * @param \WP_REST_Request $request
     */
    public function add_individual($request): \WP_REST_Response
    {
        $orgUuid        = (string) $request->get_param('org_uuid');
        $membershipUuid = (string) $request->get_param('membership_uuid');

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

        // TODO AORM-5.4: run field validation (required fields, email format, phone format).
        // TODO AORM-5.5: create session + insert staged record.

        return new \WP_REST_Response(['accepted' => true], 200);
    }
}
