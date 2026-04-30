/**
 * ActivityTable — AORM-4.16.
 *
 * Read-only audit log table for a single org roster.
 *
 * Columns: Date/Time | Actor Name | Actor Type | Activity Type | Summary | Status
 *
 * Data shape expected per entry (matches the AORM-4.18 REST response):
 *   id         {number}  — unique log row ID
 *   created_at {string}  — ISO-8601 UTC datetime
 *   actor      {string}  — email address, "system", or "user:{id}"
 *   action     {string}  — slug e.g. "members_removed", "roles_added"
 *   message    {string}  — human-readable summary
 *   level      {string}  — "info" | "warning" | "error" | "debug"
 *
 * @param {{
 *   entries: Array<{
 *     id:         number,
 *     created_at: string,
 *     actor:      string,
 *     action:     string,
 *     message:    string,
 *     level:      string,
 *   }>,
 * }} props
 */

import { __ } from '@wordpress/i18n';
import '../../css/activity-log.css';

// ---------------------------------------------------------------------------
// Helpers (exported for unit-test access)
// ---------------------------------------------------------------------------

/**
 * Derive a human-readable actor type from the stored actor string.
 *
 *   "system"        → "System"
 *   has "@"         → "Admin"   (email address — a real WP user)
 *   starts "user:"  → "User"    (WP user with no stored email)
 *   anything else   → "Unknown"
 *
 * @param {string} actor
 * @returns {string}
 */
export function resolveActorType( actor ) {
	if ( ! actor ) {
		return '—';
	}
	if ( actor === 'system' ) {
		return __( 'System', 'wicket-aorm' );
	}
	if ( actor.includes( '@' ) ) {
		return __( 'Admin', 'wicket-aorm' );
	}
	if ( actor.startsWith( 'user:' ) ) {
		return __( 'User', 'wicket-aorm' );
	}
	return __( 'Unknown', 'wicket-aorm' );
}

/**
 * Format an ISO datetime string to a locale-friendly "date at time" string.
 * Returns "—" for empty or unparseable values.
 *
 * @param {string} isoString
 * @returns {string}
 */
export function formatDateTime( isoString ) {
	if ( ! isoString ) {
		return '—';
	}
	try {
		const d = new Date( isoString );
		if ( isNaN( d.getTime() ) ) {
			return '—';
		}
		return d.toLocaleString( undefined, {
			year: 'numeric',
			month: 'short',
			day: 'numeric',
			hour: '2-digit',
			minute: '2-digit',
		} );
	} catch {
		return '—';
	}
}

/**
 * Map an action slug to a human-readable activity type label.
 * Unknown slugs are prettified (underscores → spaces, title-cased).
 *
 * @param {string} action
 * @returns {string}
 */
export function formatActivityType( action ) {
	const map = {
		members_removed: __( 'Members Removed', 'wicket-aorm' ),
		roles_added:     __( 'Roles Added', 'wicket-aorm' ),
		roles_removed:   __( 'Roles Removed', 'wicket-aorm' ),
	};

	if ( map[ action ] ) {
		return map[ action ];
	}

	// Prettify unknown slugs.
	return ( action ?? '' )
		.replace( /_/g, ' ' )
		.replace( /\b\w/g, ( c ) => c.toUpperCase() );
}

/**
 * Map a log level to a human-readable status label.
 *
 * @param {string} level
 * @returns {string}
 */
export function formatStatus( level ) {
	const map = {
		info:    __( 'OK', 'wicket-aorm' ),
		warning: __( 'Warning', 'wicket-aorm' ),
		error:   __( 'Error', 'wicket-aorm' ),
		debug:   __( 'Debug', 'wicket-aorm' ),
	};
	return map[ level ] ?? ( level || '—' );
}

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

export default function ActivityTable( { entries = [] } ) {
	const TOTAL_COLS = 6;

	return (
		<table className="wp-list-table widefat fixed striped aorm-activity-table">
			<thead>
				<tr>
					<th scope="col">
						{ __( 'Date / Time', 'wicket-aorm' ) }
					</th>
					<th scope="col">
						{ __( 'Actor Name', 'wicket-aorm' ) }
					</th>
					<th scope="col">
						{ __( 'Actor Type', 'wicket-aorm' ) }
					</th>
					<th scope="col">
						{ __( 'Activity Type', 'wicket-aorm' ) }
					</th>
					<th scope="col">
						{ __( 'Summary', 'wicket-aorm' ) }
					</th>
					<th scope="col">
						{ __( 'Status', 'wicket-aorm' ) }
					</th>
				</tr>
			</thead>
			<tbody>
				{ entries.map( ( entry ) => (
					<tr
						key={ entry.id }
						className={ `aorm-activity-table__row aorm-activity-table__row--${ entry.level }` }
					>
						<td className="aorm-activity-table__col--datetime">
							{ formatDateTime( entry.created_at ) }
						</td>
						<td>{ entry.actor || '—' }</td>
						<td>{ resolveActorType( entry.actor ) }</td>
						<td>{ formatActivityType( entry.action ) }</td>
						<td>{ entry.message || '—' }</td>
						<td>
							<span
								className={ `aorm-activity-table__status aorm-activity-table__status--${ entry.level }` }
							>
								{ formatStatus( entry.level ) }
							</span>
						</td>
					</tr>
				) ) }
				{ entries.length === 0 && (
					<tr>
						<td
							colSpan={ TOTAL_COLS }
							className="aorm-activity-table__empty"
						>
							{ __( 'No activity recorded yet.', 'wicket-aorm' ) }
						</td>
					</tr>
				) }
			</tbody>
		</table>
	);
}
