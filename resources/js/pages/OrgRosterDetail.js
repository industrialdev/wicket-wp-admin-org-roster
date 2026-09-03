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
 *
 * The Roster Upload wizard's step/session state is owned here (via
 * useUploadWizardState()) rather than inside RosterUpload itself, because
 * TabPanel unmounts inactive tab content — RosterUpload would lose its
 * in-progress session every time the admin switched to another tab and
 * back. This component sits above the TabPanel and never unmounts across
 * tab switches, so the state survives. See useUploadWizardState.js for the
 * full bugfix writeup.
 *
 * Bugfix ("Current Roster count" not updating after sync): the roster
 * object backing RosterHeading's assigned_count (fetched once here via
 * useRestApi) previously had no way to be refreshed after a sync — the
 * admin never leaves this page during upload → sync, so nothing re-ran the
 * initial fetch. useRestApi() already exposes a refresh() for this; it's
 * now passed down as onSyncComplete so SyncProgressStep can call it the
 * moment a sync finishes (live update while the Results screen is showing).
 * SyncProgressStep's "Done" button additionally does a full
 * window.location.reload() rather than navigating client-side back to this
 * tab — see SyncProgressStep.js for why. Because of that, this component no
 * longer needs to hand it a client-side "go to Roster Assignment" callback.
 *
 * Bugfix (Done button disappearing / Sync Complete screen resetting):
 * useRestApi()'s `refresh()` sets the SAME `isLoading` flag as the initial
 * fetch. The full-page early return below used to be `if (isLoading)` with
 * no other condition, so calling `refreshRoster` from deep inside
 * SyncProgressStep (via onSyncComplete, above) replaced this entire
 * component's tree — header, tabs, the in-progress wizard, all of it — with
 * a bare Spinner, then remounted everything fresh once the refetch
 * resolved. That remount wiped SyncProgressStep's local state (`phase`,
 * synced/failed counts, ...), so it came back at 'syncing' and immediately
 * re-polled, found `is_complete` true again, called onSyncComplete() again,
 * and repeated — a flash-the-whole-page-and-remount loop, which is why the
 * Done button appeared to vanish. Fixed by only showing the full-page
 * Spinner on the genuine initial load (`isLoading && !roster`); once roster
 * data exists, a background refresh updates the header in place without
 * unmounting anything else on the page.
 */

import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Notice, Spinner, TabPanel } from '@wordpress/components';

import { useRestApi } from '../hooks/useRestApi';
import { useUploadWizardState } from '../hooks/useUploadWizardState';
import RosterBreadcrumb from '../components/RosterBreadcrumb';
import RosterHeading from '../components/RosterHeading';
import RosterAssignment from '../components/RosterAssignment';
import RosterUpload from '../components/RosterUpload';
import RosterActivity from '../components/RosterActivity';
import '../../css/roster-detail.css';

export default function OrgRosterDetail() {
	// org_uuid and membership_uuid are read from $_GET in PHP (Assets.php) and
	// injected here via wp_localize_script → window.aormContext.
	const context        = window.aormContext ?? {};
	const orgUuid        = context.orgUuid        ?? null;
	const membershipUuid = context.membershipUuid ?? null;

	// tabNav drives programmatic tab switching (AORM-4.7).
	// Incrementing `key` forces TabPanel to remount with a fresh
	// `initialTabName`, because TabPanel is an uncontrolled component.
	const [ tabNav, setTabNav ] = useState( { tab: 'assignment', key: 0 } );

	function goToTab( name ) {
		setTabNav( ( prev ) => ( { tab: name, key: prev.key + 1 } ) );
	}

	const { data: roster, isLoading, error, refresh: refreshRoster } = useRestApi(
		orgUuid && membershipUuid
			? `/wicket-aorm/v1/rosters/${ orgUuid }/${ membershipUuid }`
			: null
	);

	// Owned here (not inside RosterUpload) so it survives TabPanel unmounting
	// the "Roster Upload" tab's content when the admin switches tabs. Must be
	// called unconditionally, before the early returns below, per the rules
	// of hooks.
	const uploadWizard = useUploadWizardState();

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

	// Only block the whole page on the genuine initial load. Once roster data
	// exists, a background refresh() (e.g. onSyncComplete below) sets
	// isLoading again but must NOT unmount the rest of the page — see the
	// bugfix note above.
	if ( isLoading && ! roster ) {
		return (
			<>
				<Spinner />
			</>
		);
	}

	if ( error && ! roster ) {
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
				key={ tabNav.key }
				className="aorm-roster-tabs"
				initialTabName={ tabNav.tab }
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
								onGoToUpload={ () => goToTab( 'upload' ) }
							/>
						) }
						{ tab.name === 'upload' && (
							<RosterUpload
								orgUuid={ orgUuid }
								membershipUuid={ membershipUuid }
								onSyncComplete={ refreshRoster }
								{ ...uploadWizard }
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

