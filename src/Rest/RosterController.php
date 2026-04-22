<?php

declare(strict_types=1);

namespace WicketAORM\Rest;

use WicketAORM\Services\MdpClient;

/**
 * Roster list/detail endpoints.
 *
 * Routes registered under the wicket-aorm/v1 namespace:
 *   GET /rosters/{org_uuid}/{membership_uuid}  — org+membership detail (AORM-4.1)
 */
class RosterController extends RestController
{
    /**
     * UUID v4 route parameter pattern (lowercase hex with dashes).
     */
    private const UUID_PATTERN = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    public function __construct(private readonly ?MdpClient $mdpClient = null)
    {
    }

    /**
     * Register all roster routes.
     */
    public function register_routes(): void
    {
        register_rest_route(
            $this->namespace,
            '/rosters/(?P<org_uuid>' . self::UUID_PATTERN . ')/(?P<membership_uuid>' . self::UUID_PATTERN . ')',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [$this, 'get_item'],
                    'permission_callback' => [$this, 'get_item_permissions_check'],
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
     * Permission check for GET /rosters/{org_uuid}/{membership_uuid}.
     *
     * @param \WP_REST_Request $request
     */
    public function get_item_permissions_check($request): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * Handle GET /rosters/{org_uuid}/{membership_uuid}.
     *
     * Fetches org + membership detail from the MDP and returns a flat JSON
     * representation suitable for consumption by the React detail view.
     *
     * Returns 404 when no matching record is found in the MDP.
     *
     * @param \WP_REST_Request $request
     */
    public function get_item($request): \WP_REST_Response
    {
        $orgUuid        = (string) $request->get_param('org_uuid');
        $membershipUuid = (string) $request->get_param('membership_uuid');

        $client = $this->mdpClient ?? new MdpClient();
        $data   = $client->getOrgMembershipDetail($orgUuid, $membershipUuid);

        if (empty($data)) {
            return new \WP_REST_Response(['message' => 'Roster not found.'], 404);
        }

        return new \WP_REST_Response($data, 200);
    }
}
