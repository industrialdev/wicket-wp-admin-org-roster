/**
 * Roster Activity tab — AORM-4.16 / AORM-4.19 / AORM-4.20.
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
 * AORM-4.20 adds server-side pagination. The page and per_page params
 * are always included in the API path. Changing filters resets to page 1.
 * A pagination bar (per-page selector + first/prev/next/last buttons)
 * is rendered below the table whenever there is at least one entry.
 *
 * States:
 *   - Missing params → error Notice
 *   - Loading        → Spinner
 *   - Error          → error Notice with the server message
 *   - Empty          → ActivityTable (shows its own empty-state row)
 *   - Populated      → ActivityTable + pagination bar
 *
 * @param {{ orgUuid: string, membershipUuid: string }} props
 */

import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Notice, Spinner } from '@wordpress/components';

import { useRestApi } from '../hooks/useRestApi';
import ActivityTable from './ActivityTable';
import ActivityFilters, { EMPTY_FILTERS } from './ActivityFilters';

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

export const PER_PAGE_OPTIONS = [ 10, 20, 50 ];
export const DEFAULT_PER_PAGE = 20;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Build the REST API path for the activity log, including pagination and any
 * active server-side filter params.
 *
 * @param {string} orgUuid
 * @param {string} membershipUuid
 * @param {import('./ActivityFilters').ActivityFilterValues} filters
 * @param {number} page    Current page number (1-based).
 * @param {number} perPage Items per page.
 * @returns {string}
 */
export function buildActivityPath( orgUuid, membershipUuid, filters, page = 1, perPage = DEFAULT_PER_PAGE ) {
	const base = `/wicket-aorm/v1/rosters/${ orgUuid }/${ membershipUuid }/activity`;

	const params = new URLSearchParams();
	params.set( 'page',     String( page ) );
	params.set( 'per_page', String( perPage ) );
	if ( filters.action )    params.set( 'action',    filters.action );
	if ( filters.level )     params.set( 'level',     filters.level );
	if ( filters.date_from ) params.set( 'date_from', filters.date_from );
	if ( filters.date_to )   params.set( 'date_to',   filters.date_to );

	return `${ base }?${ params.toString() }`;
}

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

export default function RosterActivity( { orgUuid, membershipUuid } ) {
	const hasParams = !! orgUuid && !! membershipUuid;

	// Committed filters (applied to the API request).
	const [ filters, setFilters ] = useState( { ...EMPTY_FILTERS } );

	// Pagination state (AORM-4.20).
	const [ page, setPage ]       = useState( 1 );
	const [ perPage, setPerPage ] = useState( DEFAULT_PER_PAGE );

	const path = hasParams
		? buildActivityPath( orgUuid, membershipUuid, filters, page, perPage )
		: null;

	const { data, isLoading, error } = useRestApi( path );

	// Pagination data from the API envelope.
	const total      = data?.total       ?? 0;
	const totalPages = data?.total_pages ?? 1;

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
		setPage( 1 ); // Reset to first page when filters change.
	}

	function handleResetFilters() {
		setFilters( { ...EMPTY_FILTERS } );
		setPage( 1 );
	}

	function goToPage( next ) {
		setPage( Math.max( 1, Math.min( next, totalPages ) ) );
	}

	function handlePerPageChange( evt ) {
		setPerPage( Number( evt.target.value ) );
		setPage( 1 );
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

	// ── Shared filter bar rendered in every state ─────────────────────────────
	const filterBar = (
		<ActivityFilters
			initialFilters={ filters }
			onApply={ handleApplyFilters }
			onReset={ handleResetFilters }
		/>
	);

	// ── Pagination bar (AORM-4.20) ────────────────────────────────────────────
	const paginationBar = total > 0 && (
		<div className="aorm-activity__pagination tablenav">
			<div className="tablenav-pages">
				<span className="displaying-num">
					{ total }{ ' ' }
					{ total === 1
						? __( 'item', 'wicket-aorm' )
						: __( 'items', 'wicket-aorm' ) }
				</span>

				{ /* Per-page selector */ }
				<label htmlFor="aorm-activity-per-page" className="screen-reader-text">
					{ __( 'Items per page', 'wicket-aorm' ) }
				</label>
				<select
					id="aorm-activity-per-page"
					className="aorm-activity__per-page"
					value={ perPage }
					onChange={ handlePerPageChange }
				>
					{ PER_PAGE_OPTIONS.map( ( opt ) => (
						<option key={ opt } value={ opt }>{ opt }</option>
					) ) }
				</select>

				{ totalPages > 1 && (
					<span className="pagination-links">
						<Button
							variant="secondary"
							disabled={ page <= 1 }
							onClick={ () => goToPage( 1 ) }
							aria-label={ __( 'First page', 'wicket-aorm' ) }
						>
							«
						</Button>
						<Button
							variant="secondary"
							disabled={ page <= 1 }
							onClick={ () => goToPage( page - 1 ) }
							aria-label={ __( 'Previous page', 'wicket-aorm' ) }
						>
							‹
						</Button>
						<span className="paging-input">
							{ page }{ ' ' }{ __( 'of', 'wicket-aorm' ) }{ ' ' }{ totalPages }
						</span>
						<Button
							variant="secondary"
							disabled={ page >= totalPages }
							onClick={ () => goToPage( page + 1 ) }
							aria-label={ __( 'Next page', 'wicket-aorm' ) }
						>
							›
						</Button>
						<Button
							variant="secondary"
							disabled={ page >= totalPages }
							onClick={ () => goToPage( totalPages ) }
							aria-label={ __( 'Last page', 'wicket-aorm' ) }
						>
							»
						</Button>
					</span>
				) }
			</div>
		</div>
	);

	// ── Loading ───────────────────────────────────────────────────────────────
	if ( isLoading ) {
		return (
			<div className="aorm-activity">
				{ filterBar }
				<Spinner />
			</div>
		);
	}

	// ── Error ─────────────────────────────────────────────────────────────────
	if ( error ) {
		return (
			<div className="aorm-activity">
				{ filterBar }
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			</div>
		);
	}

	// ── Data ──────────────────────────────────────────────────────────────────
	return (
		<div className="aorm-activity">
			{ filterBar }
			<ActivityTable entries={ entries } />
			{ paginationBar }
		</div>
	);
}
