<?php

declare(strict_types=1);

namespace WicketAORM\Services;

/**
 * Row-level validation rules for staged records.
 *
 * Used for both the CSV bulk-upload flow and the individual add form (AORM-5.4).
 * Rules mirror the client-side validation in IndividualAddForm.js exactly.
 */
class ValidationService
{
    /**
     * Email format regex — enforces the rules introduced in AORM-6.13:
     *   - Single @ (character class [^\s@] excludes a second @).
     *   - Local part must start with an alphanumeric character (no leading punctuation).
     *   - Local part must end with an alphanumeric character (no trailing punctuation).
     *   - Domain must contain at least one dot.
     *   - No whitespace anywhere.
     *
     * The local-part group `([^\s@]*[a-zA-Z0-9])?` is optional so that
     * single-character local parts (e.g. a@example.com) are accepted.
     */
    public const EMAIL_REGEX = '/^[a-zA-Z0-9]([^\s@]*[a-zA-Z0-9])?@[^\s@]+\.[^\s@]+$/';

    /**
     * Maximum total length of a valid email address (RFC 5321).
     */
    public const EMAIL_MAX_LENGTH = 254;

    /**
     * Human-readable validation label for rows that are missing one or more
     * required fields (first_name, last_name, email_address).
     *
     * Stored in the validation_message column of wp_wicket_aorm_staged_records
     * so the CSV validation review step can surface a consistent label to
     * administrators.  Used by the bulk CSV upload flow (AORM-6.12).
     */
    public const VALIDATION_LABEL_MISSING_REQUIRED = 'Invalid – Missing Required Data';

    /**
     * Human-readable validation label for rows where the email address is
     * present but fails format validation (AORM-6.13).
     *
     * Applied when all required fields are present but the email value does
     * not satisfy EMAIL_REGEX or exceeds EMAIL_MAX_LENGTH.
     */
    public const VALIDATION_LABEL_INVALID_EMAIL = 'Invalid – Invalid Email Format';

    /**
     * Name format regex — enforces the rule that first_name/last_name may
     * only contain letters, spaces, hyphens, and apostrophes.
     *
     * Allows common name shapes such as "Mary-Jane", "O'Brien", and
     * "Van Der Berg". Rejects digits and other symbols (e.g. "@", "#", ".").
     * Letters are restricted to ASCII a-z/A-Z; accented/unicode letters are
     * intentionally out of scope for this rule.
     */
    public const NAME_REGEX = "/^[a-zA-Z\s'-]+$/";

    /**
     * Human-readable validation label for rows where first_name or last_name
     * is present but contains characters outside NAME_REGEX.
     *
     * Applied when the field is non-empty (so it does not overlap with
     * VALIDATION_LABEL_MISSING_REQUIRED) but fails the name format check.
     */
    public const VALIDATION_LABEL_INVALID_NAME = 'Invalid – Invalid Name Format';

    /**
     * Minimum number of digits in a valid phone number after stripping all
     * non-numeric characters (AORM-6.14).
     *
     * Mirrors the MDP minimum length rule and the ITU-T recommendation for
     * local subscriber numbers.
     */
    public const PHONE_MIN_DIGITS = 7;

    /**
     * Maximum number of digits in a valid phone number after stripping all
     * non-numeric characters (AORM-6.14).
     *
     * Mirrors the E.164 international standard ceiling of 15 digits.
     */
    public const PHONE_MAX_DIGITS = 15;

    /**
     * Phone character-shape regex (post-AORM-6.14 bugfix).
     *
     * Mirrors PHONE_REGEX in IndividualAddForm.js exactly — the two were
     * always intended to apply the same rule to CSV rows, but the CSV path
     * only ever checked digit count, so any string containing 7-15 digit
     * characters passed regardless of what else was in the field (including
     * letters, or digits with no plausible phone shape).
     *
     * Accepts an optional leading +, then 7-20 characters of digits, spaces,
     * hyphens, parentheses, or dots. Checked before the digit-count rule so
     * disallowed characters are rejected outright.
     */
    public const PHONE_REGEX = '/^[+]?[\d\s\-().]{7,20}$/';

