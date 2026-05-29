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

// ── Component ─────────────────────────────────────────────────────────────────

export default function ReviewMatchModal( { record, onClose } ) {
	const [ selectedAction, setSelectedAction ] = useState(
		() => defaultAction( record )
	);

	if ( ! record ) {
		return null;
	}

	const rawData = record.raw_data ?? {};

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
			     Location, Phone, Title, Employer, Membership Status, MDP Link. */ }
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

				<MatchesTable recordId={ record.id } rawData={ rawData } />
			</section>

			{ /* ── Action selection (AORM-8B.15) ─────────────────────────────────
			     RadioControl lets the admin choose what to do with this record:
			     Create as New Record, Manual Updates, Merge to Existing, or Discard.
			     The merge sub-flow (AORM-8B.16) and save behaviour (AORM-8B.19)
			     are wired up in subsequent tickets. */ }
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
					onChange={ ( value ) => setSelectedAction( value ) }
				/>
			</section>

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
