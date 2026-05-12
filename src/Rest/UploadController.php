<?php

declare(strict_types=1);

namespace WicketAORM\Rest;

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
 *      — return error with existing session ID if found.
 *   3. Parse CSV using fgetcsv, validate required column headers exist (AORM-6.10).
 */
class UploadController extends RestController
{
    /**
     * Maximum allowed upload size in bytes (1 MB).
     *
     * Mirrors the client-side MAX_FILE_SIZE constant in UploadFileStep.js (AORM-6.4).
     */
    public const MAX_UPLOAD_SIZE = 1 * 1024 * 1024; // 1,048,576 bytes

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
                        'file'            => [
                            'required' => true,
                        ],
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
     *   1. AORM-6.6 — Validate file type (.csv only) and size (max 1 MB).
     *   2. AORM-6.7 — Active session gate (to be implemented).
     *   3. AORM-6.10 — CSV parsing + column header validation (to be implemented).
     *
     * @param \WP_REST_Request $request
     */
    public function upload($request): \WP_REST_Response
    {
        $orgUuid        = (string) $request->get_param('org_uuid');
        $membershipUuid = (string) $request->get_param('membership_uuid');

        // AORM-6.6: Validate file type and size before any further processing.
        $fileParams     = $request->get_file_params();
        $fileValidation = $this->validateFile($fileParams);

        if ($fileValidation !== null) {
            return $fileValidation;
        }

        // AORM-6.7: active session gate goes here.
        // AORM-6.10: CSV parsing + column header validation goes here.

        return new \WP_REST_Response(
            [
                'accepted'        => true,
                'org_uuid'        => $orgUuid,
                'membership_uuid' => $membershipUuid,
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
