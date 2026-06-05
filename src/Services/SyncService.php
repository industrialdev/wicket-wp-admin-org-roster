<?php

declare(strict_types=1);

namespace WicketAORM\Services;

/**
 * Sync staged records to MDP (relationships, persons).
 *
 * The per-record sync logic is implemented ticket-by-ticket in AORM-9.4+.
 * This class is instantiated by SyncJobRunner (AORM-9.3); the syncRecord()
 * entry point is the only public surface called by the runner.
 */
class SyncService
{
    /**
     * WordPress option key for plugin settings.
     *
     * The 'roster_type' key within this option controls whether the Relationship
     * or Direct Assignment sync path is used. Phase 1 always routes to the
     * Relationship path regardless of this setting.
     */
    public const SETTINGS_OPTION = 'wicket_aorm_settings';

    /**
     * Roster type value for the Relationship sync path.
     *
     * Persons are linked to the org via a person-to-org relationship record.
     * This is the only path active in Phase 1.
     */
    public const ROSTER_TYPE_RELATIONSHIP = 'relationship';

    /**
     * Roster type value for the Direct Assignment sync path (Phase 2).
     *
     * Persons are linked via a membership assignment. Routing to this path
     * is unlocked in Phase 2; AORM-9.4 always falls through to the Relationship
     * path in the interim.
     */
    public const ROSTER_TYPE_DIRECT_ASSIGNMENT = 'direct_assignment';

    /**
     * Settings key for the email address type used when creating a person in MDP.
     *
     * Stored under wicket_aorm_settings[email_address_type]. Defaults to 'work'.
     *
     * @see AORM-9.5
     */
    public const SETTINGS_KEY_EMAIL_TYPE = 'email_address_type';

    /**
     * Settings key for the phone number type used when creating a person in MDP.
     *
     * Stored under wicket_aorm_settings[phone_number_type]. Defaults to 'work'.
     *
     * @see AORM-9.5
     */
    public const SETTINGS_KEY_PHONE_TYPE = 'phone_number_type';

    /**
     * Default email address type sent to PersonService when the setting is absent.
     *
     * @see AORM-9.5
     */
    public const DEFAULT_EMAIL_TYPE = 'work';

    /**
     * Default phone number type sent to PersonService when the setting is absent.
     *
     * @see AORM-9.5
     */
    public const DEFAULT_PHONE_TYPE = 'work';

    /**
     * @param \WicketORM\Services\PersonService|null    $personService
     *   Optional PersonService instance for DI / testing. When null, a fresh
     *   instance is created on first use.
     * @param \WicketORM\Services\ConnectionService|null $connectionService
     *   Optional ConnectionService instance for DI / testing. When null, a
     *   fresh instance is created on first use.
     */
    public function __construct(
        private readonly ?\WicketORM\Services\PersonService $personService = null,
        private readonly ?\WicketORM\Services\ConnectionService $connectionService = null,
    ) {
    }

    /**
     * Sync a single staged record to MDP.
     *
     * Reads the roster type from wicket_aorm_settings and branches to the
     * appropriate sync path. Phase 1 always routes to the Relationship path;
     * the Direct Assignment branch is unlocked in Phase 2 (AORM-9.16+).
     *
     * @param array<string, mixed> $record  A row from wp_wicket_aorm_staged_records.
     *
     * @see AORM-9.4  — roster type routing (Phase 1: always relationship path)
     * @see AORM-9.5  — new_record: create person
     * @see AORM-9.6  — new_record: create relationship
     * @see AORM-9.8  — exact_match / already_on_roster: update title
     * @see AORM-9.11 — merging_to_record: update name/title
     * @see AORM-9.15 — remove_existing: end-date relationship
     */
    public function syncRecord(array $record): void
    {
        $settings   = (array) get_option(self::SETTINGS_OPTION, []);
        $rosterType = (string) ($settings['roster_type'] ?? self::ROSTER_TYPE_RELATIONSHIP);

        // Phase 1: always route to the Relationship path.
        // Direct Assignment routing is implemented in Phase 2 (AORM-9.16+).
        $this->syncViaRelationshipPath($record);
    }

