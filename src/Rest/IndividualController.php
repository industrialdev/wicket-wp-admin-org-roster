<?php

declare(strict_types=1);

namespace WicketAORM\Rest;

use WicketAORM\Database\RosterMetaTable;
use WicketAORM\Database\StagedRecordsTable;
use WicketAORM\Services\MatchingService;
use WicketAORM\Services\ValidationService;

/**
 * Individual add endpoint.
 *
 * Routes registered under the wicket-aorm/v1 namespace:
 *   POST /rosters/{org_uuid}/{membership_uuid}/individual — add a single person (AORM-5)
 *
 * Processing order enforced by add_individual():
 *   1. Gate-check for an active staged-records session (AORM-5.3) — 409 if found.
 *   2. Validate submitted fields against the shared roster row rules (AORM-5.4) — 422 if invalid.
 *   3. Generate a new upload session UUID, insert the staged record (AORM-5.5).
 *   4. Set roster_status = 'in_progress' on wp_wicket_aorm_roster_meta (AORM-5.9).
 *   5. Run synchronous MDP matching for the single row (AORM-5.6) — updates the staged record
 *      with match_count, matched_persons, match_details, record_status, and category.
 *      Returns 200 with session_id, record_id, and match_category.
 */
class IndividualController extends RestController
{
    /**
     * UUID v4 route parameter pattern (lowercase hex with dashes).
     */
    private const UUID_PATTERN = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    public function __construct(
        private readonly ?StagedRecordsTable $stagedRecordsTable = null,
        private readonly ?ValidationService $validationService = null,
        private readonly ?MatchingService $matchingService = null,
        private readonly ?RosterMetaTable $rosterMetaTable = null,
    ) {
    }

