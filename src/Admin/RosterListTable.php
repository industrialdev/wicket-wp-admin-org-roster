<?php

declare(strict_types=1);

namespace WicketAORM\Admin;

use WicketAORM\Database\RosterMetaTable;
use WicketAORM\Services\MdpClient;
use WP_List_Table;

/**
 * WP_List_Table subclass for the Organization Memberships list view.
 *
 * Renders all org membership records as rows in the WordPress admin table.
 * The primary entity is the org membership (not the org itself) — a single
 * org with multiple tiers appears as multiple rows.
 *
 * Columns (AORM-3.1):
 *   org_name          — Organization Name, links to the detail view
 *   membership_tier   — Membership Tier with end date
 *   assigned_count    — # Assigned (assigned / max seats)
 *   membership_status — Membership Status
 *   created           — Created date
 *   roster_status     — Roster Status (from wp_wicket_aorm_roster_meta)
 *   last_updated      — Last Updated date + user email (from wp_wicket_aorm_roster_meta)
 *   mdp_link          — Link to MDP record
 *
 * @see AORM-3
 */
class RosterListTable extends WP_List_Table
{
    /**
     * WordPress user-meta key used to persist the admin's per-page preference.
     *
     * Referenced by get_items_per_page() in prepare_items() and by
     * MenuPage::registerScreenOptions() when registering the Screen Options panel entry.
     *
     * @see AORM-3.7
     */
    public const SCREEN_OPTION_PER_PAGE = 'aorm_rosters_per_page';

    private MdpClient $mdpClient;

    private RosterMetaTable $rosterMetaTable;

    /**
     * @param array<string, mixed>  $args            Passed through to WP_List_Table.
     * @param MdpClient|null        $mdpClient        Injected MDP client; defaults to new MdpClient().
     * @param RosterMetaTable|null  $rosterMetaTable  Injected meta table; defaults to new RosterMetaTable().
     */
    public function __construct(
        array $args = [],
        ?MdpClient $mdpClient = null,
        ?RosterMetaTable $rosterMetaTable = null,
    ) {
        parent::__construct($args);
        $this->mdpClient       = $mdpClient ?? new MdpClient();
        $this->rosterMetaTable = $rosterMetaTable ?? new RosterMetaTable();
    }

    /**
     * Define all visible columns and their header labels.
     *
     * Keys must be stable — they are used as CSS class names, orderby query
     * args, and routing identifiers for column_*() render methods.
     *
     * @return array<string, string>
     */
    public function get_columns(): array
    {
        return [
            'org_name'          => __('Organization Name', 'wicket-aorm'),
            'membership_tier'   => __('Membership Tier', 'wicket-aorm'),
            'assigned_count'    => __('# Assigned', 'wicket-aorm'),
            'membership_status' => __('Membership Status', 'wicket-aorm'),
            'created'           => __('Created', 'wicket-aorm'),
            'roster_status'     => __('Roster Status', 'wicket-aorm'),
            'last_updated'      => __('Last Updated', 'wicket-aorm'),
            'mdp_link'          => __('MDP', 'wicket-aorm'),
        ];
    }

    /**
     * Declare which columns are sortable and which direction is the default.
     *
     * All 8 columns are sortable. The default sort is Last Updated descending
     * (newest first), indicated by passing true as the second element.
     *
     * @return array<string, array{string, bool}>
     */
    public function get_sortable_columns(): array
    {
        return [
            'org_name'          => ['org_name', false],
            'membership_tier'   => ['membership_tier', false],
            'assigned_count'    => ['assigned_count', false],
            'membership_status' => ['membership_status', false],
            'created'           => ['created', false],
            'roster_status'     => ['roster_status', false],
            'last_updated'      => ['last_updated', true], // default sort, newest first
            'mdp_link'          => ['mdp_link', false],
        ];
    }

