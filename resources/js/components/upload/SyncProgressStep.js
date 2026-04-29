/**
 * Commit & MDP sync progress step — AORM-9.
 *
 * Stub: full implementation in AORM-9.1 – 9.20+.
 * Shows a confirmation modal before sync starts (sync cannot be cancelled).
 * Polls sync progress via Action Scheduler job.
 * Per-row status tracking with error detail and retry support.
 * On completion, resets the wizard (resetWizard) and returns to landing.
 *
 * @param {{
 *   goToStep: (step: string) => void,
 *   resetWizard: () => void,
 *   orgUuid: string,
 *   membershipUuid: string,
 *   sessionId: string|null,
 *   uploadAction: string,
 * }} props
 */

import { __ } from '@wordpress/i18n';
import { Notice } from '@wordpress/components';

export default function SyncProgressStep() {
	return (
		<div className="aorm-wizard-step aorm-wizard-step--sync-progress">
			<Notice status="info" isDismissible={ false }>
				{ __(
					'Commit & sync progress — coming in AORM-9.',
					'wicket-aorm'
				) }
			</Notice>
		</div>
	);
}
