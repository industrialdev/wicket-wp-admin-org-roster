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
     * AORM-6.5: skeleton endpoint — accepts org_uuid, membership_uuid, and file.
     * Validation (AORM-6.6), session gate (AORM-6.7), and CSV parsing (AORM-6.10)
     * are implemented in subsequent subtasks.
     *
     * @param \WP_REST_Request $request
     */
    public function upload($request): \WP_REST_Response
    {
        $orgUuid        = (string) $request->get_param('org_uuid');
        $membershipUuid = (string) $request->get_param('membership_uuid');

        // Retrieve uploaded file — WP surfaces $_FILES via get_file_params().
        // AORM-6.6: file type + size validation goes here.
        // AORM-6.7: active session gate goes here.
        // AORM-6.10: CSV parsing + column header validation goes here.
        $request->get_file_params();

        return new \WP_REST_Response(
            [
                'accepted'        => true,
                'org_uuid'        => $orgUuid,
                'membership_uuid' => $membershipUuid,
            ],
            200,
        );
    }
}
