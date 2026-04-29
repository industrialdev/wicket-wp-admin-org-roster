/**
 * Wizard landing screen — AORM-4.14.
 *
 * Entry point for the Roster Upload tab. Presents two primary paths:
 *
 *   Add Individual  — navigates to the individual-add form (AORM-5).
 *   Bulk Upload     — navigates to the CSV file-upload step (AORM-6).
 *
 * @param {{
 *   goToStep: (step: string) => void,
 *   orgUuid: string,
 *   membershipUuid: string,
 * }} props
 */

import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';

export default function WizardLanding( { goToStep } ) {
	return (
		<div className="aorm-wizard-landing">
			<h2 className="aorm-wizard-landing__title">
				{ __( 'Add Members to Roster', 'wicket-aorm' ) }
			</h2>
			<p className="aorm-wizard-landing__description">
				{ __(
					'Choose how you would like to add members to this roster.',
					'wicket-aorm'
				) }
			</p>

			<div className="aorm-wizard-landing__paths">
				{ /* --- Individual add path (AORM-5) --- */ }
				<div className="aorm-wizard-landing__path">
					<h3 className="aorm-wizard-landing__path-title">
						{ __( 'Add Individual', 'wicket-aorm' ) }
					</h3>
					<p className="aorm-wizard-landing__path-description">
						{ __(
							'Add a single member by entering their details manually.',
							'wicket-aorm'
						) }
					</p>
					<Button
						variant="secondary"
						onClick={ () => goToStep( 'individual-form' ) }
					>
						{ __( 'Add Individual', 'wicket-aorm' ) }
					</Button>
				</div>

				{ /* --- Bulk upload path (AORM-6 – 9) --- */ }
				<div className="aorm-wizard-landing__path aorm-wizard-landing__path--bulk">
					<h3 className="aorm-wizard-landing__path-title">
						{ __( 'Bulk Upload', 'wicket-aorm' ) }
					</h3>
					<p className="aorm-wizard-landing__path-description">
						{ __(
							'Upload a CSV file to add multiple members at once.',
							'wicket-aorm'
						) }
					</p>
					<Button
						variant="primary"
						onClick={ () => goToStep( 'upload-file' ) }
					>
						{ __( 'Upload CSV', 'wicket-aorm' ) }
					</Button>
				</div>
			</div>
		</div>
	);
}
