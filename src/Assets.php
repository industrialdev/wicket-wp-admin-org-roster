<?php

declare(strict_types=1);

namespace WicketAORM;

use WicketAORM\Admin\MenuPage;

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

        // Pass REST URL and nonce to the React app via window.aormSettings.
        wp_localize_script(
            self::HANDLE . '-index',
            'aormSettings',
            [
                'restUrl' => esc_url_raw(rest_url()),
                'nonce'   => wp_create_nonce('wp_rest'),
            ]
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
