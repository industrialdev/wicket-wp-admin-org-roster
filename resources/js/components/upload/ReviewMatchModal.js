/**
 * Review Match modal — AORM-8B.10.
 *
 * Opens when an admin clicks "See Details" on a merging_to_record row in the
 * Ready to Sync panel (AORM-8B.1), or "View Match" on a possible_match /
 * probable_match row (AORM-8B.5).
 *
 * AORM-8B.10: Modal scaffold using @wordpress/components Modal. Contains two
 * sections — an imported record summary (full panel built in AORM-8B.11) and
 * a matches section (full table built in AORM-8B.13, using the endpoint from
 * AORM-8B.12). The imported record section uses ImportedRecordSummary
 * (AORM-8B.11); the matches section uses MatchesTable (AORM-8B.13).
 *
 * AORM-8B.13: The stub matches section is replaced with the MatchesTable
 * component, which fetches GET /wicket-aorm/v1/staged/{id}/matches and
 * renders a table with columns: Name/ID, Email, Location, Phone, Title,
 * Employer, Membership Status, MDP Link.
 *
 * AORM-8B.14: ReviewMatchModal passes rawData to MatchesTable so it can
 * highlight cells whose value matches the corresponding imported field.
 *
 * AORM-8B.15: Action RadioControl with four options — Create as New Record,
 * Manual Updates, Merge to Existing, Discard — rendered between the Matches
 * section and the footer. Initial selection is derived from the record's
 * record_status: merging_to_record defaults to ACTION_MERGE, all others
 * default to ACTION_CREATE_NEW. The selected action is held in local state;
 * wiring to the save endpoint happens in AORM-8B.18/19.
 *
 * AORM-8B.16: Merge sub-flow. When ACTION_MERGE is selected, a
 * "Select merge target" sub-section appears below the action radio, showing
 * one RadioControl option per match candidate (label = "Name (email)",
 * value = UUID). The selected target UUID is stored in
 * selectedMergeTargetUuid state (auto-initialised to the first candidate when
 * the Merge option is chosen and no target has been set yet). The match
 * candidates are shared from MatchesTable via the onMatchesLoaded callback so
 * no second fetch is needed. Exports MERGE_TARGET_HEADING and
 * MERGE_TARGET_SECTION_CLASS constants.
 *
 * AORM-8B.20: Cancel button and the modal's built-in close (×) button both
 * call onClose without making any state changes.
 *
 * @param {{
 *   record:   Object|null,  — the staged record being reviewed; null = modal closed
 *   onClose:  () => void,  — callback to dismiss the modal
 * }} props
 */

import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Modal, RadioControl } from '@wordpress/components';
import ImportedRecordSummary from './ImportedRecordSummary';
import MatchesTable from './MatchesTable';

// ── Constants ─────────────────────────────────────────────────────────────────

/**
 * The modal title string.
 * Exported so test assertions can reference the same value without duplication.
 *
 * @type {string}
 */
export const MODAL_TITLE = __( 'Review Match', 'wicket-aorm' );

/**
 * Heading for the imported record summary section (AORM-8B.11).
 *
 * @type {string}
 */
export const IMPORTED_RECORD_HEADING = __( 'Imported Record', 'wicket-aorm' );

/**
 * Heading for the matches section (AORM-8B.13).
 *
 * @type {string}
 */
export const MATCHES_HEADING = __( 'Matches', 'wicket-aorm' );

// ── Action constants (AORM-8B.15) ─────────────────────────────────────────────

/**
 * Heading for the action selection section (AORM-8B.15).
 *
 * @type {string}
 */
export const ACTION_HEADING = __( 'Action', 'wicket-aorm' );

/**
 * Action value: create a brand-new MDP person from the imported record.
 *
 * @type {string}
 */
export const ACTION_CREATE_NEW = 'create_new_record';

/**
 * Action value: move the record to Manual Updates for offline resolution.
 *
 * @type {string}
 */
export const ACTION_MANUAL_UPDATE = 'manual_update';

/**
 * Action value: merge the imported record into a selected existing MDP person.
 *
 * @type {string}
 */
export const ACTION_MERGE = 'merge_to_existing';

/**
 * Action value: discard the record entirely.
 *
 * @type {string}
 */
