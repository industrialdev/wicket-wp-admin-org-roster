/**
 * useStagedRecords — manages staged records for a given upload session.
 *
 * Fetches the staged rows for a session ID and exposes helpers for
 * triggering a sync and refreshing the list.
 *
 * @example
 * const { records, isLoading, isSyncing, sync, error } =
 *   useStagedRecords( sessionId );
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import { apiFetch } from '../utils/apiFetch';

/**
 * @typedef {Object} StagedRecord
 * @property {number} id
 * @property {string} upload_session_id
 * @property {string} status   - 'pending' | 'matched' | 'synced' | 'error'
 * @property {Object} data     - Parsed row data.
 * @property {string|null} mdp_person_uuid
 * @property {string|null} error_message
 */

/**
 * @param {string|null} sessionId - The upload session UUID.
 * @returns {{
 *   records: StagedRecord[],
 *   isLoading: boolean,
 *   isSyncing: boolean,
 *   error: string|null,
 *   refresh: Function,
 *   sync: Function,
 * }}
 */
export function useStagedRecords( sessionId ) {
	const [ records, setRecords ] = useState( [] );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ isSyncing, setIsSyncing ] = useState( false );
	const [ error, setError ] = useState( null );

	const fetchRecords = useCallback( () => {
		if ( ! sessionId ) {
			return;
		}

		setIsLoading( true );
		setError( null );

		apiFetch( { path: `/wicket-aorm/v1/staged-records/${ sessionId }` } )
			.then( ( response ) => {
				setRecords( response ?? [] );
			} )
			.catch( ( err ) => {
				setError( err?.message ?? 'Failed to load staged records.' );
			} )
			.finally( () => {
				setIsLoading( false );
			} );
	}, [ sessionId ] );

	/**
	 * Kick off a sync for the current session, then refresh the record list.
	 *
	 * @param {'add'|'replace'} mode - Bulk action mode.
	 */
	const sync = useCallback(
		( mode = 'add' ) => {
			if ( ! sessionId ) {
				return;
			}

			setIsSyncing( true );
			setError( null );

			apiFetch( {
				path: `/wicket-aorm/v1/sync/${ sessionId }`,
				method: 'POST',
				data: { mode },
			} )
				.then( () => {
					fetchRecords();
				} )
				.catch( ( err ) => {
					setError( err?.message ?? 'Sync failed.' );
				} )
				.finally( () => {
					setIsSyncing( false );
				} );
		},
		[ sessionId, fetchRecords ]
	);

	useEffect( () => {
		fetchRecords();
	}, [ fetchRecords ] );

	return { records, isLoading, isSyncing, error, refresh: fetchRecords, sync };
}
