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
     * Human-readable validation label for rows where the phone value is
     * present but the digit count (after stripping non-numeric characters) falls
     * outside PHONE_MIN_DIGITS–PHONE_MAX_DIGITS (AORM-6.14).
     *
     * Stored in the validation_message column of wp_wicket_aorm_staged_records.
     */
    public const VALIDATION_LABEL_INVALID_PHONE = 'Invalid – Phone Format';

    /**
     * Human-readable validation label for rows whose first_name + last_name +
     * email combination appears more than once within the same import file
     * (AORM-6.15).
     *
     * Applied to the second and all subsequent occurrences of a duplicate key.
     * Comparison is case-insensitive and whitespace-trimmed on all three fields.
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
     *   2. Email format matches EMAIL_REGEX.
     *   3. Phone digit count within PHONE_MIN_DIGITS–PHONE_MAX_DIGITS after
     *      stripping non-numeric characters (only when phone is provided).
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

        // 2. Email format (only when not already flagged as missing).
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

        // 3. Phone format (optional field — only validate when provided).
        //    AORM-6.14: strip all non-numeric characters first; the remaining
        //    digit count must fall within PHONE_MIN_DIGITS–PHONE_MAX_DIGITS
        //    (MDP rules).  An absent or blank value is valid (empty is OK).
        $phone = trim((string) ($fields['phone'] ?? ''));

        if ($phone !== '') {
            $digits     = (string) preg_replace('/\D/', '', $phone);
            $digitCount = strlen($digits);

            if ($digitCount < self::PHONE_MIN_DIGITS || $digitCount > self::PHONE_MAX_DIGITS) {
                $errors['phone'] = 'Invalid phone number format.';
            }
        }

        return $errors;
    }

    /**
     * Return true when the errors array from validateRow() contains at least
     * one required-field error (first_name, last_name, or email).
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
     * Two rows are considered duplicates when their first_name, last_name, and
     * email values are identical after trimming whitespace and lowercasing.
     * The first occurrence of any given key is kept; the second and all
     * subsequent occurrences are returned as duplicates (AORM-6.15).
     *
     * The phone number and title fields are intentionally excluded from the
     * duplicate key — only name + email uniqueness is checked.
     *
     * @param array<int, array<string, string>> $rows  Normalised rows from FileParserService::parseFile().
     * @return int[]  0-based indices of rows that are duplicates.
     */
    public function detectDuplicates(array $rows): array
    {
        $seen       = [];
        $duplicates = [];

        foreach ($rows as $index => $row) {
            $key = strtolower(trim((string) ($row['first_name'] ?? '')))
                . '|'
                . strtolower(trim((string) ($row['last_name'] ?? '')))
                . '|'
                . strtolower(trim((string) ($row['email'] ?? '')));

            if (isset($seen[$key])) {
                $duplicates[] = $index;
            } else {
                $seen[$key] = true;
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
}
