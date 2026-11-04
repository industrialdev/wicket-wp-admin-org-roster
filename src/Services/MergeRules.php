<?php

declare(strict_types=1);

namespace WicketAORM\Services;

/**
 * Sync merge logic — per-field "imported value" / "original value" rules.
 *
 * Configured on the Settings page (Section 8, "Sync Merge Logic") and stored
 * under wicket_aorm_settings[merge_rules] as a map of field => rule:
 *
 *   [
 *     'first_name' => 'imported' | 'original',
 *     'last_name'  => 'imported' | 'original',
 *     'email'      => 'imported' | 'original',
 *     'phone'      => 'imported' | 'original',
 *     'title'      => 'imported' | 'original',
 *   ]
 *
 * Field keys match the staged record raw_data keys produced by
 * FileParserService, so a rule can be applied directly to raw_data.
 *
 * Rule semantics (decided with Alex):
 *   - imported → the non-empty imported value is written to the MDP (the
 *                "CSV is the source of truth" behaviour; blank CSV cells
 *                still never clear MDP values).
 *   - original → the imported value never overwrites an existing MDP
 *                person, on any sync path, even when the MDP value is empty.
 *                For email this means the imported address is dropped
 *                entirely (not added as a secondary).
 *
 * Person creation exception: a brand-new person (new_record when no MDP
 * person owns the email) is always created with the imported first name,
 * last name and email — wicket_create_or_get_person() refuses to create a
 * person without them, and there is no original value yet. Title and phone
 * follow the rules even on creation, so a brand-new person gets no
 * title/phone when those are set to original. When new_record resolves an
 * EXISTING person by email, wicket_create_or_get_person() does not touch
 * their names, so original names stay protected there too.
 *
 * Every field defaults to 'imported', so sites that never save Section 8
 * keep the pre-AORM-11.11 behaviour.
 *
 * Consumers:
 *   - SettingsPage   — renders/sanitizes Section 8.
 *   - SyncService    — applyImportedProfileFields() blanks "original" fields
 *                      via filterRawData() before any MDP write.
 *   - Assets         — passes fromSettings() to React as
 *                      window.aormContext.mergeRules for the Review Match
 *                      modal's Merge Preview.
 *
 * @see AORM-11.11
 */
final class MergeRules
{
    /** Key within wicket_aorm_settings. */
    public const SETTINGS_KEY = 'merge_rules';

    /** Rule: write the imported value to the MDP. */
    public const IMPORTED = 'imported';

    /** Rule: keep the MDP value — never write the imported value. */
    public const ORIGINAL = 'original';

    /** Allowed rule values. */
    public const VALID_RULES = [self::IMPORTED, self::ORIGINAL];

    /** Rule used for any field without a valid saved rule. */
    public const DEFAULT_RULE = self::IMPORTED;

    public const FIELD_FIRST_NAME = 'first_name';
    public const FIELD_LAST_NAME  = 'last_name';
    public const FIELD_EMAIL      = 'email';
    public const FIELD_PHONE      = 'phone';
    public const FIELD_TITLE      = 'title';

    /** Configurable fields, in display order. */
    public const FIELDS = [
        self::FIELD_FIRST_NAME,
        self::FIELD_LAST_NAME,
        self::FIELD_EMAIL,
        self::FIELD_PHONE,
        self::FIELD_TITLE,
    ];

    /**
     * Normalize any value into a complete field => rule map.
     *
     * Every field in FIELDS is present in the result. Missing, non-string or
     * unrecognised rules fall back to DEFAULT_RULE; unknown fields are dropped.
     *
     * @param mixed $raw Stored or submitted rules.
     * @return array<string, string>
     */
    public static function normalize(mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $out = [];

        foreach (self::FIELDS as $field) {
            $rule = $raw[$field] ?? null;

            $out[$field] = is_string($rule) && in_array($rule, self::VALID_RULES, true)
                ? $rule
                : self::DEFAULT_RULE;
        }

        return $out;
    }

    /**
     * Read the saved rules from wicket_aorm_settings[merge_rules].
     *
     * @return array<string, string> Complete field => rule map.
     */
    public static function fromSettings(): array
    {
        $settings = (array) get_option(SyncService::SETTINGS_OPTION, []);

        return self::normalize($settings[self::SETTINGS_KEY] ?? []);
    }

    /**
     * Whether the imported value should be written for a field.
     *
     * @param array<string, string> $rules Field => rule map.
     * @param string                $field One of FIELDS.
     */
    public static function usesImported(array $rules, string $field): bool
    {
        return ($rules[$field] ?? self::DEFAULT_RULE) !== self::ORIGINAL;
    }

    /**
     * Return a copy of raw_data with every "original" field blanked.
     *
     * The SyncService profile writers already skip empty values, so a blanked
     * field is simply never written to the MDP. Keys that are not merge
     * fields are left untouched.
     *
     * @param array<string, mixed>  $rawData Decoded raw_data from a staged record.
     * @param array<string, string> $rules   Field => rule map.
     * @return array<string, mixed>
     */
    public static function filterRawData(array $rawData, array $rules): array
    {
        foreach (self::FIELDS as $field) {
            if (! self::usesImported($rules, $field)) {
                $rawData[$field] = '';
            }
        }

        return $rawData;
    }
}
