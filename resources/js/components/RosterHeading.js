/**
 * RosterHeading — always-visible heading block for the Roster Detail page.
 *
 * Displays org + membership metadata fetched from the AORM-4.1 REST endpoint
 * (GET /wicket-aorm/v1/rosters/{org_uuid}/{membership_uuid}) plus an external
 * link to the membership record in MDP.
 *
 * The MDP link URL is constructed from window.aormContext.appEndpoint
 * (injected server-side by Assets.php via wp_localize_script) so the
 * component never needs to call a separate API.
 *
 * @see AORM-4.2
 */

import { __ } from '@wordpress/i18n';
import { Button, __experimentalHeading as Heading } from '@wordpress/components';

import '../../css/roster-heading.css';

/**
 * Build the MDP membership admin URL.
 *
 * @param {string} appEndpoint    - MDP admin base URL (no trailing slash).
 * @param {string} orgUuid        - Organization UUID.
 * @param {string} membershipUuid - Membership UUID.
 * @returns {string|null} Full URL or null when any argument is empty.
 */
function buildMdpUrl( appEndpoint, orgUuid, membershipUuid ) {
	if ( ! appEndpoint || ! orgUuid || ! membershipUuid ) {
		return null;
	}

	return `${ appEndpoint }/organizations/${ encodeURIComponent( orgUuid ) }/memberships/${ encodeURIComponent( membershipUuid ) }`;
}

/**
 * Format the roster count for display.
 *
 * @param {number}  assignedCount        - Number of assigned members.
 * @param {number}  maxAssignments       - Seat cap (ignored when unlimited).
 * @param {boolean} unlimitedAssignments - True when MDP has no seat cap.
 * @returns {string}
 */
function formatRosterCount( assignedCount, maxAssignments, unlimitedAssignments ) {
	const max = unlimitedAssignments
		? __( 'Unlimited', 'wicket-aorm' )
		: String( maxAssignments );

	return `${ assignedCount } / ${ max }`;
}

/**
 * @param {Object}      props
 * @param {Object|null} props.roster - Normalized roster object from the REST API.
 */
export default function RosterHeading( { roster } ) {
	if ( ! roster ) {
		return null;
	}

	const appEndpoint = ( window.aormContext ?? {} ).appEndpoint ?? '';
	const mdpUrl = buildMdpUrl(
		appEndpoint,
		roster.org_uuid,
		roster.membership_uuid
	);

	const fields = [
		{
			slug: 'orgUuid',
			label: __( 'Organization UUID', 'wicket-aorm' ),
			value: roster.org_uuid,
		},
		{
			slug: 'orgType',
			label: __( 'Organization Type', 'wicket-aorm' ),
			value: roster.org_type || '—',
		},
		{
			slug: 'membershipUuid',
			label: __( 'Membership UUID', 'wicket-aorm' ),
			value: roster.membership_uuid || '—',
		},
		{
			slug: 'membershipTier',
			label: __( 'Membership Tier', 'wicket-aorm' ),
			value: roster.membership_tier || '—',
		},
		{
			slug: 'membershipOwner',
			label: __( 'Membership Owner', 'wicket-aorm' ),
			value: roster.membership_owner || '—',
		},
		{
			slug: 'assignedCount',
			label: __( 'Current Roster count', 'wicket-aorm' ),
			value: formatRosterCount(
				roster.assigned_count,
				roster.max_assignments,
				roster.unlimited_assignments
			),
		},
	];

	return (
		<div className="aorm-roster-heading">
			<div className="aorm-roster-heading-top">
				<div>
					<div className="aorm-roster-heading__org-name">
						<Heading>
							{ roster.org_name }
						</Heading>
					</div>
					<p>{ __( 'Organization Roster Details', 'wicket-aorm' ) }</p>
				</div>

				{ mdpUrl && (
					<div className="aorm-roster-heading__mdp-link">
						<Button
							variant="secondary"
							href={ mdpUrl }
							target="_blank"
							rel="noreferrer noopener"
						>
							{ __( 'View in MDP', 'wicket-aorm' ) }
						</Button>
					</div>
				) }
			</div>

			<div className="aorm-roster-heading__meta-box">
				<div className="aorm-roster-heading__fields">
					{ fields.map( ( { slug, label, value } ) => (
						<div key={ label } className={ "aorm-roster-heading__field " + slug }>
							<span className="aorm-roster-heading__field-label">
								{ label }:
							</span>{ ' ' }
							<div>
								<span className="aorm-roster-heading__field-value">
									{ value }
								</span>
							</div>
						</div>
					) ) }
				</div>
			</div>
		</div>
	);
}
