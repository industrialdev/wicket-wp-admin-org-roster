/**
 * Upload validation review step — AORM-8.
 *
 * Stub: full implementation in AORM-8.1 – 8.25.
 * Accordion layout: Ready to Sync, Possible Matches, Probable Matches,
 * Manual Updates, Discard — each with counts, sortable/searchable tables,
 * per-row and bulk actions, and a Review Match modal.
 * On "Sync to MDP", navigates to sync-progress.
 *
 * @param {{
 *   goToStep: (step: string) => void,
 *   orgUuid: string,
 *   membershipUuid: string,
 *   sessionId: string|null,
 *   uploadAction: string,
 * }} props
 */

import { __ } from '@wordpress/i18n';
import { Notice } from '@wordpress/components';

export default function ValidationReviewStep() {
	return (
		<div className="aorm-wizard-step aorm-wizard-step--validation-review">
			<Notice status="info" isDismissible={ false }>
				{ __(
					'Upload validation review — coming in AORM-8.',
					'wicket-aorm'
				) }
			</Notice>
		</div>
	);
}
