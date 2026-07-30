/**
 * Upload validation review step — AORM-8.2 / AORM-8B.1 / AORM-8B.6 / AORM-9.29.
 *
 * Fetches the categorised staged records for the active upload session from
 * GET /wicket-aorm/v1/uploads/{sessionId}/staged (built in AORM-8.1) and
 * renders a @wordpress/components Panel accordion with one PanelBody per
 * category. Each panel title includes the record count.
 *
 * Categories (fixed display order):
 *   1. ready_to_sync   — Ready to Sync
 *   2. probable_match  — Probable Match
 *   3. possible_match  — Possible Match
 *   4. manual_update   — Manual Updates
 *   5. discard         — Discard
 *
 * AORM-8.4: A session header showing the uploaded file name and action type
 * is rendered above the accordion (not inside the Ready to Sync panel) since
 * this information is general and applies to the whole upload session.
 * The action_type is read from the staged API response so it remains
 * accurate after a page reload (when selectedFile is no longer in state).
 *
 * AORM-8B.1: Passes onRecordDiscarded={refetchStaged} to ReadyToSyncPanel
 * so the accordion refreshes automatically after a per-row Discard action.
 * onOpenReviewModal is now wired (AORM-8B.10) so the Review Match modal opens
 * when "See Details" is clicked on a merging_to_record row.
 *
 * AORM-8B.5: Passes onOpenReviewModal to PossibleMatchPanel and
 * ProbableMatchPanel. Both panels render the "View Match" Actions column now
 * that onOpenReviewModal is a function (wired in AORM-8B.10).
 *
 * AORM-8B.10: Review Match modal wired. reviewRecord state holds the staged
 * record currently open in the modal (null = closed). openReviewModal(record)
 * sets it; closeReviewModal() clears it. ReviewMatchModal is rendered above
 * the accordion when reviewRecord is non-null.
 *
 * AORM-8B.6: Passes onRecordCategorized={refetchStaged} to ManualUpdatePanel
 * so the accordion counts update after per-row or bulk Reinstate/Remove
 * actions move records to a different category.
 *
 * Table content within each panel was added in AORM-8.3 – 8.10.
 *
 * AORM-9.29: handleSyncConfirmed fires POST /wicket-aorm/v1/uploads/{id}/commit
 * with the selected IDs, then navigates to the sync-progress step on success.
 * A commit error is surfaced as a dismissible Notice inside the confirm modal.
 *
 * AORM-9.31: An "Abandon Session" button opens AbandonSessionModal. Confirming
 * fires DELETE /wicket-aorm/v1/uploads/{sessionId} (the same endpoint used by
 * CsvValidationStep's "Start Fresh" flow) and then calls resetWizard() to
 * return to the landing step — this is the escape hatch for a session stuck
 * with unresolved Possible Match / Probable Match / Manual Update rows.
 *
 * A "Start Over" button is also rendered alongside the staged-records fetch
 * error Notice (most commonly "Upload session not found." — e.g. the session
 * was abandoned/completed-and-cleaned-up in another tab). Unlike Abandon
 * Session, this skips the DELETE call — the session is already unreachable
 * server-side — and calls resetWizard() directly so the admin always has a
 * way back to landing instead of being stuck on a dead error Notice.
 * resetWizard() is also threaded down to ReadyToSyncPanel so its own
 * replacements-fetch error (AORM-8B.4) can offer the same recovery path.
 *
 * @param {{
 *   goToStep:       (step: string) => void,
 *   resetWizard:    () => void,
 *   orgUuid:        string,
 *   membershipUuid: string,
 *   sessionId:      string|null,
 *   uploadAction:   string,
 *   selectedFile:   File|null,
 * }} props
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Notice, Panel, PanelBody, Spinner } from '@wordpress/components';

import { apiFetch } from '../../utils/apiFetch';
import { ACTION_TYPE_LABELS } from './ReadyToSyncPanel';
import AbandonSessionModal from './AbandonSessionModal';
import DiscardPanel from './DiscardPanel';
import ManualUpdatePanel from './ManualUpdatePanel';
import PossibleMatchPanel from './PossibleMatchPanel';
import ProbableMatchPanel from './ProbableMatchPanel';
import ReadyToSyncPanel from './ReadyToSyncPanel';
import ReviewMatchModal from './ReviewMatchModal';
import SyncConfirmModal from './SyncConfirmModal';

// ── Constants ─────────────────────────────────────────────────────────────────

/**
 * CSS class for the "Sync All (N)" button.
 * Exported so tests can assert presence without duplicating the class name.
 *
 * @type {string}
 */
