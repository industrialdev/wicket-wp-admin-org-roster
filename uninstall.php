<?php

/**
 * Uninstall routine for Wicket Admin Org Roster.
 *
 * WordPress loads this file when the plugin is deleted from the Plugins
 * screen. It drops all plugin-owned database tables and removes any stored
 * wp_options values so the site is left in a clean state.
 *
 * @package WicketAORM
 */

declare(strict_types=1);

// Security: bail if WordPress didn't trigger this file.
if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Load the Composer autoloader so namespaced classes are available.
// The autoloader may already be active when called from within WP, but the
// guard keeps this safe to run standalone.
if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

if (! class_exists(WicketAORM\Database\Migrator::class)) {
    return;
}

$migrator = new WicketAORM\Database\Migrator();
$migrator->down();
