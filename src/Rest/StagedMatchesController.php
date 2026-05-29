<?php

declare(strict_types=1);

namespace WicketAORM\Rest;

use WicketAORM\Database\StagedRecordsTable;
use WicketAORM\Services\MdpClient;

/**
 * Staged record match-detail endpoint — AORM-8B.12.
 *
 * Routes:
 *   GET /wicket-aorm/v1/staged/{id}/matches
 *       Returns full MDP person details for every candidate stored in the
 *       given staged record's `matched_persons` field.
 *
 *       Used by the ReviewMatchModal (AORM-8B.10) to populate the matches
 *       table (AORM-8B.13) with richer fields than the slim summary already
 *       stored in the DB (uuid, name, email, given_name, family_name).
 *
 *       For each matched-person UUID a live MDP fetch is performed:
 *         - Primary call: GET people/{uuid}?include=phones,emails,addresses
 *         - Employer: GET people/{uuid}/organizations (lazy secondary call)
 *
 * Response shape (200):
 *   {
 *     "staged_record_id": int,
 *     "matches": [
 *       {
 *         "uuid":          string,
 *         "given_name":    string,
 *         "family_name":   string,
 *         "full_name":     string,
 *         "primary_email": string,
 *         "emails":        [{"address": string, "type": string, "primary": bool}],
 *         "primary_phone": string,
 *         "phones":        [{"number": string, "type": string, "primary": bool}],
 *         "location":      {"city": string, "country": string},
 *         "title":         string,
 *         "employer":      string,
 *         "mdp_url":       string
 *       },
 *       …
 *     ]
 *   }
 *
 * Returns 404 when no staged record exists for the given ID.
 * Returns an empty `matches` array when the record has no stored candidates
 * (i.e. `matched_persons` is null or an empty JSON array).
 */
class StagedMatchesController extends RestController
{
    public function __construct(
        private readonly ?StagedRecordsTable $stagedRecordsTable = null,
        private readonly ?MdpClient $mdpClient = null,
    ) {
    }

    /**
     * Register routes for the staged matches endpoint.
     */
    public function register_routes(): void
    {
        register_rest_route(
            $this->namespace,
            '/staged/(?P<id>\d+)/matches',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [$this, 'get_matches'],
                    'permission_callback' => [$this, 'get_matches_permissions_check'],
                    'args'                => [
                        'id' => [
                            'required'          => true,
                            'sanitize_callback' => 'absint',
                        ],
                    ],
                ],
            ],
        );
    }

    /**
     * Permission check for GET /staged/{id}/matches.
     *
     * @param \WP_REST_Request $request
     */
    public function get_matches_permissions_check($request): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * Handle GET /staged/{id}/matches.
     *
     * Resolves each UUID in the staged record's `matched_persons` field to a
     * full MDP person detail object. The MDP calls are performed serially to
     * avoid overwhelming the API; typical match sets are small (1–5 candidates).
     *
     * @param \WP_REST_Request $request
     */
    public function get_matches($request): \WP_REST_Response
    {
        $id    = (int) $request->get_param('id');
        $table = $this->stagedRecordsTable ?? new StagedRecordsTable();

        $record = $table->getRecordById($id);

        if ($record === null) {
            return new \WP_REST_Response(
                ['message' => 'Staged record not found.'],
                404,
            );
        }

        // Decode the stored match candidates — null or empty means no candidates.
        $matchedPersonsRaw = isset($record['matched_persons']) && $record['matched_persons'] !== ''
            ? $record['matched_persons']
            : null;

        $candidates = is_string($matchedPersonsRaw)
            ? (json_decode($matchedPersonsRaw, true) ?? [])
            : [];

        if (empty($candidates)) {
            return new \WP_REST_Response(
                [
                    'staged_record_id' => $id,
                    'matches'          => [],
                ],
                200,
            );
        }

        $mdp     = $this->mdpClient ?? new MdpClient();
        $appBase = $this->resolveAppEndpoint();
        $matches = [];

        foreach ($candidates as $candidate) {
            $uuid = (string) ($candidate['uuid'] ?? '');

            if ($uuid === '') {
                continue;
            }

            $details = $mdp->getPersonDetails($uuid);

            if (empty($details)) {
                // Candidate not resolvable — fall back to the slim stored values.
                $details = [
                    'uuid'          => $uuid,
                    'given_name'    => (string) ($candidate['given_name'] ?? ''),
                    'family_name'   => (string) ($candidate['family_name'] ?? ''),
                    'full_name'     => (string) ($candidate['name'] ?? ''),
                    'primary_email' => (string) ($candidate['email'] ?? ''),
                    'emails'        => [],
                    'primary_phone' => '',
                    'phones'        => [],
                    'location'      => ['city' => '', 'country' => ''],
                    'title'         => '',
                    'employer'      => '',
                ];
            }

            $details['mdp_url'] = $appBase !== '' ? $appBase . '/people/' . $uuid : '';

            $matches[] = $details;
        }

        return new \WP_REST_Response(
            [
                'staged_record_id' => $id,
                'matches'          => $matches,
            ],
            200,
        );
    }

    /**
     * Resolve the MDP admin base URL from Wicket base-plugin settings.
     *
     * Returns an empty string when `get_wicket_settings()` is unavailable or
     * the `wicket_admin` key is not configured.
     */
    private function resolveAppEndpoint(): string
    {
        if (! function_exists('get_wicket_settings')) {
            return '';
        }

        $settings = (array) get_wicket_settings();

        return esc_url_raw(rtrim((string) ($settings['wicket_admin'] ?? ''), '/'));
    }
}
