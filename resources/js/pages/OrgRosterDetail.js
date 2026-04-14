/**
 * Org Roster Detail page — AORM-4.
 *
 * Mounted into #aorm-roster-detail by index.js. Reads org_uuid and
 * membership_uuid from the current URL's query string (set by the
 * WP_List_Table row link in the list view).
 *
 * Tabs (per AORM-4):
 *   1. Roster Assignment  — current members table with bulk/row actions
 *   2. Roster Upload      — bulk upload wizard + individual add (AORM-5 – 9)
 *   3. Roster Activity    — scoped audit trail (nice-to-have, AORM-4)
 */

import { __ } from '@wordpress/i18n';
import { Notice, Spinner, TabPanel } from '@wordpress/components';

import { useRestApi } from '../hooks/useRestApi';
import RosterAssignment from '../components/RosterAssignment';
import RosterUpload from '../components/RosterUpload';
import RosterActivity from '../components/RosterActivity';

/**
 * Read a single query-string parameter from the current page URL.
 *
 * @param {string} name
 * @returns {string|null}
 */
function getUrlParam( name ) {
	return new URLSearchParams( window.location.search ).get( name );
}

export default function OrgRosterDetail() {
	const orgUuid        = getUrlParam( 'org_uuid' );
	const membershipUuid = getUrlParam( 'membership_uuid' );

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
		return <Spinner />;
	}

	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}

	return (
		<div className="aorm-page aorm-page--roster-detail">
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

/**
 * Heading block — always visible above the tabs (AORM-4).
 * Displays org + membership metadata from the REST response.
 */
function RosterHeading( { roster } ) {
	if ( ! roster ) {
		return null;
	}

	return (
		<div className="aorm-roster-heading">
			<h1 className="aorm-roster-heading__org-name">
				{ roster.org_name }
			</h1>
			<dl className="aorm-roster-heading__meta">
				<dt>{ __( 'Org ID', 'wicket-aorm' ) }</dt>
				<dd>{ roster.org_id }</dd>

				<dt>{ __( 'Organization Type', 'wicket-aorm' ) }</dt>
				<dd>{ roster.org_type }</dd>

				<dt>{ __( 'Membership Tier', 'wicket-aorm' ) }</dt>
				<dd>{ roster.membership_tier }</dd>

				<dt>{ __( 'Membership Owner', 'wicket-aorm' ) }</dt>
				<dd>{ roster.membership_owner }</dd>

				<dt>{ __( 'Roster Count', 'wicket-aorm' ) }</dt>
				<dd>
					{ roster.assigned_count } / { roster.max_count }
				</dd>

				<dt>{ __( 'MDP Record', 'wicket-aorm' ) }</dt>
				<dd>
					<a
						href={ roster.mdp_url }
						target="_blank"
						rel="noreferrer"
					>
						{ __( 'View in MDP', 'wicket-aorm' ) }
					</a>
				</dd>
			</dl>
		</div>
	);
}
