<?php

declare(strict_types=1);

namespace WicketAORM\Services;

/**
 * Thin wrapper around wicket_api_client().
 *
 * Provides a stable interface for all MDP API calls made by AORM,
 * insulating the rest of the plugin from the raw wicket_api_client() function.
 */
class MdpClient
{
    /**
     * The only MDP membership status excluded from the roster list view.
     *
     * The MDP exposes three effective states — Active (including Grace Period
     * memberships, which carry `in_grace: true` on an "Active" status record),
     * Delayed, and Inactive. We filter by exclusion (`status_not_eq`) rather
     * than inclusion so that any future statuses MDP adds are shown by default
     * without requiring a code change here.
     */
    public const EXCLUDED_STATUS = 'Inactive';

    /**
     * Fetch organization memberships from the MDP.
     *
     * Calls the `organization_memberships` JSON:API endpoint filtered to
     * {@see ALLOWED_STATUSES}. Includes related `organization` and `membership`
     * resources so the caller can resolve org names and tier names without
     * additional round-trips.
     *
     * Returns an empty result structure when `wicket_api_client()` is
     * unavailable or the request throws, so callers never need to handle null.
     *
     * @param array{
     *   page?: int,
     *   per_page?: int,
     *   sort?: string,
     *   search?: string,
     * } $args
     *
     * @return array{
     *   data: list<array<string,mixed>>,
     *   included: list<array<string,mixed>>,
     *   meta: array<string,mixed>,
     * }
     */
    public function getOrgMemberships(array $args = []): array
    {
        $empty = ['data' => [], 'included' => [], 'meta' => []];

        $client = wicket_api_client();

        if (! $client) {
            return $empty;
        }

        $queryParams = [
            'filter' => [
                'status_not_eq' => self::EXCLUDED_STATUS,
            ],
            'page' => [
                'size'   => max(1, (int) ($args['per_page'] ?? 20)),
                'number' => max(1, (int) ($args['page'] ?? 1)),
            ],
            'include' => 'organization,membership',
        ];

        if (! empty($args['sort'])) {
            $queryParams['sort'] = (string) $args['sort'];
        }

        // AORM-3.4: When a search term is present, apply a Ransack filter on
        // the organisation's English legal name.
        if (! empty($args['search'])) {
            $queryParams['filter']['organization_legal_name_en_cont'] = (string) $args['search'];
        }

        $query = (string) preg_replace(
            '/\%5B\d+\%5D/',
            '%5B%5D',
            http_build_query($queryParams),
        );

        try {
            $response = $client->get('organization_memberships?' . $query);

            return is_array($response) ? $response : $empty;
        } catch (\Exception $e) {
            return $empty;
        }
    }

