<?php

declare(strict_types=1);

namespace WicketAORM\Database;

/**
 * Schema create/upgrade on activation.
 */
class Migrator
{
    /**
     * Current database schema version.
     *
     * Bump this string whenever the schema changes so that version-gated
     * migration runs on the next activation or admin_init check.
     */
    public const string DB_VERSION = '1.0';

    /**
     * wp_options key that stores the installed schema version.
     */
    public const string DB_VERSION_OPTION = 'wicket_aorm_db_version';

    /**
     * Create or update all plugin database tables.
     *
     * Compares the installed schema version stored in wp_options against
     * DB_VERSION. If they already match the schema is current and no work
     * is done. Otherwise all three tables are passed through dbDelta and
     * the stored version is updated to DB_VERSION.
     */
    public function up(): void
    {
        $installed = get_option(self::DB_VERSION_OPTION, '');

        if ($installed === self::DB_VERSION) {
            return;
        }

        if (! function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        dbDelta($this->buildStagedRecordsSql());
        dbDelta($this->buildLogsSql());
        dbDelta($this->buildRosterMetaSql());

        update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
    }

    /**
     * Drop all plugin database tables and remove the stored schema version.
     *
     * Called by uninstall.php when the plugin is deleted from WordPress.
     * Intentionally not called on deactivation so that data survives a
     * deactivate/reactivate cycle.
     */
    public function down(): void
    {
        global $wpdb;

        $tables = [
            $wpdb->prefix . 'wicket_aorm_staged_records',
            $wpdb->prefix . 'wicket_aorm_logs',
            $wpdb->prefix . 'wicket_aorm_roster_meta',
        ];

        foreach ($tables as $table) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
        }

        delete_option(self::DB_VERSION_OPTION);
    }

    /**
     * Build the CREATE TABLE SQL for wp_wicket_aorm_logs.
     *
     * Captures every admin action (upload, validation, sync, duplicate
     * resolution, etc.) with enough context to reconstruct audit trails.
     * Extracted as a protected method so unit tests can assert on the
     * schema string without requiring a live database.
     */
    protected function buildLogsSql(): string
    {
        global $wpdb;

        $table          = $wpdb->prefix . 'wicket_aorm_logs';
        $charsetCollate = $wpdb->get_charset_collate();

        return "CREATE TABLE {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  upload_session_id VARCHAR(36) NULL DEFAULT NULL,
  org_uuid VARCHAR(36) NULL DEFAULT NULL,
  staged_record_id BIGINT UNSIGNED NULL DEFAULT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  level ENUM('debug','info','warning','error') NOT NULL DEFAULT 'info',
  action VARCHAR(64) NOT NULL,
  object_type ENUM('session','staged_record','roster','person','plugin') NOT NULL,
  object_id VARCHAR(36) NULL DEFAULT NULL,
  message TEXT NOT NULL,
  context LONGTEXT NULL DEFAULT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY upload_session_id (upload_session_id),
  KEY org_uuid (org_uuid),
  KEY staged_record_id (staged_record_id),
  KEY user_id (user_id),
  KEY level (level),
  KEY action (action),
  KEY created_at (created_at)
) {$charsetCollate};";
    }

    /**
     * Build the CREATE TABLE SQL for wp_wicket_aorm_roster_meta.
     *
     * Persistent per-roster state that survives staged-records cleanup.
     * Gives the org roster list view a single indexed query per row instead
     * of scanning staged_records or logs for the latest status, actor, and
     * timestamp. Keyed uniquely on membership_uuid (one row per org+membership
     * pair).
     *
     * Extracted as a protected method so unit tests can assert on the
     * schema string without requiring a live database.
     */
    protected function buildRosterMetaSql(): string
    {
        global $wpdb;

        $table          = $wpdb->prefix . 'wicket_aorm_roster_meta';
        $charsetCollate = $wpdb->get_charset_collate();

        // dbDelta requirements:
        //   - Two spaces between PRIMARY KEY and the key definition.
        //   - KEY / UNIQUE KEY (not INDEX) for secondary indexes.
        //   - Each column/key on its own line.
        return "CREATE TABLE {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  org_uuid VARCHAR(36) NOT NULL,
  membership_uuid VARCHAR(36) NOT NULL,
  roster_status ENUM('idle','in_progress','syncing','has_failures','synced') NULL DEFAULT NULL,
  last_updated_at DATETIME NOT NULL,
  last_updated_by VARCHAR(255) NOT NULL,
  last_synced_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY membership_uuid (membership_uuid),
  KEY org_uuid (org_uuid),
  KEY roster_status (roster_status)
) {$charsetCollate};";
    }

    /**
     * Build the CREATE TABLE SQL for wp_wicket_aorm_staged_records.
     *
     * Extracted as a protected method so unit tests can assert on the
     * schema string without requiring a live database.
     */
    protected function buildStagedRecordsSql(): string
    {
        global $wpdb;

        $table          = $wpdb->prefix . 'wicket_aorm_staged_records';
        $charsetCollate = $wpdb->get_charset_collate();

        // dbDelta requirements:
        //   - Two spaces between PRIMARY KEY and the key definition.
        //   - KEY keyword (not INDEX) for secondary indexes.
        //   - Each column/key on its own line.
        return "CREATE TABLE {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  upload_session_id VARCHAR(36) NOT NULL,
  action_type ENUM('add','replace') NOT NULL,
  org_uuid VARCHAR(36) NOT NULL,
  membership_uuid VARCHAR(36) NOT NULL,
  raw_data LONGTEXT NOT NULL,
  validation_status ENUM('valid','invalid','duplicate') NOT NULL,
  validation_message VARCHAR(255) NULL DEFAULT NULL,
  category ENUM('ready_to_sync','possible_match','probable_match','manual_update','discard') NOT NULL,
  previous_category ENUM('ready_to_sync','possible_match','probable_match','manual_update','discard') NULL DEFAULT NULL,
  record_status ENUM('new_record','exact_match','merging_to_record','already_on_roster','remove_existing') NOT NULL,
  match_count INT NOT NULL DEFAULT 0,
  matched_persons LONGTEXT NULL DEFAULT NULL,
  match_details LONGTEXT NULL DEFAULT NULL,
  merge_target_uuid VARCHAR(36) NULL DEFAULT NULL,
  merge_preview LONGTEXT NULL DEFAULT NULL,
  sync_status ENUM('pending','ready_to_sync','synced','failed') NOT NULL DEFAULT 'pending',
  error_details TEXT NULL DEFAULT NULL,
  uploaded_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY upload_session_id (upload_session_id),
  KEY org_uuid (org_uuid),
  KEY membership_uuid (membership_uuid),
  KEY sync_status (sync_status),
  KEY category (category)
) {$charsetCollate};";
    }
}
