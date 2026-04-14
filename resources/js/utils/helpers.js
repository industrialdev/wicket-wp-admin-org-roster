/**
 * General-purpose helpers for the AORM React app.
 */

/**
 * Return a human-readable label for a staged record status value.
 *
 * @param {string} status - Raw status string from the REST API.
 * @returns {string}
 */
export function statusLabel( status ) {
	const labels = {
		pending: 'Pending',
		matched: 'Matched',
		synced: 'Synced',
		error: 'Error',
	};

	return labels[ status ] ?? status;
}

/**
 * Build a query string from a plain object, omitting null/undefined values.
 *
 * @param {Record<string, any>} params
 * @returns {string} Query string without leading '?'.
 */
export function buildQueryString( params ) {
	return Object.entries( params )
		.filter( ( [ , value ] ) => value !== null && value !== undefined )
		.map(
			( [ key, value ] ) =>
				`${ encodeURIComponent( key ) }=${ encodeURIComponent( value ) }`
		)
		.join( '&' );
}
