/**
 * Ready to Sync accordion panel content — AORM-8.4 / 8.5 / 8.6 / 8B.1.
 *
 * Renders the full content area for the "Ready to Sync" PanelBody in the
 * ValidationReviewStep accordion.
 *
 * AORM-8.4: Session header — shows the uploaded file name (when available)
 * and the action type label ("Add to Roster" or "Replace Roster").
 *
 * AORM-8.5: "Records being added" table (New Record, Exact Match,
 * Merging to Record, Already on Roster) — added in AORM-8.5.
 *
 * AORM-8.6: "Records being removed" table (Remove Existing Record,
 * Replace mode only) — added in AORM-8.6.
 *
 * AORM-8B.1: Per-row actions in the "Records being added" table:
 *   - Discard         — all added records; calls PATCH staged-records/{id} (AORM-8B.8)
 *   - See Details     — merging_to_record only; opens Review Match modal (AORM-8B.10)
 *   - View in MDP     — exact_match / already_on_roster; opens MDP person page
 *
 * @param {{
 *   records:              Array<Object>,  — all ready_to_sync staged records
 *   actionType:           string,        — 'add' | 'replace' from the staged API response
 *   fileName:             string|null,   — original CSV file name (null after a page reload)
 *   onOpenReviewModal?:   (record: Object) => void, — callback for See Details (AORM-8B.10)
 *   onRecordDiscarded?:   () => void,    — called after a successful Discard to refresh data
 * }} props
 */

import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, ExternalLink, Notice } from '@wordpress/components';
import { apiFetch } from '../../utils/apiFetch';
import RecordsTable from './RecordsTable';

// ── Constants ─────────────────────────────────────────────────────────────────

/**
 * Human-readable labels for the action_type values returned by the
 * GET /wicket-aorm/v1/uploads/{session_id}/staged endpoint.
 *
 * Used by the session header (AORM-8.4) and available to tests for
 * display-string assertions.
 *
 * @type {Record<string, string>}
 */
export const ACTION_TYPE_LABELS = {
	add:     __( 'Add to Roster',   'wicket-aorm' ),
	replace: __( 'Replace Roster', 'wicket-aorm' ),
};

/**
 * Human-readable labels for record_status values surfaced in the
 * "Records being added" table (AORM-8.5).
 *
 * Exported so tests can assert display strings without duplicating them.
 *
 * @type {Record<string, string>}
 */
export const RECORD_STATUS_LABELS = {
	new_record:        __( 'New Record',        'wicket-aorm' ),
	exact_match:       __( 'Exact Match',       'wicket-aorm' ),
	merging_to_record: __( 'Merging to Record', 'wicket-aorm' ),
	already_on_roster: __( 'Already on Roster', 'wicket-aorm' ),
};

/**
 * record_status values that belong in the "Records being added" table.
 * Excludes 'remove_existing', which belongs in the AORM-8.6 removal table.
 *
 * @type {string[]}
 */
export const ADDED_STATUSES = Object.keys( RECORD_STATUS_LABELS );

/**
 * record_status values that belong in the "Records being removed" table
 * (AORM-8.6, replace mode only).
 *
 * @type {string[]}
 */
export const REMOVED_STATUSES = [ 'remove_existing' ];

/**
 * record_status values whose rows show a "See Details" button (AORM-8B.1).
 * These records have a probable or possible MDP match requiring admin review
 * via the Review Match modal (built in AORM-8B.10).
 *
 * @type {string[]}
 */
export const SEE_DETAILS_STATUSES = [ 'merging_to_record' ];

/**
 * record_status values whose rows show a "View in MDP" button (AORM-8B.1).
 * These records are confirmed matches in MDP and can be opened directly.
 *
 * @type {string[]}
 */
export const VIEW_IN_MDP_STATUSES = [ 'exact_match', 'already_on_roster' ];

// ── Component ─────────────────────────────────────────────────────────────────