export const ACTION_DISCARD = 'discard';

/**
 * Ordered action options for the RadioControl.
 * Exported so tests can assert labels and values without string duplication.
 *
 * @type {Array<{label: string, value: string}>}
 */
export const ACTION_OPTIONS = [
	{ label: __( 'Create as New Record', 'wicket-aorm' ), value: ACTION_CREATE_NEW },
	{ label: __( 'Manual Updates', 'wicket-aorm' ),       value: ACTION_MANUAL_UPDATE },
	{ label: __( 'Merge to Existing', 'wicket-aorm' ),    value: ACTION_MERGE },
	{ label: __( 'Discard', 'wicket-aorm' ),              value: ACTION_DISCARD },
];

// ── Merge sub-flow constants (AORM-8B.16) ─────────────────────────────────────

/**
 * Heading for the merge target selection sub-section (AORM-8B.16).
 *
 * @type {string}
 */
export const MERGE_TARGET_HEADING = __( 'Select merge target', 'wicket-aorm' );

/**
 * CSS class on the merge target selection section element (AORM-8B.16).
 * Exported so test assertions can locate the section without coupling to
 * markup structure.
 *
 * @type {string}
 */
export const MERGE_TARGET_SECTION_CLASS = 'aorm-review-match-modal__merge-target';

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Derive the initial RadioControl selection from a record's record_status.
 * merging_to_record records default to ACTION_MERGE; everything else defaults
 * to ACTION_CREATE_NEW.
 *
 * @param {Object} record Staged record object.
 * @return {string} Initial action value.
 */
function defaultAction( record ) {
	return record?.record_status === 'merging_to_record'
		? ACTION_MERGE
		: ACTION_CREATE_NEW;
}

/**
 * Build RadioControl options from a matches array.
 * Label format: "Full Name (email)" — falls back gracefully when either
 * field is absent.
 *
 * @param {Object[]} matches Array of match candidate objects from the API.
 * @return {Array<{label: string, value: string}>}
 */
function buildMergeTargetOptions( matches ) {
	return matches.map( ( match ) => {
		const name  = match.full_name    || '';
		const email = match.primary_email || '';

		let label;
		if ( name && email ) {
			label = `${ name } (${ email })`;
		} else if ( name ) {
			label = name;
		} else if ( email ) {
			label = email;
		} else {
			label = match.uuid;
		}

		return { label, value: match.uuid };
	} );
}

// ── Component ─────────────────────────────────────────────────────────────────

