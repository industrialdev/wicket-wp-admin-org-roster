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
     * ValidationService and the individual add form (email, phone).
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
            'field'    => 'phone',
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

    /**
     * Column definitions with header aliases for flexible, case-insensitive matching.
     *
     * Extends COLUMN_DEFINITIONS with an 'aliases' key per entry. All values in
     * 'aliases' are matched case-insensitively against actual CSV header cells.
     * The canonical 'header' value is always included in the aliases list.
     *
     * Ported from WicketORM\Services\BulkMemberUploadService (AORM-6.10).
     *
     * @return array<int, array{header: string, field: string, required: bool, aliases: string[]}>
     */
    public static function getBulkColumnDefinitions(): array
    {
        return [
            [
                'header'   => 'first_name',
                'field'    => 'first_name',
                'required' => true,
                'aliases'  => ['first_name', 'first name', 'firstname', 'first', 'given_name', 'given name'],
            ],
            [
                'header'   => 'last_name',
                'field'    => 'last_name',
                'required' => true,
                'aliases'  => ['last_name', 'last name', 'lastname', 'last', 'surname', 'family_name', 'family name'],
            ],
            [
                'header'   => 'email_address',
                'field'    => 'email',
                'required' => true,
                'aliases'  => ['email_address', 'email address', 'email', 'e-mail', 'e_mail'],
            ],
            [
                'header'   => 'phone_number',
                'field'    => 'phone',
                'required' => false,
                'aliases'  => ['phone_number', 'phone number', 'phone', 'mobile', 'phone', 'mobile phone', 'cell', 'telephone'],
            ],
            [
                'header'   => 'title',
                'field'    => 'title',
                'required' => false,
                'aliases'  => ['title', 'job_title', 'job title', 'position'],
            ],
        ];
    }

    /**
     * Find the 0-based index of a column in an actual CSV header row.
     *
     * Matching is case-insensitive and alias-aware: any value in $colDef['aliases']
     * that matches (after lowercasing and trimming) a header cell in $csvHeaders is
     * a hit. Falls back to matching the canonical 'header' value when 'aliases' is absent.
     *
     * @param string[]             $csvHeaders  Headers from the first CSV row (as-read by fgetcsv).
     * @param array<string, mixed> $colDef      A column definition from getBulkColumnDefinitions().
     * @return int|false  0-based index, or false if not found.
     */
    public static function resolveHeaderIndex(array $csvHeaders, array $colDef): int|false
    {
        $aliases = array_map('strtolower', $colDef['aliases'] ?? [$colDef['header']]);

        foreach ($csvHeaders as $index => $header) {
            if (in_array(strtolower(trim((string) $header)), $aliases, true)) {
                return $index;
            }
        }

        return false;
    }

    /**
     * Open a CSV file, validate its header row, and return normalized data rows.
     *
     * Uses fgetcsv for RFC 4180 compliant reading. Performs case-insensitive,
     * alias-aware column matching via resolveHeaderIndex(). Skips blank rows.
     *
     * @param string $filePath  Absolute path to the uploaded CSV file.
     * @return array{
     *   error?: string,
     *   missing_headers?: string[],
     *   rows?: array<int, array<string, string>>
     * }
     *   Success: ['rows' => [...]]  — each row keyed by internal field name.
     *   Failure: ['error' => '...'] — optionally with 'missing_headers'.
     */
    public function parseFile(string $filePath): array
    {
        $handle = @fopen($filePath, 'r');

        if ($handle === false) {
            return ['error' => 'Unable to open the uploaded file.'];
        }

        try {
            $rawHeaders = fgetcsv($handle);

            if ($rawHeaders === false || $rawHeaders === null) {
                return ['error' => 'The CSV file is empty or could not be read.'];
            }

            // Resolve each column definition against the actual CSV headers.
            $headerMap = []; // field => csv_column_index
            $missing   = [];

            foreach (self::getBulkColumnDefinitions() as $colDef) {
                $index = self::resolveHeaderIndex($rawHeaders, $colDef);

                if ($index === false) {
                    if ($colDef['required']) {
                        $missing[] = $colDef['header'];
                    }
                    // Optional columns simply absent from this file — skip.
                } else {
                    $headerMap[$colDef['field']] = $index;
                }
            }

            if (! empty($missing)) {
                return [
                    'error'           => sprintf(
                        'Missing required column(s): %s.',
                        implode(', ', $missing),
                    ),
                    'missing_headers' => $missing,
                ];
            }

            // Parse data rows.
            $rows = [];

            while (($row = fgetcsv($handle)) !== false) {
                if ($row === [null]) {
                    continue; // Skip blank lines.
                }

                $normalized = [];

                foreach ($headerMap as $field => $colIndex) {
                    $normalized[$field] = trim((string) ($row[$colIndex] ?? ''));
                }

                $rows[] = $normalized;
            }

            return ['rows' => $rows];
        } finally {
            fclose($handle);
        }
    }
}
