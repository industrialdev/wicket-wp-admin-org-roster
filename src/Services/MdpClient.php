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
     * Membership statuses that qualify for the roster list view.
     *
     * Only org memberships in one of these statuses are fetched and displayed.
     * The MDP uses these exact string values in its status field.
     *
     * @var list<string>
     */
    public const ALLOWED_STATUSES = ['Active', 'Delayed', 'Grace Period'];

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
                'status_in' => self::ALLOWED_STATUSES,
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
}
