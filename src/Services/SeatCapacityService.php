<?php

declare(strict_types=1);

namespace WicketAORM\Services;

use WicketAORM\Database\StagedRecordsTable;

/**
 * Seat capacity planning for roster uploads.
 *
 * Bugfix (over-capacity uploads): an upload with more people than the
 * roster has seats used to sync without any warning, and the MDP then
 * decided who actually got a seat. This service works out — before sync —
 * how many seats are free, which outstanding Ready to Sync rows need a new
 * seat, and which of them fit, in the exact order SyncJobRunner will process
 * them (StagedRecordsTable::SYNC_ORDER_BY: removals first, then file order).
 *
 * Seat limit — same rule as wicket-wp-account-centre (CascadeStrategy's
 * ensureSeatAvailability()): MembershipService::getEffectiveMaxAssignments(),
 * i.e. a per-tier override from OrgManConfig `membership.seat_limits` when
 * one is configured, else the MDP's `max_assignments`. When no override
 * applies and the MDP flags `unlimited_assignments` (or has no max), the
 * roster is treated as unlimited and nothing is planned or enforced.
 *
 * Which rows consume a seat:
 *   - new_record                          → always needs a new seat.
 *   - exact_match / already_on_roster     → needs one unless matched_persons[0]
 *                                           already holds an active seat.
 *   - merging_to_record                   → needs one unless merge_target_uuid
 *                                           already holds an active seat.
 *   - remove_existing                     → frees a seat when the person holds
 *                                           one (they normally do).
 *   - any other / unknown status          → treated as needing a seat.
 * Two rows resolving to the same person only ever need one seat.
 *
 * Fail-open: when the MDP can't be reached (payload or seat lookup fails)
 * the methods return null — callers show no capacity info and don't block,
 * rather than guessing.
 */
class SeatCapacityService
{
    /** Row will be given one of the free seats. */
    public const SEAT_ASSIGNED = 'assigned';

    /** Row needs a seat but none is left for it. */
    public const SEAT_OVER_CAPACITY = 'over_capacity';

    /** Person already holds an active seat — syncing costs nothing. */
    public const SEAT_ALREADY_HELD = 'already_held';

    /** remove_existing row — its seat is released before adds are synced. */
    public const SEAT_FREES = 'frees_seat';

    /** @var (\Closure(array<string, mixed>): ?int)|null */
    private readonly ?\Closure $maxAssignmentsResolver;

    /**
     * @param MdpClient|null          $mdpClient              DI for tests.
     * @param StagedRecordsTable|null $stagedRecordsTable     DI for tests.
     * @param \Closure|null           $maxAssignmentsResolver Receives the raw
     *   organization_membership payload, returns the effective seat limit or
     *   null. Defaults to ACC's MembershipService::getEffectiveMaxAssignments()
     *   when available, otherwise the payload's `max_assignments`.
     */
    public function __construct(
        private readonly ?MdpClient $mdpClient = null,
        private readonly ?StagedRecordsTable $stagedRecordsTable = null,
        ?\Closure $maxAssignmentsResolver = null,
    ) {
        $this->maxAssignmentsResolver = $maxAssignmentsResolver;
    }

    /**
     * Seat plan for a staged session's outstanding Ready to Sync rows.
     *
     * @param string       $sessionId      Upload session UUID.
     * @param string       $membershipUuid Org-membership (roster) UUID.
     * @param string|int[] $ids            'all' (everything a "Sync All" would
     *                                     push) or the IDs of a partial batch.
     *
     * @return array{
     *   unlimited: bool,
     *   max_seats: int|null,
     *   active_seats: int,
     *   available_seats: int|null,
     *   seats_freed: int,
     *   seats_needed: int,
     *   over_by: int,
     *   is_over_capacity: bool,
     *   records: array<int, string>,
     * }|null  `records` maps staged record id → SEAT_* constant. Null when the
     *         MDP can't be reached.
     */
    public function getSessionCapacity(string $sessionId, string $membershipUuid, string|array $ids = 'all'): ?array
    {
        $limits = $this->resolveSeatLimits($membershipUuid);

        if ($limits === null) {
            return null;
        }

        if ($limits['unlimited']) {
            return $this->buildSummary($limits, 0, 0, []);
        }

        $table = $this->stagedRecordsTable ?? new StagedRecordsTable();
        $rows  = $table->getOutstandingSyncRecords($sessionId, $ids);

        return $this->planRows($membershipUuid, $limits, $rows);
    }