    // ── Sync paths ────────────────────────────────────────────────────────

    /**
     * Sync a single record via the Relationship path.
     *
     * Dispatches to per-status handlers based on the record's record_status:
     *   new_record            → AORM-9.5 / AORM-9.6 / AORM-9.7
     *   exact_match           → AORM-9.8 / AORM-9.9 / AORM-9.10
     *   already_on_roster     → AORM-9.8 / AORM-9.10
     *   merging_to_record     → AORM-9.11 / AORM-9.12 / AORM-9.13 / AORM-9.14
     *   remove_existing       → AORM-9.15
     *
     * @param array<string, mixed> $record  A row from wp_wicket_aorm_staged_records.
     *
     * @see AORM-9.5  through AORM-9.15 — per-status handlers
     */
    protected function syncViaRelationshipPath(array $record): void
    {
        $status = (string) ($record['record_status'] ?? '');

        match ($status) {
            'new_record'        => $this->syncNewRecordViaRelationship($record),
            // AORM-9.8 through AORM-9.15 — stubs filled in by subsequent tickets.
            default             => null,
        };
    }

    // ── Per-status handlers ───────────────────────────────────────────────

    /**
     * Handle new_record sync via the Relationship path.
     *
     * 1. Finds or creates the person in MDP via PersonService::createOrGetPerson(),
     *    using the email and phone types configured in wicket_aorm_settings (AORM-9.5).
     * 2. Creates (or confirms existence of) a default-type person-to-org relationship
     *    via ConnectionService::ensurePersonConnection() (AORM-9.6). The relationship
     *    type is read from OrgManConfig::get()['relationships']['type']; start date and
     *    idempotency are handled by the service — do not reimplement.
     *
     * @param array<string, mixed> $record  A row from wp_wicket_aorm_staged_records.
     * @return string  MDP person UUID.
     *
     * @see AORM-9.5 — create person
     * @see AORM-9.6 — create relationship to roster org
     * @see AORM-9.7 — apply user role + config security roles (next ticket)
     */
    protected function syncNewRecordViaRelationship(array $record): string
    {
        $rawData = is_string($record['raw_data'] ?? null)
            ? (array) (json_decode((string) $record['raw_data'], true) ?? [])
            : (array) ($record['raw_data'] ?? []);

        // ── AORM-9.5: find or create the person ──────────────────────────

        $settings  = (array) get_option(self::SETTINGS_OPTION, []);
        $emailType = (string) ($settings[self::SETTINGS_KEY_EMAIL_TYPE] ?? self::DEFAULT_EMAIL_TYPE);
        $phoneType = (string) ($settings[self::SETTINGS_KEY_PHONE_TYPE] ?? self::DEFAULT_PHONE_TYPE);

        $personService = $this->personService ?? new \WicketORM\Services\PersonService();

        $personUuid = (string) $personService->createOrGetPerson([
            'given_name'  => (string) ($rawData['first_name'] ?? ''),
            'family_name' => (string) ($rawData['last_name'] ?? ''),
            'email'       => (string) ($rawData['email_address'] ?? ''),
            'phone'       => (string) ($rawData['mobile_phone'] ?? ''),
            'email_type'  => $emailType,
            'phone_type'  => $phoneType,
        ]);

        // ── AORM-9.6: create default-type relationship to roster org ──────

        $orgUuid           = (string) ($record['org_uuid'] ?? '');
        $orgManConfig      = \WicketORM\Config\OrgManConfig::get();
        $relationshipType  = (string) ($orgManConfig['relationships']['type'] ?? '');

        $connectionService = $this->connectionService ?? new \WicketORM\Services\ConnectionService();
        $connectionService->ensurePersonConnection($personUuid, $orgUuid, ['type' => $relationshipType]);

        return $personUuid;
    }
}
