<?php

declare(strict_types=1);

namespace WicketAORM\Rest;

use WicketAORM\Database\StagedRecordsTable;
use WicketAORM\Services\FileParserService;
use WicketAORM\Services\ValidationService;

/**
 * File upload + parse to staged records endpoint.
 *
 * The downloadable CSV template is served as a static file from
 * assets/roster-template.csv (AORM-6.3) — no endpoint needed.
 *
 * Routes:
 *   POST /wicket-aorm/v1/uploads  — upload + parse CSV (AORM-6.5)
 *
 * Processing order enforced by upload():
 *   1. Validate file type (CSV) and size (max 1 MB) (AORM-6.6).
 *   2. Check for an active upload session for this membership_uuid (AORM-6.7)
 *      — return 409 with {message, session_id} if found.
 *   3. Parse CSV using fgetcsv, validate required column headers exist (AORM-6.10).
 *   4. Validate each row for missing required fields; insert staged records with
 *      validation_status and validation_message set accordingly (AORM-6.12).
 */
class UploadController extends RestController
{
    /**
     * Allowed values for the action_type request parameter.
     *
     * @var list<string>
     */
    public const VALID_ACTION_TYPES = ['add', 'replace'];
    /**
     * Maximum allowed upload size in bytes (1 MB).
     *
     * Mirrors the client-side MAX_FILE_SIZE constant in UploadFileStep.js (AORM-6.4).
     */
    public const MAX_UPLOAD_SIZE = 1 * 1024 * 1024; // 1,048,576 bytes

    public function __construct(
        private readonly ?StagedRecordsTable $stagedRecordsTable = null,
        private readonly ?FileParserService $fileParserService = null,
        private readonly ?ValidationService $validationService = null,
    ) {
    }