    /**
     * Register the individual add route.
     */
    public function register_routes(): void
    {
        register_rest_route(
            $this->namespace,
            '/rosters/(?P<org_uuid>' . self::UUID_PATTERN . ')/(?P<membership_uuid>' . self::UUID_PATTERN . ')/individual',
            [
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => [$this, 'add_individual'],
                    'permission_callback' => [$this, 'add_individual_permissions_check'],
                    'args'                => [
                        'org_uuid'        => [
                            'required'          => true,
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'membership_uuid' => [
                            'required'          => true,
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'first_name'      => [
                            'required'          => true,
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'last_name'       => [
                            'required'          => true,
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'email'           => [
                            'required'          => true,
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'mobile_phone'    => [
                            'required'          => false,
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'title'           => [
                            'required'          => false,
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                    ],
                ],
            ],
        );
    }

    /**
     * Permission check for POST /rosters/{org_uuid}/{membership_uuid}/individual.
     *
     * @param \WP_REST_Request $request
     */
    public function add_individual_permissions_check($request): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * Handle POST /rosters/{org_uuid}/{membership_uuid}/individual.
     *
     * Processing order:
     *   1. Gate-check for an active staged-records session (AORM-5.3) — 409 if found.
     *   2. Validate submitted fields against the shared roster row rules (AORM-5.4) — 422 if invalid.
     *   3. Generate a new upload session UUID, insert the staged record (AORM-5.5).
     *   4. Set roster_status = 'in_progress' on wp_wicket_aorm_roster_meta (AORM-5.9).
     *   5. Run synchronous MDP matching for the single row (AORM-5.6) — update staged record
     *      with match results; return 200 with session_id, record_id, and match_category.
     *
     * @param \WP_REST_Request $request
     */
    public function add_individual($request): \WP_REST_Response
    {
        $orgUuid        = (string) $request->get_param('org_uuid');
        $membershipUuid = (string) $request->get_param('membership_uuid');

        // AORM-5.3: reject when an active upload session already exists.
        $table = $this->stagedRecordsTable ?? new StagedRecordsTable();

        if ($table->hasActiveSession($orgUuid, $membershipUuid)) {
            return new \WP_REST_Response(
                [
                    'message' => 'An active upload session already exists for this roster. '
                        . 'Please resolve the pending session before adding individual records.',
                ],
                409,
            );
        }

        // AORM-5.4: validate fields using the same rules applied to CSV rows.
        $validator = $this->validationService ?? new ValidationService();

        $errors = $validator->validateRow([
            'first_name'   => (string) ($request->get_param('first_name') ?? ''),
            'last_name'    => (string) ($request->get_param('last_name') ?? ''),
            'email'        => (string) ($request->get_param('email') ?? ''),
            'mobile_phone' => (string) ($request->get_param('mobile_phone') ?? ''),
        ]);

        if (! empty($errors)) {
            return new \WP_REST_Response(['errors' => $errors], 422);
        }

        // AORM-5.5: create a new upload session and insert the staged record.
        $sessionId = wp_generate_uuid4();
        $now       = current_time('mysql');
        $userId    = get_current_user_id();

        $rawData = json_encode([
            'first_name'   => (string) ($request->get_param('first_name') ?? ''),
            'last_name'    => (string) ($request->get_param('last_name') ?? ''),
            'email'        => (string) ($request->get_param('email') ?? ''),
            'mobile_phone' => (string) ($request->get_param('mobile_phone') ?? ''),
            'title'        => (string) ($request->get_param('title') ?? ''),
        ]);

        $recordId = $table->insertRecord([
            'upload_session_id' => $sessionId,
            'action_type'       => 'add',
            'org_uuid'          => $orgUuid,
            'membership_uuid'   => $membershipUuid,
            'raw_data'          => $rawData,
            'validation_status' => 'valid',
            'category'          => 'ready_to_sync',
            'record_status'     => 'new_record',
            'match_count'       => 0,
            'sync_status'       => 'pending',
            'uploaded_by'       => $userId,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);

        // AORM-5.9: mark the roster as in_progress so the list view reflects the pending work.
        $actor    = $this->resolveActorName($userId);
        $metaTable = $this->rosterMetaTable ?? new RosterMetaTable();
        $metaTable->upsertRosterStatus($orgUuid, $membershipUuid, 'in_progress', $actor, $now);

        // AORM-5.6: run synchronous MDP matching for the single inserted record.
        $matcher    = $this->matchingService ?? new MatchingService();
        $matchResult = $matcher->matchRow(
            [
                'first_name'   => (string) ($request->get_param('first_name') ?? ''),
                'last_name'    => (string) ($request->get_param('last_name') ?? ''),
                'email'        => (string) ($request->get_param('email') ?? ''),
                'mobile_phone' => (string) ($request->get_param('mobile_phone') ?? ''),
                'title'        => (string) ($request->get_param('title') ?? ''),
            ],
            $orgUuid,
            $membershipUuid,
        );

        // Persist match results back to the staged record.
        $table->updateRecord($recordId, [
            'match_count'     => $matchResult['match_count'],
            'matched_persons' => $matchResult['matched_persons'],
            'match_details'   => $matchResult['match_details'],
            'record_status'   => $matchResult['record_status'],
            'category'        => $matchResult['category'],
            'updated_at'      => $matchResult['updated_at'],
        ]);

        return new \WP_REST_Response(
            [
                'session_id'     => $sessionId,
                'record_id'      => $recordId,
                'match_category' => $matchResult['category'],
            ],
            200,
        );
    }

    /**
     * Resolve a display name for the acting WordPress user.
     *
     * Matches the convention used by ActivityLogger so roster_meta rows written
     * by IndividualController are consistent with those written during bulk
     * assignment operations.
     *
     * Preference order: user_email → "user:{id}" → "system".
     *
     * @param int $userId WordPress user ID (0 means unauthenticated).
     */
    private function resolveActorName(int $userId): string
    {
        if ($userId <= 0) {
            return 'system';
        }

        $user = get_userdata($userId);

        if ($user !== false && ! empty($user->user_email)) {
            return (string) $user->user_email;
        }

        return "user:{$userId}";
    }
}
