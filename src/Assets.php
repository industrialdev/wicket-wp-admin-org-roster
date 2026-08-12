<?php

declare(strict_types=1);

namespace WicketAORM;

use WicketAORM\Admin\MenuPage;
use WicketAORM\Database\StagedRecordsTable;
use WicketAORM\Services\MatchingService;
use WicketAORM\Services\MdpClient;
use WicketAORM\Services\SyncService;

/**
 * Enqueue React bundles and WP admin styles.
 *
 * Per AORM-1.6 the React bundle is loaded ONLY on pages that require a
 * complex stateful UI — the detail view, upload wizard, and validation view.
 * The org memberships list (AORM-3) and global logs (AORM-10) are rendered
 * server-side via WP_List_Table and must NOT receive the React bundle.
 */
class Assets
{
    /** Script/style handle prefix. */
    private const HANDLE = 'wicket-aorm';

    /**
     * Page slugs that receive the React bundle.
     *
     * Classic PHP pages (list view, configurations, settings) are intentionally
     * absent — they must never receive the React bundle.
     *
     * @var string[]
     */
    private const REACT_SLUGS = [
        MenuPage::DETAIL_SLUG,       // wicket-aorm-roster-detail
        'wicket-aorm-group-rosters',
    ];

    /**
     * Enqueue plugin scripts and styles on relevant admin pages.
     *
     * @param string $hookSuffix The current admin page hook suffix.
     */
    public function enqueue(string $hookSuffix): void
    {
        if (! $this->isReactPage($hookSuffix)) {
            return;
        }

        $assetFile = WICKET_AORM_PATH . 'build/index.asset.php';

        if (! is_file($assetFile)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
                trigger_error(
                    'Wicket Admin Org Roster: build/index.asset.php not found. Run `npm run build`.',
                    E_USER_WARNING
                );
            }
            return;
        }

        $asset = require $assetFile;

        wp_enqueue_script(
            self::HANDLE . '-index',
            WICKET_AORM_URL . 'build/index.js',
            $asset['dependencies'],
            $asset['version'],
            true // Load in footer.
        );

        // Pass REST URL, nonce, and page-specific context to the React app via window.aormContext.
        wp_localize_script(
            self::HANDLE . '-index',
            'aormContext',
            $this->buildLocalizationData($hookSuffix)
        );

