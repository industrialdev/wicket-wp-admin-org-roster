<?php

declare(strict_types=1);

namespace WicketAORM\Admin;

use WicketAORM\Services\MdpClient;
use WicketAORM\Services\SyncService;
use WicketAORM\Services\SyncJobRunner;
use WicketORM\Config\OrgManConfig;

/**
 * WordPress Settings API integration for AORM.
 *
 * Registers the `wicket_aorm_settings` option and its sanitization callback.
 * All AORM settings are stored as a single serialized array in wp_options.
 *
 * Keys registered here:
 *   - roster_type          (string) 'relationship' | 'direct_assignment'
 *   - email_address_type   (string) e.g. 'work', 'home', 'personal'
 *   - phone_number_type    (string) e.g. 'work', 'home', 'mobile'
 *   - security_roles       (string[]) array of MDP role slug strings
 *   - sync_batch_size      (int) positive integer, default 50
 *   - cleanup_ttl_days     (int) positive integer, default 30
 *
 * Sections and fields (add_settings_section / add_settings_field) are
 * added in subsequent tickets (AORM-11.3, 11.7, 11.8, 11.10).
 *
 * @see AORM-11.2
 */
class SettingsPage
{
    /** Option group used in settings_fields() and register_setting(). */
    public const OPTION_GROUP = 'wicket_aorm_settings_group';

    /** wp_options key — matches the key used throughout SyncService / SyncJobRunner. */
    public const OPTION_NAME = 'wicket_aorm_settings';

    /** Settings page slug — matches the submenu registered in MenuPage. */
    public const PAGE_SLUG = 'wicket-aorm-settings';

    // -------------------------------------------------------------------------
    // Valid values
    // -------------------------------------------------------------------------

    /** Allowed roster_type values. */
    public const VALID_ROSTER_TYPES = [
        SyncService::ROSTER_TYPE_RELATIONSHIP,
        SyncService::ROSTER_TYPE_DIRECT_ASSIGNMENT,
    ];

    /** Default sync batch size — mirrors SyncJobRunner::DEFAULT_BATCH_SIZE. */
    public const DEFAULT_BATCH_SIZE = SyncJobRunner::DEFAULT_BATCH_SIZE;

    /** Default number of days before completed staged records are purged. */
    public const DEFAULT_CLEANUP_TTL_DAYS = 30;

    // -------------------------------------------------------------------------
    // Section / field IDs
    // -------------------------------------------------------------------------

    /** Section 1 — Roster type. */
    public const SECTION_ROSTER_TYPE = 'aorm_section_roster_type';

    /** Field ID for the roster_type select. */
    public const FIELD_ROSTER_TYPE = 'aorm_field_roster_type';

    // Section 2 (Relationship Config) was removed in AORM-11.12: the roster type
    // is locked to "Relationship" in Phase 1, the section had no fields (AORM-11.5
    // was dropped), and the JS toggle became dead code. Restore in Phase 2 if
    // relationship-specific settings are needed.

    /**
     * Section 3 — Security roles.
     *
     * Additional MDP role slugs (beyond the base member role from OrgManConfig)
     * that are applied to every synced roster member via applyPersonOrgRoles().
     * Roles are sourced from OrgManConfig::get()['access']['roles']['labels'] so
     * the displayed options always match the active child-theme configuration.
     *
     * @see AORM-11.6
     */
    public const SECTION_SECURITY_ROLES = 'aorm_section_security_roles';

    /** Field ID for the security_roles checkbox group. */
    public const FIELD_SECURITY_ROLES = 'aorm_field_security_roles';

    /**
     * Section 4 — Default email type.
     *
     * Stores the MDP `email_type` value used when creating or updating a
     * person's email address during a roster sync. Available options are
     * fetched from the MDP schema definitions endpoint; the field falls back
     * to the well-known set ('work', 'home', 'personal') when the MDP is
     * unavailable.
     *
     * @see AORM-11.7
     */
    public const SECTION_EMAIL_TYPE = 'aorm_section_email_type';

    /** Field ID for the email_address_type select. */
    public const FIELD_EMAIL_TYPE = 'aorm_field_email_type';

