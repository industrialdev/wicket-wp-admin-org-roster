/**
 * SyncConfirmModal — AORM-9.1.
 *
 * A @wordpress/components Modal that asks the admin to confirm the sync
 * action before any records are committed to MDP.
 *
 * Displayed when the admin clicks "Sync to MDP" in the bulk action toolbar
 * of the Ready to Sync panel. Sync cannot be cancelled once started, so
 * the admin must explicitly confirm before proceeding.
 *
 * Intentionally presentational: never talks to the REST API itself.
 * Calls `onConfirm()` when the admin clicks "Proceed", or `onClose()`
 * when they click "Cancel" or the modal's ×.
 *
 * @param {{
 *   isOpen:    boolean,
 *   onConfirm: function(): void,
 *   onClose:   function(): void,
 * }} props
 */

import { __ } from '@wordpress/i18n';
import { Button, Modal } from '@wordpress/components';

// ── Constants (exported for test assertions) ───────────────────────────────────

export const MODAL_TITLE   = __( 'Confirm Sync', 'wicket-aorm' );
export const MODAL_MESSAGE = __( 'Sync cannot be cancelled once started. Proceed?', 'wicket-aorm' );
export const CONFIRM_LABEL = __( 'Proceed', 'wicket-aorm' );
export const CANCEL_LABEL  = __( 'Cancel',  'wicket-aorm' );

// ── Component ──────────────────────────────────────────────────────────────────

export default function SyncConfirmModal( {
	isOpen    = false,
	onConfirm,
	onClose,
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

			<div className="aorm-sync-confirm-modal__actions">
				<Button
					variant="primary"
					onClick={ onConfirm }
					className="aorm-sync-confirm-modal__proceed"
				>
					{ CONFIRM_LABEL }
				</Button>

				<Button
					variant="secondary"
					onClick={ onClose }
					className="aorm-sync-confirm-modal__cancel"
				>
					{ CANCEL_LABEL }
				</Button>
			</div>
		</Modal>
	);
}
