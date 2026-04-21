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

        // AORM-3.4: When a search term is present, apply a Ransack OR filter
        // across org legal name, org UUID (JSON:API id on the relationship),
        // and the MDP org identifying number (the `identifying_number` attribute).
        if (! empty($args['search'])) {
            $queryParams['filter']['organization_legal_name_en_or_organization_uuid_or_organization_identifying_number_cont'] = (string) $args['search'];
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
