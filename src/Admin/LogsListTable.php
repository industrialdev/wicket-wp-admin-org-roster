<?php

declare(strict_types=1);

namespace WicketAORM\Admin;

use WicketAORM\Database\ActivityLogTable;
use WicketAORM\Services\MdpClient;
use WP_List_Table;

/**
 * WP_List_Table subclass for the Global Logs admin page.
 *
 * Renders every row in wp_wicket_aorm_logs across ALL rosters (unlike the
 * per-roster "Activity" tab, which uses ActivityLogTable::getEntries()
 * scoped to a single org/membership). No React — plain PHP, same
 * architectural pattern as RosterListTable (AORM-3).
 *
 * Columns (AORM-10.3): date/time, organization, membership tier, actor,
 * actor type, activity type, summary, status, plus a non-sortable "Details"
 * toggle column that expands a hidden row showing the raw log context JSON
 * (AORM-10.7, inline toggle — no AJAX).
 *
 * wp_wicket_aorm_logs has no membership_uuid, org_name, or membership_tier
 * columns. Per this ticket's explicit scope, none of those are added:
 *   - membership_uuid is resolved by ActivityLogTable::getGlobalEntries()
 *     from fields that already exist (object_id when object_type='roster',
 *     otherwise a lookup against wp_wicket_aorm_staged_records.membership_uuid
 *     keyed by the existing upload_session_id column).
 *   - org_name / membership_tier are resolved here, once per distinct
 *     membership_uuid on the page, via the existing
 *     MdpClient::getOrgMembershipDetail() call (memoized in
 *     $membershipDetailCache to avoid redundant MDP round-trips).
 *
 * Filter dropdowns (AORM-10.4 — Organization, Membership Tier, Activity Type)
 * are populated from DISTINCT values already present in wp_wicket_aorm_logs
 * (via ActivityLogTable::getDistinctOrgUuids()/getDistinctMembershipUuids()/
 * getDistinctActions()), not the full MDP catalog — so the dropdowns only
 * ever list organizations/tiers/activity types that actually have log
 * activity. The Status filter is a closed 4-value ENUM (`level`), so its
 * options are hardcoded (LEVEL_LABELS), same as RosterListTable's
 * "Cascadeable" dropdown.
 *
 * @see AORM-10
 */
class LogsListTable extends WP_List_Table
{
    /** GET query-arg name for the Organization filter dropdown. */
    public const ORG_FILTER_PARAM = 'log_org';

    /** GET query-arg name for the Membership Tier filter dropdown. */
    public const TIER_FILTER_PARAM = 'log_membership';

    /** GET query-arg name for the Activity Type filter dropdown. */
    public const ACTION_FILTER_PARAM = 'log_action';

    /** GET query-arg name for the Status (level) filter dropdown. */
    public const LEVEL_FILTER_PARAM = 'log_level';

    /** GET query-arg name for the date-range "from" input. */
    public const DATE_FROM_PARAM = 'log_date_from';

    /** GET query-arg name for the date-range "to" input. */
    public const DATE_TO_PARAM = 'log_date_to';

    /**
     * Fixed rows-per-page for this table. No Screen Options entry is
     * registered for it (unlike RosterListTable's AORM-3.7) — not part of
     * this ticket's acceptance criteria.
     */
    private const DEFAULT_PER_PAGE = 20;

    /**
     * Human-readable labels for every `action` slug currently written by
     * ActivityLogger. Any slug added in the future that isn't in this map
     * falls back to humanizeSlug() rather than being hidden.
     *
     * @var array<string, string>
     */
    private const ACTION_LABELS = [
        'individual_added'  => 'Individual Added',
        'upload_validated'  => 'Upload Validated',
        'members_removed'   => 'Members Removed',
        'roles_added'       => 'Roles Added',
        'roles_removed'     => 'Roles Removed',
        'matching_complete' => 'Matching Complete',
        'record_synced'     => 'Record Synced',
        'record_failed'     => 'Record Failed',
        'sync_complete'     => 'Sync Complete',
    ];

    /**
     * Labels for the `level` ENUM column, reused as both the Status column
     * rendering and the Status filter dropdown options.
     *
     * @var array<string, string>
     */
    private const LEVEL_LABELS = [
        'debug'   => 'Debug',
        'info'    => 'Info',
        'warning' => 'Warning',
        'error'   => 'Error',
    ];

