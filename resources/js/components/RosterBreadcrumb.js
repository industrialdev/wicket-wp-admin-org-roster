/**
 * RosterBreadcrumb — breadcrumb navigation for the Roster Detail page.
 *
 * Renders a two-item trail:
 *   "Organization Rosters" (link back to the WP_List_Table page)
 *   › org_name (terminal crumb, aria-current="page")
 *
 * The list page URL is read from window.aormContext.rosterListUrl, which is
 * injected server-side by Assets.php via wp_localize_script (AORM-4.4).
 * When the URL is unavailable the parent label is rendered as plain text so
 * the component degrades gracefully.
 *
 * The org name is passed as a prop and may be null/undefined while the REST
 * API call is in-flight — in that case only the parent crumb is shown.
 *
 * @see AORM-4.4
 */

import { __ } from '@wordpress/i18n';

import '../../css/roster-breadcrumb.css';

/**
 * @param {Object}      props
 * @param {string|null} [props.orgName] - Current org name shown as the terminal crumb.
 *                                        Omitted when null or an empty string.
 */
export default function RosterBreadcrumb( { orgName = null } = {} ) {
	const listUrl = ( window.aormContext ?? {} ).rosterListUrl ?? '';

	return (
		<nav className="aorm-breadcrumb" aria-label={ __( 'Breadcrumb', 'wicket-aorm' ) }>
			{ listUrl
				? <a href={ listUrl }>{ __( 'Organization Rosters', 'wicket-aorm' ) }</a>
				: <span>{ __( 'Organization Rosters', 'wicket-aorm' ) }</span>
			}
			{ orgName && (
				<>
					<span aria-hidden="true"> › </span>
					<span aria-current="page">{ orgName }</span>
				</>
			) }
		</nav>
	);
}