    /**
     * Maximum allowed run of the same digit repeated consecutively in a
     * phone number, after stripping non-numeric characters (bugfix,
     * post-AORM-6.14).
     *
     * Digit-count-only validation let obviously-fake numbers through as
     * long as the total digit count fell within PHONE_MIN_DIGITS–
     * PHONE_MAX_DIGITS — e.g. "123-78945-1111111" strips to exactly 15
     * digits and passed despite the trailing run of seven repeated "1"s.
     * Real phone numbers essentially never contain a run this long, so
     * rejecting 6+ consecutive identical digits catches this class of junk
     * data with minimal risk of flagging a legitimate number.
     */
    public const PHONE_MAX_CONSECUTIVE_REPEATED_DIGITS = 6;

    /**
     * Human-readable validation label for rows where the phone value is
     * present but fails the character-shape, digit-count, or
     * repeated-digit checks (AORM-6.14; extended by the repeated-digit
     * bugfix above).
     *
     * Stored in the validation_message column of wp_wicket_aorm_staged_records.
     */
    public const VALIDATION_LABEL_INVALID_PHONE = 'Invalid – Phone Format';

    /**
     * Human-readable validation label for rows whose email address appears
     * more than once within the same import file (AORM-6.15, extended).
     *
     * Originally scoped to the first_name + last_name + email combination;
     * broadened so every email in the file must be unique regardless of the
     * name it is paired with — two rows sharing an email are considered
     * duplicates even when the names differ (e.g. a typo'd name against the
     * same address is still very likely the same person, or a data-entry
     * error).  Applied to the second and all subsequent occurrences of a
     * duplicate email. Comparison is case-insensitive and whitespace-trimmed.
     *
     * Stored in the validation_message column of wp_wicket_aorm_staged_records.
     */
    public const VALIDATION_LABEL_DUPLICATE = 'Duplicate in Import File';

    /**
     * Required person fields for roster records.
     *
     * @var string[]
     */
    private const REQUIRED_FIELDS = ['first_name', 'last_name', 'email'];

    /**
     * Validate a single person row against the roster field rules.
     *
     * Returns an associative array mapping field names to human-readable error
     * messages.  An empty array means the row is valid.
     *
     * Rules applied (same order as CSV validation):
     *   1. Required fields present and non-empty after trimming.
     *   2. first_name/last_name format matches NAME_REGEX (only when present).
     *   3. Email format matches EMAIL_REGEX.
     *   4. Phone (only when phone is provided): character shape matches
     *      PHONE_REGEX; digit count within PHONE_MIN_DIGITS–PHONE_MAX_DIGITS
     *      after stripping non-numeric characters; no run of
     *      PHONE_MAX_CONSECUTIVE_REPEATED_DIGITS or more identical digits.
     *
     * @param array<string, string> $fields Associative array of field values.
     * @return array<string, string> Field-keyed error messages (empty = valid).
     */
    public function validateRow(array $fields): array
    {
        $errors = [];

        // 1. Required fields.
        foreach (self::REQUIRED_FIELDS as $field) {
            $value = trim((string) ($fields[$field] ?? ''));

            if ($value === '') {
                $errors[$field] = $this->requiredFieldMessage($field);
            }
        }

        // 2. Name format (first_name, last_name) — only letters, spaces,
        //    hyphens, and apostrophes are permitted. Only checked when the
        //    field is present (missing already flagged above).
        foreach (['first_name', 'last_name'] as $field) {
            if (isset($errors[$field])) {
                continue;
            }

            $value = trim((string) ($fields[$field] ?? ''));

            if ($value !== '' && ! preg_match(self::NAME_REGEX, $value)) {
                $errors[$field] = $this->invalidNameMessage($field);
            }
        }

        // 3. Email format (only when not already flagged as missing).
        //    AORM-6.13: length check runs before regex (cheaper); both enforce the
        //    "no leading/trailing punctuation" and structural rules.
        if (! isset($errors['email'])) {
            $email = trim((string) ($fields['email'] ?? ''));

            if ($email !== '') {
                if (strlen($email) > self::EMAIL_MAX_LENGTH) {
                    $errors['email'] = sprintf(
                        'Email address must not exceed %d characters.',
                        self::EMAIL_MAX_LENGTH,
                    );
                } elseif (! preg_match(self::EMAIL_REGEX, $email)) {
                    $errors['email'] = 'Invalid email format.';
                }
            }
        }

        // 4. Phone format (optional field — only validate when provided).
        //    An absent or blank value is valid (empty is OK). Three checks,
        //    in order (cheapest/most-decisive first):
        //      a. Character shape — PHONE_REGEX (bugfix, post-AORM-6.14).
        //         Rejects disallowed characters (e.g. letters) and enforces
        //         overall length before looking at digit content at all.
        //      b. Digit count within PHONE_MIN_DIGITS–PHONE_MAX_DIGITS after
        //         stripping non-numeric characters (AORM-6.14, MDP rules).
        //      c. No run of PHONE_MAX_CONSECUTIVE_REPEATED_DIGITS or more
        //         identical digits (bugfix, post-AORM-6.14) — catches
        //         obviously-fake numbers (e.g. "123-78945-1111111") that
        //         satisfy (a) and (b) but aren't a plausible real number.
        $phone = trim((string) ($fields['phone'] ?? ''));

        if ($phone !== '') {
            if (! preg_match(self::PHONE_REGEX, $phone)) {
                $errors['phone'] = 'Invalid phone number format.';
            } else {
                $digits     = (string) preg_replace('/\D/', '', $phone);
                $digitCount = strlen($digits);

                if ($digitCount < self::PHONE_MIN_DIGITS || $digitCount > self::PHONE_MAX_DIGITS) {
                    $errors['phone'] = 'Invalid phone number format.';
                } elseif (preg_match('/(\d)\1{' . (self::PHONE_MAX_CONSECUTIVE_REPEATED_DIGITS - 1) . ',}/', $digits)) {
                    $errors['phone'] = 'Invalid phone number format.';
                }
            }
        }

        return $errors;
    }

