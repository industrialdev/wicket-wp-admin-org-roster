<?php

declare(strict_types=1);

namespace WicketAORM\Admin;

use WicketAORM\Services\MdpClient;
use WicketAORM\Services\StuckSessionService;

/**
 * "Stuck upload sessions" maintenance section on the Settings page.
 *
 * Lists sessions frozen on "Comparing records against MDP…" (see
 * StuckSessionService) and offers one "Fix stuck sessions" button: clean
 * sessions get their matching job queued again, sessions whose file had
 * invalid/duplicate rows are abandoned.
 *
 * Rendered by MenuPage::renderSettingsPage() BELOW the Settings API form —
 * HTML forms can't nest, so this has its own form posting to admin-post.php
 * (action ACTION, handled by handle(), registered in Main).
 */
class StuckSessionsSection
{
    /** admin-post.php action name and nonce action. */
    public const ACTION = 'wicket_aorm_fix_stuck_sessions';

    /** Query arg set on the redirect back to Settings after a fix. */
    public const RESULT_QUERY_ARG = 'aorm_stuck_fixed';

    private const CAPABILITY    = 'manage_options';
    private const SETTINGS_SLUG = 'wicket-aorm-settings';

    /** @var array<string, array<string, mixed>> getOrgMembershipDetail() memo, keyed by membership UUID. */
    private array $detailCache = [];

    public function __construct(
        private readonly ?StuckSessionService $stuckSessionService = null,
        private readonly ?MdpClient $mdpClient = null,
    ) {
    }

    /**
     * Render the section (heading, result notice, table, button).
     */
    public function render(): void
    {
        $sessions = $this->service()->findStuckSessions();

        echo '<hr />';
        echo '<h2 id="aorm-stuck-sessions">' . esc_html__('Stuck upload sessions', 'wicket-aorm') . '</h2>';
        echo '<p class="description">' . esc_html(sprintf(
            /* translators: %d: minutes */
            __('Uploads still waiting for MDP matching with no activity for %d minutes and no matching job queued. Clean files get their matching job queued again; files with invalid or duplicate rows are abandoned so the file can be fixed and re-uploaded.', 'wicket-aorm'),
            StuckSessionService::STUCK_AFTER_MINUTES,
        )) . '</p>';

        $this->renderResultNotice();

        if ($sessions === []) {
            echo '<p class="aorm-stuck-sessions__empty">' . esc_html__('No stuck upload sessions.', 'wicket-aorm') . '</p>';

            return;
        }

        echo '<table class="widefat striped aorm-stuck-sessions__table">';
        echo '<thead><tr>';

        foreach ([
            __('Organization', 'wicket-aorm'),
            __('Membership Tier', 'wicket-aorm'),
            __('Started', 'wicket-aorm'),
            __('Last Activity', 'wicket-aorm'),
            __('Rows Waiting', 'wicket-aorm'),
            __('Invalid Rows', 'wicket-aorm'),
            __('Fix', 'wicket-aorm'),
        ] as $label) {
            echo '<th scope="col">' . esc_html($label) . '</th>';
        }

        echo '</tr></thead><tbody>';

        foreach ($sessions as $session) {
            $detail = $this->resolveDetail($session['membership_uuid']);
            $org    = (string) ($detail['org_name'] ?? '') ?: $session['org_uuid'];
            $tier   = (string) ($detail['membership_tier'] ?? '') ?: $session['membership_uuid'];
            $url    = add_query_arg(
                [
                    'page'            => MenuPage::DETAIL_SLUG,
                    'org_uuid'        => $session['org_uuid'],
                    'membership_uuid' => $session['membership_uuid'],
                ],
                admin_url('admin.php'),
            );
            $fix = $session['action'] === StuckSessionService::ACTION_ABANDON
                ? __('Abandon session', 'wicket-aorm')
                : __('Restart matching', 'wicket-aorm');

            echo '<tr data-session-id="' . esc_attr($session['upload_session_id']) . '">';
            echo '<td><a href="' . esc_url($url) . '">' . esc_html($org) . '</a></td>';
            echo '<td>' . esc_html($tier) . '</td>';
            echo '<td>' . esc_html($session['started_at']) . '</td>';
            echo '<td>' . esc_html($session['last_activity_at']) . '</td>';
            echo '<td>' . esc_html((string) $session['pending_rows']) . '</td>';
            echo '<td>' . esc_html((string) $session['invalid_rows']) . '</td>';
            echo '<td>' . esc_html($fix) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '" />';
        wp_nonce_field(self::ACTION);
        echo '<p><button type="submit" class="button button-secondary aorm-stuck-sessions__fix-btn">'
            . esc_html__('Fix stuck sessions', 'wicket-aorm') . '</button></p>';
        echo '</form>';
    }

    /**
     * admin-post.php handler: verify, fix, redirect back to Settings.
     */
    public function handle(): void
    {
        wp_safe_redirect($this->processRequest());
        exit;
    }

    /**
     * Capability + nonce check, run the fix, and return the redirect URL.
     * Split from handle() so it can be unit-tested without exit.
     */
    public function processRequest(): string
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to do this.', 'wicket-aorm'));
        }

        check_admin_referer(self::ACTION);

        $result = $this->service()->fixAll();

        return add_query_arg(
            [
                'page'                 => self::SETTINGS_SLUG,
                self::RESULT_QUERY_ARG => 1,
                'requeued'             => $result['requeued'],
                'abandoned'            => $result['abandoned'],
                'failed'               => $result['failed'],
            ],
            admin_url('admin.php'),
        ) . '#aorm-stuck-sessions';
    }

    /**
     * Success / warning notice after a redirect from processRequest().
     */
    private function renderResultNotice(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only display of counts.
        if (! isset($_GET[self::RESULT_QUERY_ARG])) {
            return;
        }

        $requeued  = absint($_GET['requeued'] ?? 0);
        $abandoned = absint($_GET['abandoned'] ?? 0);
        $failed    = absint($_GET['failed'] ?? 0);
        // phpcs:enable

        $message = sprintf(
            /* translators: 1: sessions requeued, 2: sessions abandoned */
            __('Matching restarted for %1$d session(s); %2$d session(s) abandoned.', 'wicket-aorm'),
            $requeued,
            $abandoned,
        );

        if ($failed > 0) {
            $message .= ' ' . sprintf(
                /* translators: %d: sessions that could not be fixed */
                __('%d session(s) could not be fixed.', 'wicket-aorm'),
                $failed,
            );
        }

        $class = $failed > 0 ? 'notice-warning' : 'notice-success';

        echo '<div class="notice ' . esc_attr($class) . ' inline"><p>' . esc_html($message) . '</p></div>';
    }

    /**
     * Org/tier names for a membership, memoized. [] when unresolvable.
     *
     * @return array<string, mixed>
     */
    private function resolveDetail(string $membershipUuid): array
    {
        if ($membershipUuid === '') {
            return [];
        }

        if (! array_key_exists($membershipUuid, $this->detailCache)) {
            try {
                $client                             = $this->mdpClient ?? new MdpClient();
                $this->detailCache[$membershipUuid] = $client->getOrgMembershipDetail($membershipUuid);
            } catch (\Throwable $e) {
                $this->detailCache[$membershipUuid] = [];
            }
        }

        return $this->detailCache[$membershipUuid];
    }

    private function service(): StuckSessionService
    {
        return $this->stuckSessionService ?? new StuckSessionService();
    }
}
