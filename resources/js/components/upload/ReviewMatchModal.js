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
 * AORM-8B.20: Cancel button and the modal's built-in close (×) button both
 * call onClose without making any state changes.
 *
 * @param {{
 *   record:   Object|null,  — the staged record being reviewed; null = modal closed
 *   onClose:  () => void,  — callback to dismiss the modal
 * }} props
 */

import { __ } from '@wordpress/i18n';
import { Button, Modal } from '@wordpress/components';
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

// ── Component ─────────────────────────────────────────────────────────────────

export default function ReviewMatchModal( { record, onClose } ) {
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

			{ /* ── Footer ────────────────────────────────────────────────────────
			     AORM-8B.20: Cancel closes the modal without any state changes.
			     Additional action buttons (Save Update etc.) added in AORM-8B.19. */ }
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