    /**
     * Return true when the errors array from validateRow() contains at least
     * one error keyed to a required field (first_name, last_name, or email).
     *
     * Note: since first_name/last_name errors can now come from either the
     * "missing" check or the name-format check, this also returns true for a
     * present-but-invalid-format name. Callers that need to distinguish
     * "missing" from "invalid format" (e.g. UploadController) check the
     * trimmed raw field value directly rather than relying on this helper.
     *
     * Used by the bulk CSV upload flow (AORM-6.12) to decide whether a staged
     * record should be marked with VALIDATION_LABEL_MISSING_REQUIRED.
     *
     * @param array<string, string> $errors  Errors returned by validateRow().
     * @return bool
     */
    public function hasMissingRequired(array $errors): bool
    {
        foreach (self::REQUIRED_FIELDS as $field) {
            if (isset($errors[$field])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Identify rows that are duplicates within the same import batch.
     *
     * Every email address in the file must be unique (AORM-6.15, extended):
     * two rows are considered duplicates when their email values are
     * identical after trimming whitespace and lowercasing — regardless of
     * whether first_name/last_name also match. The first occurrence of any
     * given email is kept; the second and all subsequent occurrences are
     * returned as duplicates.
     *
     * Originally (AORM-6.15) the duplicate key was first_name + last_name +
     * email combined, so two rows with the same email but different names
     * were not flagged. That was intentionally broadened to email-only so
     * "all emails must be unique" holds for the whole import file. Name,
     * phone number, and title are excluded from the key.
     *
     * A blank/empty email never counts as a duplicate of another blank
     * email — rows with a missing email are already caught separately by
     * the required-field check in validateRow()/hasMissingRequired(), and
     * that check takes priority over the duplicate check in UploadController.
     *
     * @param array<int, array<string, string>> $rows  Normalised rows from FileParserService::parseFile().
     * @return int[]  0-based indices of rows that are duplicates.
     */
    public function detectDuplicates(array $rows): array
    {
        $seen       = [];
        $duplicates = [];

        foreach ($rows as $index => $row) {
            $email = strtolower(trim((string) ($row['email'] ?? '')));

            if ($email === '') {
                continue;
            }

            if (isset($seen[$email])) {
                $duplicates[] = $index;
            } else {
                $seen[$email] = true;
            }
        }

        return $duplicates;
    }

    /**
     * Build a human-readable "required" message for a field.
     *
     * @param string $field Snake-cased field name.
     */
    private function requiredFieldMessage(string $field): string
    {
        $label = ucwords(str_replace('_', ' ', $field));

        return $label . ' is required.';
    }

    /**
     * Build a human-readable "invalid format" message for a name field.
     *
     * @param string $field Snake-cased field name (first_name or last_name).
     */
    private function invalidNameMessage(string $field): string
    {
        $label = ucwords(str_replace('_', ' ', $field));

        return $label . ' contains invalid characters. Only letters, spaces, hyphens, and apostrophes are allowed.';
    }
}
