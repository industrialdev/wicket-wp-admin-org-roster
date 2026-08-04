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
     * Fetch organization memberships from the MDP.
     *
     * Calls the `organization_memberships` JSON:API endpoint with no status
     * filter, so every current membership is returned regardless of its MDP
     * status (Active, Delayed, Grace Period, or Inactive). Includes related
     * `organization` and `membership` resources so the caller can resolve
     * org names and tier names without additional round-trips.
     *
     * Returns an empty result structure when `wicket_api_client()` is
     * unavailable or the request throws, so callers never need to handle null.
     *
     * @param array{
     *   page?: int,
     *   per_page?: int,
     *   sort?: string,
     *   search?: string,
     *   cascadeable_only?: bool,
     * } $args Search term (when present) matches org legal name (partial),
     *         org UUID (exact), or identifying number / org ID (exact) — see
     *         the Ransack grouping filter applied below.
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
            'filter' => [],
            'page' => [
                'size'   => max(1, (int) ($args['per_page'] ?? 20)),
                'number' => max(1, (int) ($args['page'] ?? 1)),
            ],
            'include' => 'organization,membership',
        ];

        if (! empty($args['sort'])) {
            $queryParams['sort'] = (string) $args['sort'];
        }

        // AORM-3.4: When a search term is present, apply a Ransack grouping
        // (filter[g][0]) so the term is OR'd across the organisation's
        // English legal name (partial match), UUID (exact match), and
        // identifying number / org ID (exact match). A single "_or_" chain
        // key can't be used here because it forces one predicate across all
        // attributes, and legal name needs `cont` while UUID/identifying
        // number need `eq`.
        if (! empty($args['search'])) {
            $searchTerm = (string) $args['search'];

            $queryParams['filter']['g'] = [
                [
                    'm'                                   => 'or',
                    'organization_legal_name_en_cont'     => $searchTerm,
                    'organization_uuid_eq'                => $searchTerm,
                    'organization_identifying_number_eq'  => $searchTerm,
                ],
            ];
        }

        // Cascadeable-only filter: restricts the list to org memberships whose
        // organization is flagged is_cascadeable in the MDP. Wired to the
        // "Cascadeable" dropdown filter on the Organization Rosters admin
        // list table (RosterListTable::extra_tablenav()).
        if (! empty($args['cascadeable_only'])) {
            $queryParams['filter']['is_cascadeable_eq'] = 1;
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
     *
     * `org_type` is resolved to a human-friendly label (via
     * resolveOrgTypeLabel()) rather than the raw MDP slug — see AORM-4.2.
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
     * JSON:API endpoint. Includes `person` so name, email, and title are
     * resolved in this request; phone number is resolved separately (see below).
     *
     * Scoped to currently-active person_memberships via `filter[active_at]=now`
     * — the same Ransack predicate used for this endpoint elsewhere in the
     * Wicket ecosystem (e.g. MembershipRosterReader/MembershipService in
     * wicket-wp-account-centre) — so inactive/ended roster rows are
     * excluded from both paginated listing and getAllRosterMembers().
     *
     * Roles are intentionally NOT read from `person.attributes.role_names` —
     * that attribute reflects the person's roles globally, not roles scoped
     * to $org_uuid, and so was misleading for a roster tied to one org. Phone
     * number isn't available on this endpoint's `person` include at all. When
     * $includeRoles is true (the default), a second batched request is made
     * via fetchOrgScopedRolesAndPhones() to `/people?include=roles,phones` for
     * the person UUIDs on this page — roles are filtered down to $org_uuid
     * client-side (see fetchOrgScopedRolesAndPhones() for why), while phone
     * picks each person's primary number regardless of org — and the result
     * is merged into each member's `roles` and `phone` keys. Callers that
     * don't need either (e.g. getAllRosterMembers(), used only for the
     * replace-mode removal diff) can pass $includeRoles=false to skip this
     * extra MDP round-trip per page.
     *
     * Returns an empty result structure when `wicket_api_client()` is
     * unavailable or the request throws, so callers never need to handle null.
     *
     * @param array{
     *   page?: int,
     *   per_page?: int,
     * } $args
     * @param bool $includeRoles Whether to fetch org-scoped roles and phone for
     *                           each member (an extra MDP request per page).
     *
     * @return array{
     *   members: list<array{
     *     person_uuid: string,
     *     name: string,
     *     given_name: string,
     *     family_name: string,
     *     email: string,
     *     title: string,
     *     phone: string,
     *     roles: list<string>,
     *   }>,
     *   total: int,
     *   total_pages: int,
     * }
     */
    public function getRosterMembers(
        string $org_uuid,
        string $membership_uuid,
        array $args = [],
        bool $includeRoles = true,
    ): array {
        $empty = ['members' => [], 'total' => 0, 'total_pages' => 0];

        $client = wicket_api_client();

        if (! $client) {
            return $empty;
        }

        $queryParams = [
            'include' => 'person,membership,organization_membership',
            'sort'    => 'person_family_name',
            'filter'  => [
                'active_at' => 'now',
            ],
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

            $result = $this->normalizeRosterMembers($response, $org_uuid, $membership_uuid);
        } catch (\Exception $e) {
            return $empty;
        }

        if ($includeRoles && ! empty($result['members'])) {
            $personUuids = array_values(array_unique(array_map(
                static fn (array $member): string => (string) $member['person_uuid'],
                $result['members'],
            )));

            $extraByPerson = $this->fetchOrgScopedRolesAndPhones($client, $org_uuid, $personUuids);

            foreach ($result['members'] as &$member) {
                $extra           = $extraByPerson[$member['person_uuid']] ?? ['roles' => [], 'phone' => ''];
                $member['roles'] = $extra['roles'];
                $member['phone'] = $extra['phone'];
            }

            unset($member);
        }

        return $result;
    }

    /**
     * Fetch roles and a primary phone number, scoped to a single organization
     * where applicable, for a batch of people.
     *
     * Calls `/people?include=roles,phones&filter[uuid_in]=...` once for the
     * whole batch (rather than one `people/{uuid}/roles` request per person)
     * so a roster page of N members costs one extra MDP request, not N.
     * `uuid_in` filters the `people` resource by its own `uuid` — unlike
     * `person_uuid_in`, which is the right filter when the base resource is
     * something else (e.g. `person_memberships`) and you're filtering by the
     * *related* person's uuid, that predicate doesn't apply here since
     * `people` is the base resource itself.
     *
     * Roles: there is no server-side org filter on this request — every role
     * for each requested person comes back in `included`, regardless of org.
     * `include=roles` sideloads a person's ENTIRE roles relationship: global
     * roles (`relationships.resource.data === null`, e.g. a platform-wide
     * "user" role) and roles scoped to other orgs the person belongs to come
     * back alongside roles scoped to $orgUuid, resolved via each `people`
     * resource's `relationships.roles.data`. So the roles index built here
     * filters each included `roles` resource to
     * `relationships.resource.data.id === $orgUuid` before reading
     * `attributes.name` — the same check used by removePersonOrgRoles() above.
     * Without it, e.g. a global "user" role would incorrectly show up as an
     * "org role" for every member on every roster.
     *
     * Phone: unlike roles, phones are NOT scoped to $orgUuid here — a phone's
     * `relationships.organization.data` is commonly null even for a "work"
     * type phone, so filtering by org would zero out phone numbers entirely.
     * Instead, each included `phones` resource is resolved back to its owner
     * via `relationships.phoneable.data.id` (rather than depending on a
     * forward `people.relationships.phones` link, which may not be present)
     * and the phone flagged `attributes.primary === true` wins; if none is
     * flagged primary, the first phone encountered for that person is used.
     * `attributes.number_national_format` is used for display.
     *
     * Never throws — a failed or malformed response yields an empty map so a
     * lookup failure degrades to "no roles/phone shown" rather than breaking
     * the whole roster page.
     *
     * @param object   $client      MDP API client (must be non-null).
     * @param string   $orgUuid     Organization UUID roles are scoped to.
     * @param string[] $personUuids Person UUIDs to fetch roles/phone for.
     *
     * @return array<string, array{roles: list<string>, phone: string}> Map of person_uuid => {roles, phone}.
     */
    private function fetchOrgScopedRolesAndPhones(object $client, string $orgUuid, array $personUuids): array
    {
        if (empty($personUuids)) {
            return [];
        }

        $queryParams = [
            'include' => 'roles,phones',
            'filter'  => [
                'uuid_in' => $personUuids,
            ],
            'page'    => [
                'size' => count($personUuids),
            ],
        ];

        $query = (string) preg_replace(
            '/\%5B\d+\%5D/',
            '%5B%5D',
            http_build_query($queryParams),
        );

        $endpoint = '/people?' . $query;

        try {
            $response = $client->get($endpoint);
        } catch (\Exception $e) {
            return [];
        }

        if (! is_array($response)) {
            return [];
        }

        // Index included `roles` resources by id => role name, but only the
        // ones actually scoped to $orgUuid. A role's `relationships.resource.data`
        // is null for global roles (e.g. "user") and points at whichever org the
        // role is scoped to otherwise — the same shape checked by
        // removePersonOrgRoles() above. Without this filter, a person's global
        // roles (and roles scoped to *other* orgs they belong to) would leak
        // into every roster's "org roles", since /people?include=roles sideloads
        // ALL of that person's roles, not just the ones for this org.
        $roleNamesById = [];

        // Index included `phones` resources by owning person_uuid => display
        // number, preferring whichever phone is flagged primary.
        $phoneByPerson = [];

        foreach ($response['included'] ?? [] as $item) {
            $itemType = (string) ($item['type'] ?? '');

            if ($itemType === 'roles') {
                $resourceId = (string) ($item['relationships']['resource']['data']['id'] ?? '');

                if ($resourceId !== $orgUuid) {
                    continue;
                }

                $roleId = (string) ($item['id'] ?? '');

                if ($roleId !== '') {
                    $roleNamesById[$roleId] = (string) ($item['attributes']['name'] ?? '');
                }
            } elseif ($itemType === 'phones') {
                $ownerUuid = (string) ($item['relationships']['phoneable']['data']['id'] ?? '');
                $number    = (string) ($item['attributes']['number_national_format'] ?? '');

                if ($ownerUuid === '' || $number === '') {
                    continue;
                }

                $isPrimary = (bool) ($item['attributes']['primary'] ?? false);

                if ($isPrimary || ! isset($phoneByPerson[$ownerUuid])) {
                    $phoneByPerson[$ownerUuid] = $number;
                }
            }
        }

        // Resolve each person's roles.data references against the map above.
        $rolesByPerson = [];

        foreach ($response['data'] ?? [] as $personResource) {
            $personUuid = (string) ($personResource['id'] ?? '');

            if ($personUuid === '') {
                continue;
            }

            $roleRefs = $personResource['relationships']['roles']['data'] ?? [];
            $names    = [];

            foreach ((array) $roleRefs as $ref) {
                $roleId   = (string) ($ref['id'] ?? '');
                $roleName = $roleNamesById[$roleId] ?? '';

                if ($roleName !== '') {
                    $names[] = $roleName;
                }
            }

            $rolesByPerson[$personUuid] = $names;
        }

        // Merge by the union of person UUIDs seen in either map — a person
        // could in principle have a phone resolved via `included` without
        // appearing in the roles-by-person map (or vice versa).
        $combined = [];

        foreach (array_unique(array_merge(array_keys($rolesByPerson), array_keys($phoneByPerson))) as $personUuid) {
            $combined[$personUuid] = [
                'roles' => $rolesByPerson[$personUuid] ?? [],
                'phone' => $phoneByPerson[$personUuid] ?? '',
            ];
        }

        return $combined;
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
     * Under the site's Cascade roster-management strategy (see
     * isCascadeStrategyActive()), removal is instead delegated entirely to
     * removeRosterMembersViaCascadeStrategy() — adding a member under cascade
     * only ever creates a person-to-org connection (the MDP derives the
     * resulting membership from it; see
     * AdminNotices::renderCascadeStrategyNotice()), so deleting only the
     * person_membership row here without ending that connection would leave
     * it active and let the MDP re-derive the membership, undoing the
     * removal. CascadeStrategy::removeMember() already owns the correct
     * full removal contract (end relationship, end memberships, strip
     * roles, enforce the owner-removal guard), so it is reused rather than
     * reimplemented.
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

        if ($this->isCascadeStrategyActive()) {
            return $this->removeRosterMembersViaCascadeStrategy($orgUuid, $membershipUuid, $personUuids);
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
     * Remove roster members by delegating to the site's Cascade
     * roster-management strategy.
     *
     * Calls CascadeStrategy::removeMember() once per person UUID (the
     * strategy's contract is single-person), continuing on a per-person
     * WP_Error so one failure does not abort the rest of the batch — same
     * partial-success contract as the non-cascade path above.
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
    private function removeRosterMembersViaCascadeStrategy(string $orgUuid, string $membershipUuid, array $personUuids): array
    {
        $removed         = [];
        $failed          = [];
        $cascadeStrategy = new \WicketORM\Services\Strategies\CascadeStrategy();

        foreach ($personUuids as $personUuid) {
            $result = $cascadeStrategy->removeMember($orgUuid, $personUuid, [
                'membership_uuid' => $membershipUuid,
            ]);

            if (is_wp_error($result)) {
                $failed[] = $personUuid;

                continue;
            }

            $removed[] = $personUuid;
        }

        return ['removed' => $removed, 'failed' => $failed];
    }

    /**
     * Whether removal should behave like the ORM's "cascade" membership
     * strategy — i.e. a person's membership is derived from a person-to-org
     * connection rather than managed as its own record.
     *
     * Sourced from AORM's own wicket_aorm_settings[roster_type] setting rather
     * than the wicket-wp-account-centre plugin's OrgManConfig: the two settings
     * describe the same distinction (relationship-derived membership vs. a
     * directly-managed membership assignment), and AORM already has its own
     * roster_type setting driving this exact fork in SyncService::syncRecord().
     * Mirrors AdminNotices::renderCascadeStrategyNotice()'s detection — true
     * (cascade-like) when roster_type is SyncService::ROSTER_TYPE_RELATIONSHIP
     * or absent (the default falls back to ROSTER_TYPE_RELATIONSHIP); false
     * otherwise. Comparing by equality to ROSTER_TYPE_RELATIONSHIP rather than
     * by inequality to ROSTER_TYPE_DIRECT_ASSIGNMENT is equivalent in practice
     * — SettingsPage::sanitize() only ever persists one of the two valid
     * roster_type values — and reads more directly.
     */
    private function isCascadeStrategyActive(): bool
    {
        $settings   = (array) get_option(SyncService::SETTINGS_OPTION, []);
        $rosterType = (string) ($settings['roster_type'] ?? SyncService::ROSTER_TYPE_RELATIONSHIP);

        return $rosterType === SyncService::ROSTER_TYPE_RELATIONSHIP;
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
     * Update a person's job title in MDP.
     *
     * Issues a PATCH to `people/{uuid}` with only the `job_title` attribute so
     * all other person fields are left untouched. Does nothing when `$title` is
     * an empty string or when `wicket_api_client()` is unavailable.
     *
     * Throws on API failure so the caller (SyncService / SyncJobRunner) can
     * record the staged record as failed rather than silently losing the update.
     *
     * MDP payload shape:
     * ```json
     * {"data":{"type":"people","id":"<uuid>","attributes":{"job_title":"<title>"}}}
     * ```
     *
     * @param string $personUuid  Person UUID.
     * @param string $title       Job title to set; empty string is a no-op.
     *
     * @throws \Exception When the MDP PATCH call fails.
     *
     * @see AORM-9.8 — exact_match / already_on_roster: update title
     */
    public function updatePersonTitle(string $personUuid, string $title): void
    {
        if ($title === '') {
            return;
        }

        $client = wicket_api_client();

        if (! $client) {
            return;
        }

        $payload = [
            'data' => [
                'type'       => 'people',
                'id'         => $personUuid,
                'attributes' => [
                    'job_title' => $title,
                ],
            ],
        ];

        try {
            $this->callWithRetry(fn () => $client->patch('people/' . $personUuid, ['json' => $payload]));
        } catch (\Exception $e) {
            throw new \RuntimeException(
                'MDP person title update failed: ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    /**
     * Update a person's given name, family name, and job title in MDP.
     *
     * Issues a single PATCH to `people/{uuid}` containing only the non-empty
     * attributes among `given_name`, `family_name`, and `job_title`, so any
     * field absent from the imported row is left untouched on the MDP person
     * record. Does nothing when all three values are empty strings, or when
     * `wicket_api_client()` is unavailable.
     *
     * Throws on API failure so the caller (SyncService) can record the staged
     * record as failed rather than silently losing the update.
     *
     * MDP payload shape (only non-empty attributes are included):
     * ```json
     * {"data":{"type":"people","id":"<uuid>","attributes":{
     *   "given_name":"<first>","family_name":"<last>","job_title":"<title>"
     * }}}
     * ```
     *
     * @param string $personUuid  Person UUID.
     * @param string $givenName   First name to set; empty string is omitted from the PATCH.
     * @param string $familyName  Last name to set; empty string is omitted from the PATCH.
     * @param string $title       Job title to set; empty string is omitted from the PATCH.
     *
     * @throws \Exception When the MDP PATCH call fails.
     *
     * @see AORM-9.11 — merging_to_record: update name/title from import
     */
    public function updatePersonNameAndTitle(string $personUuid, string $givenName, string $familyName, string $title): void
    {
        $attributes = [];

        if ($givenName !== '') {
            $attributes['given_name'] = $givenName;
        }

        if ($familyName !== '') {
            $attributes['family_name'] = $familyName;
        }

        if ($title !== '') {
            $attributes['job_title'] = $title;
        }

        if (empty($attributes)) {
            return;
        }

        $client = wicket_api_client();

        if (! $client) {
            return;
        }

        $payload = [
            'data' => [
                'type'       => 'people',
                'id'         => $personUuid,
                'attributes' => $attributes,
            ],
        ];

        try {
            $this->callWithRetry(fn () => $client->patch('people/' . $personUuid, ['json' => $payload]));
        } catch (\Exception $e) {
            throw new \RuntimeException(
                'MDP person update failed: ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    /**
     * Add an imported email address as a person's new primary email, demoting
     * whichever email(s) currently hold the primary flag.
     *
     * Used by the merging_to_record sync path (AORM-9.12) when the admin has
     * chosen to merge an imported row into an existing MDP person: the
     * imported `email_address` becomes the person's primary contact email
     * without discarding their existing address(es).
     *
     * Steps:
     *   1. Fetch the person's current emails (with resource IDs) via
     *      `GET people/{uuid}?include=emails`.
     *   2. No-op when an email with the same address (case-insensitive) is
     *      already flagged primary — nothing to add or demote.
     *   3. POST a new `emails` resource for the person with `primary: true`.
     *   4. PATCH each other email currently flagged primary to `primary: false`
     *      so MDP ends up with exactly one primary address.
     *
     * Does nothing when `$emailAddress` is empty or `wicket_api_client()` is
     * unavailable.
     *
     * Throws on API failure so the caller (SyncService / SyncJobRunner) can
     * record the staged record as failed rather than silently losing the update.
     *
     * MDP payload shapes:
     * ```json
     * // POST people/{uuid}/emails
     * {"data":{"type":"emails","attributes":{"address":"<email>","email_type":"<type>","primary":true},
     *   "relationships":{"person":{"data":{"type":"people","id":"<uuid>"}}}}}
     *
     * // PATCH emails/{id}
     * {"data":{"type":"emails","id":"<id>","attributes":{"primary":false}}}
     * ```
     *
     * @param string $personUuid   Merge target person UUID.
     * @param string $emailAddress Imported email address; empty string is a no-op.
     * @param string $emailType    Email type to use for the new address (e.g. 'work').
     *
     * @throws \Exception When any MDP POST/PATCH call fails.
     *
     * @see AORM-9.12 — merging_to_record: add imported email as primary, demote existing
     */
    public function addImportedEmailAsPrimary(string $personUuid, string $emailAddress, string $emailType): void
    {
        if ($emailAddress === '') {
            return;
        }

        $client = wicket_api_client();

        if (! $client) {
            return;
        }

        $existingEmails = $this->fetchPersonEmails($client, $personUuid);

        // Already primary with the same address — nothing to add or demote.
        foreach ($existingEmails as $email) {
            if ($email['primary'] && strcasecmp($email['address'], $emailAddress) === 0) {
                return;
            }
        }

        $payload = [
            'data' => [
                'type'          => 'emails',
                'attributes'    => [
                    'address'    => $emailAddress,
                    'email_type' => $emailType,
                    'primary'    => true,
                ],
                'relationships' => [
                    'person' => [
                        'data' => [
                            'type' => 'people',
                            'id'   => $personUuid,
                        ],
                    ],
                ],
            ],
        ];

        try {
            $this->callWithRetry(fn () => $client->post('people/' . $personUuid . '/emails', ['json' => $payload]));
        } catch (\Exception $e) {
            throw new \RuntimeException(
                'MDP email add failed: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        // Demote any other email(s) currently flagged primary.
        foreach ($existingEmails as $email) {
            if (! $email['primary'] || strcasecmp($email['address'], $emailAddress) === 0) {
                continue;
            }

            $demotePayload = [
                'data' => [
                    'type'       => 'emails',
                    'id'         => $email['id'],
                    'attributes' => [
                        'primary' => false,
                    ],
                ],
            ];

            $emailId = $email['id'];

            try {
                $this->callWithRetry(fn () => $client->patch('emails/' . $emailId, ['json' => $demotePayload]));
            } catch (\Exception $e) {
                throw new \RuntimeException(
                    'MDP email demote failed: ' . $e->getMessage(),
                    0,
                    $e,
                );
            }
        }
    }

    /**
     * Fetch a person's email addresses, including their MDP resource IDs.
     *
     * Calls `GET people/{uuid}?include=emails` and resolves the sideloaded
     * `emails` resources from the `included` array, preserving the resource
     * `id` so callers can target individual addresses with follow-up PATCH
     * calls (e.g. to demote a primary email in {@see addImportedEmailAsPrimary()}).
     *
     * Returns an empty array when the person has no emails, the request fails,
     * or the response is malformed.
     *
     * @param object $client     MDP API client (must be non-null).
     * @param string $personUuid Person UUID.
     *
     * @return list<array{id: string, address: string, type: string, primary: bool}>
     */
    private function fetchPersonEmails(object $client, string $personUuid): array
    {
        $query = http_build_query(['include' => 'emails']);

        try {
            $response = $this->callWithRetry(fn () => $client->get('people/' . $personUuid . '?' . $query));
        } catch (\Exception $e) {
            return [];
        }

        if (! is_array($response) || empty($response['data'])) {
            return [];
        }

        $included = [];

        foreach ($response['included'] ?? [] as $inc) {
            $type = (string) ($inc['type'] ?? '');
            $id   = (string) ($inc['id'] ?? '');

            if ($type !== '' && $id !== '') {
                $included[$type . ':' . $id] = $inc['attributes'] ?? [];
            }
        }

        $emails = [];

        foreach ($response['data']['relationships']['emails']['data'] ?? [] as $rel) {
            $relId    = (string) ($rel['id'] ?? '');
            $relAttrs = $included['emails:' . $relId] ?? [];
            $address  = (string) ($relAttrs['address'] ?? '');

            if ($relId === '' || $address === '') {
                continue;
            }

            $emails[] = [
                'id'      => $relId,
                'address' => $address,
                'type'    => (string) ($relAttrs['email_type'] ?? ''),
                'primary' => (bool) ($relAttrs['primary'] ?? false),
            ];
        }

        return $emails;
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

        try {
            $this->addPersonOrgRoles($client, $orgUuid, $personUuid, $roleSlugs);
        } catch (\Exception $e) {
            throw new \RuntimeException(
                'MDP role assignment failed: ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    /**
     * Revoke a set of role slugs for a single person scoped to a roster org.
     *
     * Thin public wrapper around the private removePersonOrgRoles() intended
     * for use by SyncService when removing a person from a roster (AORM-9.16).
     * Does nothing when $roleSlugs is empty or when the MDP client is unavailable.
     *
     * Unlike the best-effort removeOrgRoles() used in removeRosterMembers(),
     * this method throws on API failure so the caller (SyncService / SyncJobRunner)
     * can record the record as failed rather than silently losing role revocations.
     *
     * @param string   $personUuid  Person UUID.
     * @param string   $orgUuid     Organization UUID (roles are scoped to this org).
     * @param string[] $roleSlugs   Role slugs to revoke.
     *
     * @throws \Exception When any MDP GET or DELETE call fails.
     *
     * @see AORM-9.16 — remove_existing: revoke config security roles scoped to roster org
     */
    public function revokePersonOrgRoles(string $personUuid, string $orgUuid, array $roleSlugs): void
    {
        if (empty($roleSlugs)) {
            return;
        }

        $client = wicket_api_client();

        if (! $client) {
            return;
        }

        try {
            $this->removePersonOrgRoles($client, $orgUuid, $personUuid, $roleSlugs);
        } catch (\Exception $e) {
            throw new \RuntimeException(
                'MDP role revocation failed: ' . $e->getMessage(),
                0,
                $e,
            );
        }
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

            $this->callWithRetry(fn () => $client->post('people/' . $personUuid . '/roles', ['json' => $payload]));
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
        $response = $this->callWithRetry(fn () => $client->get('people/' . $personUuid . '/roles'));

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

        $this->callWithRetry(fn () => $client->delete('people/' . $personUuid . '/relationships/roles', ['json' => $payload]));
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
     * Passes $includeRoles=false to getRosterMembers() — the replace-mode diff
     * only reads email/given_name/family_name, so the extra org-scoped-roles
     * request per page would be pure overhead here. `roles` is always `[]` in
     * the returned members.
     *
     * @param string $orgUuid        Organisation UUID.
     * @param string $membershipUuid Org-membership UUID.
     * @return list<array{person_uuid: string, email: string, name: string, given_name: string, family_name: string, title: string, phone: string, roles: list<string>, is_owner: bool}>
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
            ], false);
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
     * Check whether a person currently has an ACTIVE Direct Assignment
     * membership on a given org membership (roster).
     *
     * Unlike isPersonOnRoster() — which matches ANY person_memberships row
     * regardless of status, and is used during the matching phase to decide
     * already_on_roster vs. exact_match — this checks specifically for a
     * currently-active row via `filter[active_at]=now`, the same Ransack
     * predicate used by getRosterMembers() to scope the roster listing to
     * active members. A person can be flagged already_on_roster at matching
     * time (some assignment exists) yet have no ACTIVE assignment by the time
     * the sync job runs (e.g. their prior assignment already ended) — this
     * method is what the Direct Assignment sync path (AORM-9.20) uses to
     * decide whether a new assignment needs to be created.
     *
     * Queries `organization_memberships/{membership_uuid}/person_memberships`
     * filtered by `person_uuid_eq` AND `active_at=now`. Returns true when at
     * least one row exists, false otherwise (including on API error, missing
     * arguments, or unavailable client).
     *
     * @param string $personUuid     Person UUID to look up.
     * @param string $membershipUuid Org-membership UUID to scope the search.
     *
     * @see AORM-9.20
     */
    public function hasActivePersonMembershipAssignment(string $personUuid, string $membershipUuid): bool
    {
        if ($personUuid === '' || $membershipUuid === '') {
            return false;
        }

        $client = wicket_api_client();

        if (! $client) {
            return false;
        }

        $query    = http_build_query([
            'filter' => [
                'person_uuid_eq' => $personUuid,
                'active_at'      => 'now',
            ],
        ]);
        $endpoint = 'organization_memberships/' . $membershipUuid . '/person_memberships?' . $query;

        try {
            $response = $this->callWithRetry(fn () => $client->get($endpoint));

            return is_array($response) && ! empty($response['data']);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Create a Direct Assignment membership record linking a person to an
     * organization membership (roster).
     *
     * Unlike the Relationship path (a `connections` resource), the Direct
     * Assignment path links the person to the roster org via a
     * `person_memberships` resource — the same resource type the roster
     * listing itself is built from (see getRosterMembers()).
     *
     * Steps:
     *   1. Resolve the org membership's `memberships` (tier) resource ID via
     *      `GET organization_memberships/{membership_uuid}?include=membership`
     *      — required because `person_memberships` POSTs need a `membership`
     *      relationship pointing at the tier, not just the org membership.
     *   2. POST a `person_memberships` resource with `starts_at` set to the
     *      current sync action time (NOT the org membership's own cycle start
     *      date — deliberately different from the legacy
     *      wicket_assign_person_to_org_membership() helper in
     *      wicket-wp-base-plugin, which copies the org membership's cycle
     *      dates). `ends_at` is intentionally omitted so the assignment is
     *      open-ended; end-dating on removal is handled separately by the
     *      Direct Assignment Remove path (AORM-9.24).
     *
     * Does nothing when `$personUuid` or `$membershipUuid` is empty, or when
     * `wicket_api_client()` is unavailable.
     *
     * Throws on failure (including when the membership tier ID cannot be
     * resolved) so the caller (SyncService / SyncJobRunner) can record the
     * staged record as failed rather than silently losing the assignment.
     *
     * MDP payload shape:
     * ```json
     * {"data":{"type":"person_memberships","attributes":{"starts_at":"<iso8601>","status":"Active"},
     *   "relationships":{
     *     "person":{"data":{"type":"people","id":"<person_uuid>"}},
     *     "membership":{"data":{"type":"memberships","id":"<tier_id>"}},
     *     "organization_membership":{"data":{"type":"organization_memberships","id":"<membership_uuid>"}}
     *   }}}
     * ```
     *
     * @param string $personUuid     Person UUID.
     * @param string $membershipUuid Organization membership (roster) UUID.
     *
     * @throws \Exception When the tier ID cannot be resolved or the MDP POST call fails.
     *
     * @see AORM-9.18 — new_record: create membership assignment (roster org, start = today)
     */
    public function createPersonMembershipAssignment(string $personUuid, string $membershipUuid): void
    {
        if ($personUuid === '' || $membershipUuid === '') {
            return;
        }

        $client = wicket_api_client();

        if (! $client) {
            return;
        }

        try {
            $membershipTypeId = $this->resolveMembershipTypeId($client, $membershipUuid);

            if ($membershipTypeId === '') {
                throw new \RuntimeException('Could not resolve membership tier ID for organization_membership ' . $membershipUuid . '.');
            }

            $payload = [
                'data' => [
                    'type'          => 'person_memberships',
                    'attributes'    => [
                        'starts_at' => $this->currentActionTimestamp(),
                        'status'    => 'Active',
                    ],
                    'relationships' => [
                        'person'                   => [
                            'data' => [
                                'type' => 'people',
                                'id'   => $personUuid,
                            ],
                        ],
                        'membership'                => [
                            'data' => [
                                'type' => 'memberships',
                                'id'   => $membershipTypeId,
                            ],
                        ],
                        'organization_membership'   => [
                            'data' => [
                                'type' => 'organization_memberships',
                                'id'   => $membershipUuid,
                            ],
                        ],
                    ],
                ],
            ];

            $this->callWithRetry(fn () => $client->post('person_memberships', ['json' => $payload]));
        } catch (\Exception $e) {
            throw new \RuntimeException(
                'MDP membership assignment creation failed: ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    /**
     * Resolve the `memberships` (tier) resource ID for an organization membership.
     *
     * Reads `relationships.membership.data.id` from a single-record
     * `organization_memberships` response. Falls back to scanning `included`
     * for a sideloaded `memberships` / `membership` / `membership_types`
     * resource when the direct relationship pointer is absent — mirrors the
     * fallback used by wicket-wp-account-centre's
     * DirectAssignmentStrategy::assignPersonToMembershipSeat() for the same
     * resolution problem in the front-end self-service add-member flow.
     *
     * @param object $client         MDP API client (must be non-null).
     * @param string $membershipUuid Organization membership UUID.
     *
     * @return string  Membership tier resource ID, or '' when it cannot be resolved.
     */
    private function resolveMembershipTypeId(object $client, string $membershipUuid): string
    {
        $query    = http_build_query(['include' => 'membership']);
        $endpoint = 'organization_memberships/' . $membershipUuid . '?' . $query;

        $response = $this->callWithRetry(fn () => $client->get($endpoint));

        if (! is_array($response) || empty($response['data'])) {
            return '';
        }

        $membershipTypeId = (string) ($response['data']['relationships']['membership']['data']['id'] ?? '');

        if ($membershipTypeId !== '') {
            return $membershipTypeId;
        }

        foreach ($response['included'] ?? [] as $included) {
            $includedType = (string) ($included['type'] ?? '');

            if (in_array($includedType, ['memberships', 'membership', 'membership_types'], true)) {
                $includedId = (string) ($included['id'] ?? '');

                if ($includedId !== '') {
                    return $includedId;
                }
            }
        }

        return '';
    }

    /**
     * Current point-in-time timestamp in UTC, ISO-8601 formatted.
     *
     * Prefers wicket_time_get_current_iso8601_utc() (defined in
     * wicket-wp-base-plugin) so the timestamp format matches every other MDP
     * date attribute written by the Wicket ecosystem. Falls back to a plain
     * DateTimeImmutable formatting when the helper is unavailable (e.g. in
     * unit tests) — mirrors the identical fallback used by
     * ConnectionService::currentStartDate() / MembershipService::currentTimestamp()
     * in wicket-wp-account-centre.
     *
     * @see AORM-9.18
     */
    private function currentActionTimestamp(): string
    {
        if (function_exists('wicket_time_get_current_iso8601_utc')) {
            return wicket_time_get_current_iso8601_utc();
        }

        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
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
                $isRetryable   = $this->isRetryableException($e);

                if ($isLastAttempt || ! $isRetryable) {
                    if ($isLastAttempt && $isRetryable) {
                        error_log(sprintf(
                            '[wicket-aorm] MDP request failed after all %d attempts: %s',
                            self::RETRY_MAX_ATTEMPTS,
                            $e->getMessage(),
                        ));
                    }

                    throw $e;
                }

                // Exponential back-off: RETRY_BASE_DELAY_MS × 2^attempt (ms → µs).
                $delayMicroseconds = self::RETRY_BASE_DELAY_MS * (2 ** $attempt) * 1000;
                $delayMs           = self::RETRY_BASE_DELAY_MS * (2 ** $attempt);

                error_log(sprintf(
                    '[wicket-aorm] MDP request failed (attempt %d of %d), retrying in %dms: %s',
                    $attempt + 1,
                    self::RETRY_MAX_ATTEMPTS,
                    $delayMs,
                    $e->getMessage(),
                ));

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
        // Covers: cURL timeouts, Guzzle ConnectException (no getResponse()),
        // and OS-level connection failures.
        $message = strtolower($e->getMessage());

        return str_contains($message, 'timeout')
            || str_contains($message, 'timed out')
            || str_contains($message, 'could not connect')
            || str_contains($message, 'connection refused')
            || str_contains($message, 'connection reset')
            || str_contains($message, 'failed to connect');
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
     * Fetch the list of available email address types from the MDP.
     *
     * Attempts to read enum values from the MDP schema definitions endpoint.
     * Falls back to the well-known Wicket email types when the MDP client is
     * unavailable, the endpoint does not exist, or the response is malformed.
     *
     * Returns a map of {slug => display_label} suitable for rendering a
     * <select> on the settings page.
     *
     * @return array<string, string>  Map of slug → display label.
     *
     * @see AORM-11.7
     */
    public function getEmailTypes(): array
    {
        // Well-known Wicket MDP email types — used as the fallback when the
        // API is unreachable or returns an unexpected response format.
        $defaults = [
            'work'     => __('Work', 'wicket-aorm'),
            'home'     => __('Home', 'wicket-aorm'),
            'personal' => __('Personal', 'wicket-aorm'),
        ];

        if (! function_exists('wicket_get_resource_types')) {
            return $defaults;
        }

        try {
            $response = wicket_get_resource_types('emails');

            if (! is_array($response) || empty($response['data'])) {
                return $defaults;
            }

            $types = [];

            foreach ($response['data'] as $item) {
                $slug  = (string) ($item['attributes']['slug'] ?? '');
                $label = (string) ($item['attributes']['name'] ?? '');

                if ($slug === '') {
                    continue;
                }

                $types[$slug] = $label !== '' ? $label : ucfirst($slug);
            }

            return ! empty($types) ? $types : $defaults;
        } catch (\Exception $e) {
            return $defaults;
        }
    }

    /**
     * Fetch available phone number types from the MDP schema definitions endpoint.
     *
     * Queries `schema_definitions?filter[resource_type_eq]=phones&filter[field_name_eq]=phone_type`
     * and returns a slug → label map. Falls back to the well-known set
     * (work / home / mobile) when the MDP is unavailable or returns no data.
     *
     * @return array<string, string> Map of slug → human-readable label.
     *
     * @see AORM-11.8
     */
    public function getPhoneTypes(): array
    {
        // Well-known Wicket MDP phone types — used as the fallback when the
        // API is unreachable or returns an unexpected response format.
        $defaults = [
            'work'   => __('Work', 'wicket-aorm'),
            'home'   => __('Home', 'wicket-aorm'),
            'mobile' => __('Mobile', 'wicket-aorm'),
        ];

        if (! function_exists('wicket_get_resource_types')) {
            return $defaults;
        }

        try {
            $response = wicket_get_resource_types('phones');

            if (! is_array($response) || empty($response['data'])) {
                return $defaults;
            }

            $types = [];

            foreach ($response['data'] as $item) {
                $slug  = (string) ($item['attributes']['slug'] ?? '');
                $label = (string) ($item['attributes']['name'] ?? '');

                if ($slug === '') {
                    continue;
                }

                $types[$slug] = $label !== '' ? $label : ucfirst($slug);
            }

            return ! empty($types) ? $types : $defaults;
        } catch (\Exception $e) {
            return $defaults;
        }
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
     *     given_name: string,
     *     family_name: string,
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

            $members[] = [
                'person_uuid'                => $personRelId,
                'name'                       => (string) ($personAttrs['full_name'] ?? ''),
                'given_name'                 => (string) ($personAttrs['given_name'] ?? ''),
                'family_name'                => (string) ($personAttrs['family_name'] ?? ''),
                'email'                      => (string) ($personAttrs['primary_email_address'] ?? ''),
                'title'                      => (string) ($personAttrs['job_title'] ?? ''),
                // Populated by getRosterMembers() via fetchOrgScopedRolesAndPhones() —
                // this endpoint's `person` include has no phone attribute.
                'phone'                      => '',
                // Also populated by getRosterMembers() via fetchOrgScopedRolesAndPhones() —
                // not read from person.attributes.role_names, which is global (not
                // scoped to $org_uuid) and was misleading for a per-org roster.
                'roles'                      => [],
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
     *
     * `org_type` is passed through resolveOrgTypeLabel() to convert the raw
     * MDP slug (e.g. `company`) into its display label (e.g. `Company`).
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
            'org_type'              => $this->resolveOrgTypeLabel((string) ($orgAttrs['type'] ?? '')),
            'membership_uuid'       => (string) ($record['id'] ?? ''),
            'membership_tier'       => (string) ($membershipAttrs['name'] ?? ''),
            'membership_status'     => (string) ($attrs['status'] ?? ''),
            'membership_owner'      => $ownerName,
            'assigned_count'        => (int) ($attrs['active_assignments_count'] ?? 0),
            'max_assignments'       => $maxAssignments,
            'unlimited_assignments' => $unlimitedAssignments,
        ];
    }

    /**
     * Resolve an organization type slug to its human-friendly label.
     *
     * The `organizations` resource's `type` attribute is a slug (e.g.
     * `company`, `veterinary-clinic`) rather than a display-ready string.
     * This looks the slug up in `wicket_get_org_types_list()` (provided by
     * wicket-wp-base-plugin) and returns the matching resource type's `name`
     * attribute.
     *
     * Falls back to the raw slug — never blank — when the helper function
     * is unavailable, the call throws, the response is malformed, or no
     * matching slug is found, so the roster heading always shows something
     * even if the MDP resource_types list can't be fetched.
     *
     * @see AORM-4.2
     */
    private function resolveOrgTypeLabel(string $slug): string
    {
        if ($slug === '' || ! function_exists('wicket_get_org_types_list')) {
            return $slug;
        }

        try {
            $orgTypes = wicket_get_org_types_list();
        } catch (\Exception $e) {
            return $slug;
        }

        if (! is_array($orgTypes)) {
            return $slug;
        }

        foreach ($orgTypes as $orgType) {
            $orgTypeSlug = (string) ($orgType['attributes']['slug'] ?? '');

            if ($orgTypeSlug !== $slug) {
                continue;
            }

            $label = (string) ($orgType['attributes']['name'] ?? '');

            return $label !== '' ? $label : $slug;
        }

        return $slug;
    }
}
