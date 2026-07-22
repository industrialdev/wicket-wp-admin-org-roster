/**
 * AbandonSessionModal — AORM-9.31.
 *
 * A @wordpress/components Modal that asks the admin to confirm abandoning
 * the current upload session before its staged records are permanently
 * deleted.
 *
 * Background: a session stays "active" — blocking new uploads with a 409
 * from POST /wicket-aorm/v1/uploads (AORM-6.7) — for as long as it has rows
 * still awaiting review in Possible Match / Probable Match / Manual Updates.
 * Before this ticket the only way out of that state was to resolve or
 * discard every remaining row by hand. This modal is the escape hatch,
 * shown when the admin clicks "Abandon Session" on the Review Upload
 * screen (ValidationReviewStep). Confirming fires
 * DELETE /wicket-aorm/v1/uploads/{session_id} — the same endpoint already
 * used by CsvValidationStep's "Start Fresh" flow (AORM-6.7 /
 * StagedRecordsTable::deleteRecordsBySessionId()) — and the caller then
 * returns the wizard to the landing step.
 *
 * Intentionally presentational, mirroring SyncConfirmModal (AORM-9.1): this
 * component never calls the REST API itself. ValidationReviewStep owns the
 * DELETE request and navigation so the modal stays easy to test in isolation.
 *
 * @param {{
 *   isOpen:        boolean,
 *   onConfirm:     function(): void,
 *   onClose:       function(): void,
 *   isAbandoning?: boolean,
 * }} props
 */

import { __ } from '@wordpress/i18n';
import { Button, Modal } from '@wordpress/components';

// ── Constants (exported for test assertions) ────────────────────────────────

export const MODAL_TITLE = __( 'Abandon Session', 'wicket-aorm' );

export const MODAL_MESSAGE = __(
	'This will permanently delete every staged record for this upload session, including any rows still awaiting review in Possible Match, Probable Match, and Manual Updates. This action cannot be undone.',
	'wicket-aorm'
);

export const CONFIRM_LABEL = __( 'Abandon Session', 'wicket-aorm' );
export const CANCEL_LABEL  = __( 'Cancel', 'wicket-aorm' );

// ── Component ────────────────────────────────────────────────────────────────

export default function AbandonSessionModal( {
	isOpen       = false,
	onConfirm,
	onClose,
	isAbandoning = false,
} ) {
	if ( ! isOpen ) {
		return null;
	}

	return (
		<Modal
			title={ MODAL_TITLE }
			onRequestClose={ onClose }
			className="aorm-abandon-session-modal"
		>
			<p className="aorm-abandon-session-modal__message">
				{ MODAL_MESSAGE }
			</p>

			<div className="aorm-abandon-session-modal__actions">
				<Button
					variant="primary"
					isDestructive
					isBusy={ isAbandoning }
					disabled={ isAbandoning }
					onClick={ onConfirm }
					className="aorm-abandon-session-modal__confirm"
				>
					{ CONFIRM_LABEL }
				</Button>

				<Button
					variant="secondary"
					disabled={ isAbandoning }
					onClick={ onClose }
					className="aorm-abandon-session-modal__cancel"
				>
					{ CANCEL_LABEL }
				</Button>
			</div>
		</Modal>
	);
}
