/**
 * ActivityDetailRow — AORM-4.17.
 *
 * Renders an expanded detail panel as a table row immediately following a
 * parent ActivityTable row. Displays the `context` metadata from the log
 * entry as a definition list of key/value pairs.
 *
 * When context is absent or empty, shows a "No additional details" message.
 *
 * @param {{
 *   entry:   {
 *     id:         number,
 *     created_at: string,
 *     actor:      string,
 *     action:     string,
 *     message:    string,
 *     level:      string,
 *     context?:   Object,
 *   },
 *   colSpan: number,
 * }} props
 */

import { __ } from '@wordpress/i18n';

// ---------------------------------------------------------------------------
// Helpers (exported for unit-test access)
// ---------------------------------------------------------------------------

/**
 * Convert a snake_case context key to a human-readable "Title Case" label.
 *
 * @param {string} key
 * @returns {string}
 */
export function formatContextKey( key ) {
	return ( key ?? '' )
		.replace( /_/g, ' ' )
		.replace( /\b\w/g, ( c ) => c.toUpperCase() );
}

/**
 * Render a context value for display.
 *   - Arrays  → comma-separated string, or "—" if empty
 *   - null/undefined → "—"
 *   - Objects → compact JSON string
 *   - Anything else → String()
 *
 * @param {*} value
 * @returns {string}
 */
export function formatContextValue( value ) {
	if ( value === null || value === undefined ) {
		return '—';
	}
	if ( Array.isArray( value ) ) {
		return value.length > 0 ? value.join( ', ' ) : '—';
	}
	if ( typeof value === 'object' ) {
		return JSON.stringify( value );
	}
	return String( value );
}

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

export default function ActivityDetailRow( { entry, colSpan = 7 } ) {
	const context = entry?.context;
	const hasContext =
		context !== null &&
		context !== undefined &&
		typeof context === 'object' &&
		! Array.isArray( context ) &&
		Object.keys( context ).length > 0;

	return (
		<tr className="aorm-activity-table__detail-row">
			<td
				colSpan={ colSpan }
				className="aorm-activity-table__detail-cell"
			>
				<div className="aorm-activity-detail">
					{ hasContext ? (
						<dl className="aorm-activity-detail__context">
							{ Object.entries( context ).map( ( [ key, value ] ) => (
								<div
									key={ key }
									className="aorm-activity-detail__context-item"
								>
									<dt className="aorm-activity-detail__context-key">
										{ formatContextKey( key ) }
									</dt>
									<dd className="aorm-activity-detail__context-value">
										{ formatContextValue( value ) }
									</dd>
								</div>
							) ) }
						</dl>
					) : (
						<span className="aorm-activity-detail__no-context">
							{ __(
								'No additional details.',
								'wicket-aorm'
							) }
						</span>
					) }
				</div>
			</td>
		</tr>
	);
}