    /**
     * Labels for the derived `actor_type` field (ActivityLogTable::getGlobalEntries()).
     *
     * @var array<string, string>
     */
    private const ACTOR_TYPE_LABELS = [
        'admin'  => 'Admin',
        'system' => 'System',
    ];

    private ActivityLogTable $logTable;

    private MdpClient $mdpClient;

    /**
     * Per-request memoization of MdpClient::getOrgMembershipDetail() calls,
     * keyed by membership_uuid. Shared between prepare_items() (row
     * enrichment) and buildFilterOptions() (dropdown labels) so the same
     * membership_uuid is never looked up twice in one page render.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $membershipDetailCache = [];

    /**
     * @param array<string, mixed> $args      Passed through to WP_List_Table.
     * @param ActivityLogTable|null $logTable Injected log repository; defaults to new ActivityLogTable().
     * @param MdpClient|null        $mdpClient Injected MDP client; defaults to new MdpClient().
     */
    public function __construct(
        array $args = [],
        ?ActivityLogTable $logTable = null,
        ?MdpClient $mdpClient = null,
    ) {
        parent::__construct($args);
        $this->logTable  = $logTable ?? new ActivityLogTable();
        $this->mdpClient = $mdpClient ?? new MdpClient();
    }

    /**
     * Define all visible columns and their header labels.
     *
     * @return array<string, string>
     *
     * @see AORM-10.3
     */
    public function get_columns(): array
    {
        return [
            'created_at'      => __('Date/Time', 'wicket-aorm'),
            'org_name'        => __('Organization', 'wicket-aorm'),
            'membership_tier' => __('Membership Tier', 'wicket-aorm'),
            'actor'           => __('Actor', 'wicket-aorm'),
            'actor_type'      => __('Actor Type', 'wicket-aorm'),
            'action'          => __('Activity Type', 'wicket-aorm'),
            'message'         => __('Summary', 'wicket-aorm'),
            'level'           => __('Status', 'wicket-aorm'),
            'details'         => __('Details', 'wicket-aorm'),
        ];
    }

    /**
     * Declare which columns are sortable and the default sort.
     *
     * Only plain DB columns can be sorted server-side. org_name and
     * membership_tier are resolved via an MDP lookup after the SQL query
     * runs, so — like RosterListTable's local-only columns — they are
     * deliberately absent here. actor/actor_type/message are derived or
     * free-text and not indexed for sorting.
     *
     * @return array<string, array{string, bool}>
     *
     * @see AORM-10.6
     */
    public function get_sortable_columns(): array
    {
        return [
            'created_at' => ['created_at', true], // default sort, newest first
            'action'     => ['action', false],
            'level'      => ['level', false],
        ];
    }