    /**
     * Section 5 — Default phone type.
     *
     * Stores the MDP `phone_type` value used when creating or updating a
     * person's phone number during a roster sync. Available options are
     * fetched from the MDP schema definitions endpoint; the field falls back
     * to the well-known set ('work', 'home', 'mobile') when the MDP is
     * unavailable.
     *
     * @see AORM-11.8
     */
    public const SECTION_PHONE_TYPE = 'aorm_section_phone_type';

    /** Field ID for the phone_number_type select. */
    public const FIELD_PHONE_TYPE = 'aorm_field_phone_type';

    /**
     * Section 7 — Background job settings.
     *
     * Controls the background processing behaviour for roster sync jobs:
     *   - sync_batch_size   (int) records per job invocation, default 50
     *   - cleanup_ttl_days  (int) days before completed staged records are purged, default 30
     *
     * @see AORM-11.10
     */
    public const SECTION_BACKGROUND_JOBS = 'aorm_section_background_jobs';

    /** Field ID for the sync_batch_size number input. */
    public const FIELD_SYNC_BATCH_SIZE = 'aorm_field_sync_batch_size';

    /** Field ID for the cleanup_ttl_days number input. */
    public const FIELD_CLEANUP_TTL = 'aorm_field_cleanup_ttl';

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    /**
     * Hook this method to admin_init to register all AORM settings.
     *
     * Registers the single `wicket_aorm_settings` option with a sanitization
     * callback that validates and coerces every known sub-key. Unknown keys are
     * stripped so that the stored array remains well-defined.
     */
    public function registerSettings(): void
    {
        register_setting(
            self::OPTION_GROUP,
            self::OPTION_NAME,
            [
                'type'              => 'array',
                'description'       => 'AORM plugin settings',
                'sanitize_callback' => [$this, 'sanitize'],
                'default'           => [],
            ],
        );
    }

    /**
     * Register all settings sections and fields for the AORM settings page.
     *
     * Designed to be called on admin_init alongside registerSettings() so that
     * WordPress knows about all sections/fields before do_settings_sections()
     * is called from the settings page render callback.
     *
     * @see AORM-11.3
     */
    public function registerSections(): void
    {
        // ── Section 1: Roster Type ────────────────────────────────────────
        add_settings_section(
            self::SECTION_ROSTER_TYPE,
            __('Roster Type', 'wicket-aorm'),
            [$this, 'renderRosterTypeSectionDescription'],
            self::PAGE_SLUG,
        );

        add_settings_field(
            self::FIELD_ROSTER_TYPE,
            __('Roster type', 'wicket-aorm'),
            [$this, 'renderRosterTypeField'],
            self::PAGE_SLUG,
            self::SECTION_ROSTER_TYPE,
            ['label_for' => self::FIELD_ROSTER_TYPE],
        );

        // ── Section 3: Security Roles (AORM-11.6) ────────────────────────
        // Additional roles (beyond the base member role from OrgManConfig) that
        // are applied to every synced roster member. Available options are read
        // from the active child-theme OrgManConfig so this list stays in sync
        // with the rest of the org-roster configuration.
        add_settings_section(
            self::SECTION_SECURITY_ROLES,
            __('Security Roles', 'wicket-aorm'),
            [$this, 'renderSecurityRolesSectionDescription'],
            self::PAGE_SLUG,
        );

        add_settings_field(
            self::FIELD_SECURITY_ROLES,
            __('Additional security roles', 'wicket-aorm'),
            [$this, 'renderSecurityRolesField'],
            self::PAGE_SLUG,
            self::SECTION_SECURITY_ROLES,
        );

        // ── Section 4: Default Email Type (AORM-11.7) ────────────────────
        // The MDP email_type used when creating or adding an email to a person
        // record during a sync. Available options are fetched from the MDP at
        // render time, with a fallback to the well-known set.
        add_settings_section(
            self::SECTION_EMAIL_TYPE,
            __('Default Email Type', 'wicket-aorm'),
            [$this, 'renderEmailTypeSectionDescription'],
            self::PAGE_SLUG,
        );

        add_settings_field(
            self::FIELD_EMAIL_TYPE,
            __('Default email type', 'wicket-aorm'),
            [$this, 'renderEmailTypeField'],
            self::PAGE_SLUG,
            self::SECTION_EMAIL_TYPE,
            ['label_for' => self::FIELD_EMAIL_TYPE],
        );

        // ── Section 5: Default Phone Type (AORM-11.8) ────────────────────
        // The MDP phone_type used when creating or adding a phone number to a
        // person record during a sync. Available options are fetched from the MDP
        // at render time, with a fallback to the well-known set.
        add_settings_section(
            self::SECTION_PHONE_TYPE,
            __('Default Phone Type', 'wicket-aorm'),
            [$this, 'renderPhoneTypeSectionDescription'],
            self::PAGE_SLUG,
        );

        add_settings_field(
            self::FIELD_PHONE_TYPE,
            __('Default phone type', 'wicket-aorm'),
            [$this, 'renderPhoneTypeField'],
            self::PAGE_SLUG,
            self::SECTION_PHONE_TYPE,
            ['label_for' => self::FIELD_PHONE_TYPE],
        );

        // ── Section 7: Background Job Settings (AORM-11.10) ──────────────
        // Controls sync batch size and how long completed staged records are
        // retained before a cleanup job purges them.
        add_settings_section(
            self::SECTION_BACKGROUND_JOBS,
            __('Background Job Settings', 'wicket-aorm'),
            [$this, 'renderBackgroundJobsSectionDescription'],
            self::PAGE_SLUG,
        );

        add_settings_field(
            self::FIELD_SYNC_BATCH_SIZE,
            __('Sync batch size', 'wicket-aorm'),
            [$this, 'renderSyncBatchSizeField'],
            self::PAGE_SLUG,
            self::SECTION_BACKGROUND_JOBS,
            ['label_for' => self::FIELD_SYNC_BATCH_SIZE],
        );

        add_settings_field(
            self::FIELD_CLEANUP_TTL,
            __('Staged record retention (days)', 'wicket-aorm'),
            [$this, 'renderCleanupTtlField'],
            self::PAGE_SLUG,
            self::SECTION_BACKGROUND_JOBS,
            ['label_for' => self::FIELD_CLEANUP_TTL],
        );
    }

