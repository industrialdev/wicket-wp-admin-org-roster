/**
 * Plugin entry point.
 *
 * Mounts React islands into the div#aorm-* mount points rendered by MenuPage.php.
 * Each page gets its own independent React root so WP admin navigation works
 * without a full SPA router.
 *
 * Pages rendered via classic PHP (WP_List_Table or standard admin forms) do NOT
 * have a mount point here and never receive the React bundle:
 *   - Org memberships list  (AORM-3)  → WP_List_Table
 *   - Configurations                  → PHP admin page
 *   - Settings                        → PHP admin page
 *   - Global logs           (AORM-10) → WP_List_Table
 */

import { createElement, createRoot } from '@wordpress/element';

import OrgRosterDetail from './pages/OrgRosterDetail';
import GroupRosters from './pages/GroupRosters';

/**
 * Mount map: DOM element id → React component.
 */
const mounts = {
	'aorm-roster-detail': OrgRosterDetail,
	'aorm-group-rosters': GroupRosters,
};

Object.entries( mounts ).forEach( ( [ id, Component ] ) => {
	const el = document.getElementById( id );
	if ( ! el ) {
		return;
	}

	const root = createRoot( el );
	root.render( createElement( Component ) );
} );