export const SYNC_ALL_BTN_CLASS = 'aorm-validation-review__sync-all';

/**
 * CSS class for the "Start Over" button shown alongside the staged-records
 * fetch error Notice (e.g. "Upload session not found.").
 * Exported so tests can assert presence without duplicating the class name.
 *
 * @type {string}
 */
export const START_OVER_BTN_CLASS = 'aorm-validation-review__start-over';

/**
 * Fixed display order for the accordion panels.
 * Matches the order specified in the AORM-8 ticket acceptance criteria.
 *
 * @type {string[]}
 */
export const CATEGORY_ORDER = [
	'ready_to_sync',
	'probable_match',
	'possible_match',
	'manual_update',
	'discard',
];

/**
 * Human-readable labels for each category key.
 * Translatable via @wordpress/i18n.
 *
 * @type {Record<string, string>}
 */
export const CATEGORY_LABELS = {
	ready_to_sync:  __( 'Ready to Sync',  'wicket-aorm' ),
	possible_match: __( 'Possible Match', 'wicket-aorm' ),
	probable_match: __( 'Probable Match', 'wicket-aorm' ),
	manual_update:  __( 'Manual Updates', 'wicket-aorm' ),
	discard:        __( 'Discard',        'wicket-aorm' ),
};

// ── Component ─────────────────────────────────────────────────────────────────