    /**
     * Fetch a single org-membership record from the MDP.
     *
     * Filters the `organization_memberships` endpoint to the exact
     * (org_uuid, membership_uuid) pair and includes related organization,
     * membership, and people (owner) resources so the caller receives a
     * fully-resolved detail payload in one request.
     *
     * Returns an empty array when `wicket_api_client()` is unavailable,
     * no matching record exists, or the request throws.
     *
     * @return array{
     *   org_uuid: string,
     *   org_name: string,
     *   org_type: string,
     *   membership_uuid: string,
     *   membership_tier: string,
     *   membership_status: string,
     *   membership_owner: string,
     *   assigned_count: int,
     *   max_assignments: int|null,
     *   unlimited_assignments: bool,
     * }|array{}
     */
    public function getOrgMembershipDetail(string $membership_uuid): array
    {
        $client = wicket_api_client();

        if (! $client) {
            return [];
        }

        $query = http_build_query(['include' => 'organization,membership,people,owner']);

        try {
            $response = $client->get('organization_memberships/' . $membership_uuid . '?' . $query);

            if (! is_array($response) || empty($response['data'])) {
                return [];
            }

            return $this->normalizeOrgMembershipDetail($response);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Fetch the list of people assigned to an org roster.
     *
     * Calls the `organization_memberships/{membership_uuid}/person_memberships`
     * JSON:API endpoint. Includes `person` and `person.phones` so name, email,
     * title, phone number, and role names (available directly as
     * `person.attributes.role_names`) are all resolved in a single request.
     *
     * Returns an empty result structure when `wicket_api_client()` is
     * unavailable or the request throws, so callers never need to handle null.
     *
     * @param array{
     *   page?: int,
     *   per_page?: int,
     * } $args
     *
     * @return array{
     *   members: list<array{
     *     person_uuid: string,
     *     name: string,
     *     email: string,
     *     title: string,
     *     phone: string,
     *     roles: list<string>,
     *   }>,
     *   total: int,
     *   total_pages: int,
     * }
     */
    public function getRosterMembers(string $org_uuid, string $membership_uuid, array $args = []): array
    {
        $empty = ['members' => [], 'total' => 0, 'total_pages' => 0];

        $client = wicket_api_client();

        if (! $client) {
            return $empty;
        }

        $queryParams = [
            'include' => 'person,membership,organization_membership',
            'sort'    => 'person_family_name',
            'page'    => [
                'size'   => max(1, (int) ($args['per_page'] ?? 10)),
                'number' => max(1, (int) ($args['page'] ?? 1)),
            ],
        ];

        $query = (string) preg_replace(
            '/\%5B\d+\%5D/',
            '%5B%5D',
            http_build_query($queryParams),
        );

        $endpoint = 'organization_memberships/' . $membership_uuid . '/person_memberships?' . $query;

        try {
            $response = $client->get($endpoint);

            if (! is_array($response)) {
                return $empty;
            }

            return $this->normalizeRosterMembers($response, $org_uuid, $membership_uuid);
        } catch (\Exception $e) {
            return $empty;
        }
    }

    /**
     * Normalize a person_memberships JSON:API response into flat member records.
     *
     * The `person` and `organization_membership` resources are included so name,
     * email, title, role names, and owner status are all resolved in one request.
     * The roster owner is identified via the `owner` relationship on the included
     * `organization_memberships` record — the person whose UUID matches that
     * relationship's `data.id` receives `is_owner: true`.
     * Phone is always returned as an empty string — phone lookup via the MDP
     * API requires further investigation (see TODO).
     *
     * Pagination totals are read from `meta.page.total_items` and
     * `meta.page.total_pages` — the nested structure the MDP returns for this
     * endpoint.
     *
     * @param array{
     *   data?: list<array<string,mixed>>,
     *   included?: list<array<string,mixed>>,
     *   meta?: array{page?: array<string,mixed>},
     * } $response
     *
     * @return array{
     *   members: list<array{
     *     person_uuid: string,
     *     name: string,
     *     email: string,
     *     title: string,
     *     phone: string,
     *     roles: list<string>,
     *     is_owner: bool,
     *     membership_details_page_url: string,
     *   }>,
     *   total: int,
     *   total_pages: int,
     * }
     */
    private function normalizeRosterMembers(array $response, string $org_uuid = '', string $membership_uuid = ''): array
    {
        $data = $response['data'] ?? [];

        // Index included resources by type:id for O(1) lookup.
        $included = [];

        foreach ($response['included'] ?? [] as $item) {
            $type = (string) ($item['type'] ?? '');
            $id   = (string) ($item['id'] ?? '');

            if ($type !== '' && $id !== '') {
                $included[$type . ':' . $id] = $item;
            }
        }

        // Resolve the owner person UUID from the included organization_membership.
        // The API returns the owner as a `relationships.owner.data.id` (type: people)
        // on the organization_memberships resource.
        $ownerPersonUuid = '';

        foreach ($included as $key => $item) {
            if (str_starts_with($key, 'organization_memberships:')) {
                $ownerPersonUuid = (string) ($item['relationships']['owner']['data']['id'] ?? '');
                break;
            }
        }

        $members = [];

        foreach ($data as $personMembership) {
            // Resolve the related person resource.
            $personRelId = (string) ($personMembership['relationships']['person']['data']['id'] ?? '');
            $personItem  = $included['people:' . $personRelId] ?? [];
            $personAttrs = $personItem['attributes'] ?? [];

            // Role names are a direct attribute on the person resource —
            // no need to traverse a nested roles relationship.
            $roles = array_values(array_filter(
                (array) ($personAttrs['role_names'] ?? []),
                fn ($r): bool => is_string($r) && $r !== '',
            ));

            $members[] = [
                'person_uuid'                => $personRelId,
                'name'                       => (string) ($personAttrs['full_name'] ?? ''),
                'email'                      => (string) ($personAttrs['primary_email_address'] ?? ''),
                'title'                      => (string) ($personAttrs['job_title'] ?? ''),
                'phone'                      => '', // TODO: clarify MDP phone endpoint — left empty for now
                'roles'                      => $roles,
                'is_owner'                   => $ownerPersonUuid !== '' && $personRelId === $ownerPersonUuid,
                'membership_details_page_url' => admin_url(
                    'admin.php?page=wicket_org_member_edit&id=' . $org_uuid . '&membership_uuid=' . $membership_uuid
                ),
            ];
        }

        // Pagination: meta.page.total_items / meta.page.total_pages
        $pageMeta   = $response['meta']['page'] ?? [];
        $total      = (int) ($pageMeta['total_items'] ?? count($members));
        $totalPages = (int) ($pageMeta['total_pages'] ?? 1);

        return [
            'members'     => $members,
            'total'       => $total,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * Normalize a single-record organization_memberships JSON:API response.
     *
     * Indexes the `included` array by `{type}:{id}` for O(1) resolution,
     * then flattens org name/type and membership tier into a simple map
     * suitable for direct JSON serialisation in the REST response.
     *
     * @param array{
     *   data: list<array<string,mixed>>,
     *   included?: list<array<string,mixed>>,
     * } $response
     *
     * @return array{
     *   org_uuid: string,
     *   org_name: string,
     *   org_type: string,
     *   membership_uuid: string,
     *   membership_tier: string,
     *   membership_status: string,
     *   membership_owner: string,
     *   assigned_count: int,
     *   max_assignments: int|null,
     *   unlimited_assignments: bool,
     * }|array{}
     */
    private function normalizeOrgMembershipDetail(array $response): array
    {
        $record = $response['data'] ?? [];

        if (empty($record)) {
            return [];
        }

        // Index included resources by type:id for O(1) lookup.
        $included = [];

        foreach ($response['included'] ?? [] as $item) {
            $type = (string) ($item['type'] ?? '');
            $id   = (string) ($item['id'] ?? '');

            if ($type !== '' && $id !== '') {
                $included[$type . ':' . $id] = $item;
            }
        }

        $attrs = $record['attributes'] ?? [];

        // Resolve organization.
        $orgRelId  = (string) ($record['relationships']['organization']['data']['id'] ?? '');
        $orgItem   = $included['organizations:' . $orgRelId] ?? [];
        $orgAttrs  = $orgItem['attributes'] ?? [];

        // Resolve membership tier.
        $membershipRelId  = (string) ($record['relationships']['membership']['data']['id'] ?? '');
        $membershipItem   = $included['memberships:' . $membershipRelId] ?? [];
        $membershipAttrs  = $membershipItem['attributes'] ?? [];

        // Resolve membership owner (people relationship).
        $ownerRelId = (string) ($record['relationships']['owner']['data']['id'] ?? '');
        $ownerItem  = $included['people:' . $ownerRelId] ?? [];
        $ownerAttrs = $ownerItem['attributes'] ?? [];
        $ownerName  = (string) ($ownerAttrs['full_name'] ?? '');

        $unlimitedAssignments = (bool) ($attrs['unlimited_assignments'] ?? false);
        $maxAssignments       = $unlimitedAssignments || ! isset($attrs['max_assignments'])
            ? null
            : (int) $attrs['max_assignments'];

        return [
            'org_uuid'              => $orgRelId,
            'org_name'              => (string) ($orgAttrs['legal_name_en'] ?? ''),
            'org_type'              => (string) ($orgAttrs['type'] ?? ''),
            'membership_uuid'       => (string) ($record['id'] ?? ''),
            'membership_tier'       => (string) ($membershipAttrs['name'] ?? ''),
            'membership_status'     => (string) ($attrs['status'] ?? ''),
            'membership_owner'      => $ownerName,
            'assigned_count'        => (int) ($attrs['active_assignments_count'] ?? 0),
            'max_assignments'       => $maxAssignments,
            'unlimited_assignments' => $unlimitedAssignments,
        ];
    }
}
