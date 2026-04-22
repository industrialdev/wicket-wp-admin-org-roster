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
     * (org_uuid, membership_uuid) pair and includes related organization
     * and membership resources so the caller receives a fully-resolved
     * detail payload in one request.
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
     *   assigned_count: int,
     *   max_assignments: int|null,
     * }|array{}
     */
    public function getOrgMembershipDetail(string $org_uuid, string $membership_uuid): array
    {
        $client = wicket_api_client();

        if (! $client) {
            return [];
        }

        $queryParams = [
            'filter'  => [
                'organization_uuid_eq' => $org_uuid,
                'uuid_eq'              => $membership_uuid,
            ],
            'include' => 'organization,membership',
            'page'    => [
                'size'   => 1,
                'number' => 1,
            ],
        ];

        $query = (string) preg_replace(
            '/\%5B\d+\%5D/',
            '%5B%5D',
            http_build_query($queryParams),
        );

        try {
            $response = $client->get('organization_memberships?' . $query);

            if (! is_array($response) || empty($response['data'])) {
                return [];
            }

            return $this->normalizeOrgMembershipDetail($response);
        } catch (\Exception $e) {
            return [];
        }
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
     *   assigned_count: int,
     *   max_assignments: int|null,
     * }|array{}
     */
    private function normalizeOrgMembershipDetail(array $response): array
    {
        $record = $response['data'][0] ?? [];

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
        $membershipRelId    = (string) ($record['relationships']['membership']['data']['id'] ?? '');
        $membershipItem     = $included['memberships:' . $membershipRelId] ?? [];
        $membershipAttrs    = $membershipItem['attributes'] ?? [];

        $maxAssignments = isset($attrs['max_assignments'])
            ? (int) $attrs['max_assignments']
            : null;

        return [
            'org_uuid'          => $orgRelId,
            'org_name'          => (string) ($orgAttrs['legal_name_en'] ?? ''),
            'org_type'          => (string) ($orgAttrs['type'] ?? ''),
            'membership_uuid'   => $membershipRelId,
            'membership_tier'   => (string) ($membershipAttrs['name'] ?? ''),
            'membership_status' => (string) ($attrs['status'] ?? ''),
            'assigned_count'    => (int) ($attrs['member_count'] ?? 0),
            'max_assignments'   => $maxAssignments,
        ];
    }
}
