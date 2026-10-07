/**
 * Manual Update accordion panel content — AORM-8.9 / AORM-8B.6.
 *
 * Renders the full content area for the "Manual Updates" PanelBody in the
 * ValidationReviewStep accordion.
 *
 * AORM-8.9: Displays a RecordsTable with a "Previous Category" extra column
 * that shows the previous_category value for each record.
 *
 * AORM-8B.6: Adds per-row and bulk Reinstate / Remove actions.
 *
 *   Per-row actions (Actions extra column):
 *     Reinstate — PATCH /wicket-aorm/v1/staged-records/{id} with
 *                 {category: getReinstateTargetCategory(record)}. Restores
 *                 the record to the category it came from (e.g. Possible
 *                 Match), not always Ready to Sync — see the bugfix note
 *                 on REINSTATE_CATEGORY.
 *     Remove    — PATCH /wicket-aorm/v1/staged-records/{id} with
 *                 {category: 'discard'}. Moves the record to the Discard
 *                 bucket; it will not be synced unless re-instated.
 *
 *   Bulk actions (shown when one or more rows are selected):
 *     Reinstate — calls handleBulkReinstate; PATCHes each selected ID to its
 *                 own getReinstateTargetCategory() in parallel via Promise.allSettled; partial
 *                 failures narrow the selection to failed IDs and show a Notice.
 *     Remove    — calls handleBulkRemove; PATCHes all selected IDs to
 *                 discard in parallel via Promise.allSettled; same
 *                 partial-failure behaviour.
 *
 * Both per-row and bulk actions call onRecordCategorized?() on full success
 * to let ValidationReviewStep re-fetch the accordion data and update counts.
 *
 * @param {{
 *   records:                Array<Object>,  — all manual_update staged records
 *   onRecordCategorized?:   () => void,     — called after a successful categorise action
 * }} props
 */

import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Notice } from '@wordpress/components';
import { apiFetch } from '../../utils/apiFetch';
import RecordsTable from './RecordsTable';

// ── Constants ─────────────────────────────────────────────────────────────────

/**
 * The record_status value used for all records in this panel.
 * Exported for test assertions.
 *
 * @type {string}
 */
export const MANUAL_UPDATE_STATUS = 'manual_update';

/**
 * Label for the "Previous Category" extra column.
 * Exported for test assertions.
 *
 * @type {string}
 */
export const PREVIOUS_CATEGORY_COLUMN_LABEL = __( 'Previous Category', 'wicket-aorm' );

/**
 * Fallback target category when a record is reinstated (AORM-8B.6).
 *
 * Bugfix: Reinstate used to always PATCH this value. A record moved into
 * Manual Updates from Possible Match then came back as "New Record" in Ready
 * to Sync (its record_status from matching is new_record), even though sync
 * would reuse the matched MDP person. Reinstate now restores the record's
 * previous category via getReinstateTargetCategory(); this constant is only
 * the fallback for records with no candidate matches.
 * Exported for test assertions.
 *
 * @type {string}
 */
export const REINSTATE_CATEGORY = 'ready_to_sync';

/**
 * Fallback target for a new_record row that still has MDP match candidates
 * but no usable previous_category.
 *
 * @type {string}
 */
export const REINSTATE_MATCH_FALLBACK_CATEGORY = 'possible_match';

/**
 * previous_category values Reinstate may restore to. manual_update and
 * discard are excluded: previous_category is overwritten on every move, so a
 * record that went Manual Update → Discard → Manual Update carries
 * previous_category 'discard', and Reinstate must not send it back there.
 * Exported for test assertions.
 *
 * @type {string[]}
 */
export const REINSTATABLE_CATEGORIES = [ 'ready_to_sync', 'possible_match', 'probable_match' ];

/**
 * Resolve the category a Manual Update record returns to on Reinstate.
 *
 *   1. previous_category, when it is one of REINSTATABLE_CATEGORIES.
 *   2. Otherwise, a new_record row that still has match candidates goes to
 *      Possible Match, so it is never shown as "New Record" while an MDP
 *      person may be reused.
 *   3. Otherwise, Ready to Sync (true new record, or exact/merge/on-roster
 *      statuses whose label already describes what sync will do).
 *
 * @param {Object} record Staged record.
 * @return {string} Target category.
 */
