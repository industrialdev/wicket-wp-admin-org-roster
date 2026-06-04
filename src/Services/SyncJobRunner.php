<?php

declare(strict_types=1);

namespace WicketAORM\Services;

/**
 * SyncJobRunner — background job handler for MDP sync.
 *
 * The hook constant is referenced by CommitController (AORM-9.2) when
 * dispatching the sync job via SchedulerService. The handle() implementation
 * is added in AORM-9.3.
 */
class SyncJobRunner
{
    /**
     * The WordPress action hook name fired by Action Scheduler / WP-Cron.
     *
     * Referenced in:
     *   - Main::registerHooks()  — add_action() registration (AORM-9.3)
     *   - CommitController       — dispatch on commit (AORM-9.2)
     */
    public const HOOK = 'wicket_aorm_run_sync';
}
