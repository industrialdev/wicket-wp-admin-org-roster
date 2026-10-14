/**
 * Seat capacity helpers — bugfix, over-capacity uploads.
 *
 * The staged endpoint (GET /uploads/{id}/staged) returns a `capacity` block
 * computed server-side by SeatCapacityService:
 *
 *   {
 *     unlimited, max_seats, active_seats, available_seats,
 *     seats_freed, seats_needed, over_by, is_over_capacity,
 *     records: { [id]: 'assigned' | 'over_capacity' | 'already_held' | 'frees_seat' }
 *   }
 *
 * `capacity` is null when the MDP couldn't be reached — every helper here
 * treats null (and unlimited rosters) as "no limit to show or enforce".
 * The commit endpoint re-checks on the server, so these helpers only drive
 * what the admin sees before clicking Sync.
 */

import { __, sprintf } from '@wordpress/i18n';

export const SEAT_ASSIGNED      = 'assigned';
export const SEAT_OVER_CAPACITY = 'over_capacity';
export const SEAT_ALREADY_HELD  = 'already_held';
export const SEAT_FREES         = 'frees_seat';

/** Error code CommitController returns with a 409 when a batch doesn't fit. */
export const SEAT_LIMIT_ERROR_CODE = 'seat_limit_exceeded';

/**
 * Labels for the "Seat" column on the Ready to Sync table.
 *
 * @type {Record<string, string>}
 */
export const SEAT_STATUS_LABELS = {
	[ SEAT_ASSIGNED ]:      __( 'Will get a seat', 'wicket-aorm' ),
	[ SEAT_OVER_CAPACITY ]: __( 'Over capacity',   'wicket-aorm' ),
	[ SEAT_ALREADY_HELD ]:  __( 'Already has a seat', 'wicket-aorm' ),
	[ SEAT_FREES ]:         __( 'Frees a seat',    'wicket-aorm' ),
};

/**
 * True when the roster has a seat limit worth showing.
 *
 * @param {Object|null|undefined} capacity
 * @return {boolean} True for a limited roster with a known capacity.
 */
export function isSeatLimited( capacity ) {
	return !! capacity && ! capacity.unlimited;
}

/**
 * Seat status for one staged record, or null when there's nothing to show.
 *
 * @param {Object|null|undefined} capacity
 * @param {number}                recordId
 * @return {string|null} One of the SEAT_* constants, or null.
 */
export function getRecordSeatStatus( capacity, recordId ) {
	if ( ! isSeatLimited( capacity ) ) {
		return null;
	}

	return capacity.records?.[ recordId ] ?? capacity.records?.[ String( recordId ) ] ?? null;
}

/**
 * Seats needed vs. available for a sync request.
 *
 * `ids === 'all'` uses the server's totals as-is. For a selection, rows
 * planned as 'assigned' or 'over_capacity' need a seat, and selected
 * removals ('frees_seat') release one — the same rule the server applies
 * when it re-plans just that batch. Selections are planned against the
 * whole free pool, not the server's per-row order, so any selection that
 * fits will be accepted.
 *
 * @param {Object|null|undefined} capacity
 * @param {'all'|number[]}        ids
 * @return {{ limited: boolean, seatsNeeded: number, seatsAvailable: number, overBy: number, isOver: boolean }} Seat evaluation.
 */
export function evaluateSeatsForSync( capacity, ids ) {
	if ( ! isSeatLimited( capacity ) ) {
		return { limited: false, seatsNeeded: 0, seatsAvailable: 0, overBy: 0, isOver: false };
	}

	const baseAvailable = Math.max( 0, Number( capacity.available_seats ) || 0 );

	if ( ids === 'all' ) {
		const seatsNeeded    = Number( capacity.seats_needed ) || 0;
		const seatsAvailable = baseAvailable + ( Number( capacity.seats_freed ) || 0 );
		const overBy         = Math.max( 0, seatsNeeded - seatsAvailable );

		return { limited: true, seatsNeeded, seatsAvailable, overBy, isOver: overBy > 0 };
	}

	let seatsNeeded = 0;
	let freed       = 0;

	( ids ?? [] ).forEach( ( id ) => {
		const status = getRecordSeatStatus( capacity, id );

		if ( status === SEAT_ASSIGNED || status === SEAT_OVER_CAPACITY ) {
			seatsNeeded++;
		} else if ( status === SEAT_FREES ) {
			freed++;
		}
	} );

	const seatsAvailable = baseAvailable + freed;
	const overBy         = Math.max( 0, seatsNeeded - seatsAvailable );

	return { limited: true, seatsNeeded, seatsAvailable, overBy, isOver: overBy > 0 };
}

/**
 * Human sentence describing a seat evaluation, e.g.
 * "This roster has 2 seat(s) available. 6 record(s) need a seat — 4 over capacity."
 *
 * @param {{ seatsNeeded: number, seatsAvailable: number, overBy: number, isOver: boolean }} evaluation
 * @return {string} Translated sentence.
 */
export function describeSeatEvaluation( evaluation ) {
	if ( evaluation.isOver ) {
		return sprintf(
			/* translators: 1: seats available, 2: records needing a seat, 3: how many over */
			__(
				'This roster has %1$d seat(s) available, but %2$d record(s) need a seat — %3$d over capacity. Discard records (or sync a smaller selection) until it fits.',
				'wicket-aorm'
			),
			evaluation.seatsAvailable,
			evaluation.seatsNeeded,
			evaluation.overBy
		);
	}

	return sprintf(
		/* translators: 1: records needing a seat, 2: seats available */
		__( '%1$d record(s) need a seat; %2$d seat(s) are available on this roster.', 'wicket-aorm' ),
		evaluation.seatsNeeded,
		evaluation.seatsAvailable
	);
}
