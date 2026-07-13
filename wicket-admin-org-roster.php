<?php

/**
 * Plugin Name: Wicket Admin Org Roster
 * Plugin URI: https://wicket.io
 * Description: WordPress admin plugin for managing organization rosters and person-to-organization relationships via the Wicket MDP API. Provides bulk upload, validation, duplicate resolution, and sync workflows.
 * Version: 0.1.1
 * Author: Wicket Inc.
 * Author URI: https://wicket.io
 * Text Domain: wicket-aorm
 * Domain Path: /languages
 * Requires at least: 6.6
 * Requires PHP: 8.2
 * Requires Plugins: wicket-wp-base-plugin, wicket-wp-account-centre
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Plugin constants — guarded so the file is safe to re-include in tests.
if (! defined('WICKET_AORM_FILE')) {
    define('WICKET_AORM_FILE', __FILE__);
}
if (! defined('WICKET_AORM_PATH')) {
    define('WICKET_AORM_PATH', plugin_dir_path(__FILE__));
}
if (! defined('WICKET_AORM_URL')) {
    define('WICKET_AORM_URL', plugin_dir_url(__FILE__));
}
if (! defined('WICKET_AORM_BASENAME')) {
    define('WICKET_AORM_BASENAME', plugin_basename(__FILE__));
}

// Composer autoloader (WicketAORM\ only).
if (is_file(WICKET_AORM_PATH . 'vendor/autoload.php')) {
    require_once WICKET_AORM_PATH . 'vendor/autoload.php';
}

/*
|--------------------------------------------------------------------------
| Dependency: wicket-wp-account-centre
|--------------------------------------------------------------------------
|
| This plugin requires wicket-wp-account-centre to be active. That plugin
| provides the WicketORM\ namespace (PersonService, ConnectionService, etc.)
| that AORM relies on for all MDP sync operations. This namespace previously
| lived in the now-retired wicket-wp-organization-roster plugin.
|
| On activation we abort with a clear message if the dependency is missing.
| After activation we bail out of the bootstrap and show an admin notice if
| the dependency is deactivated while AORM is still active.
|
*/

if (! function_exists('wicket_aorm_check_account_centre_dependency')) {
    /**
     * Returns true when wicket-wp-account-centre is active.
     */
    function wicket_aorm_check_account_centre_dependency(): bool
    {
        if (! function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return is_plugin_active('wicket-wp-account-centre/class-wicket-acc-main.php');
    }
}

if (! function_exists('wicket_aorm_dependency_missing_notice')) {
    /**
     * Renders the admin notice shown when the required plugin is inactive.
     */
    function wicket_aorm_dependency_missing_notice(): void
    {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__(
            'Wicket Admin Org Roster requires the Wicket Account Centre plugin to be installed and activated.',
            'wicket-aorm'
        );
        echo '</p></div>';
    }
}

/**
 * Global accessor for the Admin Org Roster plugin singleton.
 *
 * @return WicketAORM\Main
 */
if (! function_exists('WicketAdminOrgRoster')) {
    function WicketAdminOrgRoster(): WicketAORM\Main
    {
        return WicketAORM\Main::getInstance();
    }
}

/*
|--------------------------------------------------------------------------
| Activation / Deactivation
|--------------------------------------------------------------------------
*/

if (! function_exists('wicket_aorm_activate')) {
    /**
     * Runs on plugin activation.
     *
     * Aborts with a user-facing error when wicket-wp-account-centre is
     * not active. Creates the staged-records database table via the Migrator
     * and flushes rewrite rules so any custom REST routes are immediately
     * available.
     */
    function wicket_aorm_activate(): void
    {
        if (! wicket_aorm_check_account_centre_dependency()) {
            deactivate_plugins(WICKET_AORM_BASENAME);
            wp_die(
                esc_html__(
                    'Wicket Admin Org Roster requires the Wicket Account Centre plugin to be installed and activated. Please activate it before enabling this plugin.',
                    'wicket-aorm'
                ),
                esc_html__('Plugin activation failed', 'wicket-aorm'),
                ['back_link' => true]
            );
        }

        // Ensure the autoloader is available before touching namespaced classes.
        if (! class_exists(WicketAORM\Database\Migrator::class)) {
            return;
        }

        $migrator = new WicketAORM\Database\Migrator();
        $migrator->up();

        // AORM-13.4: Schedule the daily staged-records cleanup job.
        // maybeScheduleCleanup() in Main also covers the steady-state case,
        // but scheduling on activation ensures the event exists from the very
        // first admin page load without waiting for init to fire.
        if (! wp_next_scheduled(WicketAORM\Services\CleanupJobRunner::HOOK)) {
            wp_schedule_event(time(), 'daily', WicketAORM\Services\CleanupJobRunner::HOOK);
        }

        flush_rewrite_rules();
    }
}

if (! function_exists('wicket_aorm_deactivate')) {
    /**
     * Runs on plugin deactivation.
     *
     * Cleans up transients, scheduled events, and flushes rewrite rules.
     * Database tables are intentionally preserved so data survives a
     * deactivate/reactivate cycle.
     */
    function wicket_aorm_deactivate(): void
    {
        // Remove any plugin-specific scheduled events.
        $timestamp = wp_next_scheduled('wicket_aorm_cleanup_staged_records');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'wicket_aorm_cleanup_staged_records');
        }

        // Clear plugin transients.
        delete_transient('wicket_aorm_schema_version');

        flush_rewrite_rules();
    }
}

