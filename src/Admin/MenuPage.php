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
     * Render the Configurations page — read-only display of the ORM config
     * values that drive AORM sync behaviour.
     *
     * Values are sourced from WicketORM\Config\OrgManConfig::get(), which
     * is filterable via the 'wicket/acc/orgman/config' WordPress filter
     * (registered in the active child theme). The page is intentionally
     * read-only: AORM does not own these settings, it only consumes them.
     *
     * Sections:
     *   - Membership: strategy, default relationship type.
     *   - Roles: base member role, auto-assign roles, access role slugs.
     */
    public function renderConfigurationsPage(): void
    {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Roster Configurations', 'wicket-aorm') . '</h1>';

        if (! class_exists(\WicketORM\Config\OrgManConfig::class)) {
            echo '<div class="notice notice-error inline"><p>';
            echo esc_html__('The wicket-wp-organization-roster plugin is not active. ORM configuration is unavailable.', 'wicket-aorm');
            echo '</p></div>';
            echo '</div>';

            return;
        }

        $config = \WicketORM\Config\OrgManConfig::get();

        // ── Extract values ────────────────────────────────────────────────

        $strategy         = (string) ($config['membership']['strategy'] ?? '');
        $relationshipType = (string) ($config['relationships']['defaults']['type'] ?? '');

        $baseMemberRole  = (string) ($config['member_management']['addition']['base_member_role'] ?? '');
        $autoAssignRoles = array_values(array_filter(
            array_map('strval', (array) ($config['member_management']['addition']['auto_assign_roles'] ?? [])),
        ));

        $ownerRole   = (string) ($config['access']['roles']['owner'] ?? '');
        $managerRole = (string) ($config['access']['roles']['manager'] ?? '');
        $editorRole  = (string) ($config['access']['roles']['editor'] ?? '');

        // ── Intro note ────────────────────────────────────────────────────

        echo '<p class="description">';
        echo esc_html__('These values are read from the wicket-wp-organization-roster plugin via the wicket/acc/orgman/config filter and are used during roster sync. To change them, update the filter in your child theme.', 'wicket-aorm');
        echo '</p>';

        // ── Membership section ────────────────────────────────────────────

        echo '<h2 class="title">' . esc_html__('Membership', 'wicket-aorm') . '</h2>';
        echo '<table class="widefat striped" style="max-width:640px;">';
        echo '<thead><tr>';
        echo '<th scope="col" style="width:260px;">' . esc_html__('Setting', 'wicket-aorm') . '</th>';
        echo '<th scope="col">' . esc_html__('Value', 'wicket-aorm') . '</th>';
        echo '</tr></thead><tbody>';

        $this->renderConfigRow(
            __('Strategy', 'wicket-aorm'),
            $strategy,
        );
        $this->renderConfigRow(
            __('Default relationship type', 'wicket-aorm'),
            $relationshipType,
        );

        echo '</tbody></table>';

        // ── Roles section ─────────────────────────────────────────────────

        echo '<h2 class="title">' . esc_html__('Roles', 'wicket-aorm') . '</h2>';
        echo '<table class="widefat striped" style="max-width:640px;">';
        echo '<thead><tr>';
        echo '<th scope="col" style="width:260px;">' . esc_html__('Setting', 'wicket-aorm') . '</th>';
        echo '<th scope="col">' . esc_html__('Value', 'wicket-aorm') . '</th>';
        echo '</tr></thead><tbody>';

        $this->renderConfigRow(
            __('Base member role', 'wicket-aorm'),
            $baseMemberRole,
        );
        $this->renderConfigRow(
            __('Auto-assign roles', 'wicket-aorm'),
            $autoAssignRoles,
        );
        $this->renderConfigRow(
            __('Owner role slug', 'wicket-aorm'),
            $ownerRole,
        );
        $this->renderConfigRow(
            __('Manager role slug', 'wicket-aorm'),
            $managerRole,
        );
        $this->renderConfigRow(
            __('Editor role slug', 'wicket-aorm'),
            $editorRole,
        );

        echo '</tbody></table>';
        echo '</div>';
    }

    /**
     * Render a single configuration table row.
     *
     * Accepts a string value (rendered as <code>) or an array of strings
     * (each rendered as its own <code> tag, or "—" when the array is empty).
     *
     * @param string          $label  Human-readable row label.
     * @param string|string[] $value  Config value(s) to display.
     */
    private function renderConfigRow(string $label, string|array $value): void
    {
        echo '<tr>';
        echo '<td>' . esc_html($label) . '</td>';
        echo '<td>';

        if (is_array($value)) {
            if (empty($value)) {
                echo '&mdash;';
            } else {
                foreach ($value as $slug) {
                    echo '<code>' . esc_html($slug) . '</code> ';
                }
            }
        } else {
            echo $value !== '' ? '<code>' . esc_html($value) . '</code>' : '&mdash;';
        }

        echo '</td>';
        echo '</tr>';
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