export default function ReadyToSyncPanel( {
	records,
	actionType,
	fileName,
	onOpenReviewModal,
	onRecordDiscarded,
} ) {
	const [ discardingIds, setDiscardingIds ] = useState( new Set() );
	const [ discardError,  setDiscardError  ] = useState( null );

	const actionLabel = ACTION_TYPE_LABELS[ actionType ] ?? actionType;

	// ── Discard handler ──────────────────────────────────────────────────────

	/**
	 * Move a single "added" record to the Discard category via PATCH
	 * /wicket-aorm/v1/staged-records/{id} (endpoint built in AORM-8B.8).
	 *
	 * @param {number} recordId
	 */
	function handleDiscard( recordId ) {
		setDiscardingIds( ( prev ) => new Set( [ ...prev, recordId ] ) );
		setDiscardError( null );

		apiFetch( {
			path:   `/wicket-aorm/v1/staged-records/${ recordId }`,
			method: 'PATCH',
			data:   { category: 'discard' },
		} )
			.then( () => {
				setDiscardingIds( ( prev ) => {
					const next = new Set( prev );
					next.delete( recordId );

					return next;
				} );
				onRecordDiscarded?.();
			} )
			.catch( ( err ) => {
				setDiscardingIds( ( prev ) => {
					const next = new Set( prev );
					next.delete( recordId );

					return next;
				} );
				setDiscardError(
					err?.message ??
						__( 'Failed to discard record. Please try again.', 'wicket-aorm' )
				);
			} );
	}

	// ── AORM-8.5: Derive "Records being added" subset ────────────────────────

	const addedRecords = records.filter(
		( r ) => ADDED_STATUSES.includes( r.record_status )
	);

	// ── AORM-8B.1: Build the "Actions" extra column ───────────────────────────

	/**
	 * Renders per-row action buttons for the "Records being added" table.
	 *
	 * Actions by record_status:
	 *   ALL added statuses   → Discard (PATCH to discard category, built in AORM-8B.8)
	 *   merging_to_record    → See Details (opens Review Match modal, AORM-8B.10)
	 *   exact_match          → View in MDP (external link using matched_persons[0].uuid)
	 *   already_on_roster    → View in MDP (external link using matched_persons[0].uuid)
	 */
	const actionsColumn = {
		key:   'actions',
		label: __( 'Actions', 'wicket-aorm' ),
		render: ( record ) => {
			const isDiscarding = discardingIds.has( record.id );
			const appEndpoint  = String( window.aormContext?.appEndpoint ?? '' ).replace( /\/$/, '' );
			const personUuid   = record.matched_persons?.[ 0 ]?.uuid ?? '';
			const mdpUrl = appEndpoint && personUuid
				? `${ appEndpoint }/people/${ personUuid }`
				: '';

			return (
				<div className="aorm-rts-row-actions">

					{ /* Discard — all added records (AORM-8B.8 endpoint required) */ }
					<Button
						variant="tertiary"
						isDestructive
						isBusy={ isDiscarding }
						disabled={ isDiscarding }
						onClick={ () => handleDiscard( record.id ) }
						className="aorm-rts-row-actions__discard"
						aria-label={ sprintf(
							/* translators: %s: person first name */
							__( 'Discard record for %s', 'wicket-aorm' ),
							record.raw_data?.first_name ?? record.id
						) }
					>
						{ __( 'Discard', 'wicket-aorm' ) }
					</Button>

					{ /* See Details — merging_to_record only (opens AORM-8B.10 modal) */ }
					{ SEE_DETAILS_STATUSES.includes( record.record_status ) && (
						<Button
							variant="secondary"
							onClick={ () => onOpenReviewModal?.( record ) }
							className="aorm-rts-row-actions__see-details"
						>
							{ __( 'See Details', 'wicket-aorm' ) }
						</Button>
					) }

					{ /* View in MDP — exact_match / already_on_roster only */ }
					{ VIEW_IN_MDP_STATUSES.includes( record.record_status ) && mdpUrl && (
						<ExternalLink
							href={ mdpUrl }
							className="aorm-rts-row-actions__view-in-mdp"
						>
							{ __( 'View in MDP', 'wicket-aorm' ) }
						</ExternalLink>
					) }

				</div>
			);
		},
	};

	/**
	 * Extra columns for the "Records being added" table:
	 *   1. Status column (human-readable record_status label)
	 *   2. Actions column (per-row action buttons — AORM-8B.1)
	 */
	const addedExtraColumns = [
		{
			key:    'record_status',
			label:  __( 'Status', 'wicket-aorm' ),
			render: ( record ) =>
				RECORD_STATUS_LABELS[ record.record_status ] ?? record.record_status,
		},
		actionsColumn,
	];

	// ── AORM-8.6: Derive "Records being removed" subset (replace mode only) ──

	const removedRecords = records.filter(
		( r ) => REMOVED_STATUSES.includes( r.record_status )
	);

	return (
		<div className="aorm-ready-to-sync-panel">

			{ /* AORM-8.4: Session header */ }
			<div className="aorm-ready-to-sync-panel__header">
				<dl className="aorm-ready-to-sync-panel__header-details">

					{ fileName && (
						<>
							<dt className="aorm-ready-to-sync-panel__header-term">
								{ __( 'File', 'wicket-aorm' ) }
							</dt>
							<dd className="aorm-ready-to-sync-panel__header-value">
								{ fileName }
							</dd>
						</>
					) }

					<dt className="aorm-ready-to-sync-panel__header-term">
						{ __( 'Action', 'wicket-aorm' ) }
					</dt>
					<dd className="aorm-ready-to-sync-panel__header-value">
						{ actionLabel }
					</dd>

				</dl>
			</div>

			{ /* AORM-8B.1: Discard error notice */ }
			{ discardError && (
				<Notice
					status="error"
					isDismissible
					onRemove={ () => setDiscardError( null ) }
					className="aorm-ready-to-sync-panel__discard-error"
				>
					{ discardError }
				</Notice>
			) }

			{ /* AORM-8.5: "Records being added" table (with AORM-8B.1 actions) */ }
			<div className="aorm-ready-to-sync-panel__section aorm-ready-to-sync-panel__section--added">
				<h3 className="aorm-ready-to-sync-panel__section-heading">
					{ __( 'Records being added', 'wicket-aorm' ) }
				</h3>
				<RecordsTable
					records={ addedRecords }
					extraColumns={ addedExtraColumns }
					noRecordsText={ __( 'No records to add.', 'wicket-aorm' ) }
				/>
			</div>

			{ /* AORM-8.6: "Records being removed" table (replace mode only) */ }
			{ actionType === 'replace' && (
				<div className="aorm-ready-to-sync-panel__section aorm-ready-to-sync-panel__section--removed">
					<h3 className="aorm-ready-to-sync-panel__section-heading">
						{ __( 'Records being removed', 'wicket-aorm' ) }
					</h3>
					<RecordsTable
						records={ removedRecords }
						noRecordsText={ __( 'No records to remove.', 'wicket-aorm' ) }
					/>
				</div>
			) }

		</div>
	);
}