    // -------------------------------------------------------------------------
    // Section / field render callbacks
    // -------------------------------------------------------------------------

    /**
     * Render the introductory description for Section 1 (Roster Type).
     *
     * @see AORM-11.3
     */
    public function renderRosterTypeSectionDescription(): void
    {
        echo '<p class="description">';
        echo esc_html__(
            'Choose how members are associated with an organization in the MDP. This setting determines the sync path used when uploading a roster.',
            'wicket-aorm',
        );
        echo '</p>';
    }

    /**
     * Render the roster_type <select> field.
     *
     * Reads the currently-stored value from wp_options so the control reflects
     * the saved configuration on page load. Defaults to "relationship" when no
     * value has been saved yet.
     *
     * @see AORM-11.3
     */
    public function renderRosterTypeField(): void
    {
        $options = (array) get_option(self::OPTION_NAME, []);
        $current = (string) ($options['roster_type'] ?? SyncService::ROSTER_TYPE_RELATIONSHIP);

        $choices = [
            SyncService::ROSTER_TYPE_RELATIONSHIP      => __('Relationship', 'wicket-aorm'),
            SyncService::ROSTER_TYPE_DIRECT_ASSIGNMENT => __('Direct Assignment', 'wicket-aorm'),
        ];

        echo '<select id="' . esc_attr(self::FIELD_ROSTER_TYPE) . '" '
            . 'name="' . esc_attr(self::OPTION_NAME) . '[roster_type]"'
            . ' disabled style="opacity:0.5;cursor:not-allowed;">';

        foreach ($choices as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"'
                . selected($current, $value, false) . '>'
                . esc_html($label)
                . '</option>';
        }

        echo '</select>';
        echo '<p class="description">';
        echo esc_html__(
            'Currently locked to "Relationship" mode (Phase 1). The relationship type and base member role are defined by the Organization Roster plugin configuration. Additional roster types will be available in a future release.',
            'wicket-aorm',
        );
        echo '</p>';
    }

    /**
     * Render the introductory description for Section 3 (Security Roles).
     *
     * @see AORM-11.6
     */
    public function renderSecurityRolesSectionDescription(): void
    {
        echo '<p class="description">';
        echo esc_html__(
            'Select additional MDP security roles to apply to every member synced via a roster upload. These roles are applied on top of the base member role defined in the child-theme OrgMan configuration.',
            'wicket-aorm',
        );
        echo '</p>';
    }

