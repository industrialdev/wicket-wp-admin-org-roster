/**
 * Roster Upload tab wizard — AORM-4.14 / AORM-5.7.
 *
 * Orchestrates the multi-step bulk upload + individual add flows.
 * State lives here; each step is a self-contained component that
 * receives goToStep / shared session props and renders its own UI.
 *
 * Steps
 * -----
 * landing           — choose "Add Individual" or "Bulk CSV Upload"
 * individual-form   — individual add form          (AORM-5)
 * upload-file       — CSV file drop / picker       (AORM-6 step 1)
 * action-select     — add-to-roster vs replace     (AORM-6 step 2)
 * csv-validation    — validation results screen     (AORM-6.18)
 * matching-progress — MDP background matching      (AORM-7)
 * validation-review — accordion review screen      (AORM-8)
 * sync-progress     — commit & sync progress       (AORM-9)
 *
 * Initial step resolution
 * -----------------------
 * Assets.php queries the database at page-render time and injects an
 * `activeSession` object into window.aormContext:
 *
 *   null                        → no active session → start at 'landing'
 *   { isComplete: false, … }    → matching in progress → 'matching-progress'
 *   { isComplete: true,  … }    → matching done        → 'validation-review'
 *
 * Exported constants
 * ------------------
 * WIZARD_STEPS — ordered array of all step names (useful for tests).
 *
 * @param {{ orgUuid: string, membershipUuid: string }} props
 */

import { useState, useCallback } from '@wordpress/element';

import WizardLanding        from './upload/WizardLanding';
import IndividualAddForm    from './upload/IndividualAddForm';
import UploadFileStep       from './upload/UploadFileStep';
import ActionSelectStep     from './upload/ActionSelectStep';
import CsvValidationStep    from './upload/CsvValidationStep';
import MatchingProgressStep from './upload/MatchingProgressStep';
import ValidationReviewStep from './upload/ValidationReviewStep';
import SyncProgressStep     from './upload/SyncProgressStep';

import '../../css/roster-upload.css';

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
 * @param {{ sessionId: string, isComplete: boolean }|null} activeSession
 * @returns {string}
 */
function initialStep( activeSession ) {
	if ( ! activeSession?.sessionId ) {
		return 'landing';
	}

	return activeSession.isComplete ? 'validation-review' : 'matching-progress';
}

export default function RosterUpload( { orgUuid, membershipUuid } ) {
	// Active session injected by Assets.php at page-render time.
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
	 * in-progress session, otherwise set after a CSV upload creates one.
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

	// ── Wizard actions ────────────────────────────────────────────────────────

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

	// ── Render ────────────────────────────────────────────────────────────────

	/** Props forwarded to every step component. */
	const sharedProps = {
		orgUuid,
		membershipUuid,
		sessionId,
		uploadAction,
		matchCategory,
		selectedFile,
		goToStep,
		startNewSession,
		setMatchCategory,
		setUploadAction,
		setSelectedFile,
		resetWizard,
	};

	return (
		<div className="aorm-upload-wizard" data-step={ step }>
			{ step === 'landing' && (
				<WizardLanding { ...sharedProps } />
			) }
			{ step === 'individual-form' && (
				<IndividualAddForm { ...sharedProps } />
			) }
			{ step === 'upload-file' && (
				<UploadFileStep { ...sharedProps } />
			) }
			{ step === 'action-select' && (
				<ActionSelectStep { ...sharedProps } />
			) }
			{ step === 'csv-validation' && (
				<CsvValidationStep { ...sharedProps } />
			) }
			{ step === 'matching-progress' && (
				<MatchingProgressStep { ...sharedProps } />
			) }
			{ step === 'validation-review' && (
				<ValidationReviewStep { ...sharedProps } />
			) }
			{ step === 'sync-progress' && (
				<SyncProgressStep { ...sharedProps } />
			) }
		</div>
	);
}
