/**
 * ActivityTable — AORM-4.16 / AORM-4.17.
 *
 * Read-only audit log table for a single org roster. Each row can be
 * expanded in-place to reveal a context detail panel (AORM-4.17).
 *
 * Columns: (expand) | Date/Time | Actor Name | Actor Type | Activity Type | Summary | Status
 *
 * Data shape expected per entry (matches the AORM-4.18 REST response):
 *   id         {number}  — unique log row ID
 *   created_at {string}  — ISO-8601 UTC datetime
 *   actor      {string}  — email address, "system", or "user:{id}"
 *   action     {string}  — slug e.g. "members_removed", "roles_added"
 *   message    {string}  — human-readable summary
 *   level      {string}  — "info" | "warning" | "error" | "debug"
 *   context    {Object}  — optional metadata object (shown in detail panel)
 *
 * @param {{
 *   entries: Array<{
 *     id:         number,
 *     created_at: string,
 *     actor:      string,
 *     action:     string,
 *     message:    string,
 *     level:      string,
 *     context?:   Object,
 *   }>,
 * }} props
 */

import { useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import ActivityDetailRow from './ActivityDetailRow';
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
		roles_updated:   __( 'Roles Updated', 'wicket-aorm' ),
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
	// IDs of currently expanded rows.
	const [ expandedIds, setExpandedIds ] = useState( new Set() );

	// Total column count: 1 toggle + 6 data columns.
	const TOTAL_COLS = 7;

	function toggleRow( id ) {
		setExpandedIds( ( prev ) => {
			const next = new Set( prev );
			if ( next.has( id ) ) {
				next.delete( id );
			} else {
				next.add( id );
			}
			return next;
		} );
	}

	return (
		<table className="wp-list-table widefat fixed striped aorm-activity-table">
			<thead>
				<tr>
					{ /* Expand-toggle column — no visible header text */ }
					<th
						scope="col"
						className="aorm-activity-table__col--toggle"
					>
						<span className="screen-reader-text">
							{ __( 'Details', 'wicket-aorm' ) }
						</span>
					</th>
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
				{ entries.map( ( entry ) => {
					const isExpanded = expandedIds.has( entry.id );

					return (
						<>
							<tr
								key={ `row-${ entry.id }` }
								className={ `aorm-activity-table__row aorm-activity-table__row--${ entry.level }` }
							>
								<td className="aorm-activity-table__col--toggle">
									<Button
										className={ `aorm-activity-table__toggle${ isExpanded ? ' is-expanded' : '' }` }
										aria-expanded={ isExpanded }
										aria-label={
											isExpanded
												? __( 'Collapse details', 'wicket-aorm' )
												: __( 'Expand details', 'wicket-aorm' )
										}
										onClick={ () => toggleRow( entry.id ) }
									>
										<span
											className={ `aorm-activity-table__toggle-icon${ isExpanded ? ' is-expanded' : '' }` }
											aria-hidden="true"
										>
											▶
										</span>
									</Button>
								</td>
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
							{ isExpanded && (
								<ActivityDetailRow
									key={ `detail-${ entry.id }` }
									entry={ entry }
									colSpan={ TOTAL_COLS }
								/>
							) }
						</>
					);
				} ) }
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
