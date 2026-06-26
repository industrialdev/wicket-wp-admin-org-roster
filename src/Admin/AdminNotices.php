<?php

declare(strict_types=1);

namespace WicketAORM\Admin;

use WicketAORM\Database\RosterMetaTable;

/**
 * Renders admin notices for the Organization Rosters page.
 *
 * Surfaces a warning whenever one or more organization rosters have a
 * roster_status of 'has_failures', prompting the administrator to review
 * and retry the failed sync records.
 *
 * render() is called directly from MenuPage::renderOrgRostersPage() rather
 * than via the admin_notices hook to guarantee execution timing regardless
 * of when the plugin singleton is initialised.
 *
 * @see AORM-13.5
 */
class AdminNotices
{
    /** roster_status value that represents a sync with at least one failed record. */
    public const STATUS_HAS_FAILURES = 'has_failures';

    private RosterMetaTable $rosterMetaTable;

    /**
     * @param RosterMetaTable|null $rosterMetaTable  Injected meta table; defaults to new RosterMetaTable().
     */
    public function __construct(?RosterMetaTable $rosterMetaTable = null)
    {
        $this->rosterMetaTable = $rosterMetaTable ?? new RosterMetaTable();
    }

    /**
     * Render an admin notice if one or more rosters have sync failures.
     *
     * Intended to be called directly from the page render method that should
     * display the notice (e.g. MenuPage::renderOrgRostersPage()), not via the
     * admin_notices hook — calling it inline guarantees execution timing and
     * avoids hook-registration issues specific to when the singleton is booted.
     *
     * Silently returns when no rosters have a roster_status of 'has_failures'.
     * Outputs a dismissible warning notice with a count of affected rosters.
     */
    public function render(): void
    {
        $failureCount = $this->rosterMetaTable->countByStatus(self::STATUS_HAS_FAILURES);

        if ($failureCount < 1) {
            return;
        }

        $message = $failureCount === 1
            ? esc_html__('1 organization roster has sync failures requiring attention.', 'wicket-aorm')
            : sprintf(
                /* translators: %d: number of organization rosters with sync failures */
                esc_html__('%d organization rosters have sync failures requiring attention.', 'wicket-aorm'),
                $failureCount,
            );

        echo '<div class="notice notice-warning is-dismissible">';
        echo '<p>' . $message . '</p>'; // $message is already escaped above.
        echo '</div>';
    }
}
