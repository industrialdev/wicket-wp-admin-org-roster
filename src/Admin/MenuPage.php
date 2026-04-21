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

    /**
     * Hook suffix returned by add_menu_page() for the roster list page.
     *
     * Stored so the load-{hook} action can be registered and the Screen
     * Options panel entry added only when this page is actually loading.
     *
     * @see AORM-3.7
     */
    private ?string $rosterListHook = null;

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
        // Capture the hook suffix so we can register the Screen Options entry
        // only when this specific page is loading (AORM-3.7).
        $this->rosterListHook = add_menu_page(
            __('Roster Management', 'wicket-aorm'),
            __('Roster Management', 'wicket-aorm'),
            self::CAPABILITY,
            self::MENU_SLUG,
            [$this, 'renderOrgRostersPage'],
            'dashicons-groups',
            self::MENU_POSITION,
        );

        add_action('load-' . $this->rosterListHook, [$this, 'registerScreenOptions']);

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
    // Screen option registration (AORM-3.7)
    // -------------------------------------------------------------------------

    /**
     * Register the per-page screen option for the org memberships list table.
     *
     * Hooked to load-{page_hook} so it runs only when the roster list page is
     * actually being served, not on every admin request. WP_List_Table reads the
     * saved value via get_items_per_page(RosterListTable::SCREEN_OPTION_PER_PAGE).
     *
     * The companion filter 'set_screen_option_aorm_rosters_per_page' is registered
     * in the plugin bootstrap file to persist the value when the user clicks Apply.
     *
     * @see AORM-3.7
     */
    public function registerScreenOptions(): void
    {
        add_screen_option('per_page', [
            'label'   => __('Organizations per page', 'wicket-aorm'),
            'default' => 20,
            'option'  => RosterListTable::SCREEN_OPTION_PER_PAGE,
        ]);
    }

    // -------------------------------------------------------------------------
    // Page render callbacks
    // -------------------------------------------------------------------------

    /**
     * Render the org memberships list page.
     *
     * Instantiates RosterListTable, fetches items via prepare_items(), then
     * renders the standard WordPress admin page scaffold: a .wrap container,
     * an h1 heading, and a GET form wrapping the list table so that column
     * sorting, search, and filter dropdowns (extra_tablenav) all submit back
     * to the same admin page with the correct page slug preserved.
     *
     * Uses WP_List_Table — no React bundle is enqueued here (AORM-1.6).
     *
     * @see AORM-3.10
     */
    public function renderOrgRostersPage(): void
    {
        $table = new RosterListTable();
        $table->prepare_items();

        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">' . esc_html__('Organization Rosters', 'wicket-aorm') . '</h1>';
        echo '<hr class="wp-header-end" />';
        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="' . esc_attr(self::MENU_SLUG) . '" />';
        $table->display();
        echo '</form>';
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
