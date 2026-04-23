/**
 * Org Roster Detail page — AORM-4.
 *
 * Mounted into #aorm-roster-detail by index.js. Reads org_uuid and
 * membership_uuid from window.aormContext (injected server-side by
 * Assets.php via wp_localize_script), falling back to the URL query
 * string if those values are absent.
 *
 * Tabs (per AORM-4):
 *   1. Roster Assignment  — current members table with bulk/row actions
 *   2. Roster Upload      — bulk upload wizard + individual add (AORM-5 – 9)
 *   3. Roster Activity    — scoped audit trail (nice-to-have, AORM-4)
 */

import { __ } from '@wordpress/i18n';
import { Notice, Spinner, TabPanel } from '@wordpress/components';

import { useRestApi } from '../hooks/useRestApi';
import RosterBreadcrumb from '../components/RosterBreadcrumb';
import RosterHeading from '../components/RosterHeading';
import RosterAssignment from '../components/RosterAssignment';
import RosterUpload from '../components/RosterUpload';
import RosterActivity from '../components/RosterActivity';

export default function OrgRosterDetail() {
	// org_uuid and membership_uuid are read from $_GET in PHP (Assets.php) and
	// injected here via wp_localize_script → window.aormContext.
	const context        = window.aormContext ?? {};
	const orgUuid        = context.orgUuid        ?? null;
	const membershipUuid = context.membershipUuid ?? null;

	const { data: roster, isLoading, error } = useRestApi(
		orgUuid && membershipUuid
			? `/wicket-aorm/v1/rosters/${ orgUuid }/${ membershipUuid }`
			: null
	);

	if ( ! orgUuid || ! membershipUuid ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ __(
					'Missing org_uuid or membership_uuid URL parameters.',
					'wicket-aorm'
				) }
			</Notice>
		);
	}

	if ( isLoading ) {
		return (
			<>
				<Spinner />
			</>
		);
	}

	if ( error ) {
		return (
			<>
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			</>
		);
	}

	return (
		<div className="aorm-page aorm-page--roster-detail">
			<RosterBreadcrumb orgName={ roster?.org_name } />
			<RosterHeading roster={ roster } />

			<TabPanel
				className="aorm-roster-tabs"
				tabs={ [
					{
						name:  'assignment',
						title: __( 'Roster Assignment', 'wicket-aorm' ),
					},
					{
						name:  'upload',
						title: __( 'Roster Upload', 'wicket-aorm' ),
					},
					{
						name:  'activity',
						title: __( 'Roster Activity', 'wicket-aorm' ),
					},
				] }
			>
				{ ( tab ) => (
					<div className={ `aorm-tab aorm-tab--${ tab.name }` }>
						{ tab.name === 'assignment' && (
							<RosterAssignment
								orgUuid={ orgUuid }
								membershipUuid={ membershipUuid }
							/>
						) }
						{ tab.name === 'upload' && (
							<RosterUpload
								orgUuid={ orgUuid }
								membershipUuid={ membershipUuid }
							/>
						) }
						{ tab.name === 'activity' && (
							<RosterActivity
								orgUuid={ orgUuid }
								membershipUuid={ membershipUuid }
							/>
						) }
					</div>
				) }
			</TabPanel>
		</div>
	);
}

