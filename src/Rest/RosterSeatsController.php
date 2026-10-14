<?php

declare(strict_types=1);

namespace WicketAORM\Rest;

use WicketAORM\Services\SeatCapacityService;

/**
 * Roster seat availability endpoint.
 *
 * Routes:
 *   GET /wicket-aorm/v1/rosters/{org_uuid}/{membership_uuid}/seats
 *
 * Response (200):
 *   {
 *     "unlimited":       bool,
 *     "max_seats":       int|null,  // effective limit (ACC tier override or MDP max)
 *     "active_seats":    int,
 *     "available_seats": int|null,  // null when unlimited
 *     "is_full":         bool       // limited and no seat left
 *   }
 *   or { "unavailable": true } with 200 when the MDP can't be reached, so
 *   the client simply shows no warning.
 *
 * Seat capacity (bugfix, over-capacity uploads): the Add Individual form
 * fetches this on mount to warn that a person added to a full roster won't
 * receive an active membership until a seat is freed. Live, uncached MDP
 * numbers — same seat-limit rule as SeatCapacityService.
 */
class RosterSeatsController extends RestController
{
    private const UUID_PATTERN = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    public function __construct(
        private readonly ?SeatCapacityService $seatCapacityService = null,
    ) {
    }

    /**
     * Register routes.
     */
    public function register_routes(): void
    {
        register_rest_route(
            $this->namespace,
            '/rosters/(?P<org_uuid>' . self::UUID_PATTERN . ')/(?P<membership_uuid>' . self::UUID_PATTERN . ')/seats',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [$this, 'get_seats'],
                    'permission_callback' => [$this, 'get_seats_permissions_check'],
                    'args'                => [
                        'org_uuid'        => [
                            'required'          => true,
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'membership_uuid' => [
                            'required'          => true,
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                    ],
                ],
            ],
        );
    }

    /**
     * Permission check for GET /rosters/{org_uuid}/{membership_uuid}/seats.
     *
     * @param \WP_REST_Request $request
     */
    public function get_seats_permissions_check($request): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * Handle GET /rosters/{org_uuid}/{membership_uuid}/seats.
     *
     * @param \WP_REST_Request $request
     */
    public function get_seats($request): \WP_REST_Response
    {
        $membershipUuid = (string) $request->get_param('membership_uuid');
        $service        = $this->seatCapacityService ?? new SeatCapacityService();
        $seats          = $service->getRosterSeats($membershipUuid);

        if ($seats === null) {
            return new \WP_REST_Response(['unavailable' => true], 200);
        }

        $seats['is_full'] = ! $seats['unlimited'] && (int) $seats['available_seats'] <= 0;

        return new \WP_REST_Response($seats, 200);
    }
}
