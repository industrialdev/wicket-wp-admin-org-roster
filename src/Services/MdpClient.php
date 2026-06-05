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
     * Total number of attempts (initial + retries) for retryable MDP API calls.
     *
     * A value of 3 means: 1 initial attempt + 2 retries before giving up.
     */
    public const RETRY_MAX_ATTEMPTS = 3;

    /**
     * Base delay in milliseconds applied between retry attempts.
     *
     * The actual delay follows exponential back-off: base × 2^(attempt).
     * Attempt 0 fails → sleep 1 000 ms; attempt 1 fails → sleep 2 000 ms.
     */
    public const RETRY_BASE_DELAY_MS = 1000;

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
     * Remove people from an org roster by deleting their person_membership records
     * and stripping any configured security roles scoped to the roster org.
     *
     * Steps per person UUID:
     *   1. Look up the person_membership ID via a filtered list request.
     *   2. DELETE person_memberships/{id} to remove them from the roster.
     *   3. For each configured security role, remove any matching touch-point
     *      scoped to the roster org via DELETE touch_points/{id}.
     *
     * Returns an `{removed, failed}` summary rather than throwing, so the
     * caller can return a partial-success response to the React client.
     *
     * @param string   $orgUuid        Organization UUID.
     * @param string   $membershipUuid Organization membership UUID.
     * @param string[] $personUuids    Person UUIDs to remove.
     *
     * @return array{
     *   removed: list<string>,
     *   failed:  list<string>,
     * }
     */
    public function removeRosterMembers(string $orgUuid, string $membershipUuid, array $personUuids): array
    {
        $removed = [];
        $failed  = [];

        if (empty($personUuids)) {
            return ['removed' => $removed, 'failed' => $failed];
        }

        $client = wicket_api_client();

        if (! $client) {
            return ['removed' => [], 'failed' => array_values($personUuids)];
        }

        // Build a map of person_uuid => person_membership_id so we can delete
        // the right record without an extra round-trip per person.
        $personMembershipMap = $this->fetchPersonMembershipIds($client, $membershipUuid, $personUuids);

        // Configured security roles to strip from the org (may be empty).
        $securityRoles = $this->getConfiguredSecurityRoles();

        foreach ($personUuids as $personUuid) {
            $personMembershipId = $personMembershipMap[$personUuid] ?? null;

            if ($personMembershipId === null) {
                $failed[] = $personUuid;
                continue;
            }

            try {
                // Remove person from the roster.
                $client->delete('person_memberships/' . $personMembershipId);

                // Strip configured security roles scoped to this org.
                if (! empty($securityRoles)) {
                    $this->removeOrgRoles($client, $orgUuid, $personUuid, $securityRoles);
                }

                $removed[] = $personUuid;
            } catch (\Exception $e) {
                $failed[] = $personUuid;
            }
        }

        return ['removed' => $removed, 'failed' => $failed];
    }

    /**
     * Fetch a map of person_uuid => person_membership_id for the given person UUIDs.
     *
     * Calls the `person_memberships` sub-endpoint with a Ransack
     * `person_uuid_in` filter so we can resolve IDs without a separate
     * request per person.
     *
     * @param object   $client         MDP API client (must be non-null).
     * @param string   $membershipUuid Organization membership UUID.
     * @param string[] $personUuids    Person UUIDs to look up.
     *
     * @return array<string, string>   Map of person_uuid => person_membership_id.
     */
    private function fetchPersonMembershipIds(object $client, string $membershipUuid, array $personUuids): array
    {
        $map = [];

        $queryParams = [
            'filter' => [
                'person_uuid_in' => $personUuids,
            ],
            'page' => [
                'size' => count($personUuids),
            ],
        ];

        $query = (string) preg_replace(
            '/\%5B\d+\%5D/',
            '%5B%5D',
            http_build_query($queryParams),
        );

        $endpoint = 'organization_memberships/' . $membershipUuid . '/person_memberships?' . $query;

        try {
            $response = $client->get($endpoint);

            if (! is_array($response)) {
                return $map;
            }

            foreach ($response['data'] ?? [] as $item) {
                $id         = (string) ($item['id'] ?? '');
                $personUuid = (string) ($item['relationships']['person']['data']['id'] ?? '');

                if ($id !== '' && $personUuid !== '') {
                    $map[$personUuid] = $id;
                }
            }
        } catch (\Exception $e) {
            // Return empty map on error — callers will record affected
            // person UUIDs as failed.
        }

        return $map;
    }

    /**
     * Remove security roles scoped to an org for a given person.
     *
     * Fetches the person's touch-points filtered to the roster org UUID,
     * then deletes any whose `role_slug` attribute matches the configured list.
     *
     * Failures here are intentionally swallowed — role removal is best-effort
     * so that a transient API error does not block the person from being
     * removed from the roster altogether.
     *
     * @param object   $client      MDP API client (must be non-null).
     * @param string   $orgUuid     Organization UUID.
     * @param string   $personUuid  Person UUID.
     * @param string[] $roleSlugs   Role slugs configured in plugin settings.
     */
    private function removeOrgRoles(object $client, string $orgUuid, string $personUuid, array $roleSlugs): void
    {
        $query    = http_build_query(['filter' => ['organization_uuid_eq' => $orgUuid]]);
        $endpoint = 'people/' . $personUuid . '/touch_points?' . $query;

        try {
            $response = $client->get($endpoint);

            if (! is_array($response)) {
                return;
            }

            foreach ($response['data'] ?? [] as $touchPoint) {
                $roleSlug = (string) ($touchPoint['attributes']['role_slug'] ?? '');

                if (! in_array($roleSlug, $roleSlugs, true)) {
                    continue;
                }

                $tpId = (string) ($touchPoint['id'] ?? '');

                if ($tpId !== '') {
                    $client->delete('touch_points/' . $tpId);
                }
            }
        } catch (\Exception $e) {
            // Best-effort — silently ignore role removal failures.
        }
    }

    /**
     * Bulk add or remove role touch-points for a list of people scoped to a roster org.
     *
     * Iterates over each person UUID and, depending on `$action`:
     *   - `add`:    POST `people/{uuid}/roles` once per role name, with the org as
     *               the `resource` relationship.
     *   - `remove`: GET `people/{uuid}/roles`, resolve IDs matching the requested names
     *               scoped to the org, then DELETE `people/{uuid}/relationships/roles`
     *               with those IDs in a single batch request.
     *
     * Returns an `{updated, failed}` summary so the controller can return a
     * partial-success response — callers should inspect `failed` and surface
     * errors to the admin.
     *
     * @param string   $orgUuid        Organization UUID.
     * @param string   $membershipUuid Organization membership UUID (unused in MDP calls
     *                                 but kept for symmetry with other MdpClient methods).
     * @param string[] $personUuids    Person UUIDs to process.
     * @param string   $action         Either 'add' or 'remove'.
     * @param string[] $roleSlugs      Role slugs to apply.
     *
     * @return array{
     *   updated: list<string>,
     *   failed:  list<string>,
     * }
     */
    public function updateMemberRoles(
        string $orgUuid,
        string $membershipUuid,
        array $personUuids,
        string $action,
        array $roleSlugs,
    ): array {
        $updated = [];
        $failed  = [];

        if (empty($personUuids) || empty($roleSlugs)) {
            return ['updated' => $updated, 'failed' => $failed];
        }

        $client = wicket_api_client();

        if (! $client) {
            return ['updated' => [], 'failed' => array_values($personUuids)];
        }

        foreach ($personUuids as $personUuid) {
            try {
                if ($action === 'add') {
                    $this->addPersonOrgRoles($client, $orgUuid, $personUuid, $roleSlugs);
                } else {
                    $this->removePersonOrgRoles($client, $orgUuid, $personUuid, $roleSlugs);
                }

                $updated[] = $personUuid;
            } catch (\Exception $e) {
                $failed[] = $personUuid;
            }
        }

        return ['updated' => $updated, 'failed' => $failed];
    }

    /**
     * Apply a set of role slugs for a single person scoped to a roster org.
     *
     * Thin public wrapper around the private addPersonOrgRoles() intended for
     * use by SyncService after creating a new relationship (AORM-9.7) or
     * ensuring an existing one (AORM-9.10). Does nothing when $roleSlugs is
     * empty or when the MDP client is unavailable.
     *
     * Throws on API failure so the caller (SyncService / SyncJobRunner) can
     * record the record as failed rather than silently losing role assignments.
     *
     * @param string   $personUuid  Person UUID.
     * @param string   $orgUuid     Organization UUID (roles are scoped to this org).
     * @param string[] $roleSlugs   Role slugs to assign.
     *
     * @throws \Exception When any MDP POST call fails.
     *
     * @see AORM-9.7  — new_record: apply roles after relationship creation
     * @see AORM-9.10 — exact_match / already_on_roster: ensure roles
     */
    public function applyPersonOrgRoles(string $personUuid, string $orgUuid, array $roleSlugs): void
    {
        if (empty($roleSlugs)) {
            return;
        }

        $client = wicket_api_client();

        if (! $client) {
            return;
        }

        $this->addPersonOrgRoles($client, $orgUuid, $personUuid, $roleSlugs);
    }

    /**
     * Add roles for a person scoped to an org.
     *
     * POSTs one `roles` resource per role name to
     * `people/{person_uuid}/roles`, linking the person to the organisation
     * for each requested role via the `resource` relationship.
     *
     * MDP payload shape (per role):
     * ```json
     * {"data":{"type":"roles","attributes":{"name":"<role_name>"},
     *   "relationships":{"resource":{"data":{"type":"organizations","id":"<org_uuid>"}}}}}
     * ```
     *
     * Throws on the first API error so the caller can record the person as failed.
     *
     * @param object   $client     MDP API client (must be non-null).
     * @param string   $orgUuid    Organization UUID.
     * @param string   $personUuid Person UUID.
     * @param string[] $roleSlugs  Role names to assign.
     *
     * @throws \Exception When any MDP POST call fails.
     */
    private function addPersonOrgRoles(object $client, string $orgUuid, string $personUuid, array $roleSlugs): void
    {
        foreach ($roleSlugs as $roleSlug) {
            $payload = [
                'data' => [
                    'type'          => 'roles',
                    'attributes'    => ['name' => $roleSlug],
                    'relationships' => [
                        'resource' => [
                            'data' => [
                                'type' => 'organizations',
                                'id'   => $orgUuid,
                            ],
                        ],
                    ],
                ],
            ];

            $client->post('people/' . $personUuid . '/roles', ['json' => $payload]);
        }
    }

    /**
     * Remove roles for a person scoped to an org.
     *
     * Steps:
     *   1. GET `people/{person_uuid}/roles` to list the person's current roles.
     *   2. Filter to entries whose `attributes.name` is in `$roleSlugs` AND whose
     *      `relationships.resource.data.id` matches `$orgUuid`.
     *   3. If any matching role IDs are found, issue a single
     *      DELETE `people/{person_uuid}/relationships/roles` with the ID list.
     *
     * MDP DELETE payload shape:
     * ```json
     * {"data":[{"type":"roles","id":"<role_id>"},...]}
     * ```
     *
     * Throws on GET or DELETE errors so the caller can record the person as
     * failed (unlike the best-effort {@see removeOrgRoles()} used when
     * removing members from a roster).
     *
     * @param object   $client     MDP API client (must be non-null).
     * @param string   $orgUuid    Organization UUID.
     * @param string   $personUuid Person UUID.
     * @param string[] $roleSlugs  Role names to remove.
     *
     * @throws \Exception When any MDP call fails.
     */
    private function removePersonOrgRoles(object $client, string $orgUuid, string $personUuid, array $roleSlugs): void
    {
        $response = $client->get('people/' . $personUuid . '/roles');

        if (! is_array($response)) {
            return;
        }

        // Collect IDs of roles that match the requested names AND belong to this org.
        $roleIds = [];

        foreach ($response['data'] ?? [] as $role) {
            $roleName   = (string) ($role['attributes']['name'] ?? '');
            $resourceId = (string) ($role['relationships']['resource']['data']['id'] ?? '');

            if (! in_array($roleName, $roleSlugs, true) || $resourceId !== $orgUuid) {
                continue;
            }

            $roleId = (string) ($role['id'] ?? '');

            if ($roleId !== '') {
                $roleIds[] = $roleId;
            }
        }

        if (empty($roleIds)) {
            return;
        }

        $payload = [
            'data' => array_map(
                static fn (string $id): array => ['type' => 'roles', 'id' => $id],
                $roleIds,
            ),
        ];

        $client->delete('people/' . $personUuid . '/relationships/roles', ['json' => $payload]);
    }

    /**
     * Fetch all person_membership records for an org roster, paginating until
     * all pages are consumed.
     *
     * Delegates to getRosterMembers() with a fixed page size of 100, iterating
     * until `total_pages` is exhausted. Returns a flat list in the same member
     * shape that getRosterMembers() returns in its `members` key.
     *
     * Used by MatchingJobRunner (AORM-7.9) to build the full current-roster
     * set for the replace-mode diff without callers needing to handle pagination
     * themselves.
     *
     * @param string $orgUuid        Organisation UUID.
     * @param string $membershipUuid Org-membership UUID.
     * @return list<array{person_uuid: string, email: string, name: string, title: string, phone: string, roles: list<string>, is_owner: bool}>
     */
    public function getAllRosterMembers(string $orgUuid, string $membershipUuid): array
    {
        $all     = [];
        $page    = 1;
        $perPage = 100;

        do {
            $result     = $this->getRosterMembers($orgUuid, $membershipUuid, [
                'page'     => $page,
                'per_page' => $perPage,
            ]);
            $all        = array_merge($all, $result['members']);
            $totalPages = max(1, (int) $result['total_pages']);
            $page++;
        } while ($page <= $totalPages);

        return $all;
    }

    /**
     * Search MDP for people by email, phone, or last name.
     *
     * Issues a single POST to `people/query` with a Ransack OR group so all
     * supplied fields are searched in one round-trip. Empty fields are omitted
     * from the group; if no non-empty fields remain the method returns early
     * without making any API call.
     *
     * First name is intentionally excluded from the search axes — it is too
     * common to be a useful search criterion and would flood the result set
     * with false positives. It remains a scoring signal in ScoringService.
     *
     * Phone numbers are normalised to digits-only before being added to the
     * query so formatting differences (parentheses, dashes, spaces) do not
     * prevent a match.
     *
     * Returns an empty array when `wicket_api_client()` is unavailable, no
     * results are found, or the request throws.
     *
     * @param array{
     *   first_name?: string,
     *   last_name?:  string,
     *   email?:      string,
     *   phone?:      string,
     * } $fields
     *
     * @return list<array{
     *   uuid:        string,
     *   name:        string,
     *   email:       string,
     *   given_name:  string,
     *   family_name: string,
     *   phone:       string,
     *   org_uuids:   list<string>,
     * }>
     */
    public function searchPersons(array $fields): array
    {
        $group = ['m' => 'or'];

        if (($fields['email'] ?? '') !== '') {
            $group['emails_address_eq'] = (string) $fields['email'];
        }

        $normalizedPhone = preg_replace('/\D/', '', (string) ($fields['phone'] ?? '')) ?? '';

        if ($normalizedPhone !== '') {
            $group['phones_number_eq'] = $normalizedPhone;
        }

        if (($fields['last_name'] ?? '') !== '') {
            $group['family_name_eq'] = (string) $fields['last_name'];
        }

        // All fields empty — nothing to search.
        if (count($group) <= 1) {
            return [];
        }

        $client = wicket_api_client();

        if (! $client) {
            return [];
        }

        $queryArgs = (string) preg_replace(
            '/\%5B\d+\%5D/',
            '%5B%5D',
            http_build_query([
                'page'    => ['size' => 20],
                'include' => 'phones',
            ]),
        );

        $args = [
            'filter' => [
                'g' => [$group],
            ],
        ];

        try {
            $response = $this->callWithRetry(
                fn () => $client->post('people/query?' . $queryArgs, ['json' => $args]),
            );

            return $this->normalizePeopleSearchResults($response);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Fetch full details for a single person from the MDP.
     *
     * Issues two requests:
     *   1. GET people/{uuid}?include=phones,emails,addresses — base attributes,
     *      all email addresses, all phone numbers, address-based location, and
     *      membership_status (read directly from the person attributes).
     *   2. GET people/{uuid}/organizations?page[size]=5 — employer name (lazy-
     *      loaded via a secondary call because employer is not a direct attribute
     *      on the person resource).
     *
     * Returns an empty array when `wicket_api_client()` is unavailable, the person
     * does not exist, or the request throws.
     *
     * Response shape:
     * ```
     * [
     *   'uuid'              => string,
     *   'given_name'        => string,
     *   'family_name'       => string,
     *   'full_name'         => string,
     *   'primary_email'     => string,
     *   'emails'            => list<array{address: string, type: string, primary: bool}>,
     *   'primary_phone'     => string,
     *   'phones'            => list<array{number: string, type: string, primary: bool}>,
     *   'location'          => array{city: string, country: string},
     *   'title'             => string,
     *   'employer'          => string,
     *   'membership_status' => string,
     * ]
     * ```
     *
     * @param string $uuid  Person UUID to fetch.
     * @return array<string,mixed>  Normalized person detail, or [] on failure.
     */
    public function getPersonDetails(string $uuid): array
    {
        if ($uuid === '') {
            return [];
        }

        $client = wicket_api_client();

        if (! $client) {
            return [];
        }

        $query = http_build_query(['include' => 'phones,emails,addresses']);

        try {
            $response = $client->get('people/' . $uuid . '?' . $query);
        } catch (\Exception $e) {
            return [];
        }

        if (! is_array($response) || empty($response['data'])) {
            return [];
        }

        $person = $this->normalizePersonDetail($response);

        // Lazy-load employer via a secondary organizations call.
        $person['employer'] = $this->fetchPersonEmployer($client, $uuid);

        return $person;
    }

    /**
     * Normalize a single-person JSON:API response into a flat detail array.
     *
     * Resolves phones, emails, and addresses from sideloaded `included` resources.
     * Primary phone / primary email are promoted to top-level fields for convenient
     * use in table cells. Location is taken from the primary address (city + country).
     *
     * @param array{
     *   data: array<string,mixed>,
     *   included?: list<array<string,mixed>>,
     * } $response
     * @return array<string,mixed>
     */
    private function normalizePersonDetail(array $response): array
    {
        $item  = $response['data'];
        $attrs = $item['attributes'] ?? [];
        $uuid  = (string) ($item['id'] ?? '');

        // Index included resources by type:id.
        $included = [];

        foreach ($response['included'] ?? [] as $inc) {
            $type = (string) ($inc['type'] ?? '');
            $id   = (string) ($inc['id'] ?? '');

            if ($type !== '' && $id !== '') {
                $included[$type . ':' . $id] = $inc['attributes'] ?? [];
            }
        }

        // -- Phones --
        $phones       = [];
        $primaryPhone = '';

        foreach ($item['relationships']['phones']['data'] ?? [] as $rel) {
            $relId    = (string) ($rel['id'] ?? '');
            $relAttrs = $included['phones:' . $relId] ?? [];
            $number   = (string) ($relAttrs['number'] ?? '');
            $type     = (string) ($relAttrs['phone_type'] ?? '');
            $primary  = (bool) ($relAttrs['primary'] ?? false);

            if ($number === '') {
                continue;
            }

            $phones[] = ['number' => $number, 'type' => $type, 'primary' => $primary];

            if ($primary && $primaryPhone === '') {
                $primaryPhone = $number;
            }
        }

        // Fall back to first phone if no primary was flagged.
        if ($primaryPhone === '' && ! empty($phones)) {
            $primaryPhone = $phones[0]['number'];
        }

        // -- Emails --
        $emails       = [];
        $primaryEmail = (string) ($attrs['primary_email_address'] ?? '');

        foreach ($item['relationships']['emails']['data'] ?? [] as $rel) {
            $relId    = (string) ($rel['id'] ?? '');
            $relAttrs = $included['emails:' . $relId] ?? [];
            $address  = (string) ($relAttrs['address'] ?? '');
            $type     = (string) ($relAttrs['email_type'] ?? '');
            $primary  = (bool) ($relAttrs['primary'] ?? false);

            if ($address === '') {
                continue;
            }

            $emails[] = ['address' => $address, 'type' => $type, 'primary' => $primary];
        }

        // Ensure primary_email_address is present in the emails list even when the
        // emails relationship is absent or empty (common in test fixtures).
        if ($primaryEmail !== '' && empty($emails)) {
            $emails[] = ['address' => $primaryEmail, 'type' => '', 'primary' => true];
        }

        // -- Location (primary address) --
        $city    = '';
        $country = '';

        foreach ($item['relationships']['addresses']['data'] ?? [] as $rel) {
            $relId    = (string) ($rel['id'] ?? '');
            $relAttrs = $included['addresses:' . $relId] ?? [];
            $isPrimary = (bool) ($relAttrs['primary'] ?? false);

            // Take the first address; prefer primary if one is flagged.
            if ($city === '' || $isPrimary) {
                $city    = (string) ($relAttrs['city'] ?? '');
                $country = (string) ($relAttrs['country_name'] ?? '');
            }

            if ($isPrimary) {
                break;
            }
        }

        return [
            'uuid'          => $uuid,
            'given_name'    => (string) ($attrs['given_name'] ?? ''),
            'family_name'   => (string) ($attrs['family_name'] ?? ''),
            'full_name'     => (string) ($attrs['full_name'] ?? ''),
            'primary_email' => $primaryEmail,
            'emails'        => $emails,
            'primary_phone' => $primaryPhone,
            'phones'        => $phones,
            'location'          => ['city' => $city, 'country' => $country],
            'title'             => (string) ($attrs['job_title'] ?? ''),
            'employer'          => '', // populated separately by getPersonDetails()
            'membership_status' => ((string) ($attrs['membership_status'] ?? '')) === 'Active' ? 'Active' : '',
        ];
    }

    /**
     * Fetch the employer name for a person via their organizations relationship.
     *
     * Calls `GET people/{uuid}/organizations?page[size]=5` and returns the legal
     * name of the first organization found. Returns an empty string when the
     * client is unavailable, no organizations are found, or the request throws.
     *
     * This is intentionally a secondary (lazy) call rather than an `?include=`
     * parameter because the MDP organizations relationship on people does not
     * consistently appear in the base people response.
     *
     * @param object $client  Live MDP API client (must be non-null).
     * @param string $uuid    Person UUID.
     * @return string  First organization's legal name, or '' on failure.
     */
    private function fetchPersonEmployer(object $client, string $uuid): string
    {
        $query = http_build_query(['page' => ['size' => 5]]);

        try {
            $response = $client->get('people/' . $uuid . '/organizations?' . $query);

            if (! is_array($response) || empty($response['data'])) {
                return '';
            }

            // Return the first organization's English legal name.
            $first = $response['data'][0] ?? [];

            return (string) ($first['attributes']['legal_name_en'] ?? '');
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Check whether a person is currently a member of a given org roster.
     *
     * Queries `organization_memberships/{membership_uuid}/person_memberships`
     * filtered by `person_uuid_eq`. Returns true when at least one row exists,
     * false otherwise (including on API error or unavailable client).
     *
     * @param string $personUuid     Person UUID to look up.
     * @param string $membershipUuid Org-membership UUID to scope the search.
     */
    public function isPersonOnRoster(string $personUuid, string $membershipUuid): bool
    {
        if ($personUuid === '' || $membershipUuid === '') {
            return false;
        }

        $client = wicket_api_client();

        if (! $client) {
            return false;
        }

        $query    = http_build_query(['filter' => ['person_uuid_eq' => $personUuid]]);
        $endpoint = 'organization_memberships/' . $membershipUuid . '/person_memberships?' . $query;

        try {
            $response = $this->callWithRetry(fn () => $client->get($endpoint));

            return is_array($response) && ! empty($response['data']);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Call an MDP API callable with exponential-backoff retry.
     *
     * Retries up to {@see RETRY_MAX_ATTEMPTS} total attempts (including the first).
     * Only retries when {@see isRetryableException()} returns true for the thrown
     * exception. Delays between attempts follow exponential back-off starting at
     * {@see RETRY_BASE_DELAY_MS} milliseconds.
     *
     * @param callable $apiCall Zero-argument callable that performs the API call.
     *
     * @return mixed Whatever the callable returns on success.
     *
     * @throws \Exception The last caught exception when all attempts are exhausted,
     *                    or any non-retryable exception immediately.
     */
    protected function callWithRetry(callable $apiCall): mixed
    {
        for ($attempt = 0; $attempt < self::RETRY_MAX_ATTEMPTS; $attempt++) {
            try {
                return $apiCall();
            } catch (\Exception $e) {
                $isLastAttempt = ($attempt + 1) >= self::RETRY_MAX_ATTEMPTS;

                if ($isLastAttempt || ! $this->isRetryableException($e)) {
                    throw $e;
                }

                // Exponential back-off: RETRY_BASE_DELAY_MS × 2^attempt (ms → µs).
                $delayMicroseconds = self::RETRY_BASE_DELAY_MS * (2 ** $attempt) * 1000;

                $this->sleepMicroseconds((int) $delayMicroseconds);
            }
        }

        // Unreachable — the loop always returns or throws before exiting here.
        // Satisfies static analysis tools that require all code paths to return.
        throw new \RuntimeException('callWithRetry: loop exited without result.');
    }

    /**
     * Determine whether an exception should trigger a retry.
     *
     * Retryable conditions:
     *   - HTTP 429 Too Many Requests (MDP rate limiting).
     *   - HTTP 5xx Server Error (transient server fault).
     *   - Network-level errors detected by message (timeouts, connection drops).
     *
     * @param \Exception $e Exception to inspect.
     */
    protected function isRetryableException(\Exception $e): bool
    {
        // Guzzle-compatible: inspect HTTP status code via getResponse().
        if (method_exists($e, 'getResponse') && $e->getResponse() !== null) {
            $statusCode = (int) $e->getResponse()->getStatusCode();

            return $statusCode === 429 || $statusCode >= 500;
        }

        // Fall back to message inspection for network-level errors.
        $message = strtolower($e->getMessage());

        return str_contains($message, 'timeout') || str_contains($message, 'timed out');
    }

    /**
     * Sleep for the given number of microseconds.
     *
     * Extracted to a protected method so tests can subclass and override it to
     * avoid real delays without patching global state.
     *
     * @param int $microseconds Duration to sleep (1 000 000 µs = 1 s).
     */
    protected function sleepMicroseconds(int $microseconds): void
    {
        usleep($microseconds);
    }

    /**
     * Normalize a JSON:API `people` list response into a flat candidate array
     * suitable for MatchingService scoring.
     *
     * When the response includes sideloaded `phones` resources (via
     * `?include=phones`), the primary phone number — or the first phone if no
     * primary is flagged — is extracted and added to each result row.
     *
     * The `relationships.organizations.data` array is extracted as `org_uuids`
     * so callers can check org overlap without an additional API round-trip.
     *
     * @param mixed $response Raw API response.
     *
     * @return list<array{
     *   uuid:        string,
     *   name:        string,
     *   email:       string,
     *   given_name:  string,
     *   family_name: string,
     *   phone:       string,
     *   org_uuids:   list<string>,
     * }>
     */
    private function normalizePeopleSearchResults(mixed $response): array
    {
        if (! is_array($response) || empty($response['data'])) {
            return [];
        }

        // Build a lookup map: phone id → phone attributes from the included sideload.
        $phoneMap = [];

        foreach ($response['included'] ?? [] as $included) {
            if (($included['type'] ?? '') === 'phones') {
                $phoneMap[(string) $included['id']] = $included['attributes'] ?? [];
            }
        }

        $results = [];

        foreach ($response['data'] as $item) {
            $uuid  = (string) ($item['id'] ?? '');
            $attrs = $item['attributes'] ?? [];

            if ($uuid === '') {
                continue;
            }

            // Resolve the phone number from sideloaded data.
            $phone          = '';
            $phoneRelations = $item['relationships']['phones']['data'] ?? [];

            if (! empty($phoneRelations)) {
                // Prefer the primary phone; fall back to the first in the list.
                $primaryId  = '';
                $fallbackId = (string) ($phoneRelations[0]['id'] ?? '');

                foreach ($phoneRelations as $rel) {
                    $relId    = (string) ($rel['id'] ?? '');
                    $relAttrs = $phoneMap[$relId] ?? [];

                    if (! empty($relAttrs['primary'])) {
                        $primaryId = $relId;

                        break;
                    }
                }

                $resolvedId = $primaryId !== '' ? $primaryId : $fallbackId;

                if ($resolvedId !== '' && isset($phoneMap[$resolvedId])) {
                    $phone = (string) ($phoneMap[$resolvedId]['number'] ?? '');
                }
            }

            // Extract org UUIDs from relationships.organizations.data so the
            // org_overlap scoring signal can be evaluated without a second API call.
            $orgUuids = [];

            foreach ($item['relationships']['organizations']['data'] ?? [] as $orgRel) {
                $orgId = (string) ($orgRel['id'] ?? '');

                if ($orgId !== '') {
                    $orgUuids[] = $orgId;
                }
            }

            $results[] = [
                'uuid'        => $uuid,
                'name'        => (string) ($attrs['full_name'] ?? ''),
                'email'       => (string) ($attrs['primary_email_address'] ?? ''),
                'given_name'  => (string) ($attrs['given_name'] ?? ''),
                'family_name' => (string) ($attrs['family_name'] ?? ''),
                'phone'       => $phone,
                'org_uuids'   => $orgUuids,
            ];
        }

        return $results;
    }

    /**
     * Read the configured security role slugs from plugin settings.
     *
     * Admins configure these in the AORM Settings page under
     * `wicket_aorm_settings[security_roles]`. Empty array when unconfigured.
     *
     * @return string[]
     */
    private function getConfiguredSecurityRoles(): array
    {
        $settings = (array) get_option('wicket_aorm_settings', []);
        $roles    = $settings['security_roles'] ?? [];

        return array_values(array_filter(
            array_map('strval', is_array($roles) ? $roles : []),
            fn (string $r): bool => $r !== '',
        ));
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
