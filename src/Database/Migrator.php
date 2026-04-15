<?php

declare(strict_types=1);

namespace WicketAORM\Database;

/**
 * Schema create/upgrade on activation.
 */
class Migrator
{
    /**
     * Create or update all plugin database tables.
     */
    public function up(): void
    {
        if (! function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        dbDelta($this->buildStagedRecordsSql());
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
