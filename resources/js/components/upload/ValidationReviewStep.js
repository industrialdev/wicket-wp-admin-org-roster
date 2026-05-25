/**
 * Upload validation review step — AORM-8.2.
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
 * Table content within each panel is added in AORM-8.3 – 8.10.
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

import { useState, useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Notice, Panel, PanelBody, Spinner } from '@wordpress/components';

import { apiFetch } from '../../utils/apiFetch';
import ReadyToSyncPanel from './ReadyToSyncPanel';

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
	}, [ sessionId ] );

	// ── Render ────────────────────────────────────────────────────────────────

	return (
		<div className="aorm-wizard-step aorm-wizard-step--validation-review">

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
									/* AORM-8.4: Session header + tables for the Ready to Sync panel. */
									<ReadyToSyncPanel
										records={ bucket.records ?? [] }
										actionType={ actionType }
										fileName={ selectedFile?.name ?? null }
									/>
								) : (
									/*
									 * Table content for other categories is added in AORM-8.7 – 8.10.
									 * Render a count summary so the accordion is not empty.
									 */
									<p className="aorm-validation-review__panel-placeholder">
										{ bucket.count === 0
											? __( 'No records in this category.', 'wicket-aorm' )
											: sprintf(
												/* translators: %d: number of records */
												__( '%d record(s) in this category.', 'wicket-aorm' ),
												bucket.count
											) }
									</p>
								) }
							</PanelBody>
						);
					} ) }
				</Panel>
			) }

		</div>
	);
}
