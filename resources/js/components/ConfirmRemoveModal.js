/**
 * ConfirmRemoveModal — AORM-4.13.
 *
 * A @wordpress/components Modal that asks the admin to confirm the destructive
 * "Remove from Roster" bulk action before any DELETE request is issued.
 *
 * The modal is intentionally presentational: it never talks to the REST API
 * itself.  It simply calls `onConfirm()` when the admin clicks the destructive
 * Remove button, or `onClose()` when they click Cancel or the modal's X.
 *
 * The member count is embedded in the confirmation message so the admin always
 * knows how many people will be removed.
 *
 * @param {{
 *   isOpen:       boolean,
 *   memberCount:  number,
 *   onConfirm:    function(): void,
 *   onClose:      function(): void,
 * }} props
 */

import { __, sprintf } from '@wordpress/i18n';
import { Button, Modal } from '@wordpress/components';
import '../../css/confirm-remove-modal.css';

export default function ConfirmRemoveModal( {
	isOpen      = false,
	memberCount = 0,
	onConfirm,
	onClose,
} ) {
	if ( ! isOpen ) {
		return null;
	}

	const message =
		memberCount === 1
			? __( 'Are you sure you want to remove 1 member from this roster? This action cannot be undone.', 'wicket-aorm' )
			: sprintf(
				/* translators: %d: number of members to be removed */
				__( 'Are you sure you want to remove %d members from this roster? This action cannot be undone.', 'wicket-aorm' ),
				memberCount
			);

	function handleConfirm() {
		if ( typeof onConfirm === 'function' ) {
			onConfirm();
		}
	}

	return (
		<Modal
			title={ __( 'Remove from Roster', 'wicket-aorm' ) }
			onRequestClose={ onClose }
			className="aorm-confirm-remove-modal"
		>
			<p className="aorm-confirm-remove-modal__message">
				{ message }
			</p>

			<div className="aorm-confirm-remove-modal__actions">
				<Button
					variant="primary"
					isDestructive
					onClick={ handleConfirm }
				>
					{ __( 'Remove', 'wicket-aorm' ) }
				</Button>

				<Button
					variant="secondary"
					onClick={ onClose }
				>
					{ __( 'Cancel', 'wicket-aorm' ) }
				</Button>
			</div>
		</Modal>
	);
}
