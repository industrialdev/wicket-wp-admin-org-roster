/**
 * useRestApi — generic data-fetching hook for AORM REST endpoints.
 *
 * Wraps @wordpress/api-fetch so all requests automatically carry the WP
 * nonce and REST root injected by Assets.php via wp_localize_script().
 *
 * @example
 * const { data, isLoading, error, refresh } = useRestApi( '/wicket-aorm/v1/rosters' );
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import { apiFetch } from '../utils/apiFetch';

/**
 * @typedef {Object} UseRestApiResult
 * @property {any}      data       Parsed response body, or null while loading.
 * @property {boolean}  isLoading  True on the initial fetch and any refresh.
 * @property {string|null} error   Error message if the request failed.
 * @property {Function} refresh    Re-fetch the endpoint on demand.
 */

/**
 * @param {string} path - REST API path relative to the WP REST root.
 * @returns {UseRestApiResult}
 */
export function useRestApi( path ) {
	const [ data, setData ] = useState( null );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	const fetchData = useCallback( () => {
		setIsLoading( true );
		setError( null );

		apiFetch( { path } )
			.then( ( response ) => {
				setData( response );
			} )
			.catch( ( err ) => {
				setError(
					err?.message ?? 'An unexpected error occurred.'
				);
			} )
			.finally( () => {
				setIsLoading( false );
			} );
	}, [ path ] );

	useEffect( () => {
		fetchData();
	}, [ fetchData ] );

	return { data, isLoading, error, refresh: fetchData };
}