    /**
     * Seat summary for the roster itself, without any staged rows.
     *
     * Used by the Add Individual form to warn when the roster is already full.
     *
     * @return array{unlimited: bool, max_seats: int|null, active_seats: int, available_seats: int|null}|null
     */
    public function getRosterSeats(string $membershipUuid): ?array
    {
        $limits = $this->resolveSeatLimits($membershipUuid);

        if ($limits === null) {
            return null;
        }

        return [
            'unlimited'       => $limits['unlimited'],
            'max_seats'       => $limits['max_seats'],
            'active_seats'    => $limits['active_seats'],
            'available_seats' => $limits['available_seats'],
        ];
    }

    /**
     * Assign seats to rows in the given (sync) order.
     *
     * @param array{unlimited: bool, max_seats: int|null, active_seats: int, available_seats: int|null} $limits
     * @param list<array<string, mixed>> $rows Staged rows in SYNC_ORDER_BY order.
     *
     * @return array<string, mixed>|null
     */
    private function planRows(string $membershipUuid, array $limits, array $rows): ?array
    {
        // Resolve the person each row points at (null = brand-new person).
        $rowPeople = [];

        foreach ($rows as $row) {
            $rowPeople[(int) ($row['id'] ?? 0)] = $this->resolveRowPersonUuid($row);
        }

        $client      = $this->mdpClient ?? new MdpClient();
        $lookupUuids = array_values(array_filter($rowPeople, static fn ($uuid): bool => is_string($uuid) && $uuid !== ''));
        $holders     = $client->getActiveSeatHolderUuids($membershipUuid, $lookupUuids);

        if ($holders === null) {
            return null;
        }

        $holders = array_fill_keys($holders, true);

        // Pass 1 — removals release their seats before any add is synced.
        $plan       = [];
        $seatsFreed = 0;

        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);

            if ((string) ($row['record_status'] ?? '') !== 'remove_existing') {
                continue;
            }

            $person = $rowPeople[$id];
            $plan[$id] = self::SEAT_FREES;

