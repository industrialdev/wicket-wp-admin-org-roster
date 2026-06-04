<?php

declare(strict_types=1);

namespace WicketAORM;

/**
 * Plugin singleton orchestrator.
 *
 * Registers admin menus, hooks, and initialises sub-components.
 */
final class Main
{
    private static ?self $instance = null;

    private function __construct()
    {
        $this->registerHooks();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Register WordPress hooks and filters.
     */
    private function registerHooks(): void
    {
        // Admin menu pages.
        add_action('admin_menu', [$this, 'registerAdminMenus']);

        // REST API routes.
        add_action('rest_api_init', [$this, 'registerRestRoutes']);

        // Enqueue admin assets.
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);

        // AORM-7.2: Background MDP matching job — fired by Action Scheduler or
        // WP-Cron with the upload_session_id as the first (and only) argument.
        add_action(
            Services\MatchingJobRunner::HOOK,
            [new Services\MatchingJobRunner(), 'handle'],
        );

        // AORM-9.3: Background MDP sync job — fired by Action Scheduler or
        // WP-Cron with upload_session_id and ids as arguments.
        add_action(
            Services\SyncJobRunner::HOOK,
            [new Services\SyncJobRunner(), 'handle'],
            10,
            2,
        );
    }

    /**
     * Register admin menu pages.
     */
    public function registerAdminMenus(): void
    {
        $menuPage = new Admin\MenuPage();
        $menuPage->register();
    }

    /**
     * Register REST API routes.
     */
    public function registerRestRoutes(): void
    {
        (new Rest\RosterController())->register_routes();
        (new Rest\IndividualController())->register_routes();
        (new Rest\UploadController())->register_routes();
        (new Rest\StagedRecordController())->register_routes();
        (new Rest\SyncController())->register_routes();
        (new Rest\UploadStatusController())->register_routes();   // AORM-7.11
        (new Rest\UploadStagedController())->register_routes();    // AORM-8.1
        (new Rest\ReplacementDiffController())->register_routes(); // AORM-8B.4
        (new Rest\StagedMatchesController())->register_routes();  // AORM-8B.12
        (new Rest\StagedResolveController())->register_routes();  // AORM-8B.18
        (new Rest\CommitController())->register_routes();          // AORM-9.2
        (new Rest\ActiveSessionController())->register_routes();  // AORM-7 cross-browser
    }

    /**
     * Enqueue admin scripts and styles.
     *
     * @param string $hookSuffix The current admin page hook suffix passed by
     *                           the admin_enqueue_scripts action.
     */
    public function enqueueAssets(string $hookSuffix): void
    {
        $assets = new Assets();
        $assets->enqueue($hookSuffix);
    }

    /**
     * Prevent cloning.
     */
    private function __clone()
    {
    }
}
