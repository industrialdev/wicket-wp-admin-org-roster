<?php

declare(strict_types=1);

namespace WicketAORM\Admin;

/**
 * Register admin pages (React mount points and WP_List_Table pages).
 *
 * Page architecture (per AORM-1 / AORM-3 / AORM-10):
 *   - Org memberships list  (wicket-aorm)                → WP_List_Table, NO React
 *   - Org roster detail     (wicket-aorm-roster-detail)  → React island, hidden from menu
 *   - Group Rosters         (wicket-aorm-group-rosters)  → React island
 *   - Settings              (wicket-aorm-settings)       → classic PHP admin page, NO React
 *   - Global Logs           (wicket-aorm-logs)           → WP_List_Table, NO React
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

    /** Global Logs page slug (AORM-10). */
    public const LOGS_SLUG = 'wicket-aorm-logs';

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

        // Settings (debug toggle, etc.).
        add_submenu_page(
            self::MENU_SLUG,
            __('Roster Settings', 'wicket-aorm'),
            __('Settings', 'wicket-aorm'),
            self::CAPABILITY,
            'wicket-aorm-settings',
            [$this, 'renderSettingsPage'],
        );

        // Global Logs — read-only WP_List_Table across every roster (AORM-10).
        // Registered after Settings (appended, not inserted) so existing
        // index-based submenu assertions elsewhere are unaffected.
        add_submenu_page(
            self::MENU_SLUG,
            __('Activity Logs', 'wicket-aorm'),
            __('Logs', 'wicket-aorm'),
            self::CAPABILITY,
            self::LOGS_SLUG,
            [$this, 'renderLogsPage'],
        );

        // Hide the detail page from the visible sidebar via CSS.
        //
        // remove_submenu_page() must NOT be used here: WordPress resolves the
        // parent slug by scanning $submenu at request time (get_admin_page_parent()),
        // so removing the entry causes get_plugin_page_hookname() to produce the
        // wrong hook name, which no longer matches $_registered_pages, and every
        // direct URL visit results in "Sorry, you are not allowed to access this page."
        //
        // CSS removal is the correct WP pattern for "registered but not in the menu".
        add_action('admin_head', [$this, 'hideDetailPageFromMenu']);
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
        $notices = new AdminNotices();
        $notices->render(); // AORM-13.5: notices for rosters with sync failures.
        $notices->renderCascadeStrategyNotice(); // Informational notice when the ORM's cascade strategy is active.
        echo '<hr class="wp-header-end" />';
        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="' . esc_attr(self::MENU_SLUG) . '" />';
        $table->search_box(esc_html__('Search Organizations', 'wicket-aorm'), 'aorm-roster-search');
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
     * Render the Settings page (classic PHP admin page).
     *
     * Uses the WordPress Settings API — settings_fields() outputs the hidden
     * option-page nonce/action fields, and do_settings_sections() renders any
     * sections/fields registered via add_settings_section() / add_settings_field()
     * for this page slug (added in AORM-11.3+).
     *
     * @see AORM-11.2
     */
    public function renderSettingsPage(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'wicket-aorm'));
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Roster Settings', 'wicket-aorm') . '</h1>';

        settings_errors(SettingsPage::OPTION_NAME);

        echo '<form method="post" action="options.php">';
        settings_fields(SettingsPage::OPTION_GROUP);
        do_settings_sections(SettingsPage::PAGE_SLUG);
        submit_button();
        echo '</form>';

        echo '</div>';
    }

    /**
     * Render the Global Logs page (AORM-10).
     *
     * Instantiates LogsListTable, fetches items via prepare_items(), and
     * renders the standard WordPress admin page scaffold — same pattern as
     * renderOrgRostersPage(). No search box (not part of this ticket's
     * acceptance criteria; filtering is handled entirely by the dropdowns
     * and date range LogsListTable renders in its own extra_tablenav()).
     *
     * Ends with a small inline toggle script (AORM-10.7) so each row's
     * "View Details" button can expand/collapse its hidden JSON detail row
     * without a page reload or an AJAX round-trip.
     *
     * @see AORM-10.1
     */
    public function renderLogsPage(): void
    {
        $table = new LogsListTable();
        $table->prepare_items();

        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">' . esc_html__('Activity Logs', 'wicket-aorm') . '</h1>';
        echo '<hr class="wp-header-end" />';
        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="' . esc_attr(self::LOGS_SLUG) . '" />';
        $table->display();
        echo '</form>';
        $this->renderLogsToggleScript();
        echo '</div>';
    }

    /**
     * Echo the inline JS that toggles a Global Logs row's hidden detail row.
     *
     * Uses click-event delegation on the document rather than binding a
     * listener per button, so it works correctly regardless of pagination
     * (each page load re-renders fresh buttons, and delegation needs no
     * re-binding). Matches the existing pattern of small inline <script>/
     * <style> blocks elsewhere in this class (e.g. hideDetailPageFromMenu()).
     *
     * @see AORM-10.7
     */
    private function renderLogsToggleScript(): void
    {
        ?>
        <script>
        document.addEventListener('click', function (event) {
            var button = event.target.closest('.aorm-log-toggle');
            if (!button) {
                return;
            }
            var target = document.getElementById(button.getAttribute('data-target'));
            if (!target) {
                return;
            }
            var isHidden = target.style.display === 'none' || target.style.display === '';
            target.style.display = isHidden ? 'table-row' : 'none';
            button.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
        });
        </script>
        <?php
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Suppress the detail submenu item from the visible sidebar via CSS.
     *
     * remove_submenu_page() is deliberately avoided: it strips the entry from
     * $submenu, which breaks get_admin_page_parent() → get_plugin_page_hookname()
     * at request time, producing a hook name that no longer matches
     * $_registered_pages. The result is "Sorry, you are not allowed to access
     * this page." for every direct URL visit.
     *
     * CSS removal keeps the page fully accessible while hiding the link.
     *
     * Hooked to admin_head (fires on every admin page load, but the <style>
     * block is tiny and cached by the browser alongside other admin CSS).
     */
    public function hideDetailPageFromMenu(): void
    {
        $slug = esc_attr(self::DETAIL_SLUG);
        echo '<style>#adminmenu a[href*="page=' . $slug . '"] { display: none !important; }</style>';
    }
}
