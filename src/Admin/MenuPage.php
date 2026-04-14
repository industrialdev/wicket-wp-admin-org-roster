<?php

declare(strict_types=1);

namespace WicketAORM\Admin;

/**
 * Register admin pages (React mount points and WP_List_Table pages).
 *
 * Page architecture (per AORM-1 / AORM-3):
 *   - Org memberships list  (wicket-aorm)                → WP_List_Table, NO React
 *   - Org roster detail     (wicket-aorm-roster-detail)  → React island, hidden from menu
 *   - Group Rosters         (wicket-aorm-group-rosters)  → React island
 *   - Configurations        (wicket-aorm-configurations) → classic PHP admin page, NO React
 *   - Settings              (wicket-aorm-settings)       → classic PHP admin page, NO React
 */
class MenuPage
{
    /** Top-level menu slug — also the org memberships list page. */
    public const MENU_SLUG = 'wicket-aorm';

    /** Hidden detail page slug — linked to from list-table rows. */
    public const DETAIL_SLUG = 'wicket-aorm-roster-detail';

    /** Required capability for all AORM pages. */
    private const CAPABILITY = 'manage_options';

    /** Menu position in the admin sidebar. */
    private const MENU_POSITION = 30;

    /**
     * Register the top-level menu and its subpages.
     */
    public function register(): void
    {
        // Top-level menu — renders the org memberships list (WP_List_Table).
        add_menu_page(
            __('Roster Management', 'wicket-aorm'),
            __('Roster Management', 'wicket-aorm'),
            self::CAPABILITY,
            self::MENU_SLUG,
            [$this, 'renderOrgRostersPage'],
            'dashicons-groups',
            self::MENU_POSITION,
        );

        // Organization Rosters — replaces the auto-generated duplicate top-level link.
        add_submenu_page(
            self::MENU_SLUG,
            __('Organization Rosters', 'wicket-aorm'),
            __('Organization Rosters', 'wicket-aorm'),
            self::CAPABILITY,
            self::MENU_SLUG,
            [$this, 'renderOrgRostersPage'],
        );

        // Roster detail — hidden from the sidebar menu (accessed via list-table row links).
        add_submenu_page(
            self::MENU_SLUG,
            __('Roster Detail', 'wicket-aorm'),
            __('Roster Detail', 'wicket-aorm'),
            self::CAPABILITY,
            self::DETAIL_SLUG,
            [$this, 'renderOrgRosterDetailPage'],
        );

        // Group Rosters.
        add_submenu_page(
            self::MENU_SLUG,
            __('Group Rosters', 'wicket-aorm'),
            __('Group Rosters', 'wicket-aorm'),
            self::CAPABILITY,
            'wicket-aorm-group-rosters',
            [$this, 'renderGroupRostersPage'],
        );

        // Roster Management Configurations.
        add_submenu_page(
            self::MENU_SLUG,
            __('Roster Configurations', 'wicket-aorm'),
            __('Configurations', 'wicket-aorm'),
            self::CAPABILITY,
            'wicket-aorm-configurations',
            [$this, 'renderConfigurationsPage'],
        );

        // Settings (debug toggle, etc.).
        add_submenu_page(
            self::MENU_SLUG,
            __('Roster Settings', 'wicket-aorm'),
            __('Settings', 'wicket-aorm'),
            self::CAPABILITY,
            'wicket-aorm-settings',
            [$this, 'renderSettingsPage'],
        );

        // Remove the detail page from the visible sidebar menu.
        // It is registered above so WP recognises it as a valid admin page,
        // but admins navigate to it only via list-table row links.
        $this->hideSubmenuPage(self::MENU_SLUG, self::DETAIL_SLUG);
    }

    // -------------------------------------------------------------------------
    // Page render callbacks
    // -------------------------------------------------------------------------

    /**
     * Render the org memberships list page.
     *
     * Uses WP_List_Table — no React bundle is enqueued here (AORM-1.6).
     */
    public function renderOrgRostersPage(): void
    {
        // TODO (AORM-3): instantiate and display OrgRosterListTable.
        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">' . esc_html__('Organization Rosters', 'wicket-aorm') . '</h1>';
        echo '<p>' . esc_html__('Organization memberships list (WP_List_Table — coming in AORM-3).', 'wicket-aorm') . '</p>';
        echo '</div>';
    }

    /**
     * Render the org roster detail page (React mount point).
     *
     * Receives org_uuid and membership_uuid as $_GET params set by
     * the list-table row link. The React app reads them from window.location.search.
     */
    public function renderOrgRosterDetailPage(): void
    {
        echo '<div class="wrap"><div id="aorm-roster-detail"></div></div>';
    }

    /**
     * Render the Group Rosters page (React mount point).
     */
    public function renderGroupRostersPage(): void
    {
        echo '<div class="wrap"><div id="aorm-group-rosters"></div></div>';
    }

    /**
     * Render the Configurations page (classic PHP admin page).
     *
     * TODO: implement configuration form fields (sync path, seat limits, etc.).
     */
    public function renderConfigurationsPage(): void
    {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Roster Configurations', 'wicket-aorm') . '</h1>';
        echo '<p>' . esc_html__('Configuration options coming soon.', 'wicket-aorm') . '</p>';
        echo '</div>';
    }

    /**
     * Render the Settings page (classic PHP admin page).
     *
     * TODO: implement settings fields (debug mode toggle, etc.).
     */
    public function renderSettingsPage(): void
    {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Roster Settings', 'wicket-aorm') . '</h1>';
        echo '<p>' . esc_html__('Settings coming soon.', 'wicket-aorm') . '</p>';
        echo '</div>';
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Remove a submenu item from the visible sidebar while keeping the page
     * registered so WP still serves it at its URL.
     *
     * @param string $parentSlug
     * @param string $submenuSlug
     */
    private function hideSubmenuPage(string $parentSlug, string $submenuSlug): void
    {
        remove_submenu_page($parentSlug, $submenuSlug);
    }
}