    /**
     * Render the security_roles checkbox group.
     *
     * Available role options are read from OrgManConfig::get()['access']['roles']['labels']
     * so the list automatically reflects the active child-theme configuration.
     * Falls back to an informational notice when OrgManConfig is unavailable
     * (e.g. the wicket-wp-account-centre plugin is not active).
     *
     * Each checkbox posts to wicket_aorm_settings[security_roles][],
     * which the sanitize() callback collects and stores as a string[].
     *
     * @see AORM-11.6
     */
    public function renderSecurityRolesField(): void
    {
        if (!class_exists(OrgManConfig::class)) {
            echo '<p class="description">';
            echo esc_html__(
                'Security roles are unavailable because the wicket-wp-account-centre plugin is not active.',
                'wicket-aorm',
            );
            echo '</p>';

            return;
        }

        $config        = OrgManConfig::get();
        $roleLabels    = (array) ($config['access']['roles']['labels'] ?? []);
        $options       = (array) get_option(self::OPTION_NAME, []);
        $savedRoles    = (array) ($options['security_roles'] ?? []);
        $fieldName     = self::OPTION_NAME . '[security_roles][]';

        if (empty($roleLabels)) {
            echo '<p class="description">';
            echo esc_html__(
                'No roles are defined in the OrgMan configuration. Add roles via the wicket/acc/orgman/config filter in the child theme.',
                'wicket-aorm',
            );
            echo '</p>';

            return;
        }

        echo '<fieldset id="' . esc_attr(self::FIELD_SECURITY_ROLES) . '">';

        foreach ($roleLabels as $slug => $label) {
            $slug    = (string) $slug;
            $label   = (string) $label;
            $checked = in_array($slug, $savedRoles, true);
            $id      = 'aorm-security-role-' . esc_attr($slug);

            echo '<label for="' . esc_attr($id) . '" style="display:block;margin-bottom:4px;">';
            echo '<input type="checkbox"'
                . ' id="' . esc_attr($id) . '"'
                . ' name="' . esc_attr($fieldName) . '"'
                . ' value="' . esc_attr($slug) . '"'
                . checked($checked, true, false)
                . '> ';
            echo esc_html($label);
            echo '</label>';
        }

        echo '</fieldset>';
        echo '<p class="description">';
        echo esc_html__(
            'Checked roles will be assigned (via POST people/{uuid}/roles) to every person synced to this roster, scoped to the roster organization.',
            'wicket-aorm',
        );
        echo '</p>';
    }

    /**
     * Render the introductory description for Section 4 (Default Email Type).
     *
     * @see AORM-11.7
     */
    public function renderEmailTypeSectionDescription(): void
    {
        echo '<p class="description">';
        echo esc_html__(
            'Choose the email address type applied when creating or updating a person\'s email in the MDP during a roster sync.',
            'wicket-aorm',
        );
        echo '</p>';
    }

    /**
     * Render the email_address_type <select> field.
     *
     * Available options are fetched from the MDP via MdpClient::getEmailTypes()
     * at render time. When the MDP is unavailable the field falls back to the
     * well-known set (work / home / personal) so the settings page is always
     * usable. The current saved value is pre-selected; defaults to
     * SyncService::DEFAULT_EMAIL_TYPE ('work') when no value has been stored.
     *
     * @see AORM-11.7
     */
    public function renderEmailTypeField(): void
    {
        $options = (array) get_option(self::OPTION_NAME, []);
        $current = (string) ($options[SyncService::SETTINGS_KEY_EMAIL_TYPE] ?? SyncService::DEFAULT_EMAIL_TYPE);
        $types   = (new MdpClient())->getEmailTypes();

        echo '<select id="' . esc_attr(self::FIELD_EMAIL_TYPE) . '" '
            . 'name="' . esc_attr(self::OPTION_NAME) . '[' . esc_attr(SyncService::SETTINGS_KEY_EMAIL_TYPE) . ']">';

        foreach ($types as $value => $label) {
            echo '<option value="' . esc_attr((string) $value) . '"'
                . selected($current, (string) $value, false) . '>'
                . esc_html((string) $label)
                . '</option>';
        }

        echo '</select>';
        echo '<p class="description">';
        echo esc_html__(
            'This type is assigned to email addresses created for new person records and merge operations during a roster sync.',
            'wicket-aorm',
        );
        echo '</p>';
    }

