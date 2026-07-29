/**
 * Discard accordion panel content — AORM-8.10 / AORM-8B.7.
 *
 * Renders the full content area for the "Discard" PanelBody in the
 * ValidationReviewStep accordion.
 *
 * AORM-8.10: Displays a RecordsTable with a "Previous Category" extra column
 * that shows the previous_category value for each record.
 *
 * AORM-8B.7: Adds per-row and bulk Reinstate actions.
 *
 *   Per-row action (Actions extra column):
 *     Reinstate — PATCH /wicket-aorm/v1/staged-records/{id} with
 *                 {category: <record's own previous_category>}. Moves the
 *                 record back into the bucket it was discarded from
 *                 (possible_match, probable_match, manual_update, or
 *                 ready_to_sync), falling back to ready_to_sync only when
 *                 previous_category is missing. See getReinstateTargetCategory().
 *
 *   Bulk action (shown when one or more rows are selected):
 *     Reinstate — calls handleBulkReinstate; PATCHes each selected ID to its
 *                 own previous_category in parallel via Promise.allSettled;
 *                 partial failures narrow the selection to failed IDs and
 *                 show a Notice.
 *
 * Both per-row and bulk actions call onRecordCategorized?() on full success
 * to let ValidationReviewStep re-fetch the accordion data and update counts.
 *
 * AORM-8B.22: Adds per-row and bulk permanent Remove actions.
 *
 *   Per-row action (Actions extra column):
 *     Remove — opens ConfirmDeleteRecordsModal; on confirm, calls
 *              DELETE /wicket-aorm/v1/staged-records/{id} (AORM-8B.9),
 *              permanently removing the record from the database. Unlike
 *              Reinstate, this cannot be undone.
 *
 *   Bulk action (shown when one or more rows are selected):
 *     Remove — opens the same confirm modal for all selected IDs; on
 *              confirm, DELETEs each ID in parallel via Promise.allSettled.
 *              Partial failures keep the modal open and show an inline
 *              error; full success clears the selection and closes it.
 *
 * Both the per-row and bulk Remove actions call onRecordCategorized?() on
 * full success, same as Reinstate, so ValidationReviewStep re-fetches the
 * accordion data and the Discard count drops accordingly.
 *
 * @param {{
 *   records:                Array<Object>,  — all discard staged records
 *   onRecordCategorized?:   () => void,     — called after a successful reinstate or delete action
 * }} props
 */

import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Notice } from '@wordpress/components';
import { apiFetch } from '../../utils/apiFetch';
import RecordsTable from './RecordsTable';
import ConfirmDeleteRecordsModal from './ConfirmDeleteRecordsModal';

// ── Constants ─────────────────────────────────────────────────────────────────

/**
 * The record_status value used for all records in this panel.
 * Exported for test assertions.
 *
 * @type {string}
 */
export const DISCARD_STATUS = 'discard';

/**
 * Label for the "Previous Category" extra column.
 * Exported for test assertions.
 *
 * @type {string}
 */
export const PREVIOUS_CATEGORY_COLUMN_LABEL = __( 'Previous Category', 'wicket-aorm' );

/**
 * Fallback target category when a discarded record is reinstated but has no
 * recorded previous_category (e.g. legacy rows). Exported for test assertions.
 *
 * @type {string}
 */
export const REINSTATE_CATEGORY = 'ready_to_sync';

/**
 * Resolve the category a discarded record should move to when reinstated.
 *
 * Restores the record to the bucket it was discarded from (its own
 * previous_category — possible_match, probable_match, manual_update, or
 * ready_to_sync) rather than always dropping it into Ready to Sync. Falls
 * back to REINSTATE_CATEGORY when previous_category is missing or empty.
 *
 * @param {Object} record
 * @return {string} The category to PATCH the record to on reinstate.
 */
function getReinstateTargetCategory( record ) {
	return record?.previous_category || REINSTATE_CATEGORY;
}

// ── Component ─────────────────────────────────────────────────────────────────

