<?php

declare(strict_types=1);

namespace WicketAORM\Services;

/**
 * Writes roster-activity milestone touchpoints to the MDP.
 *
 * Touchpoints are written to the roster's membership owner (the person on the
 * organization_membership `owner` relationship) under the "Admin Roster
 * Manager" touchpoint service, so reporting can build roster timelines:
 *
 *   - "Roster Upload Started" — the CSV was uploaded and accepted (rows staged).
 *   - "Roster in Progress"    — validation/import staging (and MDP matching,
 *                               when it runs) is complete and the roster is
 *                               ready for admin review.
 *
 * The actual write is delegated to WicketORM\Services\TouchpointService
 * (wicket-wp-account-centre), which wraps the base plugin's
 * write_touchpoint() / get_create_touchpoint_service_id() helpers. The
 * service ID lookup is cached by the base plugin.
 *
 * Contract: never throws, and never blocks the calling workflow.
 *   - Touchpoint helpers unavailable → returns false silently.
 *   - No membership owner in the MDP → nothing is written; a 'touchpoint_skipped'
 *     warning row is added to the AORM activity log.
 *   - Write rejected / exception      → a 'touchpoint_failed' error row is added
 *     to the AORM activity log.
 *
 * Each touchpoint carries an external_event_id derived from the upload session,
 * so a retried background job does not create duplicates in the MDP.
 */
class RosterTouchpointService
{
    /** MDP touchpoint service name (created on first use when absent). */
    public const SERVICE_NAME = 'Admin Roster Manager';

    /** Description used when the MDP service has to be created. */
    public const SERVICE_DESCRIPTION = 'Roster activity from the Admin Roster Manager';

    /** Touchpoint action — roster file uploaded and accepted. */
    public const ACTION_UPLOAD_STARTED = 'Roster Upload Started';

    /** Touchpoint action — staging complete, roster ready for admin review. */
    public const ACTION_PROCESSING_READY = 'Roster in Progress';

    /** external_event_id prefixes (suffixed with the upload session UUID). */
    public const EVENT_PREFIX_UPLOAD_STARTED   = 'aorm_roster_upload_started_';
    public const EVENT_PREFIX_PROCESSING_READY = 'aorm_roster_processing_ready_';

    /** AORM activity log action slugs for touchpoint problems. */
    public const LOG_ACTION_SKIPPED = 'touchpoint_skipped';
    public const LOG_ACTION_FAILED  = 'touchpoint_failed';

    /**
     * Per-request cache of getOrgMembershipDetail() results, keyed by
     * membership UUID, so writing both touchpoints in one request (rejected
     * file path) only looks the roster up once.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $detailCache = [];

    /**
     * @param MdpClient|null                            $mdpClient          Roster/owner lookup.
     * @param \WicketORM\Services\TouchpointService|null $touchpointService Touchpoint writer (wicket-wp-account-centre).
     * @param ActivityLogger|null                       $activityLogger     Logs skipped/failed writes.
     */
    public function __construct(
        private readonly ?MdpClient $mdpClient = null,
        private readonly ?\WicketORM\Services\TouchpointService $touchpointService = null,
        private readonly ?ActivityLogger $activityLogger = null,
    ) {
    }

    /**
     * Write the "Roster Upload Started" touchpoint.
     *
     * @param string $uploadSessionId Upload session UUID (the "upload ID").
     * @param string $orgUuid         Organization UUID.
     * @param string $membershipUuid  Organization membership UUID (the "roster ID").
     * @param int    $userId          WordPress user ID of the uploading admin.
     * @param int    $totalRows       Total data rows submitted in the file.
     * @param string $actionType      'add' or 'replace'.
     *
     * @return bool True when the touchpoint was written.
     */
    public function logUploadStarted(
        string $uploadSessionId,
        string $orgUuid,
        string $membershipUuid,
        int $userId,
        int $totalRows,
        string $actionType = 'add',
    ): bool {
        $timestamp = gmdate('c');

        return $this->writeForRoster(
            self::ACTION_UPLOAD_STARTED,
            self::EVENT_PREFIX_UPLOAD_STARTED,
            $uploadSessionId,
            $orgUuid,
            $membershipUuid,
            $userId,
            [
                'uploaded_at' => $timestamp,
                'action_type' => $actionType,
                'total_rows'  => $totalRows,
            ],
            static fn (array $roster, array $actor): string => sprintf(
                "Roster file uploaded for %s (%s) on %s.\n\nUpload ID: %s\n\nTotal rows submitted: %d\n\nUploaded by: %s (ID: %d)",
                $roster['org_name'],
                $roster['membership_tier'] !== '' ? $roster['membership_tier'] : $membershipUuid,
                $timestamp,
                $uploadSessionId,
                $totalRows,
                $actor['name'],
                $actor['id'],
            ),
        );
    }