    /**
     * Render the introductory description for Section 5 (Default Phone Type).
     *
     * @see AORM-11.8
     */
    public function renderPhoneTypeSectionDescription(): void
    {
        echo '<p class="description">';
        echo esc_html__(
            'Choose the phone number type applied when creating or updating a person\'s phone number in the MDP during a roster sync.',
            'wicket-aorm',
        );
        echo '</p>';
    }

    /**
     * Render the phone_number_type <select> field.
     *
     * Available options are fetched from the MDP via MdpClient::getPhoneTypes()
     * at render time. When the MDP is unavailable the field falls back to the
     * well-known set (work / home / mobile) so the settings page is always
     * usable. The current saved value is pre-selected; defaults to
     * SyncService::DEFAULT_PHONE_TYPE ('work') when no value has been stored.
     *
     * @see AORM-11.8
     */
    public function renderPhoneTypeField(): void
    {
        $options = (array) get_option(self::OPTION_NAME, []);
        $current = (string) ($options[SyncService::SETTINGS_KEY_PHONE_TYPE] ?? SyncService::DEFAULT_PHONE_TYPE);
        $types   = (new MdpClient())->getPhoneTypes();

        echo '<select id="' . esc_attr(self::FIELD_PHONE_TYPE) . '" '
            . 'name="' . esc_attr(self::OPTION_NAME) . '[' . esc_attr(SyncService::SETTINGS_KEY_PHONE_TYPE) . ']">';

        foreach ($types as $value => $label) {
            echo '<option value="' . esc_attr((string) $value) . '"'
                . selected($current, (string) $value, false) . '>'
                . esc_html((string) $label)
                . '</option>';
        }

        echo '</select>';
        echo '<p class="description">';
        echo esc_html__(
            'This type is assigned to phone numbers created for new person records and merge operations during a roster sync.',
            'wicket-aorm',
        );
        echo '</p>';
    }

    /**
     * Render the introductory description for Section 7 (Background Job Settings).
     *
     * @see AORM-11.10
     */
    public function renderBackgroundJobsSectionDescription(): void
    {
        echo '<p class="description">';
        echo esc_html__(
            'Configure how background sync jobs process roster uploads and how long staged records are retained after a sync completes.',
            'wicket-aorm',
        );
        echo '</p>';
    }

    /**
     * Render the sync_batch_size number input.
     *
     * Determines how many staged records are processed per background job
     * invocation. Large batches finish faster but put more load on the MDP API.
     * Defaults to self::DEFAULT_BATCH_SIZE (50) when no value has been saved.
     *
     * @see AORM-11.10
     */
    public function renderSyncBatchSizeField(): void
    {
        $options = (array) get_option(self::OPTION_NAME, []);
        $current = (int) ($options['sync_batch_size'] ?? self::DEFAULT_BATCH_SIZE);

        echo '<input'
            . ' type="number"'
            . ' id="' . esc_attr(self::FIELD_SYNC_BATCH_SIZE) . '"'
            . ' name="' . esc_attr(self::OPTION_NAME) . '[sync_batch_size]"'
            . ' value="' . esc_attr((string) $current) . '"'
            . ' min="1"'
            . ' step="1"'
            . ' class="small-text"'
            . '>';
        echo '<p class="description">';
        echo esc_html__(
            'Number of records synced per background job run. Increase for faster processing; decrease to reduce MDP API load. Default: 50.',
            'wicket-aorm',
        );
        echo '</p>';
    }

    /**
     * Render the cleanup_ttl_days number input.
     *
     * Determines how many days completed staged records are retained in the
     * wp_wicket_aorm_staged_records table before a scheduled cleanup job purges
     * them. Defaults to self::DEFAULT_CLEANUP_TTL_DAYS (30) when no value has
     * been saved.
     *
     * @see AORM-11.10
     */
    public function renderCleanupTtlField(): void
    {
        $options = (array) get_option(self::OPTION_NAME, []);
        $current = (int) ($options['cleanup_ttl_days'] ?? self::DEFAULT_CLEANUP_TTL_DAYS);

        echo '<input'
            . ' type="number"'
            . ' id="' . esc_attr(self::FIELD_CLEANUP_TTL) . '"'
            . ' name="' . esc_attr(self::OPTION_NAME) . '[cleanup_ttl_days]"'
            . ' value="' . esc_attr((string) $current) . '"'
            . ' min="1"'
            . ' step="1"'
            . ' class="small-text"'
            . '>';
        echo '<p class="description">';
        echo esc_html__(
            'Number of days to retain staged records after a sync completes before they are purged by the cleanup job. Default: 30.',
            'wicket-aorm',
        );
        echo '</p>';
    }