export default function ValidationReviewStep( { sessionId, selectedFile, goToStep, resetWizard } ) {
	const [ categories, setCategories ] = useState( null );
	const [ actionType, setActionType ] = useState( null );
	const [ isLoading,  setIsLoading  ] = useState( false );
	const [ error,      setError      ] = useState( null );

	/**
	 * Incrementing key used to re-trigger the fetch effect without changing
	 * sessionId. Incremented by refetchStaged() after per-row actions such
	 * as Discard (AORM-8B.1) so the accordion counts update immediately.
	 */
	const [ refetchKey, setRefetchKey ] = useState( 0 );

	/**
	 * Trigger a fresh fetch of the staged records for the current session.
	 * Passed to ReadyToSyncPanel as onRecordDiscarded (AORM-8B.1).
	 */
	const refetchStaged = useCallback( () => {
		setRefetchKey( ( k ) => k + 1 );
	}, [] );

	// ── AORM-8B.10: Review Match modal state ─────────────────────────────────

	/**
	 * The staged record currently open in the Review Match modal.
	 * null when the modal is closed.
	 */
	const [ reviewRecord, setReviewRecord ] = useState( null );

	/**
	 * Open the Review Match modal for the given staged record.
	 * Passed to ReadyToSyncPanel (See Details — AORM-8B.1),
	 * PossibleMatchPanel (View Match — AORM-8B.5), and
	 * ProbableMatchPanel (View Match — AORM-8B.5).
	 *
	 * @param {Object} record — the staged record to review
	 */
	const openReviewModal = useCallback( ( record ) => {
		setReviewRecord( record );
	}, [] );

	/**
	 * Close the Review Match modal without making any state changes (AORM-8B.20).
	 */
	const closeReviewModal = useCallback( () => {
		setReviewRecord( null );
	}, [] );

	/**
	 * Called by ReviewMatchModal after a successful save (AORM-8B.19).
	 * Closes the modal and refreshes the accordion so category counts update.
	 */
	const handleModalResolved = useCallback( () => {
		setReviewRecord( null );
		refetchStaged();
	}, [ refetchStaged ] );

	// ── AORM-9.1: Sync confirmation modal state ───────────────────────────────

	/**
	 * IDs selected for sync — non-null when the SyncConfirmModal is open.
	 * null means the modal is closed.
	 *
	 * @type {number[]|null}
	 */
	const [ syncConfirmIds, setSyncConfirmIds ] = useState( null );

	/**
	 * Called when the admin clicks "Sync to MDP" in the bulk action toolbar.
	 * Opens the confirmation modal before committing anything to MDP.
	 *
	 * @param {number[]} ids — the staged record IDs selected for sync
	 */
	const handleSyncSelected = useCallback( ( ids ) => {
		setSyncConfirmIds( ids );
	}, [] );

	/**
	 * Dismiss the sync confirmation modal without starting a sync.
	 */
	const closeSyncConfirmModal = useCallback( () => {
		setSyncConfirmIds( null );
	}, [] );

	/**
	 * Sync all ready_to_sync records — opens the confirmation modal with the
	 * sentinel value 'all' so handleSyncConfirmed sends {ids:'all'} to the
	 * commit endpoint.
	 */
	const handleSyncAll = useCallback( () => {
		setSyncConfirmIds( 'all' );
	}, [] );

	/** Whether the commit POST is in-flight. */
	const [ isCommitting,   setIsCommitting   ] = useState( false );

	/** Error surfaced in the SyncConfirmModal while committing. */
	const [ commitError,    setCommitError    ] = useState( null );

	/**
	 * Admin confirmed the sync (AORM-9.29).
	 * Fires POST /wicket-aorm/v1/uploads/{sessionId}/commit, then
	 * navigates to sync-progress on success.
	 */
	const handleSyncConfirmed = useCallback( async () => {
		if ( ! sessionId || isCommitting ) {
			return;
		}

		setIsCommitting( true );
		setCommitError( null );

		const ids = syncConfirmIds ?? 'all';

		try {
			await apiFetch( {
				path:   `/wicket-aorm/v1/uploads/${ encodeURIComponent( sessionId ) }/commit`,
				method: 'POST',
				data:   { ids },
			} );

			setSyncConfirmIds( null );
			goToStep( 'sync-progress' );
		} catch ( err ) {
			setCommitError(
				err?.message ??
					__( 'Failed to start sync. Please try again.', 'wicket-aorm' )
			);
		} finally {
			setIsCommitting( false );
		}
	}, [ sessionId, syncConfirmIds, isCommitting, goToStep ] );

	// ── AORM-9.31: Abandon Session modal state ────────────────────────────────

	/** Whether the AbandonSessionModal confirmation dialog is open. */
	const [ isAbandonModalOpen, setIsAbandonModalOpen ] = useState( false );

	/** Whether the abandon DELETE request is in-flight. */
	const [ isAbandoning, setIsAbandoning ] = useState( false );

	/** Open the Abandon Session confirmation modal. */
	const openAbandonModal = useCallback( () => {
		setIsAbandonModalOpen( true );
	}, [] );

	/** Dismiss the modal without abandoning anything. */
	const closeAbandonModal = useCallback( () => {
		setIsAbandonModalOpen( false );
	}, [] );

	/**
	 * Admin confirmed the abandon action (AORM-9.31).
	 * Fires DELETE /wicket-aorm/v1/uploads/{sessionId} — errors are ignored,
	 * mirroring CsvValidationStep's abandonSession(): the endpoint is
	 * idempotent (deleting an already-cleared or unknown session is a no-op),
	 * and the admin should always be able to back out to a clean landing step.
	 */
	const handleAbandonConfirmed = useCallback( async () => {
		if ( ! sessionId || isAbandoning ) {
			return;
		}

		setIsAbandoning( true );

		try {
			await apiFetch( {
				path:   `/wicket-aorm/v1/uploads/${ encodeURIComponent( sessionId ) }`,
				method: 'DELETE',
			} );
		} catch {
			// Ignore — the session is being abandoned either way.
		}

		setIsAbandoning( false );
		setIsAbandonModalOpen( false );
		resetWizard?.();
	}, [ sessionId, isAbandoning, resetWizard ] );

	// ── Fetch staged records ──────────────────────────────────────────────────

	useEffect( () => {
		if ( ! sessionId ) {
			setError(
				__(
					'No upload session found. Please go back and upload a file.',
					'wicket-aorm'
				)
			);
			return;
		}

		setIsLoading( true );
		setError( null );

		apiFetch( {
			path: `/wicket-aorm/v1/uploads/${ encodeURIComponent( sessionId ) }/staged`,
		} )
			.then( ( response ) => {
				setCategories( response?.categories ?? null );
				setActionType( response?.action_type ?? null );
			} )
			.catch( ( err ) => {
				setError(
					err?.message ??
						__( 'Failed to load staged records.', 'wicket-aorm' )
				);
			} )
			.finally( () => {
				setIsLoading( false );
			} );
	}, [ sessionId, refetchKey ] );

	// ── Render ────────────────────────────────────────────────────────────────

	return (
		<div className="aorm-wizard-step aorm-wizard-step--validation-review">

			{ /* AORM-9.1: Sync confirmation modal — rendered when syncConfirmIds is set */ }
			{ syncConfirmIds !== null && (
				<SyncConfirmModal
					isOpen
					onConfirm={ handleSyncConfirmed }
					onClose={ closeSyncConfirmModal }
					isCommitting={ isCommitting }
					commitError={ commitError }
				/>
			) }

			{ /* AORM-8B.10: Review Match modal — rendered when reviewRecord is set */ }
			{ reviewRecord && (
				<ReviewMatchModal
					record={ reviewRecord }
					onClose={ closeReviewModal }
					onResolved={ handleModalResolved }
				/>
			) }

			{ /* AORM-9.31: Abandon Session confirmation modal */ }
			<AbandonSessionModal
				isOpen={ isAbandonModalOpen }
				onConfirm={ handleAbandonConfirmed }
				onClose={ closeAbandonModal }
				isAbandoning={ isAbandoning }
			/>

			<div className="aorm-validation-review__heading-row">
				<h2 className="aorm-validation-review__heading">
					{ __( 'Review Upload', 'wicket-aorm' ) }
				</h2>

				{ /* AORM-9.31: escape hatch for a session stuck with unresolved rows */ }
				{ sessionId && (
					<Button
						variant="secondary"
						isDestructive
						onClick={ openAbandonModal }
						className="aorm-validation-review__abandon-session"
					>
						{ __( 'Abandon Session', 'wicket-aorm' ) }
					</Button>
				) }
			</div>

			{ /* Loading state */ }
			{ isLoading && (
				<div
					className="aorm-validation-review__loading"
					aria-live="polite"
					aria-label={ __( 'Loading staged records', 'wicket-aorm' ) }
				>
					<Spinner />
					<p>{ __( 'Loading records…', 'wicket-aorm' ) }</p>
				</div>
			) }

			{ /* Error state */ }
			{ error && ! isLoading && (
				<Notice status="error" isDismissible={ false }>
					<p>{ error }</p>
					{ resetWizard && (
						<Button
							variant="secondary"
							className={ START_OVER_BTN_CLASS }
							onClick={ resetWizard }
						>
							{ __( 'Start Over', 'wicket-aorm' ) }
						</Button>
					) }
				</Notice>
			) }

			{ /* Session header — file name + action type (general, not category-specific) */ }
			{ actionType && (
				<div className="aorm-validation-review__session-header">
					<dl className="aorm-validation-review__session-header-details">

						{ selectedFile?.name && (
							<>
								<dt className="aorm-validation-review__session-header-term">
									{ __( 'File', 'wicket-aorm' ) }
								</dt>
								<dd className="aorm-validation-review__session-header-value">
									{ selectedFile.name }
								</dd>
							</>
						) }

						<dt className="aorm-validation-review__session-header-term">
							{ __( 'Action', 'wicket-aorm' ) }
						</dt>
						<dd className="aorm-validation-review__session-header-value">
							{ ACTION_TYPE_LABELS[ actionType ] ?? actionType }
						</dd>

					</dl>
				</div>
			) }

			{ /* Sync All button — shown when there are ready_to_sync records */ }
			{ ! isLoading && ! error && ( categories?.ready_to_sync?.count ?? 0 ) > 0 && (
				<div className="aorm-validation-review__sync-all-wrapper">
					<Button
						variant="primary"
						onClick={ handleSyncAll }
						className={ SYNC_ALL_BTN_CLASS }
					>
						{ sprintf(
							/* translators: %d: number of records ready to sync */
							__( 'Sync All Users (%d)', 'wicket-aorm' ),
							categories.ready_to_sync.count
						) }
					</Button>
				</div>
			) }

			{ /* Accordion — rendered once data arrives */ }
			{ ! isLoading && ! error && categories && (
				<Panel
					className="aorm-validation-review__accordion"
					header={ __( 'Upload categories', 'wicket-aorm' ) }
				>
					{ CATEGORY_ORDER.map( ( key ) => {
						const bucket = categories[ key ] ?? { count: 0, records: [] };
						const label  = CATEGORY_LABELS[ key ] ?? key;

						/*
						 * Panel title format: "Ready to Sync (5)"
						 * translators: 1: category label, 2: record count
						 */
						const title = sprintf(
							/* translators: 1: category label, 2: record count */
							__( '%1$s (%2$d)', 'wicket-aorm' ),
							label,
							bucket.count
						);

						return (
							<PanelBody
								key={ key }
								title={ title }
								initialOpen={ key === 'ready_to_sync' }
								className={ `aorm-validation-review__panel aorm-validation-review__panel--${ key }` }
							>
								{ key === 'ready_to_sync' ? (
									/* AORM-8.4: Session header + tables.
									 * AORM-8B.1: onRecordDiscarded re-fetches after Discard.
									 * AORM-8B.4: sessionId enables dedicated replacements fetch.
									 * AORM-8B.10: onOpenReviewModal opens Review Match modal. */
									<ReadyToSyncPanel
										records={ bucket.records ?? [] }
										actionType={ actionType }
										sessionId={ sessionId }
										onRecordDiscarded={ refetchStaged }
										onOpenReviewModal={ openReviewModal }
										onSyncSelected={ handleSyncSelected }
										resetWizard={ resetWizard }
									/>
								) : key === 'possible_match' ? (
									/* AORM-8.7: Possible Match panel — table with # Matches column.
									 * AORM-8B.5 / AORM-8B.10: onOpenReviewModal opens Review Match modal. */
									<PossibleMatchPanel
										records={ bucket.records ?? [] }
										onOpenReviewModal={ openReviewModal }
									/>
								) : key === 'probable_match' ? (
									/* AORM-8.8: Probable Match panel — table with # Matches column.
									 * AORM-8B.5 / AORM-8B.10: onOpenReviewModal opens Review Match modal. */
									<ProbableMatchPanel
										records={ bucket.records ?? [] }
										onOpenReviewModal={ openReviewModal }
									/>
								) : key === 'manual_update' ? (
									/* AORM-8.9: Manual Updates panel — table with Previous Category column.
									 * AORM-8B.6: onRecordCategorized re-fetches after Reinstate/Remove. */
									<ManualUpdatePanel
										records={ bucket.records ?? [] }
										onRecordCategorized={ refetchStaged }
									/>
								) : (
									/* AORM-8.10 / AORM-8B.7: Discard panel — table with Previous Category column + Reinstate actions. */
									<DiscardPanel
										records={ bucket.records ?? [] }
										onRecordCategorized={ refetchStaged }
									/>
								) }
							</PanelBody>
						);
					} ) }
				</Panel>
			) }

		</div>
	);
}
