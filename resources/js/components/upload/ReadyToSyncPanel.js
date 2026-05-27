/**
 * Ready to Sync accordion panel content — AORM-8.4 / 8.5 / 8.6 / 8B.1 / 8B.2 / 8B.3 / 8B.4.
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
 * AORM-8B.2: Bulk actions in the "Records being added" table:
 *   - CheckboxControl on every row (plus a "select all" header checkbox).
 *   - Bulk toolbar appears when one or more rows are selected, offering:
 *       - "Sync to MDP" — fires onSyncSelected(ids) prop (wired in AORM-9).
 *       - "Discard"     — calls PATCH staged-records/{id} for every selected
 *                         record in parallel via Promise.allSettled; shows a
 *                         partial-failure Notice and keeps failed IDs selected.
 *
 * AORM-8B.3: Per-row actions in the "Records being removed" table:
 *   - View in MDP     — all remove_existing records; ExternalLink to
 *                       {appEndpoint}/people/{raw_data.person_uuid}. Omitted
 *                       when appEndpoint or person_uuid is unavailable.
 *   - Discard Removal — all remove_existing records; calls PATCH
 *                       staged-records/{id} with {category:'discard'} to
 *                       remove the row from the removal list. Uses
 *                       `discardingRemovedIds` Set state to track in-flight
 *                       requests; dismissible Notice surfaces errors. Calls
 *                       onRecordDiscarded?() on success.
 *
 * @param {{
 *   records:              Array<Object>,  — all ready_to_sync staged records
 *   actionType:           string,        — 'add' | 'replace' from the staged API response
 *   fileName:             string|null,   — original CSV file name (null after a page reload)
 *   onOpenReviewModal?:   (record: Object) => void, — callback for See Details (AORM-8B.10)
 *   onRecordDiscarded?:   () => void,    — called after a successful Discard to refresh data
 *   onSyncSelected?:      (ids: number[]) => void,  — bulk Sync to MDP (AORM-9 stub)
 *   sessionId?:           string|null,             — session UUID; enables AORM-8B.4 replacements fetch
 * }} props
 *
 * AORM-8B.4: When sessionId is provided and actionType === 'replace', the
 * "Records being removed" table is populated by fetching
 * GET /wicket-aorm/v1/uploads/{sessionId}/replacements rather than filtering
 * the records prop. The component maintains its own removalRecords /
 * isLoadingRemovals / removalFetchError state and re-fetches (via
 * removalRefetchKey) after each successful Discard Removal action.
 */

import { useState, useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, ExternalLink, Notice, Spinner } from '@wordpress/components';
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
 * record_status values whose rows in the "Records being removed" table show
 * a "View in MDP" link (AORM-8B.3).
 *
 * The MDP URL is built from raw_data.person_uuid (matched_persons is null
 * for remove_existing rows — they are synthetic rows inserted by the
 * matching job, not matched against an uploaded CSV row).
 *
 * @type {string[]}
 */
