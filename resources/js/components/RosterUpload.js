/**
 * Roster Upload tab wizard — AORM-4.14 / AORM-5.7.
 *
 * Orchestrates the multi-step bulk upload + individual add flows.
 * Each step is a self-contained component that receives goToStep / shared
 * session props and renders its own UI.
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
 * State ownership
 * ----------------
 * This component is intentionally presentational — the wizard's step and
 * session state live in `useUploadWizardState()`, owned by the parent
 * (`OrgRosterDetail`) instead of here. That's a deliberate fix for a
 * tab-switch bug: `OrgRosterDetail`'s `TabPanel` unmounts this component
 * whenever the admin switches away from the "Roster Upload" tab, and
 * remounts a fresh instance when they switch back. State that lived in a
 * local `useState` here (seeded once from `window.aormContext.activeSession`,
 * a snapshot taken at the initial full page load) would reset to `'landing'`
 * on every remount, silently forgetting any session created client-side
 * since the page loaded — see `useUploadWizardState.js` for the full
 * writeup. Keeping state in the never-unmounted parent means it survives
 * tab switches for the lifetime of the page view.
 *
 * @param {Object} props
 * @param {string} props.orgUuid
 * @param {string} props.membershipUuid
 * @param {string} props.step             Current wizard step (see WIZARD_STEPS).
 * @param {string|null} props.sessionId
 * @param {string} props.uploadAction
 * @param {string|null} props.matchCategory
 * @param {File|null} props.selectedFile
 * @param {Function} props.goToStep
 * @param {Function} props.startNewSession
 * @param {Function} props.setMatchCategory
 * @param {Function} props.setUploadAction
 * @param {Function} props.setSelectedFile
 * @param {Function} props.resetWizard
 * @param {Function} [props.onGoToAssignment] Switches the parent TabPanel to the
 *                                            "Roster Assignment" tab. Passed through
 *                                            to SyncProgressStep, whose "Done" button
 *                                            calls it after clearing the session so
 *                                            the admin lands on the roster they just
 *                                            synced instead of back on the wizard.
 * @param {Function} [props.onSyncComplete]  Re-fetches the roster header data owned
 *                                            by OrgRosterDetail (its useRestApi()
 *                                            `refresh`). Passed through to
 *                                            SyncProgressStep, which calls it as soon
 *                                            as a sync finishes so the "Current
 *                                            Roster count" in RosterHeading reflects
 *                                            the sync instead of staying stuck at
 *                                            whatever it was on initial page load.
 */

import WizardLanding        from './upload/WizardLanding';
import IndividualAddForm    from './upload/IndividualAddForm';
import UploadFileStep       from './upload/UploadFileStep';
import ActionSelectStep     from './upload/ActionSelectStep';
import CsvValidationStep    from './upload/CsvValidationStep';
import MatchingProgressStep from './upload/MatchingProgressStep';
import ValidationReviewStep from './upload/ValidationReviewStep';
import SyncProgressStep     from './upload/SyncProgressStep';

import '../../css/roster-upload.css';

// Re-exported for backward compatibility — WIZARD_STEPS now lives alongside
// the wizard state it describes in useUploadWizardState.js.
export { WIZARD_STEPS } from '../hooks/useUploadWizardState';

export default function RosterUpload( {
	orgUuid,
	membershipUuid,
	step,
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
	onGoToAssignment,
	onSyncComplete,
} ) {
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
		onGoToAssignment,
		onSyncComplete,
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