            if ($person !== null && isset($holders[$person])) {
                $seatsFreed++;
                // A removed person no longer holds a seat for later rows.
                unset($holders[$person]);
            }
        }

        // Pass 2 — adds in file order take whatever is left.
        $remaining   = max(0, (int) $limits['available_seats']) + $seatsFreed;
        $seatsNeeded = 0;
        $seen        = [];

        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);

            if (isset($plan[$id])) {
                continue;
            }

            $person = $rowPeople[$id];

            if ($person !== null && (isset($holders[$person]) || isset($seen[$person]))) {
                $plan[$id] = self::SEAT_ALREADY_HELD;

                continue;
            }

            if ($person !== null) {
                $seen[$person] = true;
            }

            $seatsNeeded++;

            if ($remaining > 0) {
                $plan[$id] = self::SEAT_ASSIGNED;
                $remaining--;
            } else {
                $plan[$id] = self::SEAT_OVER_CAPACITY;
            }
        }

        return $this->buildSummary($limits, $seatsFreed, $seatsNeeded, $plan);
    }

    /**
     * @param array{unlimited: bool, max_seats: int|null, active_seats: int, available_seats: int|null} $limits
     * @param array<int, string> $plan
     *
     * @return array<string, mixed>
     */
    private function buildSummary(array $limits, int $seatsFreed, int $seatsNeeded, array $plan): array
    {
        $overBy = 0;

        if (! $limits['unlimited']) {
            $overBy = max(0, $seatsNeeded - (max(0, (int) $limits['available_seats']) + $seatsFreed));
        }

        return [
            'unlimited'        => $limits['unlimited'],
            'max_seats'        => $limits['max_seats'],
            'active_seats'     => $limits['active_seats'],
            'available_seats'  => $limits['available_seats'],
            'seats_freed'      => $seatsFreed,
            'seats_needed'     => $seatsNeeded,
            'over_by'          => $overBy,
            'is_over_capacity' => $overBy > 0,
            'records'          => $plan,
        ];
    }

    /**
     * Read the roster's seat limit and current usage from the MDP.
     *
     * @return array{unlimited: bool, max_seats: int|null, active_seats: int, available_seats: int|null}|null
     */
    private function resolveSeatLimits(string $membershipUuid): ?array
    {
        $client  = $this->mdpClient ?? new MdpClient();
        $payload = $client->getOrgMembershipSeatPayload($membershipUuid);

        if ($payload === null) {
            return null;
        }

        $attrs     = (array) ($payload['data']['attributes'] ?? []);
        $active    = max(0, (int) ($attrs['active_assignments_count'] ?? 0));
        $apiMax    = isset($attrs['max_assignments']) && is_numeric($attrs['max_assignments'])
            ? (int) $attrs['max_assignments']
            : null;
        $unlimited = (bool) ($attrs['unlimited_assignments'] ?? false);
        $effective = $this->resolveEffectiveMax($payload, $apiMax);

        // A configured per-tier override always wins (that's what ACC enforces).
        // Otherwise honour the MDP's unlimited flag / missing max.
        $isOverride = $effective !== null && $effective !== $apiMax;

        if (! $isOverride && ($unlimited || $effective === null)) {
            return [
                'unlimited'       => true,
                'max_seats'       => null,
                'active_seats'    => $active,
                'available_seats' => null,
            ];
        }

        $max = max(0, (int) $effective);

        return [
            'unlimited'       => false,
            'max_seats'       => $max,
            'active_seats'    => $active,
            'available_seats' => max(0, $max - $active),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveEffectiveMax(array $payload, ?int $apiMax): ?int
    {
        if ($this->maxAssignmentsResolver !== null) {
            return ($this->maxAssignmentsResolver)($payload);
        }

        if (class_exists(\WicketORM\Services\MembershipService::class)
            && method_exists(\WicketORM\Services\MembershipService::class, 'getEffectiveMaxAssignments')
        ) {
            try {
                return (new \WicketORM\Services\MembershipService())->getEffectiveMaxAssignments($payload);
            } catch (\Throwable $e) {
                return $apiMax;
            }
        }

        return $apiMax;
    }

    /**
     * Person UUID a staged row will sync to, or null for a brand-new person.
     *
     * @param array<string, mixed> $row
     */
    private function resolveRowPersonUuid(array $row): ?string
    {
        $status = (string) ($row['record_status'] ?? '');

        if ($status === 'remove_existing') {
            $raw = is_array($row['raw_data'] ?? null)
                ? $row['raw_data']
                : json_decode((string) ($row['raw_data'] ?? ''), true);

            $uuid = is_array($raw) ? (string) ($raw['person_uuid'] ?? '') : '';

            return $uuid !== '' ? $uuid : null;
        }

        if ($status === 'merging_to_record') {
            $uuid = (string) ($row['merge_target_uuid'] ?? '');

            return $uuid !== '' ? $uuid : null;
        }

        if ($status === 'exact_match' || $status === 'already_on_roster') {
            $matched = is_array($row['matched_persons'] ?? null)
                ? $row['matched_persons']
                : json_decode((string) ($row['matched_persons'] ?? ''), true);

            $uuid = is_array($matched) ? (string) ($matched[0]['uuid'] ?? '') : '';

            return $uuid !== '' ? $uuid : null;
        }

        return null;
    }
}
