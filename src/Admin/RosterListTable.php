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
 *   created           — Created date (MDP organization_membership.created_at)
 *   mdp_updated_at    — Updated At (MDP organization_membership.updated_at) — the only
 *                        column the MDP API can genuinely sort/paginate by, so it carries
 *                        the sortable + default-sort behavior
 *   roster_status     — Roster Status (from wp_wicket_aorm_roster_meta)
 *   last_updated      — Roster Last Saved date + user email (from wp_wicket_aorm_roster_meta);
 *                        local-only data, display-only (not sortable) since the MDP API has
 *                        no knowledge of it and can't sort/paginate by it server-side
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

    /**
     * GET query-arg name for the "Cascadeable" dropdown filter rendered in
     * extra_tablenav(). Submitted via the list table's <form method="get">
     * (see MenuPage::renderOrgRostersPage()), so it round-trips automatically
     * alongside sorting, search, and pagination.
     */
    public const CASCADEABLE_FILTER_PARAM = 'is_cascadeable';

    /**
     * Value of CASCADEABLE_FILTER_PARAM that restricts the list to
     * cascadeable orgs ("Cascadeable Only").
     */
    public const CASCADEABLE_FILTER_VALUE = '1';

    /**
     * Value of CASCADEABLE_FILTER_PARAM that restricts the list to
     * non-cascadeable orgs ("Non-Cascadeable Only"). Any value other than
     * this or CASCADEABLE_FILTER_VALUE (including absence of the param)
     * means "All Rosters".
     */
    public const NON_CASCADEABLE_FILTER_VALUE = '0';

    /**
     * GET query-arg name for the "Roster Status" dropdown filter. Value is
     * one of the ROSTER_STATUS_LABELS keys, or '' / absent for "All".
     */
    public const ROSTER_STATUS_FILTER_PARAM = 'roster_status_filter';

    /**
     * GET query-arg name for the "Membership Status" dropdown filter. Value
     * is one of the MEMBERSHIP_STATUS_LABELS keys, or '' / absent for "All".
     */
    public const MEMBERSHIP_STATUS_FILTER_PARAM = 'membership_status_filter';

    /**
     * GET query-arg name for the "Membership Tier" dropdown filter. Value is
     * an exact tier name (see MdpClient::getDistinctMembershipTiers()), or ''
     * / absent for "All".
     */
    public const MEMBERSHIP_TIER_FILTER_PARAM = 'membership_tier_filter';

    /**
     * Sentinel ROSTER_STATUS_FILTER_PARAM value meaning "no roster_meta row
     * exists yet" — not a stored roster_status ENUM value. See
     * RosterMetaTable::getAllTrackedMembershipUuids().
     */
    public const ROSTER_STATUS_NOT_STARTED = 'not_started';

    /**
     * Sentinel MEMBERSHIP_STATUS_FILTER_PARAM value meaning "in_grace = 1",
     * regardless of the underlying MDP `status` attribute. Matches the
     * derived "Grace Period" state column_membership_status() renders in
     * place of the raw status.
     */
    public const MEMBERSHIP_STATUS_GRACE_PERIOD = 'grace_period';

    /**
     * Roster Status filter dropdown options, value => label. Order here is
     * the order rendered in the <select>. 'not_started' is a sentinel (see
     * ROSTER_STATUS_NOT_STARTED); the remaining five are the real
     * wp_wicket_aorm_roster_meta.roster_status ENUM values.
     *
     * @var array<string, string>
     */
    private const ROSTER_STATUS_LABELS = [
        'not_started'  => 'Not Started',
        'idle'         => 'Idle',
        'in_progress'  => 'In Progress',
        'syncing'      => 'Syncing',
        'has_failures' => 'Has Failures',
        'synced'       => 'Synced',
    ];

    /**
     * Membership Status filter dropdown options, value => label.
     * 'grace_period' is a sentinel (see MEMBERSHIP_STATUS_GRACE_PERIOD); the
     * other two are the raw MDP organization_membership `status` values this
     * list is already scoped to (see MdpClient::getOrgMemberships()).
     *
     * @var array<string, string>
     */
    private const MEMBERSHIP_STATUS_LABELS = [
        'Active'       => 'Active',
        'Delayed'      => 'Delayed',
        'grace_period' => 'Grace Period',
    ];

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
            'mdp_updated_at'    => __('Updated At', 'wicket-aorm'),
            'roster_status'     => __('Roster Status', 'wicket-aorm'),
            'last_updated'      => __('Roster Last Saved', 'wicket-aorm'),
            'mdp_link'          => __('MDP', 'wicket-aorm'),
        ];
    }

    /**
     * Declare which columns are sortable and which direction is the default.
     *
     * 6 of the 9 columns are sortable. The default sort is Updated At
     * descending (newest first), indicated by passing true as the second
     * element. Membership Status sorts by the MDP `status` attribute only —
     * the derived "Grace Period" label (in_grace) is not a separate sort
     * group, so grace-period rows sort alongside their underlying status.
     * "Roster Last Saved" (last_updated) is intentionally NOT
     * sortable here — it is sourced from the local wp_wicket_aorm_roster_meta
     * table, which the MDP API (the source of pagination/sorting for this
     * table) has no knowledge of and cannot sort or paginate by server-side.
     *
     * @return array<string, array{string, bool}>
     */
    public function get_sortable_columns(): array
    {
        return [
            'org_name'          => ['org_name', false],
            'membership_tier'   => ['membership_tier', false],
            'created'           => ['created', false],
            'mdp_updated_at'    => ['mdp_updated_at', true], // default sort, newest first
            'roster_status'     => ['roster_status', false],
            'membership_status' => ['membership_status', false],
        ];
    }

    /**
     * Fetch org memberships from MDP, enrich with local DB data, and populate $this->items.
     *
     * Retrieves only "current" organization memberships (Active or Delayed —
     * Inactive/lapsed memberships are excluded server-side via
     * `filter[status_in][]=Active&…=Delayed` in MdpClient::getOrgMemberships();
     * see that method's docblock for why a boolean `active_eq` filter was
     * tried first and rejected). Normalises the JSON:API response into flat
     * item rows, then enriches each row with roster_status, last_updated, last_updated_by,
     * and last_synced_at from wp_wicket_aorm_roster_meta using a single
     * indexed IN query (AORM-3.3).
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
        $orderby   = sanitize_key((string) ($_GET['orderby'] ?? 'mdp_updated_at'));
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $order     = strtolower(sanitize_key((string) ($_GET['order'] ?? 'desc'))) === 'asc' ? 'asc' : 'desc';
        $sortField = $this->mapColumnToSortField($orderby, $order);

        // AORM-3.4: The built-in WP search box submits the query as $_REQUEST['s'].
        // Sanitize and forward it to the MDP client so the API can filter results.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $search = sanitize_text_field((string) ($_REQUEST['s'] ?? ''));

        // Roster Status filter: resolve the local-only roster_status value
        // into a set of membership UUIDs to include/exclude from the MDP
        // query, since roster_status itself lives only in
        // wp_wicket_aorm_roster_meta and the MDP has no knowledge of it.
        // When the selected status matches zero local rows, no UUID list
        // could ever satisfy an `uuid_in` filter (an empty filter value is
        // indistinguishable from "no filter" to the MDP), so that case is
        // short-circuited to an empty result set below instead.
        $uuidIn           = null;
        $uuidNotIn        = null;
        $rosterStatusFilter = $this->currentFilterValue(self::ROSTER_STATUS_FILTER_PARAM);
        $forceEmptyResult   = false;

        if ($rosterStatusFilter !== '' && isset(self::ROSTER_STATUS_LABELS[$rosterStatusFilter])) {
            if ($rosterStatusFilter === self::ROSTER_STATUS_NOT_STARTED) {
                $tracked = $this->rosterMetaTable->getAllTrackedMembershipUuids();

                if (! empty($tracked)) {
                    $uuidNotIn = $tracked;
                }
            } else {
                $matching = $this->rosterMetaTable->getMembershipUuidsByStatus($rosterStatusFilter);

                if (empty($matching)) {
                    $forceEmptyResult = true;
                } else {
                    $uuidIn = $matching;
                }
            }
        }

        if ($forceEmptyResult) {
            $this->items = [];

            $this->set_pagination_args([
                'total_items' => 0,
                'per_page'    => $perPage,
                'total_pages' => 0,
            ]);

            return;
        }

        $response = $this->mdpClient->getOrgMemberships([
            'page'                 => $currentPage,
            'per_page'             => $perPage,
            'sort'                 => $sortField,
            'search'               => $search,
            'cascadeable_only'     => $this->isCascadeableFilterActive(),
            'non_cascadeable_only' => $this->isNonCascadeableFilterActive(),
            'membership_status'    => $this->currentFilterValue(self::MEMBERSHIP_STATUS_FILTER_PARAM),
            'membership_tier'      => $this->currentFilterValue(self::MEMBERSHIP_TIER_FILTER_PARAM),
            'uuid_in'              => $uuidIn,
            'uuid_not_in'          => $uuidNotIn,
        ]);

        $data       = (array) ($response['data'] ?? []);
        $included   = (array) ($response['included'] ?? []);
        $totalCount = (int) ($response['meta']['page']['total_items'] ?? count($data));

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

        $output = '<strong><a class="row-title" href="' . esc_url($url) . '">' . esc_html($orgName) . '</a></strong>';
        $output .= '<div class="row-actions"><span class="edit"><a href="' . esc_url($url) . '">' . esc_html__('Edit Roster', 'wicket-aorm') . '</a></span></div>';

        return $output;
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
        $tier   = esc_html((string) ($item['membership_tier'] ?? ''));
        $endsAt = (string) ($item['membership_ends_at'] ?? '');

        if ($endsAt === '') {
            return $tier;
        }

        $dateLabel = esc_html($this->formatDate($endsAt));

        return $tier . '<br /><small>' . esc_html__('End Date:', 'wicket-aorm') . ' ' . $dateLabel . '</small>';
    }

    /**
     * Render the # Assigned column.
     *
     * Renders as "active / max" (e.g. "200 / 350"), or "active / ∞" when the
     * membership has unlimited assignments, or just "active" when neither
     * max_assignments nor unlimited_assignments is set.
     *
     * @param array<string, mixed> $item
     */
    protected function column_assigned_count($item): string
    {
        $active    = (int) ($item['active_assignments_count'] ?? 0);
        $max       = $item['max_assignments'] ?? null;
        $unlimited = (bool) ($item['unlimited_assignments'] ?? false);

        if ($unlimited) {
            return esc_html($active . ' / ∞');
        }

        if ($max !== null) {
            return esc_html($active . ' / ' . (int) $max);
        }

        return esc_html((string) $active);
    }

    /**
     * Render the Membership Status column.
     *
     * Grace-period rows (in_grace = true) render as "Active (Grace Period)"
     * in place of the raw MDP status, making clear the membership is still
     * active while in its grace window.
     *
     * @param array<string, mixed> $item
     */
    protected function column_membership_status($item): string
    {
        if ((bool) ($item['in_grace'] ?? false)) {
            return esc_html__('Active (Grace Period)', 'wicket-aorm');
        }

        return esc_html((string) ($item['membership_status'] ?? ''));
    }

    /**
     * Render the Created column.
     *
     * Parses the ISO 8601 timestamp returned by the MDP and formats it as
     * YYYY-MM-DD. Returns an empty string when no value is set or the value
     * cannot be parsed.
     *
     * @param array<string, mixed> $item
     */
    protected function column_created($item): string
    {
        return esc_html($this->formatDate((string) ($item['created'] ?? '')));
    }

    /**
     * Render the Updated At column.
     *
     * Parses the ISO 8601 timestamp returned by the MDP for the org
     * membership resource's own `updated_at` attribute and formats it as
     * YYYY-MM-DD. Distinct from "Roster Last Saved" (last_updated), which is
     * a local wp_wicket_aorm_roster_meta value tracking AORM's own roster
     * actions — this column reflects MDP-side mutations only. Returns an
     * empty string when no value is set or the value cannot be parsed.
     *
     * @param array<string, mixed> $item
     */
    protected function column_mdp_updated_at($item): string
    {
        return esc_html($this->formatDate((string) ($item['mdp_updated_at'] ?? '')));
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
        $status = (string) ($item['roster_status'] ?? '');

        if ($status === '') {
            return '<span class="description" aria-label="' . esc_attr__('No roster activity yet', 'wicket-aorm') . '">—</span>';
        }

        return esc_html($status);
    }

    /**
     * Render the Roster Last Saved column (array/column key remains `last_updated`).
     *
     * Shows the date from wp_wicket_aorm_roster_meta.last_updated_at, plus a
     * second line with the acting user's email from
     * wp_wicket_aorm_roster_meta.last_updated_by (e.g. "By: alex@wicket.io") —
     * same `<br /><small>...</small>` pattern used by column_membership_tier()
     * for its "End Date:" sub-line. last_updated_by may also be "system"
     * (background job, no logged-in user) or "user:{id}" (user has no email)
     * per ActivityLogger::resolveActorName() — both are rendered as-is. The
     * "By:" line is omitted entirely when last_updated_by is empty (e.g. rows
     * written before this field existed). Not sortable — see
     * get_sortable_columns() docblock.
     *
     * TODO (AORM-3.x): format date via formatDate().
     *
     * @param array<string, mixed> $item
     */
    protected function column_last_updated($item): string
    {
        $lastUpdated = (string) ($item['last_updated'] ?? '');

        if ($lastUpdated === '') {
            return '<span class="description" aria-label="' . esc_attr__('Never updated', 'wicket-aorm') . '">—</span>';
        }

        $output = esc_html($lastUpdated);

        $lastUpdatedBy = (string) ($item['last_updated_by'] ?? '');

        if ($lastUpdatedBy !== '') {
            $output .= '<br /><small>' . esc_html__('By:', 'wicket-aorm') . ' ' . esc_html($lastUpdatedBy) . '</small>';
        }

        return $output;
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
            '<a href="%s" target="_blank" rel="noopener noreferrer">%s <span class="dashicons dashicons-external"></span></a>',
            esc_url($url),
            esc_html__('View in MDP', 'wicket-aorm'),
        );
    }

    /**
     * Render extra controls in the table navigation row.
     *
     * Adds a "Cascadeable" dropdown filter to the top tablenav — a <select>
     * with "All Rosters" / "Cascadeable Only" (`1`) / "Non-Cascadeable Only"
     * (`0`) options plus a "Filter" submit
     * button, matching the native WordPress admin pattern used for dropdown
     * filters elsewhere in core (e.g. the category filter on the Posts list
     * table). Rendered inside the same <form method="get"> that wraps the
     * whole list table (MenuPage::renderOrgRostersPage()), so selecting a
     * value and clicking Filter resubmits the page with
     * `?is_cascadeable=1` alongside the existing sort/search/pagination args.
     *
     * Only rendered for $which === 'top' — WordPress core never repeats
     * filter dropdowns in the bottom tablenav.
     *
     * Also renders three further dropdowns using the same "native WP filter
     * dropdown" pattern, each independently combinable with the others, with
     * Cascadeable, and with search/sort/pagination (all round-trip via the
     * same <form method="get">):
     *   - Roster Status     (ROSTER_STATUS_FILTER_PARAM)     — local data
     *   - Membership Tier   (MEMBERSHIP_TIER_FILTER_PARAM)   — MDP data
     *   - Membership Status (MEMBERSHIP_STATUS_FILTER_PARAM) — MDP data
     * A single shared "Filter" submit button applies all of them at once.
     *
     * @param string $which 'top' or 'bottom'.
     */
    protected function extra_tablenav($which): void
    {
        if ($which !== 'top') {
            return;
        }

        $isActive    = $this->isCascadeableFilterActive();
        $isNonActive = $this->isNonCascadeableFilterActive();

        echo '<div class="alignleft actions">';
        echo '<label for="aorm-cascadeable-filter" class="screen-reader-text">' . esc_html__('Filter by cascadeable', 'wicket-aorm') . '</label>';
        echo '<select name="' . esc_attr(self::CASCADEABLE_FILTER_PARAM) . '" id="aorm-cascadeable-filter">';
        echo '<option value="">' . esc_html__('All Rosters', 'wicket-aorm') . '</option>';
        echo '<option value="' . esc_attr(self::CASCADEABLE_FILTER_VALUE) . '"' . ($isActive ? ' selected="selected"' : '') . '>' . esc_html__('Cascadeable Only', 'wicket-aorm') . '</option>';
        echo '<option value="' . esc_attr(self::NON_CASCADEABLE_FILTER_VALUE) . '"' . ($isNonActive ? ' selected="selected"' : '') . '>' . esc_html__('Non-Cascadeable Only', 'wicket-aorm') . '</option>';
        echo '</select>';

        $this->renderSelectFilter(
            self::ROSTER_STATUS_FILTER_PARAM,
            __('All Roster Statuses', 'wicket-aorm'),
            self::ROSTER_STATUS_LABELS,
            __('Filter by roster status', 'wicket-aorm'),
        );

        $this->renderSelectFilter(
            self::MEMBERSHIP_TIER_FILTER_PARAM,
            __('All Membership Tiers', 'wicket-aorm'),
            $this->buildMembershipTierOptions(),
            __('Filter by membership tier', 'wicket-aorm'),
        );

        $this->renderSelectFilter(
            self::MEMBERSHIP_STATUS_FILTER_PARAM,
            __('All Membership Statuses', 'wicket-aorm'),
            self::MEMBERSHIP_STATUS_LABELS,
            __('Filter by membership status', 'wicket-aorm'),
        );

        echo '<input type="submit" name="filter_action" id="aorm-cascadeable-filter-submit" class="button" value="' . esc_attr__('Filter', 'wicket-aorm') . '" />';
        echo '</div>';
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Whether the "Cascadeable Only" filter is currently active, based on
     * the CASCADEABLE_FILTER_PARAM GET query-arg.
     *
     * @see CASCADEABLE_FILTER_PARAM
     */
    private function isCascadeableFilterActive(): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return (string) ($_GET[self::CASCADEABLE_FILTER_PARAM] ?? '') === self::CASCADEABLE_FILTER_VALUE;
    }

    /**
     * Whether the "Non-Cascadeable Only" filter is currently active, based on
     * the CASCADEABLE_FILTER_PARAM GET query-arg.
     *
     * @see NON_CASCADEABLE_FILTER_VALUE
     */
    private function isNonCascadeableFilterActive(): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return (string) ($_GET[self::CASCADEABLE_FILTER_PARAM] ?? '') === self::NON_CASCADEABLE_FILTER_VALUE;
    }

    /**
     * Read and sanitize the current value of a GET-based dropdown filter.
     *
     * Uses sanitize_text_field() rather than sanitize_key() so exact-match
     * values containing spaces/mixed case (e.g. a membership tier name like
     * "Gold Tier") survive the round trip unchanged.
     *
     * @param string $param GET query-arg name.
     */
    private function currentFilterValue(string $param): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return sanitize_text_field((string) ($_GET[$param] ?? ''));
    }

    /**
     * Render a single <select> filter dropdown, matching the native
     * WordPress admin dropdown-filter markup (label, "All …" default option,
     * one <option> per value => label pair, current value pre-selected).
     *
     * @param array<string, string> $options Value => label.
     */
    private function renderSelectFilter(string $param, string $allLabel, array $options, string $srLabel): void
    {
        $current = $this->currentFilterValue($param);

        echo '<label for="aorm-' . esc_attr($param) . '-filter" class="screen-reader-text">' . esc_html($srLabel) . '</label>';
        echo '<select name="' . esc_attr($param) . '" id="aorm-' . esc_attr($param) . '-filter">';
        echo '<option value="">' . esc_html($allLabel) . '</option>';

        foreach ($options as $value => $label) {
            printf(
                '<option value="%1$s"%2$s>%3$s</option>',
                esc_attr((string) $value),
                $current === (string) $value ? ' selected="selected"' : '',
                esc_html((string) $label),
            );
        }

        echo '</select>';
    }

    /**
     * Build the Membership Tier dropdown's value => label options from
     * MdpClient::getDistinctMembershipTiers() (the MDP's `memberships`
     * catalog). Both value and label are the tier name itself — the filter
     * matches on exact name (see MdpClient::getOrgMemberships()'s
     * `membership_name_en_eq`), and there is no separate id to key on here.
     *
     * @return array<string, string>
     */
    private function buildMembershipTierOptions(): array
    {
        $options = [];

        foreach ($this->mdpClient->getDistinctMembershipTiers() as $tierName) {
            $options[$tierName] = $tierName;
        }

        return $options;
    }

    /**
     * Parse an ISO 8601 date string and return it formatted as YYYY-MM-DD,
     * using the site's configured timezone via wp_date().
     *
     * Used by column renderers to normalise the raw UTC timestamps returned by
     * the MDP API (e.g. "2026-12-31T00:00:00.000Z") into a date string that
     * reflects the WordPress site timezone — so admins see dates that match
     * their locale rather than raw UTC.
     *
     * Returns an empty string when the input is empty, cannot be parsed by
     * strtotime(), or wp_date() fails, so column renderers never output garbage.
     *
     * @param string $isoDate Raw ISO 8601 string from the MDP API.
     */
    private function formatDate(string $isoDate): string
    {
        if ($isoDate === '') {
            return '';
        }

        $timestamp = strtotime($isoDate);

        if ($timestamp === false) {
            return '';
        }

        return wp_date('Y-m-d', $timestamp) ?: '';
    }

    /**
     * Map a WP_List_Table column key + sort direction to an MDP sort field.
     *
     * JSON:API convention: prefix '-' for descending (e.g. '-updated_at').
     * Columns that have no direct MDP equivalent fall back to 'updated_at'.
     * `last_updated` (Roster Last Saved) is intentionally absent from this map
     * and from get_sortable_columns() — it is a local-only value the MDP API
     * cannot sort or paginate by; any orderby value not present here (including
     * a manually-crafted `?orderby=last_updated`) falls back to 'updated_at'.
     *
     * @param string $orderby WP column key (from $_GET['orderby']).
     * @param string $order   'asc' or 'desc'.
     */
    private function mapColumnToSortField(string $orderby, string $order): string
    {
        $columnMap = [
            'org_name'        => 'organization_legal_name_en',
            'membership_tier' => 'membership_name_en',
            'created'         => 'created_at',
            'mdp_updated_at'    => 'updated_at',
            'membership_status' => 'status',
            'roster_status'     => 'updated_at', // local-only; fall back to MDP updated_at
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
                'org_uuid'            => $orgId,
                'org_name'            => (string) ($orgAttrs['legal_name'] ?? ''),
                'membership_uuid'     => (string) ($record['id'] ?? ''),
                'membership_tier'     => (string) ($membershipAttrs['name'] ?? ''),
                'membership_ends_at'      => (string) ($attrs['ends_at'] ?? ''),
                'active_assignments_count' => (int) ($attrs['active_assignments_count'] ?? 0),
                'max_assignments'          => isset($attrs['max_assignments']) ? (int) $attrs['max_assignments'] : null,
                'unlimited_assignments'    => (bool) ($attrs['unlimited_assignments'] ?? false),
                'membership_status'   => (string) ($attrs['status'] ?? ''),
                'in_grace'            => (bool) ($attrs['in_grace'] ?? false),
                'created'             => (string) ($attrs['created_at'] ?? ''),
                'mdp_updated_at'      => (string) ($attrs['updated_at'] ?? ''),
                'roster_status'       => '',
                'last_updated'        => '',
                'last_updated_by'     => '',
                'last_synced_at'      => '',
                'mdp_link'            => $orgId, // column_mdp_link() renders link in AORM-3.9
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
