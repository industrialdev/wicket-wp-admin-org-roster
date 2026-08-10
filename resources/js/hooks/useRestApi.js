/**
 * useRestApi — generic data-fetching hook for AORM REST endpoints.
 *
 * Wraps @wordpress/api-fetch so all requests automatically carry the WP
 * nonce and REST root injected by Assets.php via wp_localize_script().
 *
 * @example
 * const { data, isLoading, error, refresh } = useRestApi( '/wicket-aorm/v1/rosters' );
 *
 * Bugfix: when `path` changes rapidly (e.g. a debounced search box
 * committing a new term while the previous request for the old path is
 * still in flight), two requests can end up in flight at once. Without
 * guarding against this, whichever response happens to resolve *last* wins
 * the final `setData`/`setIsLoading(false)` call — even if it's the stale
 * one — which can either show data for the wrong path, or (worse) leave
 * `isLoading` stuck `true` forever if the in-between transition that a
 * naive consumer relies on to know "the new request has started" never
 * actually occurs (isLoading was already `true` from the earlier request
 * and never dips back to `false` in between). `generationRef` tags every
 * request with a monotonically increasing number so a superseded response
 * is simply ignored when it resolves; `settledPathRef` tracks which path
 * the current `data`/`error` actually correspond to, so `isLoading` is
 * derived synchronously as "no confirmed-fresh response for the current
 * path yet" rather than solely from the async `.finally()` callback — this
 * closes the one-render gap between `path` changing and the effect below
 * actually kicking off the new request, without any bridging state needed
 * in the calling component.
 */

import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
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

	// See the bugfix note above.
	const settledPathRef = useRef( null );
	const generationRef  = useRef( 0 );

	const fetchData = useCallback( () => {
		const generation = ++generationRef.current;

		setIsLoading( true );
		setError( null );

		apiFetch( { path } )
			.then( ( response ) => {
				if ( generation !== generationRef.current ) {
					return; // A newer request has since superseded this one.
				}
				setData( response );
			} )
			.catch( ( err ) => {
				if ( generation !== generationRef.current ) {
					return;
				}
				setError(
					err?.message ?? 'An unexpected error occurred.'
				);
			} )
			.finally( () => {
				if ( generation !== generationRef.current ) {
					return;
				}
				settledPathRef.current = path;
				setIsLoading( false );
			} );
	}, [ path ] );

	useEffect( () => {
		fetchData();
	}, [ fetchData ] );

	// Loading whenever the async state says so, OR the current path hasn't
	// been confirmed-settled yet (covers the render where `path` has already
	// changed but the effect above hasn't run the new fetchData() yet).
	const isPathStale = settledPathRef.current !== path;

	return { data, isLoading: isLoading || isPathStale, error, refresh: fetchData };
}