        // Enqueue the companion stylesheet if the build produced one.
        $cssFile = WICKET_AORM_PATH . 'build/index.css';
        if (is_file($cssFile)) {
            wp_enqueue_style(
                self::HANDLE . '-index',
                WICKET_AORM_URL . 'build/index.css',
                ['wp-components'],
                $asset['version']
            );
        }
    }

    /**
     * Build the aormContext object passed to window via wp_localize_script.
     *
     * All pages receive restUrl and nonce. The roster-detail page additionally
     * receives orgUuid, membershipUuid, and activeSession — read from the
     * request URL params and the database here in PHP so React never needs to
     * parse window.location.search itself or make an extra API call on mount.
     *
     * activeSession shape (null when no session is in progress):
     *   { sessionId: string, step: string }
     *
     * React reads this to set the wizard's initial step:
     *   null                        → 'landing'
     *   step='matching-progress'    → background matching still running
     *   step='validation-review'    → matching done, no sync dispatched yet
     *   step='sync-progress'        → sync dispatched or running
     *
     * Also includes phoneMatchType/phoneMatchTypeLabel — the configured
     * wicket_aorm_settings[phone_match_type] value (raw slug + human label,
     * both '' for "Any type") — used by MemberTable.js and MatchesTable.js to
     * label and populate the "Phone" column consistently with MdpClient.
     *
     * @param string $hookSuffix
     * @return array<string, mixed>
     */
    private function buildLocalizationData(string $hookSuffix): array
    {
        $data = [
            'restUrl' => esc_url_raw(rest_url()),
            'nonce'   => wp_create_nonce('wp_rest'),
        ];

        if (str_contains($hookSuffix, MenuPage::DETAIL_SLUG)) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $orgUuid = sanitize_text_field(wp_unslash((string) ($_GET['org_uuid'] ?? '')));
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $membershipUuid = sanitize_text_field(wp_unslash((string) ($_GET['membership_uuid'] ?? '')));

            $data['orgUuid']        = $orgUuid;
            $data['membershipUuid'] = $membershipUuid;

            // MDP admin base URL used by RosterHeading to build the external link.
            // get_wicket_settings() is provided by wicket-wp-base-plugin; guard so
            // the page still loads when the base plugin is inactive.
            $wicketSettings      = function_exists('get_wicket_settings') ? (array) get_wicket_settings() : [];
            $data['appEndpoint'] = esc_url_raw(rtrim((string) ($wicketSettings['wicket_admin'] ?? ''), '/'));

            // List page URL used by RosterBreadcrumb to render the back-link (AORM-4.4).
            $data['rosterListUrl'] = esc_url(admin_url('admin.php?page=' . MenuPage::MENU_SLUG));

            // CSV template download URL used by UploadFileStep (AORM-6.3).
            // Served as a static plugin asset — no REST endpoint needed.
            $data['templateDownloadUrl'] = esc_url(WICKET_AORM_URL . 'assets/roster-template.csv');

            // Active session context — lets React set the correct initial wizard.
            $data['activeSession'] = $this->resolveActiveSession($orgUuid, $membershipUuid);

            // Configured phone match type — lets the Roster Assignment tab
            // (MemberTable.js) and Review Match modal (MatchesTable.js) label
            // and populate the "Phone" column consistently with what
            // MdpClient actually matched/fetched. Empty string ("Any type")
            // yields an empty label so those components fall back to the
            // plain "Phone" header + today's primary-phone display.
            $settings       = (array) get_option(SyncService::SETTINGS_OPTION, []);
            $phoneMatchType = (string) (
                $settings[MatchingService::SETTINGS_KEY_PHONE_MATCH_TYPE] ?? MatchingService::DEFAULT_PHONE_MATCH_TYPE
            );

            $data['phoneMatchType']      = $phoneMatchType;
            $data['phoneMatchTypeLabel'] = $phoneMatchType !== ''
                ? ((new MdpClient())->getPhoneTypes()[$phoneMatchType] ?? ucfirst($phoneMatchType))
                : '';
        }

        return $data;
    }

    /**
     * Look up any in-progress upload session for the given roster and return
     * the wizard step to resume at, or null when no session is active.
     *
     * Returned step values:
     *   'matching-progress' — background matching job is still running
     *   'validation-review' — matching done; admin hasn't committed a sync yet
     *   'sync-progress'     — sync has been dispatched or is actively running
     *
     * Detection order for sync-progress:
     *   1. Any staged record already has sync_status = 'synced' or 'failed'
     *      (background job has processed at least one row).
     *   2. roster_meta.roster_status = 'syncing' (CommitController writes this
     *      immediately on dispatch to close the race between job dispatch and
     *      job start; covers the window where the admin navigates away right
     *      after clicking "Sync").
     *
     * @param string $orgUuid
     * @param string $membershipUuid
     * @return array{sessionId: string, step: string}|null
     */
    private function resolveActiveSession(string $orgUuid, string $membershipUuid): ?array
    {
        if ($orgUuid === '' || $membershipUuid === '') {
            return null;
        }

        $table     = new StagedRecordsTable();
        $sessionId = $table->getActiveSessionId($orgUuid, $membershipUuid);

        if ($sessionId === null) {
            return null;
        }

        // Step 1 — is matching still running?
        $matchingProgress = $table->getMatchingProgress($sessionId);
        $matchingComplete = $matchingProgress['total'] === 0
            || $matchingProgress['processed'] === $matchingProgress['total'];

        if (! $matchingComplete) {
            return [
                'sessionId' => $sessionId,
                'step'      => 'matching-progress',
            ];
        }

        // Step 2 — has a sync been dispatched or started?
        $syncProgress = $table->getSyncProgress($sessionId);

        if ($syncProgress['synced'] + $syncProgress['failed'] > 0) {
            // At least one record was processed by the sync job.
            return [
                'sessionId' => $sessionId,
                'step'      => 'sync-progress',
            ];
        }

        $rosterStatus = $table->getRosterSyncStatus($orgUuid, $membershipUuid);

        if ($rosterStatus === 'syncing') {
            // Job was dispatched but hasn't processed a record yet.
            return [
                'sessionId' => $sessionId,
                'step'      => 'sync-progress',
            ];
        }

        // Default — matching is done, no sync in flight.
        return [
            'sessionId' => $sessionId,
            'step'      => 'validation-review',
        ];
    }

    /**
     * Determine whether the current admin page should receive the React bundle.
     *
     * WP generates hook suffixes in the form:
     *   toplevel_page_{slug}        — top-level menu page
     *   {parent-title}_page_{slug}  — subpage
     *
     * Checking for the specific REACT_SLUGS (and explicitly NOT the list-view
     * slug) ensures we never bundle React onto the WP_List_Table pages.
     *
     * @param string $hookSuffix
     * @return bool
     */
    private function isReactPage(string $hookSuffix): bool
    {
        foreach (self::REACT_SLUGS as $slug) {
            if (str_contains($hookSuffix, $slug)) {
                return true;
            }
        }

        return false;
    }
}
