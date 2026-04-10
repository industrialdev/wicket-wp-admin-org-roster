<?php

declare(strict_types=1);

namespace WicketAORM\Admin;

/**
 * Register admin pages (React mount points).
 *
 * Creates a top-level "Roster Management" menu in wp-admin with subpages
 * for organization rosters, group rosters, configurations, and settings.
 */
class MenuPage
{
    /** Top-level menu slug — also used as the parent for subpages. */
    private const MENU_SLUG = 'wicket-aorm';

    /** Required capability for all AORM pages. */
    private const CAPABILITY = 'manage_options';

    /** Menu position in the admin sidebar. */
    private const MENU_POSITION = 30;

    /**
     * Register the top-level menu and its subpages.
     */
    public function register(): void
    {
        // Top-level menu — defaults to the first submenu page.
        add_menu_page(
            __('Roster Management', 'wicket-aorm'),
            __('Roster Management', 'wicket-aorm'),
            self::CAPABILITY,
            self::MENU_SLUG,
            [$this, 'renderOrgRostersPage'],
            'dashicons-groups',
            self::MENU_POSITION,
        );

        // Organization Rosters (replaces the auto-generated duplicate top-level link).
        add_submenu_page(
            self::MENU_SLUG,
            __('Organization Rosters', 'wicket-aorm'),
            __('Organization Rosters', 'wicket-aorm'),
            self::CAPABILITY,
            self::MENU_SLUG, // same slug as parent so it replaces the default submenu entry
            [$this, 'renderOrgRostersPage'],
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
    }

    /**
     * Render the Organization Rosters page (React mount point).
     */
    public function renderOrgRostersPage(): void
    {
        echo '<div class="wrap"><div id="aorm-org-rosters"></div></div>';
    }

    /**
     * Render the Group Rosters page (React mount point).
     */
    public function renderGroupRostersPage(): void
    {
        echo '<div class="wrap"><div id="aorm-group-rosters"></div></div>';
    }

    /**
     * Render the Configurations page (React mount point).
     */
    public function renderConfigurationsPage(): void
    {
        echo '<div class="wrap"><div id="aorm-configurations"></div></div>';
    }

    /**
     * Render the Settings page (React mount point).
     */
    public function renderSettingsPage(): void
    {
        echo '<div class="wrap"><div id="aorm-settings"></div></div>';
    }
}
