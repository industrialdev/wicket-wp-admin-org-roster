/**
 * apiFetch wrapper.
 *
 * Configures @wordpress/api-fetch once with the nonce and REST root
 * injected by Assets.php, then re-exports it so all hooks import
 * from a single place.
 *
 * Assets.php calls wp_localize_script() to expose:
 *   window.aormSettings.restUrl  — the WP REST root URL
 *   window.aormSettings.nonce    — wp_create_nonce( 'wp_rest' )
 */

import apiFetchLib from '@wordpress/api-fetch';

const settings = window.aormSettings ?? {};

if ( settings.nonce ) {
	apiFetchLib.use( apiFetchLib.createNonceMiddleware( settings.nonce ) );
}

if ( settings.restUrl ) {
	apiFetchLib.use( apiFetchLib.createRootURLMiddleware( settings.restUrl ) );
}

/**
 * Pre-configured apiFetch. Use exactly like @wordpress/api-fetch.
 *
 * @type {typeof import('@wordpress/api-fetch').default}
 */
export const apiFetch = apiFetchLib;
