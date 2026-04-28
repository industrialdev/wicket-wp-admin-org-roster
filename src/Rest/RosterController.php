<?php

declare(strict_types=1);

namespace WicketAORM\Rest;

use WicketAORM\Services\MdpClient;

/**
 * Roster list/detail/members endpoints.
 *
 * Routes registered under the wicket-aorm/v1 namespace:
 *   GET    /rosters/{org_uuid}/{membership_uuid}         — org+membership detail (AORM-4.1)
 *   GET    /rosters/{org_uuid}/{membership_uuid}/members — paginated member list (AORM-4.5)
 *   DELETE /rosters/{org_uuid}/{membership_uuid}/members — bulk remove members   (AORM-4.9)
 *   POST   /rosters/{org_uuid}/{membership_uuid}/roles   — bulk add/remove roles (AORM-4.10)
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
     * Valid values for the `action` parameter on the /roles endpoint.
     */
    private const VALID_ROLE_ACTIONS = ['add', 'remove'];

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

        register_rest_route(
            $this->namespace,
            '/rosters/(?P<org_uuid>' . self::UUID_PATTERN . ')/(?P<membership_uuid>' . self::UUID_PATTERN . ')/members',
            [
                // GET — paginated member list (AORM-4.5)
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [$this, 'get_members'],
                    'permission_callback' => [$this, 'get_members_permissions_check'],
                    'args'                => [
                        'org_uuid'        => [
                            'required'          => true,
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'membership_uuid' => [
                            'required'          => true,
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'page'            => [
                            'required'          => false,
                            'sanitize_callback' => 'absint',
                            'default'           => 1,
                        ],
                        'per_page'        => [
                            'required'          => false,
                            'sanitize_callback' => 'absint',
                            'default'           => 25,
                        ],
                    ],
                ],
                // DELETE — bulk remove members (AORM-4.9)
                [
                    'methods'             => \WP_REST_Server::DELETABLE,
                    'callback'            => [$this, 'delete_members'],
                    'permission_callback' => [$this, 'delete_members_permissions_check'],
                    'args'                => [
                        'org_uuid'        => [
                            'required'          => true,
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'membership_uuid' => [
                            'required'          => true,
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'person_uuids'    => [
                            'required'          => true,
                            'type'              => 'array',
                            'items'             => ['type' => 'string'],
                            'sanitize_callback' => static function (mixed $value): array {
                                return array_values(array_filter(
                                    array_map('sanitize_text_field', (array) $value),
                                    fn (string $v): bool => $v !== '',
                                ));
                            },
                        ],
                    ],
                ],
            ],
        );

        register_rest_route(
            $this->namespace,
            '/rosters/(?P<org_uuid>' . self::UUID_PATTERN . ')/(?P<membership_uuid>' . self::UUID_PATTERN . ')/roles',
            [
                // POST — bulk add/remove roles (AORM-4.10)
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => [$this, 'update_member_roles'],
                    'permission_callback' => [$this, 'update_member_roles_permissions_check'],
                    'args'                => [
                        'org_uuid'        => [
                            'required'          => true,
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'membership_uuid' => [
                            'required'          => true,
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'person_uuids'    => [
                            'required'          => true,
                            'type'              => 'array',
                            'items'             => ['type' => 'string'],
                            'sanitize_callback' => static function (mixed $value): array {
                                return array_values(array_filter(
                                    array_map('sanitize_text_field', (array) $value),
                                    fn (string $v): bool => $v !== '',
                                ));
                            },
                        ],
                        'action'          => [
                            'required'          => true,
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'role_slugs'      => [
                            'required'          => true,
                            'type'              => 'array',
                            'items'             => ['type' => 'string'],
                            'sanitize_callback' => static function (mixed $value): array {
                                return array_values(array_filter(
                                    array_map('sanitize_key', (array) $value),
                                    fn (string $v): bool => $v !== '',
                                ));
                            },
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
        $data   = $client->getOrgMembershipDetail($membershipUuid);

        if (empty($data)) {
            return new \WP_REST_Response(['message' => 'Roster not found.'], 404);
        }

        return new \WP_REST_Response($data, 200);
    }

    /**
     * Permission check for GET /rosters/{org_uuid}/{membership_uuid}/members.
     *
     * @param \WP_REST_Request $request
     */
    public function get_members_permissions_check($request): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * Handle GET /rosters/{org_uuid}/{membership_uuid}/members.
     *
     * Fetches the list of people assigned to the roster from the MDP and
     * returns a paginated JSON payload with member details.
     *
     * @param \WP_REST_Request $request
     */
    public function get_members($request): \WP_REST_Response
    {
        $orgUuid        = (string) $request->get_param('org_uuid');
        $membershipUuid = (string) $request->get_param('membership_uuid');
        $page           = max(1, (int) ($request->get_param('page') ?? 1));
        $perPage        = max(1, (int) ($request->get_param('per_page') ?? 10));

        $client = $this->mdpClient ?? new MdpClient();
        $result = $client->getRosterMembers($orgUuid, $membershipUuid, [
            'page'     => $page,
            'per_page' => $perPage,
        ]);

        return new \WP_REST_Response($result, 200);
    }

    /**
     * Permission check for DELETE /rosters/{org_uuid}/{membership_uuid}/members.
     *
     * @param \WP_REST_Request $request
     */
    public function delete_members_permissions_check($request): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * Handle DELETE /rosters/{org_uuid}/{membership_uuid}/members.
     *
     * Accepts a JSON body with a `person_uuids` array.  Delegates to
     * MdpClient::removeRosterMembers() which removes each person's
     * person_membership record and strips any configured security roles
     * scoped to the roster org.
     *
     * Returns 400 when `person_uuids` is absent or empty.
     * Returns 200 with `{removed, failed}` lists on success (partial
     * successes are allowed — callers should inspect `failed` and retry
     * or surface errors to the admin).
     *
     * @param \WP_REST_Request $request
     */
    public function delete_members($request): \WP_REST_Response
    {
        $orgUuid        = (string) $request->get_param('org_uuid');
        $membershipUuid = (string) $request->get_param('membership_uuid');
        $personUuids    = (array) ($request->get_param('person_uuids') ?? []);

        if (empty($personUuids)) {
            return new \WP_REST_Response(
                ['message' => 'person_uuids must be a non-empty array.'],
                400,
            );
        }

        $client = $this->mdpClient ?? new MdpClient();
        $result = $client->removeRosterMembers($orgUuid, $membershipUuid, $personUuids);

        return new \WP_REST_Response($result, 200);
    }

    /**
     * Permission check for POST /rosters/{org_uuid}/{membership_uuid}/roles.
     *
     * @param \WP_REST_Request $request
     */
    public function update_member_roles_permissions_check($request): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * Handle POST /rosters/{org_uuid}/{membership_uuid}/roles.
     *
     * Accepts a JSON body with `person_uuids` (array), `action` ('add'|'remove'),
     * and `role_slugs` (array).  Delegates to MdpClient::updateMemberRoles()
     * which adds or removes touch-point role assignments scoped to the roster org.
     *
     * Returns 400 when required params are absent/empty or when `action` is
     * not 'add' or 'remove'.
     * Returns 200 with `{updated, failed}` lists on success (partial successes
     * are allowed — callers should inspect `failed` and surface errors to the admin).
     *
     * @param \WP_REST_Request $request
     */
    public function update_member_roles($request): \WP_REST_Response
    {
        $orgUuid        = (string) $request->get_param('org_uuid');
        $membershipUuid = (string) $request->get_param('membership_uuid');
        $personUuids    = (array) ($request->get_param('person_uuids') ?? []);
        $action         = (string) ($request->get_param('action') ?? '');
        $roleSlugs      = (array) ($request->get_param('role_slugs') ?? []);

        if (empty($personUuids)) {
            return new \WP_REST_Response(
                ['message' => 'person_uuids must be a non-empty array.'],
                400,
            );
        }

        if (empty($roleSlugs)) {
            return new \WP_REST_Response(
                ['message' => 'role_slugs must be a non-empty array.'],
                400,
            );
        }

        if (! in_array($action, self::VALID_ROLE_ACTIONS, true)) {
            return new \WP_REST_Response(
                ['message' => 'action must be one of: ' . implode(', ', self::VALID_ROLE_ACTIONS) . '.'],
                400,
            );
        }

        $client = $this->mdpClient ?? new MdpClient();
        $result = $client->updateMemberRoles($orgUuid, $membershipUuid, $personUuids, $action, $roleSlugs);

        return new \WP_REST_Response($result, 200);
    }
}
