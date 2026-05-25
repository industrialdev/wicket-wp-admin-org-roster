/**
 * RecordsTable — AORM-8.3.
 *
 * Reusable table component for staged records in the upload validation-review
 * accordion. Provides:
 *  - Three base sortable columns: First Name, Last Name, Email (asc/desc toggle).
 *  - SearchControl that filters across ALL records before pagination.
 *  - Client-side pagination at PAGE_SIZE (10) records per page.
 *
 * Additional columns for specific categories (AORM-8.4 – 8.10) are injected
 * via the `extraColumns` prop; they are appended after the three base columns.
 *
 * Record shape (from GET /wicket-aorm/v1/uploads/{session_id}/staged):
 *   {
 *     id:                number,
 *     record_status:     string,
 *     sync_status:       string,
 *     raw_data:          { first_name?: string, last_name?: string, email?: string, … },
 *     match_count:       number,
 *     previous_category: string|null,
 *   }
 *
 * @param {{
 *   records:       Array<Object>,
 *   extraColumns?: Array<{key: string, label: string, render: (record: Object) => import('@wordpress/element').WPElement}>,
 *   noRecordsText?: string,
 * }} props
 */

import { useState, useMemo } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, SearchControl } from '@wordpress/components';

// ── Constants ─────────────────────────────────────────────────────────────────

/** Records displayed per page. */
export const PAGE_SIZE = 10;

/** Base sortable column definitions. */
export const BASE_COLUMNS = [
	{ key: 'first_name', label: __( 'First Name', 'wicket-aorm' ) },
	{ key: 'last_name',  label: __( 'Last Name',  'wicket-aorm' ) },
	{ key: 'email',      label: __( 'Email',      'wicket-aorm' ) },
];

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Safely extract a string field from a record's raw_data, falling back to ''.
 *
 * @param {Object} record
 * @param {string} field
 * @return {string}
 */
function rawField( record, field ) {
	return String( record?.raw_data?.[ field ] ?? '' ).trim();
}

/**
 * Compare two records for a given sort field and direction.
 *
 * @param {Object} a
 * @param {Object} b
 * @param {string} field
 * @param {'asc'|'desc'} direction
 * @return {number}
 */
function compareRecords( a, b, field, direction ) {
	const va = rawField( a, field ).toLowerCase();
	const vb = rawField( b, field ).toLowerCase();
	const cmp = va.localeCompare( vb );

	return direction === 'asc' ? cmp : -cmp;
}

/**
 * Return true when any base search field in the record matches `query`.
 *
 * @param {Object} record
 * @param {string} query  Lowercased search query.
 * @return {boolean}
 */
function recordMatchesSearch( record, query ) {
	if ( ! query ) {
		return true;
	}

	return BASE_COLUMNS.some( ( col ) =>
		rawField( record, col.key ).toLowerCase().includes( query )
	);
}

// ── Sort indicator ─────────────────────────────────────────────────────────

/**
 * Screen-reader-accessible sort direction label.
 *
 * @param {string|null} sortField    Currently sorted field.
 * @param {'asc'|'desc'} sortDir    Current direction.
 * @param {string} colKey           Column being rendered.
 * @return {string}
 */
function ariaSort( sortField, sortDir, colKey ) {
	if ( sortField !== colKey ) {
		return 'none';
	}

	return sortDir === 'asc' ? 'ascending' : 'descending';
}

/**
 * Unicode sort indicator shown after the column label.
 * Visible arrow keeps the UX consistent with WP_List_Table.
 *
 * @param {string|null} sortField
 * @param {'asc'|'desc'} sortDir
 * @param {string} colKey
 * @return {string}
 */
function sortIndicator( sortField, sortDir, colKey ) {
	if ( sortField !== colKey ) {
		return '';
	}

	return sortDir === 'asc' ? ' ▲' : ' ▼';
}

// ── Component ─────────────────────────────────────────────────────────────────

