<?php

declare(strict_types=1);

namespace WicketAORM\Services;

use WicketAORM\Database\StagedRecordsTable;
use WicketAORM\Rest\UploadController;

/**
 * Finds and repairs upload sessions stuck on "Comparing records against MDP…".
 *
 * A session is stuck when it has valid rows still at sync_status = 'pending',
 * nothing in it has changed for STUCK_AFTER_MINUTES, and no matching job is
 * queued or running for it. Two causes, two repairs:
 *
 *   - Clean file (no invalid/duplicate rows) — the matching job was lost
 *     (failed, purged, or never ran). ACTION_REQUEUE dispatches
 *     MatchingJobRunner again; it only picks up rows still 'pending', so
 *     re-running is safe.
 *   - File had invalid/duplicate rows — matching is never dispatched for
 *     these by design (UploadController::upload()); the session only looks
 *     "in progress" because the admin left the CSV validation screen without
 *     re-uploading. ACTION_ABANDON deletes it via UploadController::abandon(),
 *     the same path as "Start Fresh", so the session_abandoned log row and
 *     roster status update still happen.
 *
 * Used by Admin\StuckSessionsSection (Settings page).
 */
class StuckSessionService
{
    /** Minutes without any row change before a pending session counts as stuck. */
    public const STUCK_AFTER_MINUTES = 10;

    public const ACTION_REQUEUE = 'requeue';
    public const ACTION_ABANDON = 'abandon';

    public function __construct(
        private readonly ?StagedRecordsTable $stagedRecordsTable = null,
        private readonly ?SchedulerService $schedulerService = null,
        private readonly ?UploadController $uploadController = null,
    ) {
    }

    /**
     * Stuck sessions with the repair each one will get.
     *
     * @return list<array{upload_session_id: string, org_uuid: string, membership_uuid: string, action_type: string, started_at: string, last_activity_at: string, pending_rows: int, invalid_rows: int, action: string}>
     */
    public function findStuckSessions(): array
    {
        $table     = $this->stagedRecordsTable ?? new StagedRecordsTable();
        $scheduler = $this->schedulerService ?? new SchedulerService();
        $cutoff    = gmdate('Y-m-d H:i:s', $this->nowTimestamp() - self::STUCK_AFTER_MINUTES * 60);

        $stuck = [];

        foreach ($table->getStuckMatchingSessions($cutoff) as $session) {
            $sessionId = $session['upload_session_id'];

            if ($sessionId === '') {
                continue;
            }

            // A queued or running job will finish the session on its own.
            if ($scheduler->isScheduled(MatchingJobRunner::HOOK, ['upload_session_id' => $sessionId])) {
                continue;
            }

            $session['action'] = $session['invalid_rows'] > 0 ? self::ACTION_ABANDON : self::ACTION_REQUEUE;
            $stuck[]           = $session;
        }

        return $stuck;
    }

    /**
     * Repair every stuck session. The list is rebuilt here rather than taken
     * from the submitted form, so only sessions that are still stuck are touched.
     *
     * @return array{requeued: int, abandoned: int, failed: int}
     */
    public function fixAll(): array
    {
        $scheduler = $this->schedulerService ?? new SchedulerService();
        $result    = ['requeued' => 0, 'abandoned' => 0, 'failed' => 0];

        foreach ($this->findStuckSessions() as $session) {
            $sessionId = $session['upload_session_id'];

            try {
                if ($session['action'] === self::ACTION_REQUEUE) {
                    $dispatched = $scheduler->dispatch(
                        MatchingJobRunner::HOOK,
                        ['upload_session_id' => $sessionId],
                    );

                    $dispatched === false ? ++$result['failed'] : ++$result['requeued'];

                    continue;
                }

                $controller = $this->uploadController ?? new UploadController();
                $request    = new \WP_REST_Request('DELETE', '/wicket-aorm/v1/uploads/' . $sessionId);
                $request->set_param('session_id', $sessionId);
                $response = $controller->abandon($request);

                $response->get_status() === 200 ? ++$result['abandoned'] : ++$result['failed'];
            } catch (\Throwable $e) {
                ++$result['failed'];
            }
        }

        return $result;
    }

    /**
     * Current time on the same clock as the staged rows' updated_at
     * (current_time('mysql'), i.e. site-local time), as a Unix-style timestamp
     * so it can be formatted back with gmdate() without a timezone shift.
     */
    protected function nowTimestamp(): int
    {
        return (int) strtotime(current_time('mysql') . ' UTC');
    }
}
