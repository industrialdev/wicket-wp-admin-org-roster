<?php

declare(strict_types=1);

namespace WicketAORM\Services;

/**
 * CSV file parser — normalizes rows for staged records.
 *
 * Defines the fixed CSV template column spec and will house all CSV
 * parsing/normalization logic as later sub-tasks are implemented.
 */
class FileParserService
{
    /**
     * CSV column definitions for the bulk roster upload template.
     *
     * Each entry defines:
     *   - header:   the exact column name used in the CSV file.
     *   - field:    the internal field name used in staged records and validation.
     *   - required: whether the column must be present and non-empty.
     *
     * The header→field mapping exists because the user-facing CSV column names
     * (email_address, phone_number) differ from the internal field names used by
     * ValidationService and the individual add form (email, mobile_phone).
     *
     * Column order matches the downloadable template (AORM-6.3).
     * Header matching is case-insensitive and alias-aware (AORM-6.10).
     *
     * Required columns: first_name, last_name, email_address.
     * Optional columns:  phone_number, title.
     */
    public const COLUMN_DEFINITIONS = [
        [
            'header'   => 'first_name',
            'field'    => 'first_name',
            'required' => true,
        ],
        [
            'header'   => 'last_name',
            'field'    => 'last_name',
            'required' => true,
        ],
        [
            'header'   => 'email_address',
            'field'    => 'email',
            'required' => true,
        ],
        [
            'header'   => 'phone_number',
            'field'    => 'mobile_phone',
            'required' => false,
        ],
        [
            'header'   => 'title',
            'field'    => 'title',
            'required' => false,
        ],
    ];

    /**
     * Return the canonical CSV header names in template order.
     *
     * @return string[]
     */
    public static function getTemplateHeaders(): array
    {
        return array_column(self::COLUMN_DEFINITIONS, 'header');
    }

    /**
     * Return only the headers marked as required.
     *
     * @return string[]
     */
    public static function getRequiredHeaders(): array
    {
        return array_column(
            array_filter(
                self::COLUMN_DEFINITIONS,
                static fn (array $col): bool => $col['required'],
            ),
            'header',
        );
    }
}