    /**
     * Fetch org memberships from MDP, enrich with local DB data, and populate $this->items.
     *
     * Retrieves organization memberships with Active, Delayed, or Grace Period
     * status from the MDP via MdpClient::getOrgMemberships(). Normalises the
     * JSON:API response into flat item rows, then enriches each row with
     * roster_status, last_updated, last_updated_by, and last_synced_at from
     * wp_wicket_aorm_roster_meta using a single indexed IN query (AORM-3.3).
     *
     * Fields populated by later tickets:
     *   - column render logic  (AORM-3.8, AORM-3.9)
     *
     * @see AORM-3.2
     * @see AORM-3.3
     * @see AORM-3.4
     */
    public function prepare_items(): void
    {
        $this->_column_headers = [
            $this->get_columns(),
            [],
            $this->get_sortable_columns(),
        ];

        $perPage     = $this->get_items_per_page(self::SCREEN_OPTION_PER_PAGE, 20);
        $currentPage = $this->get_pagenum();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $orderby   = sanitize_key((string) ($_GET['orderby'] ?? 'last_updated'));
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $order     = strtolower(sanitize_key((string) ($_GET['order'] ?? 'desc'))) === 'asc' ? 'asc' : 'desc';
        $sortField = $this->mapColumnToSortField($orderby, $order);

        // AORM-3.4: The built-in WP search box submits the query as $_REQUEST['s'].
        // Sanitize and forward it to the MDP client so the API can filter results.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $search = sanitize_text_field((string) ($_REQUEST['s'] ?? ''));

        $response = $this->mdpClient->getOrgMemberships([
            'page'     => $currentPage,
            'per_page' => $perPage,
            'sort'     => $sortField,
            'search'   => $search,
        ]);

        $data       = (array) ($response['data'] ?? []);
        $included   = (array) ($response['included'] ?? []);
        $totalCount = (int) ($response['meta']['page']['total_count'] ?? count($data));

        $this->items = $this->normalizeItems($data, $included);
        $this->enrichFromLocalDb($this->items);

        $this->set_pagination_args([
            'total_items' => $totalCount,
            'per_page'    => $perPage,
            'total_pages' => (int) ceil($totalCount / max(1, $perPage)),
        ]);
    }

    // -------------------------------------------------------------------------
    // Column render methods
    // -------------------------------------------------------------------------

    /**
     * Fallback renderer for any column without a dedicated column_*() method.
     *
     * @param array<string, mixed> $item
     */
    protected function column_default($item, $columnName): string
    {
        return esc_html((string) ($item[$columnName] ?? ''));
    }

    /**
     * Render the Organization Name column.
     *
     * Links to the roster detail page, passing org_uuid and membership_uuid as
     * URL params so the React island can bootstrap the correct roster context.
     * Falls back to plain escaped text when either UUID is absent.
     *
     * @param array<string, mixed> $item
     * @see AORM-3.8
     */
    protected function column_org_name($item): string
    {
        $orgName        = (string) ($item['org_name'] ?? '');
        $orgUuid        = (string) ($item['org_uuid'] ?? '');
        $membershipUuid = (string) ($item['membership_uuid'] ?? '');

        if ($orgUuid === '' || $membershipUuid === '') {
            return esc_html($orgName);
        }

        $url = add_query_arg(
            [
                'page'            => MenuPage::DETAIL_SLUG,
                'org_uuid'        => $orgUuid,
                'membership_uuid' => $membershipUuid,
            ],
            admin_url('admin.php'),
        );

        return '<a href="' . esc_url($url) . '">' . esc_html($orgName) . '</a>';
    }

    /**
     * Render the Membership Tier column with the end date.
     *
     * TODO (AORM-3.x): format as "Tier Name (ends YYYY-MM-DD)".
     *
     * @param array<string, mixed> $item
     */
    protected function column_membership_tier($item): string
    {
        return esc_html((string) ($item['membership_tier'] ?? ''));
    }

