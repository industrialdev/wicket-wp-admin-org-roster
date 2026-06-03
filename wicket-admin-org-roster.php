<?php

/**
 * Plugin Name: Wicket Admin Org Roster
 * Plugin URI: https://wicket.io
 * Description: WordPress admin plugin for managing organization rosters and person-to-organization relationships via the Wicket MDP API. Provides bulk upload, validation, duplicate resolution, and sync workflows.
 * Version: 0.1
 * Author: Wicket Inc.
 * Author URI: https://wicket.io
 * Text Domain: wicket-aorm
 * Domain Path: /languages
 * Requires at least: 6.6
 * Requires PHP: 8.2
 * Requires Plugins: wicket-wp-base-plugin
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
| WicketORM\ Autoloader — Vendored Library
|--------------------------------------------------------------------------
|
| AORM vendors the wicket-lib-org-roster library at lib/wicket-lib-org-roster/.
| If the standalone wicket-wp-organization-roster plugin is active, it already
| provides the WicketORM\ namespace via its own autoloader. In that case we
| skip loading the vendored copy to avoid duplicate class registration.
|
| When the standalone plugin is not available, we register a PSR-4 autoloader
| pointing at the local lib/ copy so AORM can use WicketORM\ services.
|
*/
if (! class_exists(WicketORM\OrgMan::class, false)) {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'WicketORM\\';
        $baseDir = WICKET_AORM_PATH . 'lib/wicket-lib-org-roster/src/';

        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }

        $relativeClass = substr($class, strlen($prefix));
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

        if (is_file($file)) {
            require_once $file;
        }
    });

    // Load the OrgManagement\OrgMan -> WicketORM\OrgMan compat alias.
    require_once WICKET_AORM_PATH . 'lib/wicket-lib-org-roster/src/compat.php';
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
     * Creates the staged-records database table via the Migrator and flushes
     * rewrite rules so any custom REST routes are immediately available.
     */
    function wicket_aorm_activate(): void
    {
        // Ensure the autoloader is available before touching namespaced classes.
        if (! class_exists(WicketAORM\Database\Migrator::class)) {
            return;
        }

        $migrator = new WicketAORM\Database\Migrator();
        $migrator->up();

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
| Initialise the plugin on the 'init' hook. The base plugin defers its
| helper includes (which define wicket_api_client()) to 'init' priority 0
| via WicketWP\Includes, so we hook at priority 1 to guarantee the
| function is available.
|
*/

add_action('init', static function (): void {
    // Always initialise the singleton so admin_menu pages are registered by WP.
    // Gating initialization on wicket_api_client() caused WordPress to never add
    // the pages to $_registered_pages, resulting in "Sorry, you are not allowed
    // to access this page" even for admins. Individual MDP operations inside
    // MdpClient already bail gracefully when wicket_api_client() returns null.
    WicketAdminOrgRoster();
}, 1);

/*
|--------------------------------------------------------------------------
| Dependency notice
|--------------------------------------------------------------------------
*/

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
