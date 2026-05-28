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
 *                 {category: 'ready_to_sync'}. Moves the record back into
 *                 the Ready to Sync bucket so it can be synced to MDP.
 *     Remove    — PATCH /wicket-aorm/v1/staged-records/{id} with
 *                 {category: 'discard'}. Moves the record to the Discard
 *                 bucket; it will not be synced unless re-instated.
 *
 *   Bulk actions (shown when one or more rows are selected):
 *     Reinstate — calls handleBulkReinstate; PATCHes all selected IDs to
 *                 ready_to_sync in parallel via Promise.allSettled; partial
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
 * Target category when a record is reinstated (AORM-8B.6).
 * Exported for test assertions.
 *
 * @type {string}
 */
export const REINSTATE_CATEGORY = 'ready_to_sync';

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
	 * @param {string}   category  Target category.
	 * @param {Function} setIsBusy State setter for the in-progress flag.
	 */
	function patchBulkCategory( category, setIsBusy ) {
		const ids = Array.from( selectedIds );

		setIsBusy( true );
		setBulkActionError( null );

		Promise.allSettled(
			ids.map( ( id ) =>
				apiFetch( {
					path:   `/wicket-aorm/v1/staged-records/${ id }`,
					method: 'PATCH',
					data:   { category },
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

	function handleReinstate( recordId ) {
		patchCategory( recordId, REINSTATE_CATEGORY, setReinstatingIds );
	}

	function handleRemove( recordId ) {
		patchCategory( recordId, REMOVE_CATEGORY, setRemovingIds );
	}

	// ── Bulk action handlers ──────────────────────────────────────────────────

	function handleBulkReinstate() {
		patchBulkCategory( REINSTATE_CATEGORY, setIsBulkReinstating );
	}

	function handleBulkRemove() {
		patchBulkCategory( REMOVE_CATEGORY, setIsBulkRemoving );
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
					{ /* Reinstate — move back to ready_to_sync */ }
					<Button
						variant="secondary"
						isBusy={ isReinstating }
						disabled={ isBusy }
						onClick={ () => handleReinstate( record.id ) }
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