register_activation_hook(__FILE__, 'wicket_aorm_activate');
register_deactivation_hook(__FILE__, 'wicket_aorm_deactivate');

if (! function_exists('wicket_aorm_maybe_upgrade')) {
    /**
     * Runs on admin_init to catch schema upgrades after plugin file updates.
     *
     * WordPress does not re-run the activation hook when a plugin is updated
     * via the auto-updater or a file replacement. Hooking Migrator::up() into
     * admin_init ensures the schema is always current on the first admin page
     * load after an update. The version-gate inside Migrator::up() makes this
     * a cheap no-op when the schema is already up to date.
     */
    function wicket_aorm_maybe_upgrade(): void
    {
        if (! class_exists(WicketAORM\Database\Migrator::class)) {
            return;
        }

        $migrator = new WicketAORM\Database\Migrator();
        $migrator->up();
    }
}

add_action('admin_init', 'wicket_aorm_maybe_upgrade');

// AORM-3.7: Persist the per-page screen option for the org roster list table.
// The filter fires during admin_init when WP processes screen option saves. It
// must be registered before admin_init — hooking here (plugin load time) ensures
// that. See RosterListTable::SCREEN_OPTION_PER_PAGE and MenuPage::registerScreenOptions().
add_filter(
    'set_screen_option_aorm_rosters_per_page',
    static fn (mixed $keep, string $option, mixed $value): int => (int) $value,
    10,
    3,
);

/*
|--------------------------------------------------------------------------
| Bootstrap
|--------------------------------------------------------------------------
|
| Deferred to plugins_loaded so all plugin autoloaders are in place before
| we check the dependency. If wicket-wp-account-centre is not active
| we skip initialisation and show an admin notice instead.
|
*/

add_action('plugins_loaded', static function (): void {
    if (! wicket_aorm_check_account_centre_dependency()) {
        add_action('admin_notices', 'wicket_aorm_dependency_missing_notice');

        return;
    }

    // Initialise the singleton on 'init'. The base plugin defers its helper
    // includes (which define wicket_api_client()) to 'init' priority 0 via
    // WicketWP\Includes, so we hook at priority 1 to guarantee the function
    // is available.
    add_action('init', static function (): void {
        WicketAdminOrgRoster();
    }, 1);

    // Show a notice when the Wicket Base Plugin is missing.
    add_action('admin_notices', static function (): void {
        if (function_exists('wicket_api_client')) {
            return;
        }

        echo '<div class="notice notice-error"><p>';
        echo esc_html__(
            'Wicket Admin Org Roster requires the Wicket Base Plugin to be installed and activated.',
            'wicket-aorm'
        );
        echo '</p></div>';
    });
}, 1);
