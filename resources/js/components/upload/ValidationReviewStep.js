/**
 * Upload validation review step — AORM-8.2 / AORM-8B.1 / AORM-8B.6.
 *
 * Fetches the categorised staged records for the active upload session from
 * GET /wicket-aorm/v1/uploads/{sessionId}/staged (built in AORM-8.1) and
 * renders a @wordpress/components Panel accordion with one PanelBody per
 * category. Each panel title includes the record count.
 *
 * Categories (fixed display order):
 *   1. ready_to_sync   — Ready to Sync
 *   2. possible_match  — Possible Match
 *   3. probable_match  — Probable Match
 *   4. manual_update   — Manual Updates
 *   5. discard         — Discard
 *
 * AORM-8.4: The Ready to Sync panel renders a session header (via
 * ReadyToSyncPanel) showing the uploaded file name and action type.
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
 * @param {{
 *   goToStep:       (step: string) => void,
 *   orgUuid:        string,
 *   membershipUuid: string,
 *   sessionId:      string|null,
 *   uploadAction:   string,
 *   selectedFile:   File|null,
 * }} props
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Notice, Panel, PanelBody, Spinner } from '@wordpress/components';

import { apiFetch } from '../../utils/apiFetch';
import DiscardPanel from './DiscardPanel';
import ManualUpdatePanel from './ManualUpdatePanel';
import PossibleMatchPanel from './PossibleMatchPanel';
import ProbableMatchPanel from './ProbableMatchPanel';
import ReadyToSyncPanel from './ReadyToSyncPanel';
import ReviewMatchModal from './ReviewMatchModal';

// ── Constants ─────────────────────────────────────────────────────────────────

/**
 * Fixed display order for the accordion panels.
 * Matches the order specified in the AORM-8 ticket acceptance criteria.
 *
 * @type {string[]}
 */
export const CATEGORY_ORDER = [
	'ready_to_sync',
	'possible_match',
	'probable_match',
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

export default function ValidationReviewStep( { sessionId, selectedFile } ) {
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

			{ /* AORM-8B.10: Review Match modal — rendered when reviewRecord is set */ }
			{ reviewRecord && (
				<ReviewMatchModal
					record={ reviewRecord }
					onClose={ closeReviewModal }
				/>
			) }

			<h2 className="aorm-validation-review__heading">
				{ __( 'Review Upload', 'wicket-aorm' ) }
			</h2>

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
					{ error }
				</Notice>
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
										fileName={ selectedFile?.name ?? null }
										sessionId={ sessionId }
										onRecordDiscarded={ refetchStaged }
										onOpenReviewModal={ openReviewModal }
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
