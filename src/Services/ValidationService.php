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
     * Email format regex — matches the client-side EMAIL_REGEX constant.
     *
     * Requires at least one non-whitespace/non-@ character before and after
     * the @, and at least one dot after the @.
     */
    public const EMAIL_REGEX = '/^[^\s@]+@[^\s@]+\.[^\s@]+$/';

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
     * Phone format regex — matches the client-side PHONE_REGEX constant.
     *
     * Allows an optional leading +, then 7–20 characters consisting of
     * digits, spaces, hyphens, dots, and parentheses.
     */
    public const PHONE_REGEX = '/^[+]?[\d\s\-().]{7,20}$/';

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
     *   3. Phone format matches PHONE_REGEX (only when mobile_phone is provided).
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
        if (! isset($errors['email'])) {
            $email = trim((string) ($fields['email'] ?? ''));

            if ($email !== '' && ! preg_match(self::EMAIL_REGEX, $email)) {
                $errors['email'] = 'Invalid email format.';
            }
        }

        // 3. Phone format (optional field — only validate when provided).
        $phone = trim((string) ($fields['mobile_phone'] ?? ''));

        if ($phone !== '' && ! preg_match(self::PHONE_REGEX, $phone)) {
            $errors['mobile_phone'] = 'Invalid phone number format.';
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