export function getReinstateTargetCategory( record ) {
	const previous = record?.previous_category ?? '';

	if ( REINSTATABLE_CATEGORIES.includes( previous ) ) {
		return previous;
	}

	const hasCandidates =
		Number( record?.match_count ?? 0 ) > 0 ||
		( Array.isArray( record?.matched_persons ) && record.matched_persons.length > 0 );

	if ( record?.record_status === 'new_record' && hasCandidates ) {
		return REINSTATE_MATCH_FALLBACK_CATEGORY;
	}

	return REINSTATE_CATEGORY;
}

/**
 * Target category when a record is removed / discarded (AORM-8B.6).
 * Exported for test assertions.
 *
 * @type {string}
 */
export const REMOVE_CATEGORY = 'discard';

// ── Component ─────────────────────────────────────────────────────────────────

export default function ManualUpdatePanel( { records, onRecordCategorized } ) {
	// ── Per-row action state ──────────────────────────────────────────────────
	const [ reinstatingIds, setReinstatingIds ] = useState( new Set() );
	const [ removingIds,    setRemovingIds    ] = useState( new Set() );
	const [ actionError,    setActionError    ] = useState( null );

	// ── Bulk action state ─────────────────────────────────────────────────────
	const [ selectedIds,       setSelectedIds       ] = useState( new Set() );
	const [ isBulkReinstating, setIsBulkReinstating ] = useState( false );
	const [ isBulkRemoving,    setIsBulkRemoving    ] = useState( false );
	const [ bulkActionError,   setBulkActionError   ] = useState( null );

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * PATCH a single record to a new category.
	 *
	 * @param {number}   recordId
	 * @param {string}   category   REINSTATE_CATEGORY or REMOVE_CATEGORY
	 * @param {Function} setInFlight State setter for the in-flight IDs Set
	 */
	function patchCategory( recordId, category, setInFlight ) {
		setInFlight( ( prev ) => new Set( [ ...prev, recordId ] ) );
		setActionError( null );

		apiFetch( {
			path:   `/wicket-aorm/v1/staged-records/${ recordId }`,
			method: 'PATCH',
			data:   { category },
		} )
			.then( () => {
				setInFlight( ( prev ) => {
					const next = new Set( prev );
					next.delete( recordId );

					return next;
				} );
				onRecordCategorized?.();
			} )
			.catch( ( err ) => {
				setInFlight( ( prev ) => {
					const next = new Set( prev );
					next.delete( recordId );

					return next;
				} );
				setActionError(
					err?.message ??
						__( 'Action failed. Please try again.', 'wicket-aorm' )
				);
			} );
	}

	/**
	 * PATCH all selected records to a new category in parallel.
	 *
	 * Uses Promise.allSettled so a partial failure does not abort the rest.
	 * On partial failure: surfaced via bulkActionError; selection narrows to
	 * the failed IDs so the admin can retry. On full success: selection is
	 * cleared and onRecordCategorized is called to refresh accordion counts.
	 *
	 * @param {(id: number) => string} resolveCategory Returns the target category per record id.
	 * @param {Function}               setIsBusy       State setter for the in-progress flag.
	 */
	function patchBulkCategory( resolveCategory, setIsBusy ) {
		const ids = Array.from( selectedIds );

		setIsBusy( true );
		setBulkActionError( null );

		Promise.allSettled(
			ids.map( ( id ) =>
				apiFetch( {
					path:   `/wicket-aorm/v1/staged-records/${ id }`,
					method: 'PATCH',
					data:   { category: resolveCategory( id ) },
				} )
			)
		).then( ( results ) => {
			setIsBusy( false );

			const failedIds = results
				.map( ( r, i ) => ( r.status === 'rejected' ? ids[ i ] : null ) )
				.filter( ( id ) => id !== null );

			if ( failedIds.length > 0 ) {
				setBulkActionError(
					sprintf(
						/* translators: %d: number of records that could not be categorised */
						__( '%d record(s) could not be updated. Please try again.', 'wicket-aorm' ),
						failedIds.length
					)
				);
				setSelectedIds( new Set( failedIds ) );
			} else {
				setSelectedIds( new Set() );
				onRecordCategorized?.();
			}
		} );
	}

	// ── Per-row action handlers ───────────────────────────────────────────────

	function handleReinstate( record ) {
		patchCategory( record.id, getReinstateTargetCategory( record ), setReinstatingIds );
	}

	function handleRemove( recordId ) {
		patchCategory( recordId, REMOVE_CATEGORY, setRemovingIds );
	}

	// ── Bulk action handlers ──────────────────────────────────────────────────

	function handleBulkReinstate() {
		patchBulkCategory( ( id ) => {
			const record = ( records ?? [] ).find( ( r ) => r.id === id );

			return getReinstateTargetCategory( record );
		}, setIsBulkReinstating );
	}

	function handleBulkRemove() {
		patchBulkCategory( () => REMOVE_CATEGORY, setIsBulkRemoving );
	}

	// ── Extra columns ─────────────────────────────────────────────────────────

	/**
	 * "Previous Category" extra column — AORM-8.9.
	 * Shows the previous_category value for each record (null/empty → "—").
	 */
	const previousCategoryColumn = {
		key:    'previous_category',
		label:  PREVIOUS_CATEGORY_COLUMN_LABEL,
		render: ( record ) => record.previous_category ?? '—',
	};

	/**
	 * "Actions" extra column — AORM-8B.6.
	 * Per-row Reinstate and Remove buttons.
	 */
	const actionsColumn = {
		key:   'actions',
		label: __( 'Actions', 'wicket-aorm' ),
		render: ( record ) => {
			const isReinstating = reinstatingIds.has( record.id );
			const isRemoving    = removingIds.has( record.id );
			const isBusy        = isReinstating || isRemoving;

			return (
				<div className="aorm-manual-update-row-actions">
					{ /* Reinstate — restore to the previous category */ }
					<Button
						variant="secondary"
						isBusy={ isReinstating }
						disabled={ isBusy }
						onClick={ () => handleReinstate( record ) }
						className="aorm-manual-update-row-actions__reinstate"
						aria-label={ sprintf(
							/* translators: %s: person first name or record id */
							__( 'Reinstate record for %s', 'wicket-aorm' ),
							record.raw_data?.first_name ?? record.id
						) }
					>
						{ __( 'Reinstate', 'wicket-aorm' ) }
					</Button>

					{ /* Remove — move to discard */ }
					<Button
						variant="tertiary"
						isDestructive
						isBusy={ isRemoving }
						disabled={ isBusy }
						onClick={ () => handleRemove( record.id ) }
						className="aorm-manual-update-row-actions__remove"
						aria-label={ sprintf(
							/* translators: %s: person first name or record id */
							__( 'Remove record for %s', 'wicket-aorm' ),
							record.raw_data?.first_name ?? record.id
						) }
					>
						{ __( 'Remove', 'wicket-aorm' ) }
					</Button>
				</div>
			);
		},
	};

	const extraColumns = [ previousCategoryColumn, actionsColumn ];

	return (
		<div className="aorm-manual-update-panel">

			{ /* Per-row action error notice */ }
			{ actionError && (
				<Notice
					status="error"
					isDismissible
					onRemove={ () => setActionError( null ) }
					className="aorm-manual-update-panel__action-error"
				>
					{ actionError }
				</Notice>
			) }

			{ /* Bulk action error notice */ }
			{ bulkActionError && (
				<Notice
					status="error"
					isDismissible
					onRemove={ () => setBulkActionError( null ) }
					className="aorm-manual-update-panel__bulk-action-error"
				>
					{ bulkActionError }
				</Notice>
			) }

			{ /* AORM-8B.6: Bulk action toolbar — visible when rows are selected */ }
			{ selectedIds.size > 0 && (
				<div
					className="aorm-manual-update-bulk-toolbar"
					role="toolbar"
					aria-label={ __( 'Bulk actions', 'wicket-aorm' ) }
				>
					<span className="aorm-manual-update-bulk-toolbar__count">
						{ sprintf(
							/* translators: %d: number of selected records */
							__( '%d selected', 'wicket-aorm' ),
							selectedIds.size
						) }
					</span>

					{ /* Bulk Reinstate */ }
					<Button
						variant="secondary"
						isBusy={ isBulkReinstating }
						disabled={ isBulkReinstating || isBulkRemoving }
						onClick={ handleBulkReinstate }
						className="aorm-manual-update-bulk-toolbar__reinstate"
					>
						{ __( 'Reinstate', 'wicket-aorm' ) }
					</Button>

					{ /* Bulk Remove */ }
					<Button
						variant="tertiary"
						isDestructive
						isBusy={ isBulkRemoving }
						disabled={ isBulkReinstating || isBulkRemoving }
						onClick={ handleBulkRemove }
						className="aorm-manual-update-bulk-toolbar__remove"
					>
						{ __( 'Remove', 'wicket-aorm' ) }
					</Button>
				</div>
			) }

			<RecordsTable
				records={ records }
				extraColumns={ extraColumns }
				noRecordsText={ __( 'No records require manual updates.', 'wicket-aorm' ) }
				selectable
				selectedIds={ selectedIds }
				onSelectionChange={ setSelectedIds }
			/>
		</div>
	);
}