export default function DiscardPanel( { records, onRecordCategorized } ) {
	// ── Per-row action state ──────────────────────────────────────────────────
	const [ reinstatingIds, setReinstatingIds ] = useState( new Set() );
	const [ actionError,    setActionError    ] = useState( null );

	// ── Bulk action state ─────────────────────────────────────────────────────
	const [ selectedIds,       setSelectedIds       ] = useState( new Set() );
	const [ isBulkReinstating, setIsBulkReinstating ] = useState( false );
	const [ bulkActionError,   setBulkActionError   ] = useState( null );

	// ── Delete (permanent remove) action state — AORM-8B.22 ──────────────────
	// pendingDelete: null | { type: 'single', record: Object } | { type: 'bulk', ids: number[] }
	const [ pendingDelete, setPendingDelete ] = useState( null );
	const [ isDeleting,    setIsDeleting    ] = useState( false );
	const [ deleteError,   setDeleteError   ] = useState( null );

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * PATCH a single record back to its own previous_category.
	 *
	 * @param {Object} record
	 */
	function handleReinstate( record ) {
		const recordId = record.id;

		setReinstatingIds( ( prev ) => new Set( [ ...prev, recordId ] ) );
		setActionError( null );

		apiFetch( {
			path:   `/wicket-aorm/v1/staged-records/${ recordId }`,
			method: 'PATCH',
			data:   { category: getReinstateTargetCategory( record ) },
		} )
			.then( () => {
				setReinstatingIds( ( prev ) => {
					const next = new Set( prev );
					next.delete( recordId );

					return next;
				} );
				onRecordCategorized?.();
			} )
			.catch( ( err ) => {
				setReinstatingIds( ( prev ) => {
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
	 * PATCH all selected records back to their own previous_category, in
	 * parallel.
	 *
	 * Uses Promise.allSettled so a partial failure does not abort the rest.
	 * On partial failure: surfaced via bulkActionError; selection narrows to
	 * the failed IDs so the admin can retry. On full success: selection is
	 * cleared and onRecordCategorized is called to refresh accordion counts.
	 */
	function handleBulkReinstate() {
		const ids = Array.from( selectedIds );

		setIsBulkReinstating( true );
		setBulkActionError( null );

		Promise.allSettled(
			ids.map( ( id ) => {
				const record = records.find( ( r ) => r.id === id );

				return apiFetch( {
					path:   `/wicket-aorm/v1/staged-records/${ id }`,
					method: 'PATCH',
					data:   { category: getReinstateTargetCategory( record ) },
				} );
			} )
		).then( ( results ) => {
			setIsBulkReinstating( false );

			const failedIds = results
				.map( ( r, i ) => ( r.status === 'rejected' ? ids[ i ] : null ) )
				.filter( ( id ) => id !== null );

			if ( failedIds.length > 0 ) {
				setBulkActionError(
					sprintf(
						/* translators: %d: number of records that could not be reinstated */
						__( '%d record(s) could not be reinstated. Please try again.', 'wicket-aorm' ),
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

	/**
	 * Open the confirm-delete modal for a single record.
	 *
	 * @param {Object} record
	 */
	function requestDelete( record ) {
		setDeleteError( null );
		setPendingDelete( { type: 'single', record } );
	}

	/**
	 * Open the confirm-delete modal for all currently-selected records.
	 */
	function requestBulkDelete() {
		setDeleteError( null );
		setPendingDelete( { type: 'bulk', ids: Array.from( selectedIds ) } );
	}

	/**
	 * Dismiss the confirm-delete modal. No-op while a delete is in flight.
	 */
	function closeDeleteConfirm() {
		if ( isDeleting ) {
			return;
		}

		setPendingDelete( null );
		setDeleteError( null );
	}

	/**
	 * DELETE every ID targeted by the pending confirmation, in parallel.
	 *
	 * Uses Promise.allSettled so a partial failure does not abort the rest.
	 * On partial failure: the modal stays open with an inline error so the
	 * admin can retry. On full success: the modal closes, the selection
	 * (for bulk deletes) is cleared, and onRecordCategorized is called to
	 * refresh accordion counts.
	 */
	function handleConfirmDelete() {
		if ( ! pendingDelete ) {
			return;
		}

		const ids = pendingDelete.type === 'single'
			? [ pendingDelete.record.id ]
			: pendingDelete.ids;

		setIsDeleting( true );
		setDeleteError( null );

		Promise.allSettled(
			ids.map( ( id ) =>
				apiFetch( {
					path:   `/wicket-aorm/v1/staged-records/${ id }`,
					method: 'DELETE',
				} )
			)
		).then( ( results ) => {
			setIsDeleting( false );

			const failedCount = results.filter( ( r ) => r.status === 'rejected' ).length;

			if ( failedCount > 0 ) {
				setDeleteError(
					sprintf(
						/* translators: %d: number of records that could not be deleted */
						__( '%d record(s) could not be deleted. Please try again.', 'wicket-aorm' ),
						failedCount
					)
				);

				return;
			}

			setPendingDelete( null );

			if ( pendingDelete.type === 'bulk' ) {
				setSelectedIds( new Set() );
			}

			onRecordCategorized?.();
		} );
	}

	// ── Extra columns ─────────────────────────────────────────────────────────

	/**
	 * "Previous Category" extra column — AORM-8.10.
	 * Displays the previous_category for each record (null/empty → "—").
	 */
	const previousCategoryColumn = {
		key:    'previous_category',
		label:  PREVIOUS_CATEGORY_COLUMN_LABEL,
		render: ( record ) => record.previous_category ?? '—',
	};

	/**
	 * "Actions" extra column — AORM-8B.7.
	 * Per-row Reinstate button.
	 */
	const actionsColumn = {
		key:   'actions',
		label: __( 'Actions', 'wicket-aorm' ),
		render: ( record ) => {
			const isReinstating = reinstatingIds.has( record.id );

			return (
				<div className="aorm-discard-row-actions">
					{ /* Reinstate — move back to the record's previous category */ }
					<Button
						variant="secondary"
						isBusy={ isReinstating }
						disabled={ isReinstating }
						onClick={ () => handleReinstate( record ) }
						className="aorm-discard-row-actions__reinstate"
						aria-label={ sprintf(
							/* translators: %s: person first name or record id */
							__( 'Reinstate record for %s', 'wicket-aorm' ),
							record.raw_data?.first_name ?? record.id
						) }
					>
						{ __( 'Reinstate', 'wicket-aorm' ) }
					</Button>

					{ /* Remove — permanently delete the record (AORM-8B.22) */ }
					<Button
						variant="tertiary"
						isDestructive
						disabled={ isReinstating }
						onClick={ () => requestDelete( record ) }
						className="aorm-discard-row-actions__remove"
						aria-label={ sprintf(
							/* translators: %s: person first name or record id */
							__( 'Permanently delete record for %s', 'wicket-aorm' ),
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

	/**
	 * Number of records targeted by the pending delete confirmation.
	 * 0 when no delete is pending.
	 */
	let pendingDeleteCount = 0;
	if ( pendingDelete?.type === 'single' ) {
		pendingDeleteCount = 1;
	} else if ( pendingDelete?.type === 'bulk' ) {
		pendingDeleteCount = pendingDelete.ids.length;
	}

	return (
		<div className="aorm-discard-panel">

			{ /* Per-row action error notice */ }
			{ actionError && (
				<Notice
					status="error"
					isDismissible
					onRemove={ () => setActionError( null ) }
					className="aorm-discard-panel__action-error"
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
					className="aorm-discard-panel__bulk-action-error"
				>
					{ bulkActionError }
				</Notice>
			) }

			{ /* AORM-8B.7: Bulk action toolbar — visible when rows are selected */ }
			{ selectedIds.size > 0 && (
				<div
					className="aorm-discard-bulk-toolbar"
					role="toolbar"
					aria-label={ __( 'Bulk actions', 'wicket-aorm' ) }
				>
					<span className="aorm-discard-bulk-toolbar__count">
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
						disabled={ isBulkReinstating }
						onClick={ handleBulkReinstate }
						className="aorm-discard-bulk-toolbar__reinstate"
					>
						{ __( 'Reinstate', 'wicket-aorm' ) }
					</Button>

					{ /* Bulk Remove — permanently delete (AORM-8B.22) */ }
					<Button
						variant="tertiary"
						isDestructive
						disabled={ isBulkReinstating }
						onClick={ requestBulkDelete }
						className="aorm-discard-bulk-toolbar__remove"
					>
						{ __( 'Remove', 'wicket-aorm' ) }
					</Button>
				</div>
			) }

			<RecordsTable
				records={ records }
				extraColumns={ extraColumns }
				noRecordsText={ __( 'No records have been discarded.', 'wicket-aorm' ) }
				selectable
				selectedIds={ selectedIds }
				onSelectionChange={ setSelectedIds }
			/>

			{ /* AORM-8B.22: Confirm before permanently deleting record(s) */ }
			<ConfirmDeleteRecordsModal
				isOpen={ pendingDelete !== null }
				recordCount={ pendingDeleteCount }
				onConfirm={ handleConfirmDelete }
				onClose={ closeDeleteConfirm }
				isDeleting={ isDeleting }
				deleteError={ deleteError }
			/>
		</div>
	);
}
