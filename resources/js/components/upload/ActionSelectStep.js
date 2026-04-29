/**
 * Action selection step — AORM-6 (Step 2).
 *
 * Stub: full implementation in AORM-6.11.
 * Admin chooses "Add to Roster" or "Replace Existing Roster".
 * "Replace" is only available when assigned_count > 0.
 * On confirm, navigates to csv-validation.
 *
 * @param {{
 *   goToStep: (step: string) => void,
 *   uploadAction: string,
 *   setUploadAction: (action: string) => void,
 *   orgUuid: string,
 *   membershipUuid: string,
 *   sessionId: string|null,
 * }} props
 */

import { __ } from '@wordpress/i18n';
import { Button, Notice } from '@wordpress/components';

export default function ActionSelectStep( { goToStep } ) {
	return (
		<div className="aorm-wizard-step aorm-wizard-step--action-select">
			<div className="aorm-wizard-step__back">
				<Button
					variant="tertiary"
					onClick={ () => goToStep( 'upload-file' ) }
				>
					{ __( '← Back', 'wicket-aorm' ) }
				</Button>
			</div>

			<Notice status="info" isDismissible={ false }>
				{ __(
					'Action selection (Add to Roster / Replace) — coming in AORM-6.',
					'wicket-aorm'
				) }
			</Notice>
		</div>
	);
}
