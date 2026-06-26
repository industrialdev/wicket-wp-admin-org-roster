<?php

declare(strict_types=1);

namespace WicketAORM\Services;

use WicketAORM\Database\StagedRecordsTable;

/**
 * CleanupJobRunner — daily background cleanup of completed staged records.
 *
 * Fired by WordPress Cron on the 'wicket_aorm_cleanup_staged_records' hook,
 * scheduled daily by the plugin activation routine.  On each invocation the
 * runner reads the cleanup_ttl_days value from wicket_aorm_settings and
 * deletes staged records whose sync_status is 'synced' or 'failed' AND whose
 * updated_at timestamp is older than the configured TTL.
 *
 * The scheduled event is registered on activation:
 *
 *   wp_schedule_event( time(), 'daily', CleanupJobRunner::HOOK );
 *
 * and cleared on deactivation via wp_unschedule_event() in the plugin
 * bootstrap.  The add_action() registration lives in Main::registerHooks().
 *
 * Dependency injection is supported via the constructor so that unit tests
 * can supply a mock StagedRecordsTable without touching the database.
 *
 * @see AORM-13.4
 */
class CleanupJobRunner
{
    /**
     * The WordPress action hook fired by WP-Cron for the daily cleanup job.
     *
     * Referenced in:
     *   - Main::registerHooks()          — add_action() registration
     *   - wicket_aorm_activate()         — wp_schedule_event() scheduling
     *   - wicket_aorm_deactivate()       — wp_unschedule_event() teardown
     */
    public const HOOK = 'wicket_aorm_cleanup_staged_records';

    /**
     * WordPress option key for plugin settings.
     *
     * The 'cleanup_ttl_days' key within this option controls how many days a
     * completed staged record is retained before being purged.
     */
    private const SETTINGS_OPTION = 'wicket_aorm_settings';

    /**
     * Default TTL in days used when wicket_aorm_settings[cleanup_ttl_days]
     * is absent or invalid.  Mirrors SettingsPage::DEFAULT_CLEANUP_TTL_DAYS.
     */
    public const DEFAULT_TTL_DAYS = 30;

    private StagedRecordsTable $table;

    /**
     * @param StagedRecordsTable|null $table Optional table instance for DI/testing.
     */
    public function __construct(?StagedRecordsTable $table = null)
    {
        $this->table = $table ?? new StagedRecordsTable();
    }

    /**
     * Execute the cleanup job.
     *
     * Reads cleanup_ttl_days from wicket_aorm_settings, then deletes every
     * staged record that is in a terminal sync_status ('synced' or 'failed')
     * and has not been updated within the TTL window.
     *
     * A TTL of 0 or less is treated as a no-op: cleanup is skipped entirely.
     * This gives site administrators a way to disable automatic purging by
     * setting cleanup_ttl_days to 0, without requiring the cron event to be
     * unscheduled.
     */
    public function handle(): void
    {
        $options = (array) get_option(self::SETTINGS_OPTION, []);
        $days    = (int) ($options['cleanup_ttl_days'] ?? self::DEFAULT_TTL_DAYS);

        if ($days <= 0) {
            return;
        }

        $this->table->deleteCompletedOlderThan($days);
    }
}