    /**
     * Render the # Assigned column.
     *
     * TODO (AORM-3.x): format as "assigned / max_seats".
     *
     * @param array<string, mixed> $item
     */
    protected function column_assigned_count($item): string
    {
        return esc_html((string) ($item['assigned_count'] ?? ''));
    }

    /**
     * Render the Membership Status column.
     *
     * @param array<string, mixed> $item
     */
    protected function column_membership_status($item): string
    {
        return esc_html((string) ($item['membership_status'] ?? ''));
    }

    /**
     * Render the Created column.
     *
     * TODO (AORM-3.x): format date for display.
     *
     * @param array<string, mixed> $item
     */
    protected function column_created($item): string
    {
        return esc_html((string) ($item['created'] ?? ''));
    }

    /**
     * Render the Roster Status column.
     *
     * Value sourced from wp_wicket_aorm_roster_meta.roster_status (AORM-3.3).
     *
     * @param array<string, mixed> $item
     */
    protected function column_roster_status($item): string
    {
        return esc_html((string) ($item['roster_status'] ?? ''));
    }

    /**
     * Render the Last Updated column.
     *
     * Shows the date and the user email from wp_wicket_aorm_roster_meta (AORM-3.3).
     *
     * TODO (AORM-3.x): format as "YYYY-MM-DD · user@example.com".
     *
     * @param array<string, mixed> $item
     */
    protected function column_last_updated($item): string
    {
        return esc_html((string) ($item['last_updated'] ?? ''));
    }