    /**
     * Register routes for file upload.
     */
    public function register_routes(): void
    {
        register_rest_route(
            $this->namespace,
            '/uploads',
            [
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => [$this, 'upload'],
                    'permission_callback' => [$this, 'upload_permissions_check'],
                    'args'                => [
                        'org_uuid'        => [
                            'required'          => true,
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'membership_uuid' => [
                            'required'          => true,
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'action_type'     => [
                            'required'          => false,
                            'default'           => 'add',
                            'sanitize_callback' => 'sanitize_key',
                            'validate_callback' => static fn ($value): bool => in_array($value, self::VALID_ACTION_TYPES, true),
                        ],
                        // 'file' is validated in validateFile() via get_file_params().
                        // WordPress cannot see $_FILES entries when checking 'required'
                        // args, so we skip the required flag and handle the missing-file
                        // case ourselves (returns 422 with a message).
                        'file'            => [],
                    ],
                ],
            ],
        );
    }

    /**
     * Permission check for POST /uploads.
     *
     * @param \WP_REST_Request $request
     */
    public function upload_permissions_check($request): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * Handle POST /uploads.
     *
     * Processing order:
     *   1. AORM-6.6  — Validate file type (.csv only) and size (max 1 MB).
     *   2. AORM-6.7  — Active session gate: 409 with {message, session_id} if active session exists.
     *   3. AORM-6.10 — CSV parsing + column header validation.
     *   4. AORM-6.12 — Validate each row for missing required fields; insert staged records
     *                   with validation_status and validation_message; return session_id.
     *
     * @param \WP_REST_Request $request
     */
    public function upload($request): \WP_REST_Response
    {
        $orgUuid        = (string) $request->get_param('org_uuid');
        $membershipUuid = (string) $request->get_param('membership_uuid');
        $actionType     = (string) ($request->get_param('action_type') ?? 'add');

        // AORM-6.6: Validate file type and size before any further processing.
        $fileParams     = $request->get_file_params();
        $fileValidation = $this->validateFile($fileParams);

        if ($fileValidation !== null) {
            return $fileValidation;
        }

        // AORM-6.7: reject if an active session already exists for this roster.
        $table           = $this->stagedRecordsTable ?? new StagedRecordsTable();
        $existingSession = $table->getActiveSessionId($orgUuid, $membershipUuid);

        if ($existingSession !== null) {
            return new \WP_REST_Response(
                [
                    'message'    => 'An active upload session already exists for this roster. '
                        . 'Please resolve the pending session before uploading a new file.',
                    'session_id' => $existingSession,
                ],
                409,
            );
        }

        // AORM-6.10: Parse the CSV and validate required column headers exist.
        $parser      = $this->fileParserService ?? new FileParserService();
        $tmpPath     = (string) ($fileParams['file']['tmp_name'] ?? '');
        $parseResult = $parser->parseFile($tmpPath);

        if (isset($parseResult['error'])) {
            return new \WP_REST_Response(
                ['message' => $parseResult['error']],
                422,
            );
        }

        // AORM-6.12: Validate each row for missing required fields and insert
        // staged records with the appropriate validation_status.
        $rows       = $parseResult['rows'] ?? [];
        $sessionId  = wp_generate_uuid4();
        $validator  = $this->validationService ?? new ValidationService();
        $now        = current_time('mysql');
        $uploadedBy = get_current_user_id();
        $validCount   = 0;
        $invalidCount = 0;

        foreach ($rows as $row) {
            $errors            = $validator->validateRow($row);
            $hasMissingRequired = $validator->hasMissingRequired($errors);

            if ($hasMissingRequired) {
                $validationStatus  = 'invalid';
                $validationMessage = ValidationService::VALIDATION_LABEL_MISSING_REQUIRED;
                $category          = 'discard';
                ++$invalidCount;
            } else {
                $validationStatus  = 'valid';
                $validationMessage = null;
                $category          = 'ready_to_sync';
                ++$validCount;
            }

            $table->insertRecord([
                'upload_session_id'  => $sessionId,
                'action_type'        => $actionType,
                'org_uuid'           => $orgUuid,
                'membership_uuid'    => $membershipUuid,
                'raw_data'           => (string) json_encode($row),
                'validation_status'  => $validationStatus,
                'validation_message' => $validationMessage,
                'category'           => $category,
                'record_status'      => 'new_record',
                'match_count'        => 0,
                'sync_status'        => 'pending',
                'uploaded_by'        => $uploadedBy,
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);
        }

        return new \WP_REST_Response(
            [
                'accepted'        => true,
                'session_id'      => $sessionId,
                'org_uuid'        => $orgUuid,
                'membership_uuid' => $membershipUuid,
                'row_count'       => count($rows),
                'valid_count'     => $validCount,
                'invalid_count'   => $invalidCount,
            ],
            200,
        );
    }

    /**
     * Validate the uploaded file: extension must be .csv and size must not
     * exceed MAX_UPLOAD_SIZE.
     *
     * Returns null on success; a 422 WP_REST_Response with a 'message' key
     * on failure.  Extension is checked before size so the client always
     * receives the most actionable error first.
     *
     * @param  array<string, mixed> $fileParams  Result of WP_REST_Request::get_file_params().
     * @return \WP_REST_Response|null
     */
    private function validateFile(array $fileParams): ?\WP_REST_Response
    {
        $file = $fileParams['file'] ?? null;

        if (! is_array($file) || ($file['error'] ?? \UPLOAD_ERR_NO_FILE) !== \UPLOAD_ERR_OK) {
            return new \WP_REST_Response(['message' => 'No file was uploaded.'], 422);
        }

        $name      = (string) ($file['name'] ?? '');
        $extension = strtolower(pathinfo($name, \PATHINFO_EXTENSION));

        if ($extension !== 'csv') {
            return new \WP_REST_Response(
                [
                    'message' => sprintf(
                        'Invalid file type (.%s). Only .csv files are accepted.',
                        $extension ?: 'unknown',
                    ),
                ],
                422,
            );
        }

        $size = (int) ($file['size'] ?? 0);

        if ($size > self::MAX_UPLOAD_SIZE) {
            return new \WP_REST_Response(
                [
                    'message' => sprintf(
                        'File size (%d bytes) exceeds the 1 MB limit.',
                        $size,
                    ),
                ],
                422,
            );
        }

        return null;
    }
}
