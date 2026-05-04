/**
 * Roster Activity tab — AORM-4.16 / AORM-4.19.
 *
 * Fetches the scoped audit log for this org membership via
 * GET /wicket-aorm/v1/rosters/{org_uuid}/{membership_uuid}/activity
 * (endpoint built in AORM-4.18) and renders a read-only ActivityTable.
 *
 * AORM-4.19 adds a filter bar (ActivityFilters) above the table.
 * Server-side filters (action, level, date_from, date_to) are appended
 * as query-string params on the API path.  The actor filter is applied
 * client-side as a case-insensitive substring match on `entry.actor`.
 *
 * States:
 *   - Missing params → error Notice
 *   - Loading        → Spinner
 *   - Error          → error Notice with the server message
 *   - Empty          → ActivityTable (shows its own empty-state row)
 *   - Populated      → ActivityTable with one row per log entry
 *
 * @param {{ orgUuid: string, membershipUuid: string }} props
 */

import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Notice, Spinner } from '@wordpress/components';

import { useRestApi } from '../hooks/useRestApi';
import ActivityTable from './ActivityTable';
import ActivityFilters, { EMPTY_FILTERS } from './ActivityFilters';

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Build the REST API path for the activity log, including any active
 * server-side filter params.
 *
 * @param {string} orgUuid
 * @param {string} membershipUuid
 * @param {import('./ActivityFilters').ActivityFilterValues} filters
 * @returns {string}
 */
export function buildActivityPath( orgUuid, membershipUuid, filters ) {
	const base = `/wicket-aorm/v1/rosters/${ orgUuid }/${ membershipUuid }/activity`;

	const params = new URLSearchParams();
	if ( filters.action )    params.set( 'action',    filters.action );
	if ( filters.level )     params.set( 'level',     filters.level );
	if ( filters.date_from ) params.set( 'date_from', filters.date_from );
	if ( filters.date_to )   params.set( 'date_to',   filters.date_to );

	const qs = params.toString();

	return qs ? `${ base }?${ qs }` : base;
}

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

export default function RosterActivity( { orgUuid, membershipUuid } ) {
	const hasParams = !! orgUuid && !! membershipUuid;

	// Committed filters (applied to the API request).
	const [ filters, setFilters ] = useState( { ...EMPTY_FILTERS } );

	const path = hasParams
		? buildActivityPath( orgUuid, membershipUuid, filters )
		: null;

	const { data, isLoading, error } = useRestApi( path );

	// ── Guard — missing route params ─────────────────────────────────────────
	if ( ! hasParams ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ __(
					'Missing organisation or membership UUID.',
					'wicket-aorm'
				) }
			</Notice>
		);
	}

	// ── Handlers ─────────────────────────────────────────────────────────────

	function handleApplyFilters( newFilters ) {
		setFilters( newFilters );
	}

	function handleResetFilters() {
		setFilters( { ...EMPTY_FILTERS } );
	}

	// ── Client-side actor filter ──────────────────────────────────────────────
	const allEntries = data?.entries ?? [];
	const entries = filters.actor
		? allEntries.filter( ( entry ) =>
			entry.actor
				?.toLowerCase()
				.includes( filters.actor.toLowerCase() )
		)
		: allEntries;

	// ── Loading ───────────────────────────────────────────────────────────────
	if ( isLoading ) {
		return (
			<div className="aorm-activity">
				<ActivityFilters
					initialFilters={ filters }
					onApply={ handleApplyFilters }
					onReset={ handleResetFilters }
				/>
				<Spinner />
			</div>
		);
	}

	// ── Error ─────────────────────────────────────────────────────────────────
	if ( error ) {
		return (
			<div className="aorm-activity">
				<ActivityFilters
					initialFilters={ filters }
					onApply={ handleApplyFilters }
					onReset={ handleResetFilters }
				/>
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			</div>
		);
	}

	// ── Data ──────────────────────────────────────────────────────────────────
	return (
		<div className="aorm-activity">
			<ActivityFilters
				initialFilters={ filters }
				onApply={ handleApplyFilters }
				onReset={ handleResetFilters }
			/>
			<ActivityTable entries={ entries } />
		</div>
	);
}
