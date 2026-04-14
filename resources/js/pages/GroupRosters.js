/**
 * Group Rosters page.
 *
 * Mounted into #aorm-group-rosters by index.js.
 */

import { __ } from '@wordpress/i18n';
import { Notice } from '@wordpress/components';

export default function GroupRosters() {
	return (
		<div className="aorm-page aorm-page--group-rosters">
			<h1>{ __( 'Group Rosters', 'wicket-aorm' ) }</h1>

			<Notice status="info" isDismissible={ false }>
				{ __(
					'Group Roster management is coming soon.',
					'wicket-aorm'
				) }
			</Notice>
		</div>
	);
}
