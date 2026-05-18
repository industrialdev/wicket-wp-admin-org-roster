<?php

declare(strict_types=1);

namespace WicketAORM\Services;

/**
 * SchedulerService — background job dispatcher.
 *
 * Uses Action Scheduler (woocommerce/action-scheduler) when it is available in
 * the environment.  Falls back to WordPress's built-in wp_schedule_single_event
 * so the plugin functions correctly without Action Scheduler installed.
 *
 * The fallback is intentionally functional rather than a no-op: both code paths
 * honour the same $hook / $args / $delay contract.  The primary difference is
 * persistence and reliability — Action Scheduler stores pending actions in the
 * database and retries on failure, while wp_schedule_single_event relies on
 * WP-Cron and offers no persistence guarantees.
 *
 * Detection is encapsulated in the protected actionSchedulerAvailable() method
 * so that test subclasses can override it without the function_exists() call
 * being hard-coded in the dispatch path.
 */
class SchedulerService
{
    /**
     * Default Action Scheduler group for all AORM actions.
     */
    public const DEFAULT_GROUP = 'wicket-aorm';

    /**
     * Schedule a single background action.
     *
     * @param string $hook  The WordPress action hook that will be fired.
     * @param array  $args  Arguments forwarded to the hook callback.
     * @param int    $delay Seconds from now before the action fires (0 = as soon as possible).
     * @param string $group Action Scheduler group (ignored when falling back to WP-Cron).
     * @return int|bool Action Scheduler action ID (int) when AS is available,
     *                  or the bool result of wp_schedule_single_event otherwise.
     */
    public function dispatch(
        string $hook,
        array $args = [],
        int $delay = 0,
        string $group = self::DEFAULT_GROUP,
    ): int|bool {
        $timestamp = time() + $delay;

        if ($this->actionSchedulerAvailable()) {
            return as_schedule_single_action($timestamp, $hook, $args, $group);
        }

        return wp_schedule_single_event($timestamp, $hook, $args);
    }

    /**
     * Whether Action Scheduler is available in the current environment.
     *
     * Exposed publicly so callers can choose higher-fidelity error handling
     * (e.g. logging a warning when only WP-Cron is available).
     */
    public function hasActionScheduler(): bool
    {
        return $this->actionSchedulerAvailable();
    }

    /**
     * Protected detection hook — overrideable in test subclasses.
     *
     * Isolating the function_exists() call here means unit tests do not need
     * to define or un-define global functions to drive both branches.
     */
    protected function actionSchedulerAvailable(): bool
    {
        return function_exists('as_schedule_single_action');
    }
}
