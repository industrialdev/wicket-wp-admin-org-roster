/**
 * Roster Activity tab — AORM-4, Tab 3 (nice-to-have).
 *
 * Scoped audit trail for this org membership. Read-only, paginated.
 * Columns: date/time, actor, activity type, summary, status.
 * Filters: activity type, date range, actor, status.
 *
 * @param {{ orgUuid: string, membershipUuid: string }} props
 */

import { __ } from '@wordpress/i18n';
import { Notice } from '@wordpress/components';

export default function RosterActivity( { orgUuid, membershipUuid } ) {
	// TODO (AORM-4, nice-to-have): implement scoped activity log.
	return (
		<Notice status="info" isDismissible={ false }>
			{ __( 'Roster Activity log — coming soon.', 'wicket-aorm' ) }
		</Notice>
	);
}