export default function RecordsTable( {
	records = [],
	extraColumns = [],
	noRecordsText,
} ) {
	const [ searchQuery, setSearchQuery ] = useState( '' );
	const [ sortField,   setSortField   ] = useState( null );
	const [ sortDir,     setSortDir     ] = useState( 'asc' );
	const [ currentPage, setCurrentPage ] = useState( 1 );

	// ── Search filter (before sort + pagination) ──────────────────────────────

	const normalizedQuery = searchQuery.trim().toLowerCase();

	const filtered = useMemo(
		() => records.filter( ( r ) => recordMatchesSearch( r, normalizedQuery ) ),
		[ records, normalizedQuery ]
	);

	// ── Sort ──────────────────────────────────────────────────────────────────

	const sorted = useMemo( () => {
		if ( ! sortField ) {
			return filtered;
		}

		return [ ...filtered ].sort( ( a, b ) =>
			compareRecords( a, b, sortField, sortDir )
		);
	}, [ filtered, sortField, sortDir ] );

	// ── Pagination ────────────────────────────────────────────────────────────

	const totalPages = Math.max( 1, Math.ceil( sorted.length / PAGE_SIZE ) );

	// Clamp current page when filters reduce total pages.
	const safePage = Math.min( currentPage, totalPages );

	const pageStart = ( safePage - 1 ) * PAGE_SIZE;
	const pageEnd   = pageStart + PAGE_SIZE;
	const pageRows  = sorted.slice( pageStart, pageEnd );

	// ── Handlers ──────────────────────────────────────────────────────────────

	function handleSearchChange( value ) {
		setSearchQuery( value );
		setCurrentPage( 1 );
	}

	function handleSort( field ) {
		if ( sortField === field ) {
			setSortDir( ( d ) => ( d === 'asc' ? 'desc' : 'asc' ) );
		} else {
			setSortField( field );
			setSortDir( 'asc' );
		}

		setCurrentPage( 1 );
	}

	function handlePageChange( page ) {
		setCurrentPage( page );
	}

	// ── Column list ───────────────────────────────────────────────────────────

	const totalCols = BASE_COLUMNS.length + extraColumns.length;

	// ── Render ────────────────────────────────────────────────────────────────

	return (
		<div className="aorm-records-table">

			{ /* Search */ }
			<div className="aorm-records-table__search">
				<SearchControl
					label={ __( 'Search records', 'wicket-aorm' ) }
					hideLabelFromVision
					placeholder={ __( 'Search by name or email…', 'wicket-aorm' ) }
					value={ searchQuery }
					onChange={ handleSearchChange }
				/>
			</div>

			{ /* Table */ }
			<table
				className="wp-list-table widefat fixed striped aorm-records-table__table"
				aria-label={ __( 'Staged records', 'wicket-aorm' ) }
			>
				<thead>
					<tr>
						{ BASE_COLUMNS.map( ( col ) => (
							<th
								key={ col.key }
								scope="col"
								aria-sort={ ariaSort( sortField, sortDir, col.key ) }
								className={ `aorm-records-table__col--${ col.key }` }
							>
								<Button
									variant="link"
									className="aorm-records-table__sort-btn"
									onClick={ () => handleSort( col.key ) }
									aria-label={ sprintf(
										/* translators: %s: column label */
										__( 'Sort by %s', 'wicket-aorm' ),
										col.label
									) }
								>
									{ col.label }
									<span aria-hidden="true">
										{ sortIndicator( sortField, sortDir, col.key ) }
									</span>
								</Button>
							</th>
						) ) }

						{ extraColumns.map( ( col ) => (
							<th
								key={ col.key }
								scope="col"
								className={ `aorm-records-table__col--${ col.key }` }
							>
								{ col.label }
							</th>
						) ) }
					</tr>
				</thead>

				<tbody>
					{ pageRows.map( ( record ) => (
						<tr
							key={ record.id }
							className="aorm-records-table__row"
							data-record-id={ record.id }
							data-record-status={ record.record_status }
						>
							{ BASE_COLUMNS.map( ( col ) => (
								<td
									key={ col.key }
									className={ `aorm-records-table__col--${ col.key }` }
								>
									{ rawField( record, col.key ) || '—' }
								</td>
							) ) }

							{ extraColumns.map( ( col ) => (
								<td
									key={ col.key }
									className={ `aorm-records-table__col--${ col.key }` }
								>
									{ col.render( record ) }
								</td>
							) ) }
						</tr>
					) ) }

					{ pageRows.length === 0 && (
						<tr>
							<td
								colSpan={ totalCols }
								className="aorm-records-table__empty"
							>
								{ noRecordsText ??
									__( 'No records found.', 'wicket-aorm' ) }
							</td>
						</tr>
					) }
				</tbody>
			</table>

			{ /* Pagination */ }
			{ sorted.length > PAGE_SIZE && (
				<div
					className="aorm-records-table__pagination"
					role="navigation"
					aria-label={ __( 'Table pagination', 'wicket-aorm' ) }
				>
					<Button
						variant="secondary"
						disabled={ safePage <= 1 }
						onClick={ () => handlePageChange( safePage - 1 ) }
						aria-label={ __( 'Previous page', 'wicket-aorm' ) }
					>
						{ __( '‹ Previous', 'wicket-aorm' ) }
					</Button>

					<span className="aorm-records-table__pagination-info">
						{ sprintf(
							/* translators: 1: current page, 2: total pages */
							__( 'Page %1$d of %2$d', 'wicket-aorm' ),
							safePage,
							totalPages
						) }
					</span>

					<Button
						variant="secondary"
						disabled={ safePage >= totalPages }
						onClick={ () => handlePageChange( safePage + 1 ) }
						aria-label={ __( 'Next page', 'wicket-aorm' ) }
					>
						{ __( 'Next ›', 'wicket-aorm' ) }
					</Button>
				</div>
			) }

		</div>
	);
}
