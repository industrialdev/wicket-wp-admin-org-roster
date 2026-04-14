/**
 * Roster Assignment tab — AORM-4, Tab 1.
 *
 * Table of current roster members with bulk and per-row actions.
 * Columns: name, email, relationship/assignment type, roles, status.
 *
 * Bulk actions: Remove person(s), Add role(s), Remove role(s).
 * Per-row: "Edit Permissions" opens a Modal with CheckboxControls.
 *
 * @param {{ orgUuid: string, membershipUuid: string }} props
 */

import { __ } from '@wordpress/i18n';
import { Notice } from '@wordpress/components';

export default function RosterAssignment( { orgUuid, membershipUuid } ) {
	// TODO (AORM-4.3 – 4.9): implement member table, bulk actions, Edit Permissions modal.
	return (
		<Notice status="info" isDismissible={ false }>
			{ __( 'Roster Assignment — coming in AORM-4.', 'wicket-aorm' ) }
		</Notice>
	);
}
