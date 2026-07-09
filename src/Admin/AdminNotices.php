<?php

declare(strict_types=1);

namespace WicketAORM\Admin;

use WicketAORM\Database\RosterMetaTable;
use WicketORM\Config\OrgManConfig;

/**
 * Renders admin notices for the Organization Rosters page.
 *
 * Surfaces a warning whenever one or more organization rosters have a
 * roster_status of 'has_failures', prompting the administrator to review
 * and retry the failed sync records. Also surfaces an informational notice
 * when the ORM's membership strategy is set to "cascade" (see
 * renderCascadeStrategyNotice()).
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

    /**
     * membership.strategy value that triggers renderCascadeStrategyNotice().
     *
     * Mirrors the default used by WicketORM\Services\ConfigService::getRosterMode(),
     * which falls back to 'cascade' when the config key is unset.
     */
    public const CASCADE_STRATEGY = 'cascade';

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

    /**
     * Render an informational notice when the ORM's membership strategy is
     * set to "cascade".
     *
     * Under the cascade strategy, wicket-wp-organization-roster resolves the
     * org's current membership itself and only creates a person-to-organization
     * connection in the MDP — the MDP then derives and assigns the resulting
     * membership automatically (see CascadeStrategy::addMember()). AORM's own
     * sync logic (SyncService) never reads a membership UUID either — it syncs
     * by org_uuid only. So the specific membership tier shown in each
     * RosterListTable row is informational only and has no bearing on which
     * membership a synced person ends up with.
     *
     * Defaults to showing the notice when the strategy is unset, matching
     * WicketORM\Services\ConfigService::getRosterMode()'s own 'cascade'
     * fallback. Silently returns when the ORM plugin (and therefore
     * OrgManConfig) is unavailable, or when a non-cascade strategy is active.
     *
     * Intentionally not dismissible (no is-dismissible class) — this is a
     * standing informational notice about the site's current configuration,
     * not a one-time event.
     */
    public function renderCascadeStrategyNotice(): void
    {
        if (! class_exists(OrgManConfig::class)) {
            return;
        }

        $config   = OrgManConfig::get();
        $strategy = (string) ($config['membership']['strategy'] ?? self::CASCADE_STRATEGY);

        if ($strategy !== self::CASCADE_STRATEGY) {
            return;
        }

        echo '<div class="notice notice-info">';
        echo '<p>' . esc_html__(
            'This site uses the Cascade membership strategy. Adding an imported person to an organization only creates a connection between that person and the organization in the MDP — the MDP automatically creates the resulting membership from that connection. The membership shown for each row below is for reference only and does not affect which membership the person receives.',
            'wicket-aorm',
        ) . '</p>';
        echo '</div>';
    }
}
