/**
 * Individual add form step — AORM-5.
 *
 * Stub: full implementation in AORM-5.1 – 5.10.
 * Fields: first_name, last_name, email (required), mobile_phone, title (optional).
 * On submit: validates, inserts into staged records, triggers MDP matching,
 * then navigates to matching-progress.
 *
 * @param {{
 *   goToStep: (step: string) => void,
 *   resetWizard: () => void,
 *   orgUuid: string,
 *   membershipUuid: string,
 *   startNewSession: (id: string) => void,
 * }} props
 */

import { __ } from '@wordpress/i18n';
import { Button, Notice } from '@wordpress/components';

export default function IndividualAddForm( { goToStep, resetWizard } ) {
	return (
		<div className="aorm-wizard-step aorm-wizard-step--individual-form">
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
					'Individual add form — coming in AORM-5.',
					'wicket-aorm'
				) }
			</Notice>
		</div>
	);
}