    /**
     * Render the MDP link column.
     *
     * Builds an external link to the membership assignment view in the MDP admin app
     * using the wicket_admin URL from get_wicket_settings().
     * Returns an empty string when either UUID or the app base URL is unavailable.
     *
     * @param array<string, mixed> $item
     */
    protected function column_mdp_link($item): string
    {
        $orgUuid        = (string) ($item['org_uuid'] ?? '');
        $membershipUuid = (string) ($item['membership_uuid'] ?? '');

        if ($orgUuid === '' || $membershipUuid === '') {
            return '';
        }

        $wicketSettings = (array) get_wicket_settings();
        $appBaseUrl     = rtrim((string) ($wicketSettings['wicket_admin'] ?? ''), '/');

        if ($appBaseUrl === '') {
            return '';
        }

        $url = sprintf(
            '%s/organizations/%s/memberships/%s',
            $appBaseUrl,
            rawurlencode($orgUuid),
            rawurlencode($membershipUuid),
        );

        return sprintf(
            '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
            esc_url($url),
            esc_html__('View in MDP', 'wicket-aorm'),
        );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Map a WP_List_Table column key + sort direction to an MDP sort field.
     *
     * JSON:API convention: prefix '-' for descending (e.g. '-updated_at').
     * Columns that have no direct MDP equivalent fall back to 'updated_at'.
     *
     * @param string $orderby WP column key (from $_GET['orderby']).
     * @param string $order   'asc' or 'desc'.
     */
    private function mapColumnToSortField(string $orderby, string $order): string
    {
        $columnMap = [
            'org_name'          => 'organization_legal_name',
            'membership_tier'   => 'membership_name',
            'assigned_count'    => 'assigned_count',
            'membership_status' => 'status',
            'created'           => 'created_at',
            'roster_status'     => 'updated_at', // local-only; fall back to MDP updated_at
            'last_updated'      => 'updated_at',
            'mdp_link'          => 'updated_at', // no meaningful MDP sort; use fallback
        ];

        $field = $columnMap[$orderby] ?? 'updated_at';

        return $order === 'asc' ? $field : '-' . $field;
    }

    /**
     * Normalize a JSON:API response into flat item rows keyed by column name.
     *
     * Resolves `organization` and `membership` includes so column renderers
     * can read org_name and membership_tier directly from the item array
     * without knowledge of the JSON:API envelope.
     *
     * roster_status, last_updated, last_updated_by, and last_synced_at are
     * initialized to empty strings here; enrichFromLocalDb() overwrites them
     * with values from wp_wicket_aorm_roster_meta (AORM-3.3).
     *
     * @param list<array<string,mixed>> $data     JSON:API primary resource array.
     * @param list<array<string,mixed>> $included JSON:API included resources array.
     * @return list<array<string,mixed>>
     */
    private function normalizeItems(array $data, array $included): array
    {
        // Index included resources by type → id for O(1) resolution.
        $index = [];

        foreach ($included as $resource) {
            $type = (string) ($resource['type'] ?? '');
            $id   = (string) ($resource['id'] ?? '');

            if ($type !== '' && $id !== '') {
                $index[$type][$id] = $resource;
            }
        }

        $items = [];

        foreach ($data as $record) {
            $attrs = (array) ($record['attributes'] ?? []);
            $rels  = (array) ($record['relationships'] ?? []);

            // Resolve related organization.
            $orgId    = (string) ($rels['organization']['data']['id'] ?? '');
            $orgAttrs = (array) ($index['organizations'][$orgId]['attributes'] ?? []);

            // Resolve related membership tier.
            $membershipId    = (string) ($rels['membership']['data']['id'] ?? '');
            $membershipAttrs = (array) ($index['memberships'][$membershipId]['attributes'] ?? []);

            $items[] = [
                'org_uuid'          => $orgId,
                'org_name'          => (string) ($orgAttrs['legal_name'] ?? ''),
                'membership_uuid'   => (string) ($record['id'] ?? ''),
                'membership_tier'   => (string) ($membershipAttrs['name'] ?? ''),
                'assigned_count'    => (string) ($attrs['assigned_count'] ?? ''),
                'membership_status' => (string) ($attrs['status'] ?? ''),
                'created'           => (string) ($attrs['created_at'] ?? ''),
                'roster_status'     => '',
                'last_updated'      => '',
                'last_updated_by'   => '',
                'last_synced_at'    => '',
                'mdp_link'          => $orgId, // column_mdp_link() renders link in AORM-3.9
            ];
        }

        return $items;
    }

    /**
     * Enrich normalized items with local roster meta from wp_wicket_aorm_roster_meta.
     *
     * Extracts all membership UUIDs from the current page of items, issues a
     * single IN query via RosterMetaTable (which hits the unique membership_uuid
     * index), then merges the matching DB row into each item.
     *
     * Fields written per item:
     *   - roster_status   — ENUM value from roster_meta.roster_status
     *   - last_updated    — raw DATETIME from roster_meta.last_updated_at
     *   - last_updated_by — email / username from roster_meta.last_updated_by
     *   - last_synced_at  — raw DATETIME from roster_meta.last_synced_at (nullable)
     *
     * Items whose membership_uuid has no local row are left with empty strings.
     *
     * @param list<array<string,mixed>> $items Items array from normalizeItems(), passed by reference.
     *
     * @see AORM-3.3
     */
    private function enrichFromLocalDb(array &$items): void
    {
        if (empty($items)) {
            return;
        }

        /** @var list<string> $uuids */
        $uuids = array_values(array_column($items, 'membership_uuid'));
        $meta  = $this->rosterMetaTable->getByMembershipUuids($uuids);

        foreach ($items as &$item) {
            $uuid = (string) ($item['membership_uuid'] ?? '');
            $row  = $meta[$uuid] ?? null;

            if ($row === null) {
                continue;
            }

            $item['roster_status']   = (string) ($row['roster_status'] ?? '');
            $item['last_updated']    = (string) ($row['last_updated_at'] ?? '');
            $item['last_updated_by'] = (string) ($row['last_updated_by'] ?? '');
            $item['last_synced_at']  = (string) ($row['last_synced_at'] ?? '');
        }

        unset($item);
    }
}