    /**
     * Write the "Roster in Progress" (processing ready) touchpoint.
     *
     * @param string $uploadSessionId Upload session UUID.
     * @param string $orgUuid         Organization UUID.
     * @param string $membershipUuid  Organization membership UUID (the "roster ID").
     * @param int    $userId          WordPress user ID of the uploading admin (0 = system).
     * @param int    $validRows       Rows from the file that passed validation.
     * @param int    $readyRows       Valid rows routed straight to Ready to Sync.
     * @param int    $reviewRows      Valid rows requiring admin review (possible/probable/manual).
     * @param int    $rejectedRows    Rows rejected by validation (invalid + duplicate).
     * @param int    $removalRows     Replace mode: existing members staged for removal.
     *
     * @return bool True when the touchpoint was written.
     */
    public function logProcessingReady(
        string $uploadSessionId,
        string $orgUuid,
        string $membershipUuid,
        int $userId,
        int $validRows,
        int $readyRows,
        int $reviewRows,
        int $rejectedRows,
        int $removalRows = 0,
    ): bool {
        $timestamp = gmdate('c');

        return $this->writeForRoster(
            self::ACTION_PROCESSING_READY,
            self::EVENT_PREFIX_PROCESSING_READY,
            $uploadSessionId,
            $orgUuid,
            $membershipUuid,
            $userId,
            [
                'processed_at'        => $timestamp,
                'total_valid_rows'    => $validRows,
                'total_ready_rows'    => $readyRows,
                'total_review_rows'   => $reviewRows,
                'total_rejected_rows' => $rejectedRows,
                'total_removal_rows'  => $removalRows,
            ],
            static fn (array $roster, array $actor): string => sprintf(
                "Roster for %s (%s) is ready for admin review as of %s.\n\nUpload ID: %s\n\nValid rows: %d\n\nRows requiring review: %d\n\nRows rejected: %d\n\nUploaded by: %s (ID: %d)",
                $roster['org_name'],
                $roster['membership_tier'] !== '' ? $roster['membership_tier'] : $membershipUuid,
                $timestamp,
                $uploadSessionId,
                $validRows,
                $reviewRows,
                $rejectedRows,
                $actor['name'],
                $actor['id'],
            ),
        );
    }

    // ── Internals ─────────────────────────────────────────────────────────

    /**
     * Resolve the roster owner, build the payload and write the touchpoint.
     *
     * @param array<string, mixed>                  $eventData      Event-specific data keys.
     * @param callable(array, array): string        $detailsBuilder Builds the human-readable details text.
     */
    private function writeForRoster(
        string $action,
        string $eventPrefix,
        string $uploadSessionId,
        string $orgUuid,
        string $membershipUuid,
        int $userId,
        array $eventData,
        callable $detailsBuilder,
    ): bool {
        try {
            $writer = $this->resolveWriter();

            if ($writer === null || $membershipUuid === '' || $uploadSessionId === '') {
                return false;
            }

            $roster = $this->resolveRoster($membershipUuid, $orgUuid);

            if ($roster['owner_uuid'] === '') {
                $this->logIssue(
                    self::LOG_ACTION_SKIPPED,
                    'warning',
                    sprintf('"%s" touchpoint not written: roster has no membership owner in the MDP.', $action),
                    $uploadSessionId,
                    $orgUuid,
                    $membershipUuid,
                    $userId,
                    $action,
                );

                return false;
            }

            $actor = $this->resolveActor($userId);

            $params = [
                'person_id'         => $roster['owner_uuid'],
                'action'            => $action,
                'details'           => $detailsBuilder($roster, $actor),
                'data'              => array_merge(
                    [
                        'roster_id'       => $membershipUuid,
                        'org_uuid'        => $roster['org_uuid'],
                        'org_name'        => $roster['org_name'],
                        'membership_tier' => $roster['membership_tier'],
                        'upload_id'       => $uploadSessionId,
                    ],
                    $eventData,
                    [
                        'actor_id'    => $actor['id'],
                        'actor_name'  => $actor['name'],
                        'actor_email' => $actor['email'],
                    ],
                ),
                'external_event_id' => $eventPrefix . $uploadSessionId,
            ];

            $written = $writer->write($params, self::SERVICE_NAME, self::SERVICE_DESCRIPTION);

            if (! $written) {
                $this->logIssue(
                    self::LOG_ACTION_FAILED,
                    'error',
                    sprintf('"%s" touchpoint could not be written to the MDP.', $action),
                    $uploadSessionId,
                    $orgUuid,
                    $membershipUuid,
                    $userId,
                    $action,
                );
            }

            return $written;
        } catch (\Throwable $e) {
            $this->logIssue(
                self::LOG_ACTION_FAILED,
                'error',
                sprintf('"%s" touchpoint could not be written to the MDP: %s', $action, $e->getMessage()),
                $uploadSessionId,
                $orgUuid,
                $membershipUuid,
                $userId,
                $action,
            );

            return false;
        }
    }

