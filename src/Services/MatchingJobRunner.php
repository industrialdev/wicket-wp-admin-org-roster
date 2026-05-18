<?php

declare(strict_types=1);

namespace WicketAORM\Services;

/**
 * MatchingJobRunner — background job handler for MDP duplicate detection.
 *
 * This class is the WordPress action hook callback registered under HOOK.
 * Action Scheduler (or WP-Cron via the fallback path in SchedulerService)
 * invokes handle() with the upload_session_id of the batch to process.
 *
 * The actual per-row matching logic (scoring, categorisation, DB updates)
 * is added by subsequent tickets (AORM-7.3 through AORM-7.10). This class
 * provides the entry-point contract: a stable hook name constant and a
 * typed handle() signature that downstream code can depend on.
 *
 * Dispatching this job is done via SchedulerService::dispatch():
 *
 *   $scheduler->dispatch(
 *       MatchingJobRunner::HOOK,
 *       ['upload_session_id' => $sessionId],
 *   );
 *
 * Action Scheduler serialises the $args array and passes each value as an
 * individual argument to the hook callback. WP-Cron follows the same
 * convention. Both paths therefore call:
 *
 *   do_action('wicket_aorm_run_matching', $uploadSessionId)
 *
 * which maps directly to handle(string $uploadSessionId).
 */
class MatchingJobRunner
{
    /**
     * The WordPress action hook name fired by Action Scheduler / WP-Cron.
     *
     * Referenced in:
     *   - Main::registerHooks()  — add_action() registration
     *   - UploadController       — dispatch after CSV parse (AORM-7.2)
     *   - MatchingJobRunner      — re-dispatch for next batch (AORM-7.10)
     */
    public const HOOK = 'wicket_aorm_run_matching';

    public function __construct(
        private readonly ?SchedulerService $schedulerService = null,
    ) {
    }

    /**
     * Entry point invoked by Action Scheduler / WP-Cron.
     *
     * Receives the upload session UUID and orchestrates MDP matching for
     * the next unprocessed batch of staged records in that session.
     *
     * The full implementation is added across AORM-7.3 through AORM-7.10.
     * This stub establishes the callable contract so the hook registration
     * (AORM-7.2) and any dispatch call-sites can be wired without waiting
     * for the matching logic to be complete.
     *
     * @param string $uploadSessionId UUID of the upload session to process.
     */
    public function handle(string $uploadSessionId): void
    {
        // Implementation added in AORM-7.3 – 7.10.
    }
}
