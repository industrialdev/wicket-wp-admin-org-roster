/**
 * MDP background matching progress step — AORM-7.
 *
 * Stub: full implementation in AORM-7.1 – 7.14.
 * Polls GET /wicket-aorm/v1/uploads/{sessionId}/status for progress %.
 * Shows a progress bar + spinner while the Action Scheduler job runs.
 * On completion, navigates to validation-review.
 *
 * @param {{
 *   goToStep: (step: string) => void,
 *   orgUuid: string,
 *   membershipUuid: string,
 *   sessionId: string|null,
 * }} props
 */

import { __ } from '@wordpress/i18n';
import { Notice } from '@wordpress/components';

export default function MatchingProgressStep() {
	return (
		<div className="aorm-wizard-step aorm-wizard-step--matching-progress">
			<Notice status="info" isDismissible={ false }>
				{ __(
					'MDP matching progress — coming in AORM-7.',
					'wicket-aorm'
				) }
			</Notice>
		</div>
	);
}