    /**
     * Return the touchpoint writer, or null when the account-centre service
     * or the base plugin's touchpoint helpers are not available.
     */
    private function resolveWriter(): ?\WicketORM\Services\TouchpointService
    {
        $writer = $this->touchpointService;

        if ($writer === null && class_exists(\WicketORM\Services\TouchpointService::class)) {
            $writer = new \WicketORM\Services\TouchpointService();
        }

        if ($writer === null || ! $writer->isAvailable()) {
            return null;
        }

        return $writer;
    }

    /**
     * Look up org name, tier and owner UUID for the roster (memoized).
     *
     * @return array{owner_uuid: string, org_uuid: string, org_name: string, membership_tier: string}
     */
    private function resolveRoster(string $membershipUuid, string $orgUuid): array
    {
        if (! isset($this->detailCache[$membershipUuid])) {
            $client = $this->mdpClient ?? new MdpClient();

            $this->detailCache[$membershipUuid] = $client->getOrgMembershipDetail($membershipUuid);
        }

        $detail = $this->detailCache[$membershipUuid];

        return [
            'owner_uuid'      => (string) ($detail['membership_owner_uuid'] ?? ''),
            'org_uuid'        => (string) (($detail['org_uuid'] ?? '') !== '' ? $detail['org_uuid'] : $orgUuid),
            'org_name'        => (string) ($detail['org_name'] ?? ''),
            'membership_tier' => (string) ($detail['membership_tier'] ?? ''),
        ];
    }

    /**
     * Resolve the acting admin's ID, display name and email.
     *
     * @return array{id: int, name: string, email: string}
     */
    private function resolveActor(int $userId): array
    {
        if ($userId <= 0) {
            return ['id' => 0, 'name' => 'system', 'email' => ''];
        }

        $user = get_userdata($userId);

        if ($user === false) {
            return ['id' => $userId, 'name' => "user:{$userId}", 'email' => ''];
        }

        $email = (string) ($user->user_email ?? '');
        $name  = (string) ($user->display_name ?? '');

        if ($name === '') {
            $name = $email !== '' ? $email : "user:{$userId}";
        }

        return ['id' => $userId, 'name' => $name, 'email' => $email];
    }

    /**
     * Record a skipped/failed touchpoint in the AORM activity log.
     * Swallows any logging error — touchpoints must never break the workflow.
     */
    private function logIssue(
        string $logAction,
        string $level,
        string $message,
        string $uploadSessionId,
        string $orgUuid,
        string $membershipUuid,
        int $userId,
        string $touchpointAction,
    ): void {
        try {
            $logger = $this->activityLogger ?? new ActivityLogger();
            $logger->logTouchpointIssue(
                $uploadSessionId,
                $orgUuid,
                $userId,
                $logAction,
                $level,
                $message,
                [
                    'touchpoint_action' => $touchpointAction,
                    'membership_uuid'   => $membershipUuid,
                    'upload_session_id' => $uploadSessionId,
                ],
            );
        } catch (\Throwable $e) {
            // Intentionally ignored.
        }
    }
}