export default function ReviewMatchModal( { record, onClose } ) {
	const [ selectedAction, setSelectedAction ] = useState(
		() => defaultAction( record )
	);

	/** Match candidates shared from MatchesTable via onMatchesLoaded (AORM-8B.16). */
	const [ loadedMatches, setLoadedMatches ] = useState( [] );

	/**
	 * UUID of the match candidate selected as the merge target (AORM-8B.16).
	 * null until the admin selects one (or auto-initialised on first load).
	 */
	const [ selectedMergeTargetUuid, setSelectedMergeTargetUuid ] = useState( null );

	if ( ! record ) {
		return null;
	}

	const rawData = record.raw_data ?? {};

	/**
	 * Called by MatchesTable once its fetch completes (AORM-8B.16).
	 * Stores the candidates so the merge sub-flow can render without a
	 * second API call. Auto-selects the first candidate when the current
	 * action is ACTION_MERGE and no target is chosen yet.
	 *
	 * @param {Object[]} matches
	 */
	function handleMatchesLoaded( matches ) {
		setLoadedMatches( matches );

		if (
			selectedAction === ACTION_MERGE &&
			! selectedMergeTargetUuid &&
			matches.length > 0
		) {
			setSelectedMergeTargetUuid( matches[ 0 ].uuid );
		}
	}

	/**
	 * Handle action radio change. When the admin switches to ACTION_MERGE and
	 * no merge target is set yet, auto-select the first loaded candidate so the
	 * sub-section is immediately in a valid state.
	 *
	 * @param {string} value
	 */
	function handleActionChange( value ) {
		setSelectedAction( value );

		if (
			value === ACTION_MERGE &&
			! selectedMergeTargetUuid &&
			loadedMatches.length > 0
		) {
			setSelectedMergeTargetUuid( loadedMatches[ 0 ].uuid );
		}
	}

	const mergeTargetOptions = buildMergeTargetOptions( loadedMatches );

	return (
		<Modal
			title={ MODAL_TITLE }
			onRequestClose={ onClose }
			className="aorm-review-match-modal"
		>

			{ /* ── Imported Record summary ──────────────────────────────────────
			     AORM-8B.11: ImportedRecordSummary renders full name, email,
			     phone, and title drawn from the staged record's raw_data. */ }
			<section
				className="aorm-review-match-modal__imported-record"
				aria-labelledby="aorm-review-match-imported-heading"
			>
				<h3
					id="aorm-review-match-imported-heading"
					className="aorm-review-match-modal__section-heading"
				>
					{ IMPORTED_RECORD_HEADING }
				</h3>

				<ImportedRecordSummary rawData={ rawData } />
			</section>

			{ /* ── Matches section ───────────────────────────────────────────────
			     AORM-8B.13: MatchesTable fetches GET /wicket-aorm/v1/staged/{id}/matches
			     (AORM-8B.12 endpoint) and renders columns: Name/ID, Email,
			     Location, Phone, Title, Employer, Membership Status, MDP Link.
			     AORM-8B.16: onMatchesLoaded shares the fetched candidates with
			     the merge sub-flow below so no second fetch is needed. */ }
			<section
				className="aorm-review-match-modal__matches"
				aria-labelledby="aorm-review-match-matches-heading"
			>
				<h3
					id="aorm-review-match-matches-heading"
					className="aorm-review-match-modal__section-heading"
				>
					{ MATCHES_HEADING }
				</h3>

				<MatchesTable
					recordId={ record.id }
					rawData={ rawData }
					onMatchesLoaded={ handleMatchesLoaded }
				/>
			</section>

			{ /* ── Action selection (AORM-8B.15) ─────────────────────────────────
			     RadioControl lets the admin choose what to do with this record:
			     Create as New Record, Manual Updates, Merge to Existing, or Discard.
			     Save behaviour wired in AORM-8B.19. */ }
			<section
				className="aorm-review-match-modal__action"
				aria-labelledby="aorm-review-match-action-heading"
			>
				<h3
					id="aorm-review-match-action-heading"
					className="aorm-review-match-modal__section-heading"
				>
					{ ACTION_HEADING }
				</h3>

				<RadioControl
					className="aorm-review-match-modal__action-radio"
					selected={ selectedAction }
					options={ ACTION_OPTIONS }
					onChange={ handleActionChange }
				/>
			</section>

			{ /* ── Merge target selector (AORM-8B.16) ────────────────────────────
			     Rendered only when ACTION_MERGE is selected. Shows one radio
			     button per match candidate. The selected UUID is used by the
			     save flow in AORM-8B.18/19. */ }
			{ selectedAction === ACTION_MERGE && (
				<section
					className={ MERGE_TARGET_SECTION_CLASS }
					aria-labelledby="aorm-review-match-merge-target-heading"
				>
					<h3
						id="aorm-review-match-merge-target-heading"
						className="aorm-review-match-modal__section-heading"
					>
						{ MERGE_TARGET_HEADING }
					</h3>

					{ mergeTargetOptions.length > 0 ? (
						<RadioControl
							className="aorm-review-match-modal__merge-target-radio"
							selected={ selectedMergeTargetUuid ?? '' }
							options={ mergeTargetOptions }
							onChange={ setSelectedMergeTargetUuid }
						/>
					) : (
						<p className="aorm-review-match-modal__merge-target-empty">
							{ __( 'No match candidates available.', 'wicket-aorm' ) }
						</p>
					) }
				</section>
			) }

			{ /* ── Footer ────────────────────────────────────────────────────────
			     AORM-8B.20: Cancel closes the modal without any state changes.
			     Save Update button and wiring added in AORM-8B.19. */ }
			<div className="aorm-review-match-modal__footer">
				<Button
					variant="secondary"
					onClick={ onClose }
					className="aorm-review-match-modal__cancel"
				>
					{ __( 'Cancel', 'wicket-aorm' ) }
				</Button>
			</div>

		</Modal>
	);
}