    // -------------------------------------------------------------------------
    // Sanitization
    // -------------------------------------------------------------------------

    /**
     * Sanitize the raw settings array submitted via the WP Settings form.
     *
     * Called by WordPress when the option is saved. Returns a clean array
     * containing only the recognized keys, each coerced to its expected type
     * and validated against allowed values where applicable.
     *
     * On validation errors an admin notice is added via add_settings_error()
     * and the previous valid value is preserved for that key.
     *
     * @param  mixed $raw Raw $_POST value (may not be an array).
     * @return array<string, mixed> Sanitized settings array.
     */
    public function sanitize(mixed $raw): array
    {
        $raw  = is_array($raw) ? $raw : [];
        $prev = (array) get_option(self::OPTION_NAME, []);
        $out  = [];

        // ── roster_type ───────────────────────────────────────────────────
        if (array_key_exists('roster_type', $raw)) {
            $value = sanitize_text_field((string) ($raw['roster_type'] ?? ''));

            if (in_array($value, self::VALID_ROSTER_TYPES, true)) {
                $out['roster_type'] = $value;
            } else {
                add_settings_error(
                    self::OPTION_NAME,
                    'invalid_roster_type',
                    sprintf(
                        /* translators: %s: submitted value */
                        __('Invalid roster type "%s". Keeping previous value.', 'wicket-aorm'),
                        esc_html($value),
                    ),
                );
                $out['roster_type'] = $prev['roster_type'] ?? SyncService::ROSTER_TYPE_RELATIONSHIP;
            }
        } else {
            $out['roster_type'] = $prev['roster_type'] ?? SyncService::ROSTER_TYPE_RELATIONSHIP;
        }

        // ── email_address_type ────────────────────────────────────────────
        $out['email_address_type'] = sanitize_text_field(
            (string) ($raw['email_address_type'] ?? $prev['email_address_type'] ?? SyncService::DEFAULT_EMAIL_TYPE),
        );

        // ── phone_number_type ─────────────────────────────────────────────
        $out['phone_number_type'] = sanitize_text_field(
            (string) ($raw['phone_number_type'] ?? $prev['phone_number_type'] ?? SyncService::DEFAULT_PHONE_TYPE),
        );

        // ── security_roles ────────────────────────────────────────────────
        $rawRoles = $raw['security_roles'] ?? $prev['security_roles'] ?? [];
        $rawRoles = is_array($rawRoles) ? $rawRoles : [];

        $out['security_roles'] = array_values(
            array_unique(
                array_filter(
                    array_map('sanitize_text_field', array_map('strval', $rawRoles)),
                    static fn (string $s): bool => $s !== '',
                ),
            ),
        );

        // ── sync_batch_size ───────────────────────────────────────────────
        $batchRaw = $raw['sync_batch_size'] ?? $prev['sync_batch_size'] ?? self::DEFAULT_BATCH_SIZE;
        $batch    = (int) $batchRaw;

        if ($batch > 0) {
            $out['sync_batch_size'] = $batch;
        } else {
            add_settings_error(
                self::OPTION_NAME,
                'invalid_sync_batch_size',
                __('Sync batch size must be a positive integer. Reverting to previous value.', 'wicket-aorm'),
            );
            $out['sync_batch_size'] = (int) ($prev['sync_batch_size'] ?? self::DEFAULT_BATCH_SIZE);
        }

        // ── cleanup_ttl_days ──────────────────────────────────────────────
        $ttlRaw = $raw['cleanup_ttl_days'] ?? $prev['cleanup_ttl_days'] ?? self::DEFAULT_CLEANUP_TTL_DAYS;
        $ttl    = (int) $ttlRaw;

        if ($ttl > 0) {
            $out['cleanup_ttl_days'] = $ttl;
        } else {
            add_settings_error(
                self::OPTION_NAME,
                'invalid_cleanup_ttl_days',
                __('Staged record retention must be a positive integer. Reverting to previous value.', 'wicket-aorm'),
            );
            $out['cleanup_ttl_days'] = (int) ($prev['cleanup_ttl_days'] ?? self::DEFAULT_CLEANUP_TTL_DAYS);
        }

        return $out;
    }
}
