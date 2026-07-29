/**
 * ConfirmDeleteRecordsModal — AORM-8B.22.
 *
 * A @wordpress/components Modal that asks the admin to confirm permanently
 * deleting one or more staged records before issuing the DELETE request.
 *
 * Used by DiscardPanel's per-row and bulk "Remove" actions. Unlike Reinstate
 * (a PATCH that only moves a record between categories), Remove calls
 * DELETE /wicket-aorm/v1/staged-records/{id} (AORM-8B.9), which permanently
 * removes the row from the database. There is no undo, so the admin must
 * explicitly confirm before proceeding.
 *
 * Intentionally presentational, mirroring SyncConfirmModal (AORM-9.1) and
 * AbandonSessionModal (AORM-9.31): this component never calls the REST API
 * itself. DiscardPanel owns the DELETE request(s) and passes isDeleting /
 * deleteError so the modal can disable its buttons and surface failures
 * inline.
 *
 * @param {{
 *   isOpen:       boolean,
 *   recordCount:  number,
 *   onConfirm:    function(): void,
 *   onClose:      function(): void,
 *   isDeleting?:  boolean,
 *   deleteError?: string|null,
 * }} props
 */

import { __, sprintf } from '@wordpress/i18n';
import { Button, Modal, Notice } from '@wordpress/components';

// ── Constants (exported for test assertions) ────────────────────────────────

export const MODAL_TITLE   = __( 'Permanently Delete Records', 'wicket-aorm' );
export const CONFIRM_LABEL = __( 'Delete', 'wicket-aorm' );
export const CANCEL_LABEL  = __( 'Cancel', 'wicket-aorm' );

/**
 * Build the confirmation message for the given record count.
 *
 * @param {number} recordCount
 * @return {string} The confirmation message, pluralized for recordCount.
 */
export function buildConfirmMessage( recordCount ) {
	return recordCount === 1
		? __( 'Are you sure you want to permanently delete this record? This action cannot be undone.', 'wicket-aorm' )
		: sprintf(
			/* translators: %d: number of records to be permanently deleted */
			__( 'Are you sure you want to permanently delete %d records? This action cannot be undone.', 'wicket-aorm' ),
			recordCount
		);
}

// ── Component ────────────────────────────────────────────────────────────────

export default function ConfirmDeleteRecordsModal( {
	isOpen      = false,
	recordCount = 0,
	onConfirm,
	onClose,
	isDeleting  = false,
	deleteError = null,
} ) {
	if ( ! isOpen ) {
		return null;
	}

	return (
		<Modal
			title={ MODAL_TITLE }
			onRequestClose={ onClose }
			className="aorm-confirm-delete-records-modal"
		>
			<p className="aorm-confirm-delete-records-modal__message">
				{ buildConfirmMessage( recordCount ) }
			</p>

			{ deleteError && (
				<Notice
					status="error"
					isDismissible={ false }
					className="aorm-confirm-delete-records-modal__error"
				>
					{ deleteError }
				</Notice>
			) }

			<div className="aorm-confirm-delete-records-modal__actions">
				<Button
					variant="primary"
					isDestructive
					isBusy={ isDeleting }
					disabled={ isDeleting }
					onClick={ onConfirm }
					className="aorm-confirm-delete-records-modal__confirm"
				>
					{ CONFIRM_LABEL }
				</Button>

				<Button
					variant="secondary"
					disabled={ isDeleting }
					onClick={ onClose }
					className="aorm-confirm-delete-records-modal__cancel"
				>
					{ CANCEL_LABEL }
				</Button>
			</div>
		</Modal>
	);
}
