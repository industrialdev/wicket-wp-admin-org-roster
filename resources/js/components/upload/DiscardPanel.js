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
 *                 {category: 'ready_to_sync'}. Moves the record back into
 *                 the Ready to Sync bucket so it can be synced to MDP.
 *
 *   Bulk action (shown when one or more rows are selected):
 *     Reinstate — calls handleBulkReinstate; PATCHes all selected IDs to
 *                 ready_to_sync in parallel via Promise.allSettled; partial
 *                 failures narrow the selection to failed IDs and show a Notice.
 *
 * Both per-row and bulk actions call onRecordCategorized?() on full success
 * to let ValidationReviewStep re-fetch the accordion data and update counts.
 *
 * @param {{
 *   records:                Array<Object>,  — all discard staged records
 *   onRecordCategorized?:   () => void,     — called after a successful reinstate action
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
export const DISCARD_STATUS = 'discard';

/**
 * Label for the "Previous Category" extra column.
 * Exported for test assertions.
 *
 * @type {string}
 */
export const PREVIOUS_CATEGORY_COLUMN_LABEL = __( 'Previous Category', 'wicket-aorm' );

/**
 * Target category when a discarded record is reinstated (AORM-8B.7).
 * Exported for test assertions.
 *
 * @type {string}
 */
export const REINSTATE_CATEGORY = 'ready_to_sync';

// ── Component ─────────────────────────────────────────────────────────────────

export default function DiscardPanel( { records, onRecordCategorized } ) {
	// ── Per-row action state ──────────────────────────────────────────────────
	const [ reinstatingIds, setReinstatingIds ] = useState( new Set() );
	const [ actionError,    setActionError    ] = useState( null );

	// ── Bulk action state ─────────────────────────────────────────────────────
	const [ selectedIds,       setSelectedIds       ] = useState( new Set() );
	const [ isBulkReinstating, setIsBulkReinstating ] = useState( false );
	const [ bulkActionError,   setBulkActionError   ] = useState( null );

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * PATCH a single record to REINSTATE_CATEGORY (ready_to_sync).
	 *
	 * @param {number} recordId
	 */
	function handleReinstate( recordId ) {
		setReinstatingIds( ( prev ) => new Set( [ ...prev, recordId ] ) );
		setActionError( null );

		apiFetch( {
			path:   `/wicket-aorm/v1/staged-records/${ recordId }`,
			method: 'PATCH',
			data:   { category: REINSTATE_CATEGORY },
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
	 * PATCH all selected records to REINSTATE_CATEGORY in parallel.
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
			ids.map( ( id ) =>
				apiFetch( {
					path:   `/wicket-aorm/v1/staged-records/${ id }`,
					method: 'PATCH',
					data:   { category: REINSTATE_CATEGORY },
				} )
			)
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
					{ /* Reinstate — move back to ready_to_sync */ }
					<Button
						variant="secondary"
						isBusy={ isReinstating }
						disabled={ isReinstating }
						onClick={ () => handleReinstate( record.id ) }
						className="aorm-discard-row-actions__reinstate"
						aria-label={ sprintf(
							/* translators: %s: person first name or record id */
							__( 'Reinstate record for %s', 'wicket-aorm' ),
							record.raw_data?.first_name ?? record.id
						) }
					>
						{ __( 'Reinstate', 'wicket-aorm' ) }
					</Button>
				</div>
			);
		},
	};

	const extraColumns = [ previousCategoryColumn, actionsColumn ];

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
		</div>
	);
}
