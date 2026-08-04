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
     * Settings key for the security roles applied to a person when added to a roster.
     *
     * Stored under wicket_aorm_settings[security_roles] as an array of role slug strings.
     * Roles are applied scoped to the roster org after relationship creation.
     *
     * @see AORM-9.7
     */
    public const SETTINGS_KEY_SECURITY_ROLES = 'security_roles';

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
     * @param MdpClient|null                             $mdpClient
     *   Optional MdpClient instance for DI / testing. When null, a fresh
     *   instance is created on first use.
     * @param \WicketORM\Services\MembershipService|null $membershipService
     *   Optional MembershipService instance for DI / testing. When null, a
     *   fresh instance is created on first use.
     *
     * @see AORM-9.24 — membershipService added for the Direct Assignment remove_existing path
     */
    public function __construct(
        private readonly ?\WicketORM\Services\PersonService $personService = null,
        private readonly ?\WicketORM\Services\ConnectionService $connectionService = null,
        private readonly ?MdpClient $mdpClient = null,
        private readonly ?\WicketORM\Services\MembershipService $membershipService = null,
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
     * @see AORM-9.12 — merging_to_record: add imported email as primary, demote existing
     * @see AORM-9.13 — merging_to_record: end other-org relationships, ensure roster-org relationship
     * @see AORM-9.14 — merging_to_record: apply config security roles, keep existing
     * @see AORM-9.15 — remove_existing: end-date relationship
     * @see AORM-9.16 — remove_existing: revoke config security roles
     * @see AORM-9.20 — Direct Assignment exact_match/already_on_roster: update title, create assignment if not active
     * @see AORM-9.21 — Direct Assignment exact_match/already_on_roster: ensure roles
     * @see AORM-9.22 — Direct Assignment merging_to_record: update fields, add email as primary, create assignment if missing
     * @see AORM-9.23 — Direct Assignment merging_to_record: apply config security roles, keep existing
     * @see AORM-9.24 — Direct Assignment remove_existing: end date assignment (today + timestamp)
     */
    public function syncRecord(array $record): void
    {
        $settings   = (array) get_option(self::SETTINGS_OPTION, []);
        $rosterType = (string) ($settings['roster_type'] ?? self::ROSTER_TYPE_RELATIONSHIP);

        // Phase 1: always route to the Relationship path.
        // Direct Assignment routing is unlocked in Phase 2.
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
     *   remove_existing       → AORM-9.15 / AORM-9.16
     *
     * @param array<string, mixed> $record  A row from wp_wicket_aorm_staged_records.
     *
     * @see AORM-9.5  through AORM-9.15 — per-status handlers
     * @see AORM-9.11 — merging_to_record: update name/title from import
     */
    protected function syncViaRelationshipPath(array $record): void
    {
        $status = (string) ($record['record_status'] ?? '');

        match ($status) {
            'new_record'        => $this->syncNewRecordViaRelationship($record),
            'exact_match'       => $this->syncExactMatchViaRelationship($record),
            'already_on_roster' => $this->syncAlreadyOnRosterViaRelationship($record),
            'merging_to_record' => $this->syncMergingToRecordViaRelationship($record),
            'remove_existing'   => $this->syncRemoveExistingViaRelationship($record),
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
     *    type defaults are resolved internally by ConnectionService via
     *    RelationshipHelper::get_default_relationship_type(); start date and
     *    idempotency are handled by the service — do not reimplement.
     * 3. Applies the user role (from OrgManConfig::get()['roles']['user']) and any
     *    configured security roles (from wicket_aorm_settings[security_roles]) to the
     *    person, scoped to the roster org, via the shared ensureUserAndSecurityRoles()
     *    helper, which wraps MdpClient::applyPersonOrgRoles() (AORM-9.7 / AORM-9.10).
     *    Duplicate slugs are deduplicated; empty slugs are filtered out.
     *
     * @param array<string, mixed> $record  A row from wp_wicket_aorm_staged_records.
     * @return string  MDP person UUID.
     *
     * @see AORM-9.5 — create person
     * @see AORM-9.6 — create relationship to roster org
     * @see AORM-9.7 — apply user role + config security roles
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

        $personResult = $personService->createOrGetPerson(
            (string) ($rawData['first_name'] ?? ''),
            (string) ($rawData['last_name'] ?? ''),
            (string) ($rawData['email'] ?? ''),
            [
                'phone'      => (string) ($rawData['phone'] ?? ''),
                'email_type' => $emailType,
                'phone_type' => $phoneType,
            ]
        );

        if (is_wp_error($personResult)) {
            throw new \Exception($personResult->get_error_message());
        }

        $personUuid = (string) $personResult;

        // ── AORM-9.6: create default-type relationship to roster org ──────

        $orgUuid           = (string) ($record['org_uuid'] ?? '');

        $connectionService = $this->connectionService ?? new \WicketORM\Services\ConnectionService();
        $connectionService->ensurePersonConnection($personUuid, $orgUuid);

        // ── AORM-9.7: apply user role + config security roles ─────────────

        $this->ensureUserAndSecurityRoles($personUuid, $orgUuid);

        return $personUuid;
    }

    /**
     * Handle exact_match sync via the Relationship path.
     *
     * 1. Updates the person's job title in MDP from the imported raw_data.title
     *    field, when a non-empty title is present (AORM-9.8).
     * 2. Ends default-type relationships to other orgs (skipping protected
     *    relationship types such as admin roles) and ensures a default-type
     *    relationship exists to the roster org, creating one if missing (AORM-9.9).
     * 3. Ensures the user role + configured security roles scoped to the roster
     *    org via the shared ensureUserAndSecurityRoles() helper (AORM-9.10).
     *
     * The person UUID is resolved from the first entry in `matched_persons`.
     *
     * @param array<string, mixed> $record  A row from wp_wicket_aorm_staged_records.
     *
     * @see AORM-9.8  — update title
     * @see AORM-9.9  — end other-org relationships, ensure roster-org relationship
     * @see AORM-9.10 — ensure roles
     */
    protected function syncExactMatchViaRelationship(array $record): void
    {
        $personUuid = $this->extractPersonUuidFromMatchedPersons($record);

        if ($personUuid === '') {
            return;
        }

        $rawData = is_string($record['raw_data'] ?? null)
            ? (array) (json_decode((string) $record['raw_data'], true) ?? [])
            : (array) ($record['raw_data'] ?? []);

        $orgUuid = (string) ($record['org_uuid'] ?? '');

        // ── AORM-9.8: update title ────────────────────────────────────────

        $this->updatePersonTitleIfPresent($personUuid, $rawData);

        // ── AORM-9.9: end other-org relationships, ensure roster-org one ──

        $this->endOtherOrgRelationshipsAndEnsureRosterRelationship($personUuid, $orgUuid);

        // ── AORM-9.10: ensure user role + config security roles ───────────

        $this->ensureUserAndSecurityRoles($personUuid, $orgUuid);
    }

    /**
     * Handle already_on_roster sync via the Relationship path.
     *
     * 1. Updates the person's job title in MDP from the imported raw_data.title
     *    field, when a non-empty title is present (AORM-9.8).
     * 2. Ensures the user role + configured security roles scoped to the roster
     *    org via the shared ensureUserAndSecurityRoles() helper (AORM-9.10).
     *
     * The person UUID is resolved from the first entry in `matched_persons`.
     *
     * @param array<string, mixed> $record  A row from wp_wicket_aorm_staged_records.
     *
     * @see AORM-9.8  — update title
     * @see AORM-9.10 — ensure roles
     */
    protected function syncAlreadyOnRosterViaRelationship(array $record): void
    {
        $personUuid = $this->extractPersonUuidFromMatchedPersons($record);

        if ($personUuid === '') {
            return;
        }

        $rawData = is_string($record['raw_data'] ?? null)
            ? (array) (json_decode((string) $record['raw_data'], true) ?? [])
            : (array) ($record['raw_data'] ?? []);

        $orgUuid = (string) ($record['org_uuid'] ?? '');

        // ── AORM-9.8: update title ────────────────────────────────────────

        $this->updatePersonTitleIfPresent($personUuid, $rawData);

        // ── AORM-9.10: ensure user role + config security roles ───────────

        $this->ensureUserAndSecurityRoles($personUuid, $orgUuid);
    }

    /**
     * Handle merging_to_record sync via the Relationship path.
     *
     * 1. Updates the merge target's first name, last name, and job title in
     *    MDP from the imported raw_data fields, when at least one of them is
     *    present (AORM-9.11).
     * 2. Adds the imported `email_address` as the merge target's new primary
     *    email and demotes whichever address(es) currently hold the primary
     *    flag, when a non-empty email is present (AORM-9.12).
     * 3. Ends default-type relationships to other orgs (skipping protected
     *    relationship types such as admin roles) and ensures a default-type
     *    relationship exists to the roster org, creating one if missing
     *    (AORM-9.13). Delegates to the shared
     *    endOtherOrgRelationshipsAndEnsureRosterRelationship() helper —
     *    same logic as the exact_match path (AORM-9.9).
     * 4. Applies the configured security roles (and user role) scoped to the
     *    roster org via the shared ensureUserAndSecurityRoles() helper
     *    (AORM-9.14). Roles are applied additively via POST — existing roles
     *    on the person are preserved ("keep existing").
     *
     * The person to update is the admin-selected merge target — NOT the first
     * `matched_persons` candidate. It is resolved from the staged record's
     * `merge_target_uuid` column, which is set when the admin chose "Merge to
     * Existing" in the Review Match modal and saved via
     * `PATCH /wicket-aorm/v1/staged/{id}/resolve` (AORM-8B.18). The handler is
     * a no-op when that column is empty.
     *
     * @param array<string, mixed> $record  A row from wp_wicket_aorm_staged_records.
     *
     * @see AORM-9.11 — update first/last name + title from import
     * @see AORM-9.12 — add imported email as primary, demote existing
     * @see AORM-9.13 — end other-org relationships, ensure roster-org relationship
     * @see AORM-9.14 — apply config security roles, keep existing
     */
    protected function syncMergingToRecordViaRelationship(array $record): void
    {
        $personUuid = (string) ($record['merge_target_uuid'] ?? '');

        if ($personUuid === '') {
            return;
        }

        $rawData = is_string($record['raw_data'] ?? null)
            ? (array) (json_decode((string) $record['raw_data'], true) ?? [])
            : (array) ($record['raw_data'] ?? []);

        $orgUuid = (string) ($record['org_uuid'] ?? '');

        // ── AORM-9.11: update first/last name + title from import ─────────

        $this->updatePersonNameAndTitleIfPresent($personUuid, $rawData);

        // ── AORM-9.12: add imported email as primary, demote existing ─────

        $this->addImportedEmailAsPrimaryIfPresent($personUuid, $rawData);

        // ── AORM-9.13: end other-org relationships, ensure roster-org one ──

        $this->endOtherOrgRelationshipsAndEnsureRosterRelationship($personUuid, $orgUuid);

        // ── AORM-9.14: apply config security roles, keep existing ─────────

        $this->ensureUserAndSecurityRoles($personUuid, $orgUuid);
    }

    /**
     * Handle remove_existing sync via the Relationship path.
     *
     * End-dates the person's active default-type relationship(s) to the roster
     * org by delegating to
     * ConnectionService::endActivePersonOrganizationConnections(). Protected
     * relationship types (e.g. admin roles) configured in
     * OrgManConfig['member_management']['addition']['protected_relationship_types']
     * are passed as $skipTypes so they are left untouched.
     *
     * The person UUID is read from raw_data.person_uuid (not matched_persons —
     * that field is always null for synthetic remove_existing rows generated by
     * ReplacementDiffController). The handler is a no-op when either UUID is
     * absent or empty.
     *
     * @param array<string, mixed> $record  A row from wp_wicket_aorm_staged_records.
     *
     * @see AORM-9.15 — end-date relationship
     * @see AORM-9.16 — revoke config security roles scoped to roster org
     */
    protected function syncRemoveExistingViaRelationship(array $record): void
    {
        $rawData = is_string($record['raw_data'] ?? null)
            ? (array) (json_decode((string) $record['raw_data'], true) ?? [])
            : (array) ($record['raw_data'] ?? []);

        $personUuid = (string) ($rawData['person_uuid'] ?? '');
        $orgUuid    = (string) ($record['org_uuid'] ?? '');

        if ($personUuid === '' || $orgUuid === '') {
            return;
        }

        $connectionService = $this->connectionService ?? new \WicketORM\Services\ConnectionService();
        $orgManConfig      = \WicketORM\Config\OrgManConfig::get();

        $skipTypes = array_values(array_filter(
            array_map('strval', (array) ($orgManConfig['member_management']['addition']['protected_relationship_types'] ?? [])),
            fn (string $type): bool => $type !== '',
        ));

        // ── AORM-9.15: end-date the relationship to the roster org ───────────

        $connectionService->endActivePersonOrganizationConnections($personUuid, $orgUuid, $skipTypes);

        // ── AORM-9.16: revoke config security roles scoped to roster org ─────

        $this->revokeConfigSecurityRoles($personUuid, $orgUuid);
    }

    // ── Direct Assignment path (Phase 2) ─────────────────────────────────

    /**
     * Handle new_record sync via the Direct Assignment path.
     *
     * Finds or creates the person in MDP via PersonService::createOrGetPerson(),
     * using the same email/phone type settings as the Relationship path
     * (wicket_aorm_settings[email_address_type]/[phone_number_type], defaulting
     * to self::DEFAULT_EMAIL_TYPE/DEFAULT_PHONE_TYPE).
     *
     * Person creation is identical between sync paths: WicketORM's
     * PersonService::createOrGetPerson() / createOrUpdatePerson() are both thin
     * wrappers around the same wicket_create_or_get_person() call regardless of
     * how the person is subsequently linked to the roster org (relationship vs.
     * membership assignment), so this reuses the exact call shape used by
     * syncNewRecordViaRelationship() (AORM-9.5) rather than introducing a second
     * convention.
     *
     * Also creates the Direct Assignment membership record linking the
     * person to the roster org — a `person_memberships` resource, not a
     * `connections` resource like the Relationship path — via
     * MdpClient::createPersonMembershipAssignment(), using the roster
     * membership_uuid already present on the staged record row (AORM-9.18).
     * The assignment's start date is the current sync action time, not the
     * underlying membership tier's own cycle start date.
     *
     * Finally applies the user role (from OrgManConfig::get()['member_management']
     * ['addition']['base_member_role']) and any configured security roles (from
     * wicket_aorm_settings[security_roles]) to the person, scoped to the roster
     * org, via the same shared ensureUserAndSecurityRoles() helper used by the
     * Relationship path (AORM-9.7 / AORM-9.10 / AORM-9.14) — role application is
     * identical between paths regardless of how the person is linked to the
     * roster org (relationship vs. membership assignment) (AORM-9.19).
     *
     * This method is not yet wired to a Direct Assignment dispatcher or to
     * syncRecord()'s routing — that lands with a later Phase 2 ticket.
     *
     * @param array<string, mixed> $record  A row from wp_wicket_aorm_staged_records.
     * @return string  MDP person UUID.
     *
     * @see AORM-9.17 — create person
     * @see AORM-9.18 — create membership assignment (roster org, start = today)
     * @see AORM-9.19 — apply user role + config security roles
     */
    protected function syncNewRecordViaDirectAssignment(array $record): string
    {
        $rawData = is_string($record['raw_data'] ?? null)
            ? (array) (json_decode((string) $record['raw_data'], true) ?? [])
            : (array) ($record['raw_data'] ?? []);

        // ── AORM-9.17: find or create the person ──────────────────────────

        $settings  = (array) get_option(self::SETTINGS_OPTION, []);
        $emailType = (string) ($settings[self::SETTINGS_KEY_EMAIL_TYPE] ?? self::DEFAULT_EMAIL_TYPE);
        $phoneType = (string) ($settings[self::SETTINGS_KEY_PHONE_TYPE] ?? self::DEFAULT_PHONE_TYPE);

        $personService = $this->personService ?? new \WicketORM\Services\PersonService();

        $personResult = $personService->createOrGetPerson(
            (string) ($rawData['first_name'] ?? ''),
            (string) ($rawData['last_name'] ?? ''),
            (string) ($rawData['email'] ?? ''),
            [
                'phone'      => (string) ($rawData['phone'] ?? ''),
                'email_type' => $emailType,
                'phone_type' => $phoneType,
            ]
        );

        if (is_wp_error($personResult)) {
            throw new \Exception($personResult->get_error_message());
        }

        $personUuid = (string) $personResult;

        // ── AORM-9.18: create membership assignment to the roster org ─────

        $membershipUuid = (string) ($record['membership_uuid'] ?? '');

        $mdpClient = $this->mdpClient ?? new MdpClient();
        $mdpClient->createPersonMembershipAssignment($personUuid, $membershipUuid);

        // ── AORM-9.19: apply user role + config security roles ────────────

        $orgUuid = (string) ($record['org_uuid'] ?? '');

        $this->ensureUserAndSecurityRoles($personUuid, $orgUuid);

        return $personUuid;
    }

    /**
     * Handle exact_match sync via the Direct Assignment path.
     *
     * 1. Updates the person's job title in MDP from the imported raw_data.title
     *    field, when a non-empty title is present — reuses the same
     *    updatePersonTitleIfPresent() helper as the Relationship path (AORM-9.8).
     * 2. Ensures an ACTIVE Direct Assignment membership exists linking the
     *    person to the roster's org membership. A prior assignment may exist
     *    but have already ended (e.g. the person was previously removed), so
     *    this checks specifically for an active row via
     *    MdpClient::hasActivePersonMembershipAssignment() and only creates a
     *    new assignment via MdpClient::createPersonMembershipAssignment() when
     *    none is currently active.
     *
     * 3. Ensures the user role + configured security roles scoped to the
     *    roster org via the shared ensureUserAndSecurityRoles() helper — same
     *    helper used by the Relationship path (AORM-9.10) and the Direct
     *    Assignment new_record path (AORM-9.19) (AORM-9.21).
     *
     * The person UUID is resolved from the first entry in `matched_persons`,
     * same as syncExactMatchViaRelationship().
     *
     * This method is not yet wired to a Direct Assignment dispatcher or to
     * syncRecord()'s routing — that lands with a later Phase 2 ticket.
     *
     * @param array<string, mixed> $record  A row from wp_wicket_aorm_staged_records.
     *
     * @see AORM-9.20 — update title, create assignment if not active
     * @see AORM-9.21 — ensure roles
     */
    protected function syncExactMatchViaDirectAssignment(array $record): void
    {
        $personUuid = $this->extractPersonUuidFromMatchedPersons($record);

        if ($personUuid === '') {
            return;
        }

        $rawData = is_string($record['raw_data'] ?? null)
            ? (array) (json_decode((string) $record['raw_data'], true) ?? [])
            : (array) ($record['raw_data'] ?? []);

        $membershipUuid = (string) ($record['membership_uuid'] ?? '');
        $orgUuid        = (string) ($record['org_uuid'] ?? '');

        // ── AORM-9.20: update title ───────────────────────────────────────

        $this->updatePersonTitleIfPresent($personUuid, $rawData);

        // ── AORM-9.20: create assignment if not active ────────────────────

        $this->ensureActiveMembershipAssignment($personUuid, $membershipUuid);

        // ── AORM-9.21: ensure user role + config security roles ───────────

        $this->ensureUserAndSecurityRoles($personUuid, $orgUuid);
    }

    /**
     * Handle already_on_roster sync via the Direct Assignment path.
     *
     * Identical behaviour to syncExactMatchViaDirectAssignment() — updates
     * the person's title, then ensures an ACTIVE Direct Assignment membership
     * exists, creating one only when none is currently active. Direct
     * Assignment has no relationship records to end-date between the two
     * statuses (unlike the Relationship path, where exact_match additionally
     * ends other-org relationships), so both statuses share the same steps
     * for this ticket.
     *
     * 3. Ensures the user role + configured security roles scoped to the
     *    roster org via the shared ensureUserAndSecurityRoles() helper (same
     *    helper used by syncExactMatchViaDirectAssignment()) (AORM-9.21).
     *
     * The person UUID is resolved from the first entry in `matched_persons`,
     * same as syncAlreadyOnRosterViaRelationship().
     *
     * This method is not yet wired to a Direct Assignment dispatcher or to
     * syncRecord()'s routing — that lands with a later Phase 2 ticket.
     *
     * @param array<string, mixed> $record  A row from wp_wicket_aorm_staged_records.
     *
     * @see AORM-9.20 — update title, create assignment if not active
     * @see AORM-9.21 — ensure roles
     */
    protected function syncAlreadyOnRosterViaDirectAssignment(array $record): void
    {
        $personUuid = $this->extractPersonUuidFromMatchedPersons($record);

        if ($personUuid === '') {
            return;
        }

        $rawData = is_string($record['raw_data'] ?? null)
            ? (array) (json_decode((string) $record['raw_data'], true) ?? [])
            : (array) ($record['raw_data'] ?? []);

        $membershipUuid = (string) ($record['membership_uuid'] ?? '');
        $orgUuid        = (string) ($record['org_uuid'] ?? '');

        // ── AORM-9.20: update title ───────────────────────────────────────

        $this->updatePersonTitleIfPresent($personUuid, $rawData);

        // ── AORM-9.20: create assignment if not active ────────────────────

        $this->ensureActiveMembershipAssignment($personUuid, $membershipUuid);

        // ── AORM-9.21: ensure user role + config security roles ───────────

        $this->ensureUserAndSecurityRoles($personUuid, $orgUuid);
    }

    /**
     * Handle merging_to_record sync via the Direct Assignment path.
     *
     * 1. Updates the merge target's first name, last name, and job title in
     *    MDP from the imported raw_data fields, when at least one of them is
     *    present — reuses the same updatePersonNameAndTitleIfPresent() helper
     *    as the Relationship path (AORM-9.11).
     * 2. Adds the imported email as the merge target's new primary email and
     *    demotes whichever address(es) currently hold the primary flag, when
     *    a non-empty email is present — reuses the same
     *    addImportedEmailAsPrimaryIfPresent() helper as the Relationship path
     *    (AORM-9.12).
     * 3. Ensures an ACTIVE Direct Assignment membership exists linking the
     *    merge target to the roster's org membership, creating one only when
     *    none is currently active — reuses the shared
     *    ensureActiveMembershipAssignment() helper introduced for
     *    syncExactMatchViaDirectAssignment() / syncAlreadyOnRosterViaDirectAssignment()
     *    (AORM-9.20). Direct Assignment has no relationship records to end-date
     *    or roster-relationship to ensure (unlike the Relationship path's
     *    AORM-9.13 step), so this step replaces it.
     * 4. Applies the configured security roles (and user role) scoped to the
     *    roster org via the shared ensureUserAndSecurityRoles() helper
     *    (AORM-9.23). Roles are applied additively via POST — existing roles
     *    on the merge target are preserved ("keep existing"), same as the
     *    Relationship path's merge handler (AORM-9.14).
     *
     * The person to update is the admin-selected merge target — NOT the first
     * `matched_persons` candidate. It is resolved from the staged record's
     * `merge_target_uuid` column, same as syncMergingToRecordViaRelationship().
     * The handler is a no-op when that column is empty.
     *
     * This method is not yet wired to a Direct Assignment dispatcher or to
     * syncRecord()'s routing — that lands with a later Phase 2 ticket.
     *
     * @param array<string, mixed> $record  A row from wp_wicket_aorm_staged_records.
     *
     * @see AORM-9.22 — update fields, add email as primary, create assignment if missing
     * @see AORM-9.23 — apply config security roles, keep existing
     */
    protected function syncMergingToRecordViaDirectAssignment(array $record): void
    {
        $personUuid = (string) ($record['merge_target_uuid'] ?? '');

        if ($personUuid === '') {
            return;
        }

        $rawData = is_string($record['raw_data'] ?? null)
            ? (array) (json_decode((string) $record['raw_data'], true) ?? [])
            : (array) ($record['raw_data'] ?? []);

        $membershipUuid = (string) ($record['membership_uuid'] ?? '');
        $orgUuid        = (string) ($record['org_uuid'] ?? '');

        // ── AORM-9.22: update first/last name + title from import ─────────

        $this->updatePersonNameAndTitleIfPresent($personUuid, $rawData);

        // ── AORM-9.22: add imported email as primary, demote existing ─────

        $this->addImportedEmailAsPrimaryIfPresent($personUuid, $rawData);

        // ── AORM-9.22: create assignment if not already active ────────────

        $this->ensureActiveMembershipAssignment($personUuid, $membershipUuid);

        // ── AORM-9.23: apply config security roles, keep existing ─────────

        $this->ensureUserAndSecurityRoles($personUuid, $orgUuid);
    }

    /**
     * Handle remove_existing sync via the Direct Assignment path.
     *
     * End-dates the person's active Direct Assignment membership(s) on the
     * roster's org membership by delegating to
     * WicketORM\Services\MembershipService::endAllActivePersonMembershipsForOrg()
     * — the Direct Assignment counterpart to
     * syncRemoveExistingViaRelationship()'s
     * ConnectionService::endActivePersonOrganizationConnections() call
     * (AORM-9.15). Unlike the Relationship path, there are no other-org
     * relationships to consider here — a person_memberships assignment only
     * ever links to the one roster org membership it was created against.
     *
     * MembershipService::endAllActivePersonMembershipsForOrg() is existing,
     * already-used logic from wicket-wp-account-centre's OrgMan library (the
     * same package AORM already depends on for PersonService/ConnectionService
     * /OrgManConfig) — it queries `/person_memberships/query` for every
     * person_membership on the given org membership, filters to active rows,
     * and end-dates each one via the shared endPersonMembershipToday() helper
     * (which honours the configured removal anchor —
     * config['removal']['end_date_anchor'], 'action_time' or
     * 'day_start_utc'). It is reused here rather than reimplemented in
     * MdpClient, both to avoid duplicating that end-dating logic and because
     * it already handles the case of more than one active person_membership
     * row for the same org membership (a single hasActivePersonMembershipAssignment
     * ()-style lookup would only surface the first). It swallows and logs its
     * own errors (returning `['ended' => [...], 'errors' => [...]]`), so —
     * same as syncRemoveExistingViaRelationship()'s call to
     * ConnectionService::endActivePersonOrganizationConnections() — its return
     * value is not inspected here.
     *
     * The person UUID is read from raw_data.person_uuid (not matched_persons
     * — that field is always null for synthetic remove_existing rows
     * generated by ReplacementDiffController), same as
     * syncRemoveExistingViaRelationship(). The membership UUID is read from
     * the staged record's membership_uuid column (the roster's org
     * membership) — the same column syncNewRecordViaDirectAssignment()
     * (AORM-9.18) and ensureActiveMembershipAssignment() (AORM-9.20/9.22) use
     * to identify the assignment. The handler is a no-op when either UUID is
     * absent or empty.
     *
     * This method is not yet wired to a Direct Assignment dispatcher or to
     * syncRecord()'s routing — that lands with a later Phase 2 ticket.
     * AORM-9.25 (revoke config security roles) is a separate ticket and is
     * not yet implemented here.
     *
     * @param array<string, mixed> $record  A row from wp_wicket_aorm_staged_records.
     *
     * @see AORM-9.24 — end date assignment (today + timestamp)
     */
    protected function syncRemoveExistingViaDirectAssignment(array $record): void
    {
        $rawData = is_string($record['raw_data'] ?? null)
            ? (array) (json_decode((string) $record['raw_data'], true) ?? [])
            : (array) ($record['raw_data'] ?? []);

        $personUuid     = (string) ($rawData['person_uuid'] ?? '');
        $membershipUuid = (string) ($record['membership_uuid'] ?? '');

        if ($personUuid === '' || $membershipUuid === '') {
            return;
        }

        $membershipService = $this->membershipService ?? new \WicketORM\Services\MembershipService();

        // ── AORM-9.24: end-date all active Direct Assignment membership(s) ────

        $membershipService->endAllActivePersonMembershipsForOrg($personUuid, $membershipUuid);
    }

    // ── Shared helpers ────────────────────────────────────────────────────────

    /**
     * Update a person's title in MDP when the imported row supplies one.
     *
     * Reads the `title` key from `$rawData` and delegates to
     * MdpClient::updatePersonTitle(). Skipped when the title is absent or empty.
     *
     * @param string               $personUuid  Person UUID.
     * @param array<string, mixed> $rawData     Decoded raw_data from a staged record.
     *
     * @throws \Exception When the MDP PATCH call fails.
     *
     * @see AORM-9.8
     */
    private function updatePersonTitleIfPresent(string $personUuid, array $rawData): void
    {
        $title = (string) ($rawData['title'] ?? '');

        if ($title === '') {
            return;
        }

        $mdpClient = $this->mdpClient ?? new MdpClient();
        $mdpClient->updatePersonTitle($personUuid, $title);
    }

    /**
     * Update a merge target's first name, last name, and title in MDP from the
     * imported row, when at least one of those fields is present.
     *
     * Reads `first_name`, `last_name`, and `title` from `$rawData` and
     * delegates to MdpClient::updatePersonNameAndTitle(), which omits any
     * empty fields from the PATCH so untouched MDP attributes are preserved.
     * Skipped entirely when all three values are empty.
     *
     * @param string               $personUuid  Merge target person UUID.
     * @param array<string, mixed> $rawData     Decoded raw_data from a staged record.
     *
     * Shared by syncMergingToRecordViaRelationship() (AORM-9.11) and
     * syncMergingToRecordViaDirectAssignment() (AORM-9.22).
     *
     * @throws \Exception When the MDP PATCH call fails.
     *
     * @see AORM-9.11
     * @see AORM-9.22
     */
    private function updatePersonNameAndTitleIfPresent(string $personUuid, array $rawData): void
    {
        $givenName  = (string) ($rawData['first_name'] ?? '');
        $familyName = (string) ($rawData['last_name'] ?? '');
        $title      = (string) ($rawData['title'] ?? '');

        if ($givenName === '' && $familyName === '' && $title === '') {
            return;
        }

        $mdpClient = $this->mdpClient ?? new MdpClient();
        $mdpClient->updatePersonNameAndTitle($personUuid, $givenName, $familyName, $title);
    }

    /**
     * Add a merge target's imported email as their new primary address in MDP,
     * demoting whichever email(s) currently hold the primary flag, when the
     * imported row supplies a non-empty `email_address`.
     *
     * Reads the `email_address` key from `$rawData` and the configured email
     * type from `wicket_aorm_settings[email_address_type]` (same setting and
     * default used by the new_record path — AORM-9.5), then delegates to
     * MdpClient::addImportedEmailAsPrimary(). Skipped entirely when the
     * imported email is absent or empty.
     *
     * @param string               $personUuid  Merge target person UUID.
     * @param array<string, mixed> $rawData     Decoded raw_data from a staged record.
     *
     * Shared by syncMergingToRecordViaRelationship() (AORM-9.12) and
     * syncMergingToRecordViaDirectAssignment() (AORM-9.22).
     *
     * @throws \Exception When any MDP POST/PATCH call fails.
     *
     * @see AORM-9.12
     * @see AORM-9.22
     */
    private function addImportedEmailAsPrimaryIfPresent(string $personUuid, array $rawData): void
    {
        $email = (string) ($rawData['email'] ?? '');

        if ($email === '') {
            return;
        }

        $settings  = (array) get_option(self::SETTINGS_OPTION, []);
        $emailType = (string) ($settings[self::SETTINGS_KEY_EMAIL_TYPE] ?? self::DEFAULT_EMAIL_TYPE);

        $mdpClient = $this->mdpClient ?? new MdpClient();
        $mdpClient->addImportedEmailAsPrimary($personUuid, $email, $emailType);
    }

    /**
     * Ensure a person has an ACTIVE Direct Assignment membership on the given
     * org membership (roster), creating one only when none is currently active.
     *
     * Delegates the active check to MdpClient::hasActivePersonMembershipAssignment()
     * and, when it returns false, creates a new assignment via
     * MdpClient::createPersonMembershipAssignment() — the same call used by
     * syncNewRecordViaDirectAssignment() (AORM-9.18). No-ops when either UUID
     * is empty.
     *
     * Shared by syncExactMatchViaDirectAssignment() and
     * syncAlreadyOnRosterViaDirectAssignment() (AORM-9.20), and by
     * syncMergingToRecordViaDirectAssignment() (AORM-9.22).
     *
     * @param string $personUuid     Person UUID.
     * @param string $membershipUuid Org-membership (roster) UUID.
     *
     * @throws \Exception When the MDP API call fails.
     *
     * @see AORM-9.20
     * @see AORM-9.22
     */
    private function ensureActiveMembershipAssignment(string $personUuid, string $membershipUuid): void
    {
        if ($personUuid === '' || $membershipUuid === '') {
            return;
        }

        $mdpClient = $this->mdpClient ?? new MdpClient();

        if ($mdpClient->hasActivePersonMembershipAssignment($personUuid, $membershipUuid)) {
            return;
        }

        $mdpClient->createPersonMembershipAssignment($personUuid, $membershipUuid);
    }

    /**
     * Ensure the user role + configured security roles are applied to a person,
     * scoped to the roster org.
     *
     * Base member role: OrgManConfig::get()['member_management']['addition']['base_member_role']
     * — the default role assigned to a person when they are added to the roster
     * org (e.g. 'member'). May be an empty string when unconfigured.
     *
     * Config security roles: wicket_aorm_settings[security_roles] — an array
     * of additional role slugs configured in the AORM Settings page (e.g.
     * 'org_editor', 'membership_manager'). May be empty.
     *
     * Roles are merged, deduplicated, and filtered for emptiness before being
     * sent to MDP via MdpClient::applyPersonOrgRoles(). The MDP call is skipped
     * entirely when the resulting role list is empty.
     *
     * Shared by syncNewRecordViaRelationship() (AORM-9.7),
     * syncExactMatchViaRelationship() / syncAlreadyOnRosterViaRelationship()
     * (AORM-9.10), syncMergingToRecordViaRelationship() (AORM-9.14),
     * syncNewRecordViaDirectAssignment() (AORM-9.19),
     * syncExactMatchViaDirectAssignment() / syncAlreadyOnRosterViaDirectAssignment()
     * (AORM-9.21), and syncMergingToRecordViaDirectAssignment() (AORM-9.23).
     *
     * @param string $personUuid Person UUID.
     * @param string $orgUuid    Roster org UUID to scope the roles to.
     *
     * @throws \Exception When the MDP API call fails.
     *
     * @see AORM-9.7
     * @see AORM-9.10
     * @see AORM-9.14
     * @see AORM-9.19
     * @see AORM-9.21
     * @see AORM-9.23
     */
    private function ensureUserAndSecurityRoles(string $personUuid, string $orgUuid): void
    {
        if ($personUuid === '' || $orgUuid === '') {
            return;
        }

        $settings     = (array) get_option(self::SETTINGS_OPTION, []);
        $orgManConfig = \WicketORM\Config\OrgManConfig::get();

        $userRole      = (string) ($orgManConfig['member_management']['addition']['base_member_role'] ?? '');
        $securityRoles = array_values(array_filter(
            array_map('strval', (array) ($settings[self::SETTINGS_KEY_SECURITY_ROLES] ?? [])),
            fn (string $r): bool => $r !== '',
        ));

        $allRoles = array_values(array_unique(array_filter(
            array_merge($userRole !== '' ? [$userRole] : [], $securityRoles),
            fn (string $r): bool => $r !== '',
        )));

        if (empty($allRoles)) {
            return;
        }

        $mdpClient = $this->mdpClient ?? new MdpClient();
        $mdpClient->applyPersonOrgRoles($personUuid, $orgUuid, $allRoles);
    }

    /**
     * Revoke the configured security roles for a person, scoped to the roster org.
     *
     * Config security roles: wicket_aorm_settings[security_roles] — the array
     * of role slugs configured in the AORM Settings page (e.g. 'org_editor',
     * 'membership_manager'). These were applied by ensureUserAndSecurityRoles()
     * when the person was added; they must be explicitly revoked when the person
     * is removed.
     *
     * Only the security roles from settings are revoked here — the user role
     * from OrgManConfig is intentionally excluded (it is lifecycle-managed by
     * the relationship end-dating in AORM-9.15, not by role deletion).
     *
     * Roles are filtered for emptiness before being sent to MDP via
     * MdpClient::revokePersonOrgRoles(). The MDP call is skipped entirely when
     * the role list is empty or either UUID is absent.
     *
     * @param string $personUuid Person UUID.
     * @param string $orgUuid    Roster org UUID to scope the revocation to.
     *
     * @throws \Exception When the MDP API call fails.
     *
     * @see AORM-9.16
     */
    private function revokeConfigSecurityRoles(string $personUuid, string $orgUuid): void
    {
        if ($personUuid === '' || $orgUuid === '') {
            return;
        }

        $settings      = (array) get_option(self::SETTINGS_OPTION, []);
        $securityRoles = array_values(array_unique(array_filter(
            array_map('strval', (array) ($settings[self::SETTINGS_KEY_SECURITY_ROLES] ?? [])),
            fn (string $r): bool => $r !== '',
        )));

        if (empty($securityRoles)) {
            return;
        }

        $mdpClient = $this->mdpClient ?? new MdpClient();
        $mdpClient->revokePersonOrgRoles($personUuid, $orgUuid, $securityRoles);
    }

    /**
     * End the person's default-type relationships to other orgs and ensure a
     * default-type relationship exists to the roster org.
     *
     * 1. Resolves the distinct set of other orgs the person currently has a
     *    person-to-organization connection with (excluding the roster org)
     *    via ConnectionService::getPersonConnectionsById().
     * 2. For each other org, ends active connections via
     *    ConnectionService::endActivePersonOrganizationConnections(), passing
     *    the configured protected relationship types (e.g. admin roles) as
     *    $skipTypes so they are left untouched.
     * 3. Ensures (creates if missing) a default-type relationship to the
     *    roster org via ConnectionService::ensurePersonConnection() — handles
     *    payload building, start date, and idempotency.
     *
     * @param string $personUuid    Person UUID.
     * @param string $rosterOrgUuid Roster org UUID (record['org_uuid']).
     *
     * @see AORM-9.9
     */
    private function endOtherOrgRelationshipsAndEnsureRosterRelationship(string $personUuid, string $rosterOrgUuid): void
    {
        if ($personUuid === '' || $rosterOrgUuid === '') {
            return;
        }

        $connectionService = $this->connectionService ?? new \WicketORM\Services\ConnectionService();
        $orgManConfig      = \WicketORM\Config\OrgManConfig::get();

        $skipTypes = array_values(array_filter(
            array_map('strval', (array) ($orgManConfig['member_management']['addition']['protected_relationship_types'] ?? [])),
            fn (string $type): bool => $type !== '',
        ));

        foreach ($this->resolveOtherOrgUuidsFromConnections($connectionService, $personUuid, $rosterOrgUuid) as $otherOrgUuid) {
            $connectionService->endActivePersonOrganizationConnections($personUuid, $otherOrgUuid, $skipTypes);
        }

        $connectionService->ensurePersonConnection($personUuid, $rosterOrgUuid);
    }

    /**
     * Resolve the distinct set of org UUIDs (excluding the roster org) that the
     * person currently has a person-to-organization connection with.
     *
     * Reads the person's full connection list via
     * ConnectionService::getPersonConnectionsById() and collects the
     * organization id from each `person_to_organization` connection entry.
     * Active-status filtering for end-dating is delegated to
     * ConnectionService::endActivePersonOrganizationConnections() per org —
     * not reimplemented here.
     *
     * @param \WicketORM\Services\ConnectionService $connectionService
     * @param string                                 $personUuid
     * @param string                                 $rosterOrgUuid
     * @return string[]  Distinct other-org UUIDs.
     *
     * @see AORM-9.9
     */
    private function resolveOtherOrgUuidsFromConnections(
        \WicketORM\Services\ConnectionService $connectionService,
        string $personUuid,
        string $rosterOrgUuid,
    ): array {
        $connections = $connectionService->getPersonConnectionsById($personUuid);

        if (! is_array($connections) || empty($connections['data'])) {
            return [];
        }

        $otherOrgUuids = [];

        foreach ((array) $connections['data'] as $connection) {
            $connectionType = (string) ($connection['attributes']['connection_type'] ?? '');
            $orgUuid        = (string) ($connection['relationships']['organization']['data']['id'] ?? '');

            if ($connectionType !== 'person_to_organization' || $orgUuid === '' || $orgUuid === $rosterOrgUuid) {
                continue;
            }

            $otherOrgUuids[$orgUuid] = true;
        }

        return array_keys($otherOrgUuids);
    }

    /**
     * Extract the person UUID from the first entry in a record's matched_persons.
     *
     * `matched_persons` may arrive as a JSON string (raw DB row) or as a decoded
     * PHP array (pre-processed by StagedRecordsTable). Returns an empty string
     * when no candidates are present or the UUID field is absent.
     *
     * @param array<string, mixed> $record  A row from wp_wicket_aorm_staged_records.
     * @return string  Person UUID, or empty string when none is available.
     */
    private function extractPersonUuidFromMatchedPersons(array $record): string
    {
        $raw = $record['matched_persons'] ?? null;

        $persons = match (true) {
            is_string($raw) => (array) (json_decode($raw, true) ?? []),
            is_array($raw)  => $raw,
            default         => [],
        };

        return (string) ($persons[0]['uuid'] ?? '');
    }
}
