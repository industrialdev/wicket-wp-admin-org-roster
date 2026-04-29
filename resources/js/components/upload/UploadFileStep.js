/**
 * CSV file upload step — AORM-6 (Step 1).
 *
 * Stub: full implementation in AORM-6.1 – 6.10.
 * Drag-and-drop or file picker (CSV only, 1 MB max).
 * Download link for empty roster template.
 * Blocked if an active upload session already exists for this membership.
 * On success: creates staged-records session, navigates to action-select.
 *
 * @param {{
 *   goToStep: (step: string) => void,
 *   orgUuid: string,
 *   membershipUuid: string,
 *   startNewSession: (id: string) => void,
 * }} props
 */

import { __ } from '@wordpress/i18n';
import { Button, Notice } from '@wordpress/components';

export default function UploadFileStep( { goToStep } ) {
	return (
		<div className="aorm-wizard-step aorm-wizard-step--upload-file">
			<div className="aorm-wizard-step__back">
				<Button
					variant="tertiary"
					onClick={ () => goToStep( 'landing' ) }
				>
					{ __( '← Back', 'wicket-aorm' ) }
				</Button>
			</div>

			<Notice status="info" isDismissible={ false }>
				{ __(
					'CSV file upload — coming in AORM-6.',
					'wicket-aorm'
				) }
			</Notice>
		</div>
	);
}
