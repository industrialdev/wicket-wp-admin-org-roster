/**
 * CSV row validation results step — AORM-6 (Step 3).
 *
 * Stub: full implementation in AORM-6.12 – 6.20.
 * Shows per-row status: Valid, Invalid (with reason), Duplicate.
 * "Proceed" is disabled if any row is invalid or duplicate.
 * "Re-upload" clears the session and returns to upload-file.
 * On proceed, navigates to matching-progress.
 *
 * @param {{
 *   goToStep: (step: string) => void,
 *   resetWizard: () => void,
 *   orgUuid: string,
 *   membershipUuid: string,
 *   sessionId: string|null,
 * }} props
 */

import { __ } from '@wordpress/i18n';
import { Button, Notice } from '@wordpress/components';

export default function CsvValidationStep( { goToStep } ) {
	return (
		<div className="aorm-wizard-step aorm-wizard-step--csv-validation">
			<div className="aorm-wizard-step__back">
				<Button
					variant="tertiary"
					onClick={ () => goToStep( 'action-select' ) }
				>
					{ __( '← Back', 'wicket-aorm' ) }
				</Button>
			</div>

			<Notice status="info" isDismissible={ false }>
				{ __(
					'CSV row validation results — coming in AORM-6.',
					'wicket-aorm'
				) }
			</Notice>
		</div>
	);
}
