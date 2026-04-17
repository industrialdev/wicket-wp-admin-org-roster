<?php

declare(strict_types=1);

namespace WicketAORM\Admin;

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
     * Fetch org memberships from MDP and populate $this->items.
     *
     * TODO (AORM-3.2): implement MDP data fetch, search/filter, ordering,
     * and pagination via set_pagination_args().
     */
    public function prepare_items(): void
    {
        $this->_column_headers = [
            $this->get_columns(),
            [],
            $this->get_sortable_columns(),
        ];

        // TODO (AORM-3.2): fetch org memberships from MDP filtered to
        // Active, Delayed, or Grace Period status, apply search/filter/sort,
        // and paginate results.
        $this->items = [];
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
     * Links to the detail view, passing org_uuid and membership_uuid as URL
     * params so the React island can bootstrap the correct roster.
     *
     * TODO (AORM-3.3): build admin_url() link with org_uuid + membership_uuid.
     *
     * @param array<string, mixed> $item
     */
    protected function column_org_name($item): string
    {
        return esc_html((string) ($item['org_name'] ?? ''));
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
     * Value sourced from wp_wicket_aorm_roster_meta.roster_status.
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
     * Shows the date and the user email from wp_wicket_aorm_roster_meta.
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
     * TODO (AORM-3.x): render an anchor to the MDP org record URL.
     *
     * @param array<string, mixed> $item
     */
    protected function column_mdp_link($item): string
    {
        return esc_html((string) ($item['mdp_link'] ?? ''));
    }
}
