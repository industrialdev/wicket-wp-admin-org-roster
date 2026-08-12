/**
 * useUploadWizardState — owns the Roster Upload wizard's step/session state.
 *
 * Bugfix (tab-switch state loss, post-AORM-5.7/9.29): this state used to live
 * as local `useState` inside `RosterUpload.js`, seeded once from
 * `window.aormContext.activeSession` — a snapshot `Assets.php` computes at
 * the initial full page load (`Assets::resolveActiveSession()`). `RosterUpload`
 * only renders while the "Roster Upload" tab is active — `OrgRosterDetail`'s
 * `TabPanel` unmounts inactive tab content (it is not a persistent-mount tab
 * component) — so switching to "Roster Assignment" and back to "Roster
 * Upload" remounted `RosterUpload` and re-ran that `useState` initializer
 * against the *same stale* global. That global has no way to know a session
 * was created or advanced client-side since the page loaded (e.g. via
 * "Add Individual"), so the wizard reset to `'landing'` and let the admin
 * attempt a second individual add — which the server correctly rejected
 * with a 409, since a session genuinely still existed in the DB. The
 * confusing part was that nothing in the UI hinted a session was already in
 * progress. A full page refresh "fixed" it only because that re-runs PHP and
 * recomputes `activeSession` fresh from the database.
 *
 * Fix: this state now lives in the hook's caller, `OrgRosterDetail`, which
 * sits above the `TabPanel` and therefore never unmounts on tab switches.
 * `window.aormContext.activeSession` is read only once, on the very first
 * mount of the whole page; after that, wizard state lives in memory for the
 * lifetime of the page view and survives tab switching. `RosterUpload` is
 * now a purely presentational component driven by props (see its docblock).
 */

import { useState, useCallback } from '@wordpress/element';

/** Ordered list of all wizard step names. */
export const WIZARD_STEPS = [
	'landing',
	'individual-form',
	'upload-file',
	'action-select',
	'csv-validation',
	'matching-progress',
	'validation-review',
	'sync-progress',
];

/**
 * Derive the initial wizard step from the PHP-injected active session.
 *
 * Assets.php resolves the correct step server-side and passes it as
 * activeSession.step. The legacy isComplete boolean is no longer used.
 *
 * @param {{ sessionId: string, step: string }|null} activeSession
 * @returns {string}
 */
function initialStep( activeSession ) {
	if ( ! activeSession?.sessionId ) {
		return 'landing';
	}

	// Use the explicit step provided by the server when available.
	if ( activeSession.step && WIZARD_STEPS.includes( activeSession.step ) ) {
		return activeSession.step;
	}

	// Fallback for any cached/older server responses that still use isComplete.
	return activeSession.isComplete ? 'validation-review' : 'matching-progress';
}

/**
 * Owns the Roster Upload wizard's step/session state for the lifetime of the
 * page view. Call this once from a component that never unmounts across tab
 * switches (currently `OrgRosterDetail`), and spread the result into
 * `RosterUpload`'s props.
 *
 * @returns {{
 *   step: string,
 *   sessionId: (string|null),
 *   uploadAction: string,
 *   matchCategory: (string|null),
 *   selectedFile: (File|null),
 *   goToStep: Function,
 *   startNewSession: Function,
 *   setUploadAction: Function,
 *   setMatchCategory: Function,
 *   setSelectedFile: Function,
 *   resetWizard: Function,
 * }}
 */
export function useUploadWizardState() {
	// Active session injected by Assets.php at page-render time. Read only
	// once here — see the bugfix note above for why this must not be
	// re-read after the initial mount of the page.
	const activeSession = window.aormContext?.activeSession ?? null;

	/**
	 * Active wizard step — initialised from the PHP-injected session so the
	 * correct step is shown immediately on page load with no flash or extra
	 * request, regardless of which admin opened the page.
	 *
	 * @type {[string, Function]}
	 */
	const [ step, setStep ] = useState( () => initialStep( activeSession ) );

	/**
	 * Active upload session ID — pre-populated when Assets.php found an
	 * in-progress session, otherwise set after a CSV upload or individual
	 * add creates one.
	 *
	 * @type {[string|null, Function]}
	 */
	const [ sessionId, setSessionId ] = useState( activeSession?.sessionId ?? null );

	/**
	 * Bulk-upload action chosen by the admin: 'add' (default) or 'replace'.
	 * @type {[string, Function]}
	 */
	const [ uploadAction, setUploadAction ] = useState( 'add' );

	/**
	 * Match category returned by the individual add endpoint (AORM-5.7).
	 * One of 'ready_to_sync' | 'probable_match' | 'possible_match' | null.
	 *
	 * @type {[string|null, Function]}
	 */
	const [ matchCategory, setMatchCategory ] = useState( null );

	/**
	 * The File object chosen by the admin in the upload-file step.
	 * Lifted here so CsvValidationStep can POST it to the upload endpoint.
	 *
	 * @type {[File|null, Function]}
	 */
	const [ selectedFile, setSelectedFile ] = useState( null );

	/** Navigate to any named step. */
	const goToStep = useCallback( ( nextStep ) => {
		setStep( nextStep );
	}, [] );

	/** Record the upload session ID returned by the server after CSV upload. */
	const startNewSession = useCallback( ( newSessionId ) => {
		setSessionId( newSessionId );
	}, [] );

	/** Return the wizard to its initial state (landing + cleared session). */
	const resetWizard = useCallback( () => {
		setStep( 'landing' );
		setSessionId( null );
		setUploadAction( 'add' );
		setMatchCategory( null );
		setSelectedFile( null );
	}, [] );

	return {
		step,
		sessionId,
		uploadAction,
		matchCategory,
		selectedFile,
		goToStep,
		startNewSession,
		setUploadAction,
		setMatchCategory,
		setSelectedFile,
		resetWizard,
	};
}