    /**
     * Fetch one page of global log entries and enrich with MDP org/tier names.
     *
     * @see AORM-10.2
     */
    public function prepare_items(): void
    {
        $this->_column_headers = [
            $this->get_columns(),
            [],
            $this->get_sortable_columns(),
        ];

        $perPage     = self::DEFAULT_PER_PAGE;
        $currentPage = $this->get_pagenum();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $orderby = sanitize_key((string) ($_GET['orderby'] ?? 'created_at'));
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $order = strtolower(sanitize_key((string) ($_GET['order'] ?? 'desc'))) === 'asc' ? 'asc' : 'desc';

        $result = $this->logTable->getGlobalEntries([
            'page'            => $currentPage,
            'per_page'        => $perPage,
            'org_uuid'        => $this->currentFilterValue(self::ORG_FILTER_PARAM),
            'membership_uuid' => $this->currentFilterValue(self::TIER_FILTER_PARAM),
            'action'          => $this->currentFilterValue(self::ACTION_FILTER_PARAM),
            'level'           => $this->currentFilterValue(self::LEVEL_FILTER_PARAM),
            'date_from'       => $this->sanitizeDateArg(self::DATE_FROM_PARAM),
            'date_to'         => $this->sanitizeDateArg(self::DATE_TO_PARAM),
            'orderby'         => $orderby,
            'order'           => $order,
        ]);

        $this->items = $result['entries'];
        $this->enrichItemsWithMdpDetails($this->items);

        $this->set_pagination_args([
            'total_items' => $result['total'],
            'per_page'    => $perPage,
            'total_pages' => $result['total_pages'],
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
     * Render the Date/Time column.
     *
     * created_at is already stored as a MySQL DATETIME string
     * (current_time('mysql', true) in ActivityLogger), so no reformatting
     * is needed here beyond escaping.
     *
     * @param array<string, mixed> $item
     */
    protected function column_created_at($item): string
    {
        return esc_html((string) ($item['created_at'] ?? ''));
    }

    /**
     * Render the Organization column.
     *
     * Links to the roster detail page when org_uuid + membership_uuid are
     * both resolvable (same pattern as RosterListTable::column_org_name()).
     * Falls back to plain text, or an em dash when no name could be
     * resolved at all (e.g. a log row whose membership_uuid could not be
     * derived — see ActivityLogTable::resolveMembershipUuidForEntry()).
     *
     * @param array<string, mixed> $item
     */
    protected function column_org_name($item): string
    {
        $orgName        = (string) ($item['org_name'] ?? '');
        $orgUuid        = (string) ($item['org_uuid'] ?? '');
        $membershipUuid = (string) ($item['membership_uuid'] ?? '');

        if ($orgName === '') {
            return '<span class="description" aria-label="' . esc_attr__('No organization resolved', 'wicket-aorm') . '">—</span>';
        }

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
     * Render the Membership Tier column.
     *
     * @param array<string, mixed> $item
     */
    protected function column_membership_tier($item): string
    {
        $tier = (string) ($item['membership_tier'] ?? '');

        return $tier === '' ? '<span class="description" aria-label="' . esc_attr__('No membership tier resolved', 'wicket-aorm') . '">—</span>' : esc_html($tier);
    }

    /**
     * Render the Actor column.
     *
     * @param array<string, mixed> $item
     */
    protected function column_actor($item): string
    {
        return esc_html((string) ($item['actor'] ?? ''));
    }

    /**
     * Render the Actor Type column.
     *
     * @param array<string, mixed> $item
     */
    protected function column_actor_type($item): string
    {
        $type = (string) ($item['actor_type'] ?? '');

        return esc_html(self::ACTOR_TYPE_LABELS[$type] ?? $this->humanizeSlug($type));
    }

    /**
     * Render the Activity Type column.
     *
     * @param array<string, mixed> $item
     */
    protected function column_action($item): string
    {
        $slug = (string) ($item['action'] ?? '');

        return esc_html(self::ACTION_LABELS[$slug] ?? $this->humanizeSlug($slug));
    }

    /**
     * Render the Summary column.
     *
     * @param array<string, mixed> $item
     */
    protected function column_message($item): string
    {
        return esc_html((string) ($item['message'] ?? ''));
    }

    /**
     * Render the Status column.
     *
     * Wraps the label in a span carrying an `aorm-log-level--{level}` class
     * so a theme/admin stylesheet can colour-code it; no styling is shipped
     * with this ticket, this just leaves the hook in place.
     *
     * @param array<string, mixed> $item
     */
    protected function column_level($item): string
    {
        $level = (string) ($item['level'] ?? '');
        $label = self::LEVEL_LABELS[$level] ?? $this->humanizeSlug($level);

        return '<span class="aorm-log-level aorm-log-level--' . esc_attr($level) . '">' . esc_html($label) . '</span>';
    }

    /**
     * Render the Details column: a toggle button for the hidden detail row
     * appended by single_row() (AORM-10.7).
     *
     * @param array<string, mixed> $item
     */
    protected function column_details($item): string
    {
        $id = (int) ($item['id'] ?? 0);

        return sprintf(
            '<button type="button" class="button-link aorm-log-toggle" data-target="aorm-log-detail-%1$d" aria-expanded="false">%2$s</button>',
            $id,
            esc_html__('View Details', 'wicket-aorm'),
        );
    }

    /**
     * Render one item's row plus a hidden detail row showing its raw
     * context JSON (AORM-10.7 — inline toggle, no AJAX).
     *
     * Columns are rendered manually (rather than via the real
     * WP_List_Table::single_row_columns() helper) by calling each
     * column_*() method directly, so this stays testable in isolation and
     * has no dependency on WP_List_Table internals beyond get_columns().
     *
     * The detail row starts hidden (inline style="display:none") and is
     * toggled client-side by the small script MenuPage::renderLogsPage()
     * echoes once after $table->display() — event delegation on
     * `.aorm-log-toggle` clicks, matched to the button's data-target id.
     *
     * @param array<string, mixed> $item
     */
    public function single_row($item): void
    {
        echo '<tr>';

        foreach (array_keys($this->get_columns()) as $columnName) {
            $method  = 'column_' . $columnName;
            $content = method_exists($this, $method) ? $this->$method($item) : $this->column_default($item, $columnName);

            echo '<td class="' . esc_attr($columnName) . ' column-' . esc_attr($columnName) . '">' . $content . '</td>';
        }

        echo '</tr>';

        $id      = (int) ($item['id'] ?? 0);
        $context = (array) ($item['context'] ?? []);
        $colspan = count($this->get_columns());

        printf(
            '<tr id="aorm-log-detail-%1$d" class="aorm-log-detail-row" style="display:none;"><td colspan="%2$d"><pre>%3$s</pre></td></tr>',
            $id,
            $colspan,
            esc_html((string) json_encode($context, JSON_PRETTY_PRINT)),
        );
    }

    /**
     * Render extra controls in the top table navigation row: Organization,
     * Membership Tier, Activity Type, and Status dropdowns, plus a date
     * range, matching the native WordPress admin filter-dropdown pattern
     * (same as RosterListTable::extra_tablenav()).
     *
     * @param string $which 'top' or 'bottom'.
     *
     * @see AORM-10.4
     * @see AORM-10.5
     */
    protected function extra_tablenav($which): void
    {
        if ($which !== 'top') {
            return;
        }

        $filters = $this->buildFilterOptions();

        echo '<div class="alignleft actions aorm-logs-filters">';

        $this->renderSelectFilter(
            self::ORG_FILTER_PARAM,
            __('All Organizations', 'wicket-aorm'),
            $filters['orgs'],
            __('Filter by organization', 'wicket-aorm'),
        );

        $this->renderSelectFilter(
            self::TIER_FILTER_PARAM,
            __('All Membership Tiers', 'wicket-aorm'),
            $filters['tiers'],
            __('Filter by membership tier', 'wicket-aorm'),
        );

        $this->renderSelectFilter(
            self::ACTION_FILTER_PARAM,
            __('All Activity Types', 'wicket-aorm'),
            $filters['actions'],
            __('Filter by activity type', 'wicket-aorm'),
        );

        $this->renderSelectFilter(
            self::LEVEL_FILTER_PARAM,
            __('All Statuses', 'wicket-aorm'),
            self::LEVEL_LABELS,
            __('Filter by status', 'wicket-aorm'),
        );

        echo '<label for="aorm-log-date-from" class="screen-reader-text">' . esc_html__('From date', 'wicket-aorm') . '</label>';
        echo '<input type="date" name="' . esc_attr(self::DATE_FROM_PARAM) . '" id="aorm-log-date-from" value="' . esc_attr($this->currentFilterValue(self::DATE_FROM_PARAM)) . '" />';

        echo '<label for="aorm-log-date-to" class="screen-reader-text">' . esc_html__('To date', 'wicket-aorm') . '</label>';
        echo '<input type="date" name="' . esc_attr(self::DATE_TO_PARAM) . '" id="aorm-log-date-to" value="' . esc_attr($this->currentFilterValue(self::DATE_TO_PARAM)) . '" />';

        echo '<input type="submit" name="filter_action" class="button" value="' . esc_attr__('Filter', 'wicket-aorm') . '" />';
        echo '</div>';
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Render a single <select> filter dropdown.
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
     * Build the option lists for the Organization, Membership Tier, and
     * Activity Type filter dropdowns from data that already exists in
     * wp_wicket_aorm_logs — never from the full MDP org/membership catalog.
     *
     * Every distinct membership_uuid found in the logs (via
     * ActivityLogTable::getDistinctMembershipUuids()) is resolved once via
     * MdpClient::getOrgMembershipDetail(), which conveniently returns both
     * the org name and the tier name in a single call — so both dropdowns
     * are built from the same batch of lookups. Any org_uuid present in the
     * logs that has no resolvable membership_uuid (there is currently no
     * such call site, but nothing guarantees there won't be one in the
     * future) still gets a raw-UUID fallback entry so the Organization
     * filter never silently drops an option.
     *
     * @return array{orgs: array<string,string>, tiers: array<string,string>, actions: array<string,string>}
     */
    private function buildFilterOptions(): array
    {
        $orgLabels  = [];
        $tierLabels = [];

        foreach ($this->logTable->getDistinctMembershipUuids() as $membershipUuid) {
            $detail = $this->resolveMdpDetail($membershipUuid);

            if (empty($detail)) {
                continue;
            }

            $orgUuid  = (string) ($detail['org_uuid'] ?? '');
            $orgName  = (string) ($detail['org_name'] ?? '');
            $tierName = (string) ($detail['membership_tier'] ?? '');

            if ($orgUuid !== '' && ! isset($orgLabels[$orgUuid])) {
                $orgLabels[$orgUuid] = $orgName !== '' ? $orgName : $orgUuid;
            }

            $tierLabels[$membershipUuid] = trim(
                ($orgName !== '' ? $orgName . ' — ' : '') . ($tierName !== '' ? $tierName : $membershipUuid),
            );
        }

        foreach ($this->logTable->getDistinctOrgUuids() as $orgUuid) {
            if ($orgUuid !== '' && ! isset($orgLabels[$orgUuid])) {
                $orgLabels[$orgUuid] = $orgUuid;
            }
        }

        $actionLabels = [];

        foreach ($this->logTable->getDistinctActions() as $slug) {
            $actionLabels[$slug] = self::ACTION_LABELS[$slug] ?? $this->humanizeSlug($slug);
        }

        return [
            'orgs'    => $orgLabels,
            'tiers'   => $tierLabels,
            'actions' => $actionLabels,
        ];
    }

    /**
     * Resolve org name + membership tier for a membership_uuid via
     * MdpClient::getOrgMembershipDetail(), memoized per request.
     *
     * @return array<string, mixed>
     */
    private function resolveMdpDetail(string $membershipUuid): array
    {
        if ($membershipUuid === '') {
            return [];
        }

        if (! array_key_exists($membershipUuid, $this->membershipDetailCache)) {
            $this->membershipDetailCache[$membershipUuid] = $this->mdpClient->getOrgMembershipDetail($membershipUuid);
        }

        return $this->membershipDetailCache[$membershipUuid];
    }

    /**
     * Enrich getGlobalEntries() rows with org_name / membership_tier,
     * resolved once per distinct membership_uuid on the current page.
     *
     * @param list<array<string, mixed>> $items Passed by reference.
     */
    private function enrichItemsWithMdpDetails(array &$items): void
    {
        foreach ($items as &$item) {
            $detail = $this->resolveMdpDetail((string) ($item['membership_uuid'] ?? ''));

            $item['org_name']        = (string) ($detail['org_name'] ?? '');
            $item['membership_tier'] = (string) ($detail['membership_tier'] ?? '');

            // Fall back to the raw org_uuid already on the row (it comes
            // straight from wp_wicket_aorm_logs.org_uuid, independent of
            // membership_uuid resolution) when no name could be resolved.
            if ($item['org_name'] === '' && ! empty($item['org_uuid'])) {
                $item['org_name'] = (string) $item['org_uuid'];
            }
        }

        unset($item);
    }

    /**
     * Read + sanitize the current value of a filter GET param.
     */
    private function currentFilterValue(string $param): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return sanitize_text_field((string) ($_GET[$param] ?? ''));
    }

    /**
     * Read + validate a date-range GET param as YYYY-MM-DD, discarding
     * anything that doesn't match the expected shape rather than passing
     * malformed input through to ActivityLogTable::getGlobalEntries().
     */
    private function sanitizeDateArg(string $param): string
    {
        $value = $this->currentFilterValue($param);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
    }

    /**
     * Turn a snake_case slug into a Title Case fallback label, used whenever
     * a value has no entry in ACTION_LABELS / ACTOR_TYPE_LABELS / LEVEL_LABELS.
     */
    private function humanizeSlug(string $slug): string
    {
        return ucwords(str_replace('_', ' ', $slug));
    }
}
