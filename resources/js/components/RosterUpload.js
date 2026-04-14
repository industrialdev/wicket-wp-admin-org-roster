/**
 * Roster Upload tab — AORM-4, Tab 2.
 *
 * Houses the multi-step bulk upload wizard (AORM-6 – 9) and individual
 * add flow (AORM-5). Steps: file drop → validation review → sync.
 *
 * @param {{ orgUuid: string, membershipUuid: string }} props
 */

import { __ } from '@wordpress/i18n';
import { Notice } from '@wordpress/components';

export default function RosterUpload( { orgUuid, membershipUuid } ) {
	// TODO (AORM-5 – 9): implement upload wizard (DropZone → validation → sync).
	return (
		<Notice status="info" isDismissible={ false }>
			{ __( 'Roster Upload wizard — coming in AORM-5 through 9.', 'wicket-aorm' ) }
		</Notice>
	);
}
