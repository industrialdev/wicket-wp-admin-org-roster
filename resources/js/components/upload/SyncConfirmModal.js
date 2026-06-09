/**
 * SyncConfirmModal — AORM-9.1 / AORM-9.29.
 *
 * A @wordpress/components Modal that asks the admin to confirm the sync
 * action before any records are committed to MDP.
 *
 * Displayed when the admin clicks "Sync to MDP" in the bulk action toolbar
 * of the Ready to Sync panel. Sync cannot be cancelled once started, so
 * the admin must explicitly confirm before proceeding.
 *
 * AORM-9.29: Accepts `isCommitting` (disables both buttons + shows isBusy on
 * Proceed while the commit POST is in-flight) and `commitError` (renders a
 * dismissible Notice when the POST returns an error).
 *
 * @param {{
 *   isOpen:        boolean,
 *   onConfirm:     function(): void,
 *   onClose:       function(): void,
 *   isCommitting?: boolean,
 *   commitError?:  string|null,
 * }} props
 */

import { __ } from '@wordpress/i18n';
import { Button, Modal, Notice } from '@wordpress/components';

// ── Constants (exported for test assertions) ───────────────────────────────────

export const MODAL_TITLE   = __( 'Confirm Sync', 'wicket-aorm' );
export const MODAL_MESSAGE = __( 'Sync cannot be cancelled once started. Proceed?', 'wicket-aorm' );
export const CONFIRM_LABEL = __( 'Proceed', 'wicket-aorm' );
export const CANCEL_LABEL  = __( 'Cancel',  'wicket-aorm' );

// ── Component ──────────────────────────────────────────────────────────────────

export default function SyncConfirmModal( {
	isOpen       = false,
	onConfirm,
	onClose,
	isCommitting = false,
	commitError  = null,
} ) {
	if ( ! isOpen ) {
		return null;
	}

	return (
		<Modal
			title={ MODAL_TITLE }
			onRequestClose={ onClose }
			className="aorm-sync-confirm-modal"
		>
			<p className="aorm-sync-confirm-modal__message">
				{ MODAL_MESSAGE }
			</p>

			{ commitError && (
				<Notice
					status="error"
					isDismissible={ false }
					className="aorm-sync-confirm-modal__error"
				>
					{ commitError }
				</Notice>
			) }

			<div className="aorm-sync-confirm-modal__actions">
				<Button
					variant="primary"
					isBusy={ isCommitting }
					disabled={ isCommitting }
					onClick={ onConfirm }
					className="aorm-sync-confirm-modal__proceed"
				>
					{ CONFIRM_LABEL }
				</Button>

				<Button
					variant="secondary"
					disabled={ isCommitting }
					onClick={ onClose }
					className="aorm-sync-confirm-modal__cancel"
				>
					{ CANCEL_LABEL }
				</Button>
			</div>
		</Modal>
	);
}
