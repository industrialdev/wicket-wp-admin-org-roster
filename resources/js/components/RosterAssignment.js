/**
 * Roster Assignment tab — AORM-4, Tab 1.
 *
 * Fetches the list of people currently assigned to the roster via the
 * GET /wicket-aorm/v1/rosters/{org_uuid}/{membership_uuid}/members endpoint
 * (AORM-4.5) and renders a MemberTable with row-level checkboxes for
 * future bulk-action selection (AORM-4.8).
 *
 * @param {{ orgUuid: string, membershipUuid: string }} props
 */

import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Notice, Spinner } from '@wordpress/components';

import { useRestApi } from '../hooks/useRestApi';
import MemberTable from './MemberTable';

export default function RosterAssignment( { orgUuid, membershipUuid } ) {
	const [ selectedIds, setSelectedIds ] = useState( new Set() );

	const PER_PAGE = 10;

	const { data, isLoading, error } = useRestApi(
		orgUuid && membershipUuid
			? `/wicket-aorm/v1/rosters/${ orgUuid }/${ membershipUuid }/members?per_page=${ PER_PAGE }`
			: null
	);

	if ( isLoading ) {
		return <Spinner />;
	}

	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}

	const members = data?.members ?? [];

	return (
		<div className="aorm-assignment">
			<MemberTable
				members={ members }
				selectedIds={ selectedIds }
				onSelectionChange={ setSelectedIds }
			/>
		</div>
	);
}