export const VIEW_IN_MDP_REMOVAL_STATUSES = [ 'remove_existing' ];

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
	sessionId,
	onOpenReviewModal,
	onRecordDiscarded,
	onSyncSelected,
} ) {
	const [ discardingIds,       setDiscardingIds       ] = useState( new Set() );
	const [ discardError,        setDiscardError        ] = useState( null );

	// ── AORM-8B.2: Bulk selection + bulk action state ────────────────────────
	const [ selectedAddedIds, setSelectedAddedIds ] = useState( new Set() );
	const [ isBulkDiscarding, setIsBulkDiscarding ] = useState( false );
	const [ bulkDiscardError, setBulkDiscardError ] = useState( null );

	// ── AORM-8B.3: Removal table discard state ───────────────────────────────
	const [ discardingRemovedIds, setDiscardingRemovedIds ] = useState( new Set() );
	const [ discardRemovedError,  setDiscardRemovedError  ] = useState( null );

	// ── AORM-8B.4: Replacements endpoint state ───────────────────────────────
	/** Records fetched from GET /uploads/{sessionId}/replacements */
	const [ removalRecords,      setRemovalRecords      ] = useState( null );
	const [ isLoadingRemovals,   setIsLoadingRemovals   ] = useState( false );
	const [ removalFetchError,   setRemovalFetchError   ] = useState( null );
	/**
	 * Incrementing key used to re-trigger the replacements fetch after a
	 * successful Discard Removal action without changing sessionId.
	 */
	const [ removalRefetchKey, setRemovalRefetchKey ] = useState( 0 );

	/**
	 * AORM-8B.4: Fetch remove_existing records from the dedicated replacements
	 * endpoint whenever we are in replace mode and have a valid sessionId.
	 * Re-runs when removalRefetchKey is incremented (post-discard refresh).
	 */
	useEffect( () => {
		if ( actionType !== 'replace' || ! sessionId ) {
			return;
		}

		let cancelled = false;

		setIsLoadingRemovals( true );
		setRemovalFetchError( null );

		apiFetch( { path: `/wicket-aorm/v1/uploads/${ sessionId }/replacements` } )
			.then( ( response ) => {
				if ( ! cancelled ) {
					setRemovalRecords( response?.records ?? [] );
					setIsLoadingRemovals( false );
				}
			} )
			.catch( ( err ) => {
				if ( ! cancelled ) {
					setRemovalFetchError(
						err?.message ??
							__( 'Could not load removal records. Please refresh the page.', 'wicket-aorm' )
					);
					setIsLoadingRemovals( false );
				}
			} );

		return () => {
			cancelled = true;
		};
	}, [ actionType, sessionId, removalRefetchKey ] );

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

	// ── AORM-8B.2: Bulk discard handler ─────────────────────────────────────

	/**
	 * Discard all currently-selected "added" records in parallel.
	 *
	 * Uses Promise.allSettled so a partial failure does not abort the rest.
	 * On complete failure or partial failure: surfaced via bulkDiscardError,
	 * and the selection is narrowed to only the IDs that failed so the admin
	 * can retry. On full success: selection is cleared and onRecordDiscarded
	 * is called to refresh the parent's staged-records data.
	 */
	function handleBulkDiscard() {
		const ids = Array.from( selectedAddedIds );

		setIsBulkDiscarding( true );
		setBulkDiscardError( null );

		Promise.allSettled(
			ids.map( ( id ) =>
				apiFetch( {
					path:   `/wicket-aorm/v1/staged-records/${ id }`,
					method: 'PATCH',
					data:   { category: 'discard' },
				} )
			)
		).then( ( results ) => {
			setIsBulkDiscarding( false );

			const failedIds = results
				.map( ( r, i ) => ( r.status === 'rejected' ? ids[ i ] : null ) )
				.filter( ( id ) => id !== null );

			if ( failedIds.length > 0 ) {
				setBulkDiscardError(
					sprintf(
						/* translators: %d: number of records that could not be discarded */
						__( '%d record(s) could not be discarded. Please try again.', 'wicket-aorm' ),
						failedIds.length
					)
				);
				setSelectedAddedIds( new Set( failedIds ) );
			} else {
				setSelectedAddedIds( new Set() );
				onRecordDiscarded?.();
			}
		} );
	}

	// ── AORM-8B.3: Discard Removal handler ──────────────────────────────────

	/**
	 * Move a single "removed" record to the Discard category via PATCH
	 * /wicket-aorm/v1/staged-records/{id} (endpoint built in AORM-8B.8).
	 *
	 * Discarding a removal record means the person will NOT be removed from the
	 * roster during the replace-mode sync — effectively reinstating them.
	 *
	 * @param {number} recordId
	 */
	function handleDiscardRemoval( recordId ) {
		setDiscardingRemovedIds( ( prev ) => new Set( [ ...prev, recordId ] ) );
		setDiscardRemovedError( null );

		apiFetch( {
			path:   `/wicket-aorm/v1/staged-records/${ recordId }`,
			method: 'PATCH',
			data:   { category: 'discard' },
		} )
			.then( () => {
				setDiscardingRemovedIds( ( prev ) => {
					const next = new Set( prev );
					next.delete( recordId );

					return next;
				} );
				// Refresh the dedicated replacements fetch (AORM-8B.4)
				setRemovalRefetchKey( ( k ) => k + 1 );
				onRecordDiscarded?.();
			} )
			.catch( ( err ) => {
				setDiscardingRemovedIds( ( prev ) => {
					const next = new Set( prev );
					next.delete( recordId );

					return next;
				} );
				setDiscardRemovedError(
					err?.message ??
						__( 'Failed to discard removal. Please try again.', 'wicket-aorm' )
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

	// ── AORM-8B.3: Build the "Actions" extra column for the removal table ────

	/**
	 * Renders per-row action buttons for the "Records being removed" table.
	 *
	 * Actions for all remove_existing records:
	 *   View in MDP     — ExternalLink built from raw_data.person_uuid.
	 *                     Omitted when appEndpoint or person_uuid is missing.
	 *   Discard Removal — PATCH staged-records/{id} with {category:'discard'}
	 *                     (built in AORM-8B.8).
	 */
	const removedActionsColumn = {
		key:   'actions',
		label: __( 'Actions', 'wicket-aorm' ),
		render: ( record ) => {
			const isDiscarding = discardingRemovedIds.has( record.id );
			const appEndpoint  = String( window.aormContext?.appEndpoint ?? '' ).replace( /\/$/, '' );
			const personUuid   = record.raw_data?.person_uuid ?? '';
			const mdpUrl = appEndpoint && personUuid
				? `${ appEndpoint }/people/${ personUuid }`
				: '';

			return (
				<div className="aorm-rts-row-actions">

					{ /* View in MDP — all remove_existing records (AORM-8B.3) */ }
					{ VIEW_IN_MDP_REMOVAL_STATUSES.includes( record.record_status ) && mdpUrl && (
						<ExternalLink
							href={ mdpUrl }
							className="aorm-rts-row-actions__view-in-mdp-removal"
						>
							{ __( 'View in MDP', 'wicket-aorm' ) }
						</ExternalLink>
					) }

					{ /* Discard Removal — all remove_existing records (AORM-8B.8 endpoint) */ }
					<Button
						variant="tertiary"
						isDestructive
						isBusy={ isDiscarding }
						disabled={ isDiscarding }
						onClick={ () => handleDiscardRemoval( record.id ) }
						className="aorm-rts-row-actions__discard-removal"
						aria-label={ sprintf(
							/* translators: %s: person email or record id */
							__( 'Discard removal of %s', 'wicket-aorm' ),
							record.raw_data?.email ?? record.id
						) }
					>
						{ __( 'Discard Removal', 'wicket-aorm' ) }
					</Button>

				</div>
			);
		},
	};

	// ── AORM-8B.4: Resolve "Records being removed" from dedicated endpoint ────
	// When sessionId is provided, use the fetched removalRecords. Fall back to
	// filtering the records prop (e.g. when sessionId is unavailable in tests).

	const removedRecords = removalRecords !== null
		? removalRecords
		: records.filter( ( r ) => REMOVED_STATUSES.includes( r.record_status ) );

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

			{ /* AORM-8B.1: Per-row discard error notice */ }
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

			{ /* AORM-8B.2: Bulk discard error notice */ }
			{ bulkDiscardError && (
				<Notice
					status="error"
					isDismissible
					onRemove={ () => setBulkDiscardError( null ) }
					className="aorm-ready-to-sync-panel__bulk-discard-error"
				>
					{ bulkDiscardError }
				</Notice>
			) }

			{ /* AORM-8.5: "Records being added" table (with AORM-8B.1 actions + AORM-8B.2 bulk) */ }
			<div className="aorm-ready-to-sync-panel__section aorm-ready-to-sync-panel__section--added">
				<h3 className="aorm-ready-to-sync-panel__section-heading">
					{ __( 'Records being added', 'wicket-aorm' ) }
				</h3>

				{ /* AORM-8B.2: Bulk action toolbar — visible when rows are selected */ }
				{ selectedAddedIds.size > 0 && (
					<div
						className="aorm-rts-bulk-toolbar"
						role="toolbar"
						aria-label={ __( 'Bulk actions', 'wicket-aorm' ) }
					>
						<span className="aorm-rts-bulk-toolbar__count">
							{ sprintf(
								/* translators: %d: number of selected records */
								__( '%d selected', 'wicket-aorm' ),
								selectedAddedIds.size
							) }
						</span>

						{ /* Sync to MDP — fires onSyncSelected prop (wired in AORM-9) */ }
						<Button
							variant="primary"
							onClick={ () => onSyncSelected?.( Array.from( selectedAddedIds ) ) }
							className="aorm-rts-bulk-toolbar__sync"
						>
							{ __( 'Sync to MDP', 'wicket-aorm' ) }
						</Button>

						{ /* Bulk Discard */ }
						<Button
							variant="secondary"
							isDestructive
							isBusy={ isBulkDiscarding }
							disabled={ isBulkDiscarding }
							onClick={ handleBulkDiscard }
							className="aorm-rts-bulk-toolbar__discard"
						>
							{ __( 'Discard', 'wicket-aorm' ) }
						</Button>
					</div>
				) }

				<RecordsTable
					records={ addedRecords }
					extraColumns={ addedExtraColumns }
					noRecordsText={ __( 'No records to add.', 'wicket-aorm' ) }
					selectable
					selectedIds={ selectedAddedIds }
					onSelectionChange={ setSelectedAddedIds }
				/>
			</div>

			{ /* AORM-8B.3: Removal discard error notice */ }
			{ discardRemovedError && (
				<Notice
					status="error"
					isDismissible
					onRemove={ () => setDiscardRemovedError( null ) }
					className="aorm-ready-to-sync-panel__discard-removal-error"
				>
					{ discardRemovedError }
				</Notice>
			) }

			{ /* AORM-8.6: "Records being removed" table (replace mode only)    */ }
			{ /* AORM-8B.3: Actions column added — View in MDP, Discard Removal */ }
			{ /* AORM-8B.4: Table populated via GET /uploads/{id}/replacements   */ }
			{ actionType === 'replace' && (
				<div className="aorm-ready-to-sync-panel__section aorm-ready-to-sync-panel__section--removed">
					<h3 className="aorm-ready-to-sync-panel__section-heading">
						{ __( 'Records being removed', 'wicket-aorm' ) }
					</h3>

					{ /* AORM-8B.4: Loading state while fetching replacements */ }
					{ isLoadingRemovals && (
						<div className="aorm-ready-to-sync-panel__removals-loading">
							<Spinner />
						</div>
					) }

					{ /* AORM-8B.4: Error state if replacements fetch failed */ }
					{ removalFetchError && ! isLoadingRemovals && (
						<Notice
							status="error"
							isDismissible
							onRemove={ () => setRemovalFetchError( null ) }
							className="aorm-ready-to-sync-panel__removals-error"
						>
							{ removalFetchError }
						</Notice>
					) }

					{ ! isLoadingRemovals && ! removalFetchError && (
						<RecordsTable
							records={ removedRecords }
							extraColumns={ [ removedActionsColumn ] }
							noRecordsText={ __( 'No records to remove.', 'wicket-aorm' ) }
						/>
					) }
				</div>
			) }

		</div>
	);
}
