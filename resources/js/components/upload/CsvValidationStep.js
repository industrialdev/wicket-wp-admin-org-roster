/**
 * CSV row validation results step — AORM-6.18 / AORM-6.19.
 *
 * On mount this component either:
 *   a) POSTs the selected CSV file to POST /wicket-aorm/v1/uploads (when no
 *      session exists yet), then fetches the resulting staged records, OR
 *   b) Fetches staged records directly from GET /wicket-aorm/v1/staged-records/{id}
 *      when a session ID already exists (e.g. after navigating back and forward).
 *
 * 409 conflict (AORM-6.7 session gate, e.g. after a page refresh):
 *   When the upload attempt returns 409, the component shows an inline conflict
 *   notice with two options:
 *     - Resume    — loads the existing session's staged records.
 *     - Start Fresh — calls DELETE /wicket-aorm/v1/uploads/{session_id} to remove
 *                     the stuck session, then returns to the upload-file step.
 *
 * Displays:
 *   - Counts summary bar: Total / Valid / Invalid / Duplicate.
 *   - Per-row table: row #, first name, last name, email, phone, status badge,
 *     and the validation reason for invalid or duplicate rows.
 *   - Proceed button (disabled when any rows are invalid/duplicate) — AORM-6.19.
 *   - Re-upload button (calls DELETE on current session, returns to step 1) — AORM-6.19.
 *
 * @param {{
 *   goToStep:        (step: string) => void,
 *   resetWizard:     () => void,
 *   orgUuid:         string,
 *   membershipUuid:  string,
 *   sessionId:       string|null,
 *   uploadAction:    string,
 *   selectedFile:    File|null,
 *   startNewSession: (id: string) => void,
 * }} props
 */

import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Notice, Spinner } from '@wordpress/components';
import { apiFetch } from '../../utils/apiFetch';

// ── Status badge config ───────────────────────────────────────────────────────

/** @type {Record<string, {label: string, className: string}>} */
const STATUS_CONFIG = {
	valid: {
		label: __( 'Valid', 'wicket-aorm' ),
		className: 'aorm-validation-badge aorm-validation-badge--valid',
	},
	invalid: {
		label: __( 'Invalid', 'wicket-aorm' ),
		className: 'aorm-validation-badge aorm-validation-badge--invalid',
	},
	duplicate: {
		label: __( 'Duplicate', 'wicket-aorm' ),
		className: 'aorm-validation-badge aorm-validation-badge--duplicate',
	},
};

// ── Component ─────────────────────────────────────────────────────────────────

export default function CsvValidationStep( {
	goToStep,
	resetWizard,
	orgUuid,
	membershipUuid,
	sessionId,
	uploadAction,
	selectedFile,
	startNewSession,
} ) {
	const [ isUploading, setIsUploading ] = useState( false );
	const [ isFetching, setIsFetching ] = useState( false );
	const [ isAbandoning, setIsAbandoning ] = useState( false );
	const [ records, setRecords ] = useState( [] );
	const [ error, setError ] = useState( null );

	/**
	 * Set when the upload returns 409 — holds the session_id of the existing
	 * active session so the conflict UI can offer Resume / Start Fresh.
	 *
	 * @type {[string|null, Function]}
	 */
	const [ conflictSessionId, setConflictSessionId ] = useState( null );

	// Guard against double-firing in React strict mode / HMR.
	const hasRun = useRef( false );

	useEffect( () => {
		if ( hasRun.current ) {
			return;
		}
		hasRun.current = true;

		async function run() {
			try {
				let currentSessionId = sessionId;

				// ── Step 1: upload if no session exists yet ────────────────
				if ( ! currentSessionId ) {
					if ( ! selectedFile ) {
						setError(
							__(
								'No file selected. Please go back and choose a CSV file.',
								'wicket-aorm'
							)
						);
						return;
					}

					setIsUploading( true );

					const formData = new FormData();
					formData.append( 'file', selectedFile );
					formData.append( 'org_uuid', orgUuid );
					formData.append( 'membership_uuid', membershipUuid );
					formData.append( 'action_type', uploadAction || 'add' );

					let uploadResult;
					try {
						uploadResult = await apiFetch( {
							path: '/wicket-aorm/v1/uploads',
							method: 'POST',
							body: formData,
						} );
					} catch ( uploadErr ) {
						// 409: an active session already exists (e.g. after a page refresh).
						// Surface the conflict UI instead of a generic error.
						if ( uploadErr?.session_id ) {
							setConflictSessionId( uploadErr.session_id );
							return;
						}
						throw uploadErr;
					} finally {
						setIsUploading( false );
					}

					currentSessionId = uploadResult.session_id;
					startNewSession( currentSessionId );
				}

				// ── Step 2: fetch staged records ───────────────────────────
				setIsFetching( true );
				const fetchedRecords = await apiFetch( {
					path: `/wicket-aorm/v1/staged-records/${ currentSessionId }`,
				} );
				setRecords( fetchedRecords ?? [] );
			} catch ( err ) {
				setError(
					err?.message ??
						__(
							'An error occurred during upload or validation.',
							'wicket-aorm'
						)
				);
			} finally {
				setIsUploading( false );
				setIsFetching( false );
			}
		}

		run();
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps -- intentional mount-only

	/**
	 * Resume the existing conflicted session: register its ID in wizard state
	 * and fetch its staged records directly.
	 */
	const handleResume = useCallback( async () => {
		const resumeId = conflictSessionId;
		setConflictSessionId( null );
		startNewSession( resumeId );

		setIsFetching( true );
		try {
			const fetchedRecords = await apiFetch( {
				path: `/wicket-aorm/v1/staged-records/${ resumeId }`,
			} );
			setRecords( fetchedRecords ?? [] );
		} catch ( err ) {
			setError(
				err?.message ??
					__(
						'Could not load the existing session. Please try again.',
						'wicket-aorm'
					)
			);
		} finally {
			setIsFetching( false );
		}
	}, [ conflictSessionId, startNewSession ] );

	/**
	 * Call DELETE /uploads/{id} to remove all rows for a session, ignoring
	 * errors (idempotent — session may already be gone).
	 *
	 * @param {string} id  The session UUID to delete.
	 */
	const abandonSession = useCallback( async ( id ) => {
		try {
			await apiFetch( {
				path: `/wicket-aorm/v1/uploads/${ id }`,
				method: 'DELETE',
			} );
		} catch {
			// Ignore — if the session is already gone that is fine.
		}
	}, [] );

	/**
	 * Re-upload handler for the "Re-upload" button shown after validation results.
	 * Deletes the current session on the server (if any) before resetting the wizard.
	 */
	const handleReupload = useCallback( async () => {
		if ( sessionId ) {
			setIsAbandoning( true );
			await abandonSession( sessionId );
			setIsAbandoning( false );
		}
		resetWizard();
		goToStep( 'upload-file' );
	}, [ sessionId, abandonSession, resetWizard, goToStep ] );

	/**
	 * "Start Fresh" handler shown in the 409 conflict notice.
	 * Deletes the stuck session on the server, then returns to the upload-file step.
	 */
	const handleStartFresh = useCallback( async () => {
		setIsAbandoning( true );
		await abandonSession( conflictSessionId );
		setIsAbandoning( false );
		resetWizard();
		goToStep( 'upload-file' );
	}, [ conflictSessionId, abandonSession, resetWizard, goToStep ] );

	// ── Derived counts ────────────────────────────────────────────────────────

	const isLoading    = isUploading || isFetching || isAbandoning;
	const validCount   = records.filter( ( r ) => r.validation_status === 'valid' ).length;
	const invalidCount = records.filter( ( r ) => r.validation_status === 'invalid' ).length;
	const dupCount     = records.filter( ( r ) => r.validation_status === 'duplicate' ).length;
	const hasProblems  = invalidCount > 0 || dupCount > 0;

	// ── Render ────────────────────────────────────────────────────────────────

	return (
		<div className="aorm-wizard-step aorm-wizard-step--csv-validation">

			{ /* Back — hidden while uploading so the admin can't abandon mid-request */ }
			{ ! isUploading && (
				<div className="aorm-wizard-step__back">
					<Button
						variant="tertiary"
						onClick={ () => goToStep( 'action-select' ) }
					>
						{ __( '← Back', 'wicket-aorm' ) }
					</Button>
				</div>
			) }

			<h2 className="aorm-csv-validation__heading">
				{ __( 'Validation Results', 'wicket-aorm' ) }
			</h2>

			{ /* Error notice */ }
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ /* Loading indicator */ }
			{ isLoading && (
				<div className="aorm-csv-validation__loading">
					<Spinner />
					<p className="aorm-csv-validation__loading-text">
						{ isAbandoning
							? __( 'Removing session…', 'wicket-aorm' )
							: isUploading
								? __( 'Uploading and validating file…', 'wicket-aorm' )
								: __( 'Loading validation results…', 'wicket-aorm' ) }
					</p>
				</div>
			) }

			{ /* 409 conflict — active session exists (e.g. after a page refresh) */ }
			{ conflictSessionId && ! isLoading && (
				<Notice status="warning" isDismissible={ false }>
					<p>
						{ __(
							'An upload session for this roster is already in progress. Would you like to resume it or start fresh with a new file?',
							'wicket-aorm'
						) }
					</p>
					<div className="aorm-csv-validation__conflict-actions">
						<Button
							variant="primary"
							onClick={ handleResume }
						>
							{ __( 'Resume', 'wicket-aorm' ) }
						</Button>
						<Button
							variant="secondary"
							isDestructive
							onClick={ handleStartFresh }
						>
							{ __( 'Start Fresh', 'wicket-aorm' ) }
						</Button>
					</div>
				</Notice>
			) }

			{ /* Results */ }
			{ ! isLoading && ! error && records.length > 0 && (
				<>
					{ /* ── Counts summary ────────────────────────────────────── */ }
					<div
						className="aorm-csv-validation__summary"
						role="status"
						aria-label={ __( 'Validation summary', 'wicket-aorm' ) }
					>
						<div className="aorm-csv-validation__summary-item aorm-csv-validation__summary-item--total">
							<span className="aorm-csv-validation__summary-count">
								{ records.length }
							</span>
							<span className="aorm-csv-validation__summary-label">
								{ __( 'Total Rows', 'wicket-aorm' ) }
							</span>
						</div>
						<div className="aorm-csv-validation__summary-item aorm-csv-validation__summary-item--valid">
							<span className="aorm-csv-validation__summary-count">
								{ validCount }
							</span>
							<span className="aorm-csv-validation__summary-label">
								{ __( 'Valid', 'wicket-aorm' ) }
							</span>
						</div>
						<div className="aorm-csv-validation__summary-item aorm-csv-validation__summary-item--invalid">
							<span className="aorm-csv-validation__summary-count">
								{ invalidCount }
							</span>
							<span className="aorm-csv-validation__summary-label">
								{ __( 'Invalid', 'wicket-aorm' ) }
							</span>
						</div>
						<div className="aorm-csv-validation__summary-item aorm-csv-validation__summary-item--duplicate">
							<span className="aorm-csv-validation__summary-count">
								{ dupCount }
							</span>
							<span className="aorm-csv-validation__summary-label">
								{ __( 'Duplicate', 'wicket-aorm' ) }
							</span>
						</div>
					</div>

					{ /* ── Problems / all-clear notice ────────────────────────── */ }
					{ hasProblems ? (
						<Notice
							status="warning"
							isDismissible={ false }
							className="aorm-csv-validation__status-notice"
						>
							{ sprintf(
								/* translators: 1: number of invalid rows, 2: number of duplicate rows */
								__(
									'%1$d invalid and %2$d duplicate row(s) were found. Rows with issues will be discarded and cannot be synced.',
									'wicket-aorm'
								),
								invalidCount,
								dupCount
							) }
						</Notice>
					) : (
						<Notice
							status="success"
							isDismissible={ false }
							className="aorm-csv-validation__status-notice"
						>
							{ __(
								"All rows passed validation. We're already checking these records against the MDP for duplicates in the background — click Proceed to view progress.",
								'wicket-aorm'
							) }
						</Notice>
					) }

					{ /* ── Per-row table ─────────────────────────────────────── */ }
					<table className="aorm-csv-validation__table wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th
									scope="col"
									className="aorm-csv-validation__col-row"
								>
									#
								</th>
								<th
									scope="col"
									className="aorm-csv-validation__col-name"
								>
									{ __( 'First Name', 'wicket-aorm' ) }
								</th>
								<th
									scope="col"
									className="aorm-csv-validation__col-name"
								>
									{ __( 'Last Name', 'wicket-aorm' ) }
								</th>
								<th
									scope="col"
									className="aorm-csv-validation__col-email"
								>
									{ __( 'Email', 'wicket-aorm' ) }
								</th>
								<th
									scope="col"
									className="aorm-csv-validation__col-phone"
								>
									{ __( 'Phone', 'wicket-aorm' ) }
								</th>
								<th
									scope="col"
									className="aorm-csv-validation__col-status"
								>
									{ __( 'Status', 'wicket-aorm' ) }
								</th>
								<th
									scope="col"
									className="aorm-csv-validation__col-reason"
								>
									{ __( 'Reason', 'wicket-aorm' ) }
								</th>
							</tr>
						</thead>
						<tbody>
							{ records.map( ( record, index ) => {
								const raw    = record.raw_data ?? {};
								const config =
									STATUS_CONFIG[ record.validation_status ] ??
									STATUS_CONFIG.invalid;

								return (
									<tr
										key={ record.id }
										className={ `aorm-csv-validation__row aorm-csv-validation__row--${ record.validation_status }` }
									>
										<td className="aorm-csv-validation__col-row">
											{ index + 1 }
										</td>
										<td className="aorm-csv-validation__col-name">
											{ raw.first_name || '—' }
										</td>
										<td className="aorm-csv-validation__col-name">
											{ raw.last_name || '—' }
										</td>
										<td className="aorm-csv-validation__col-email">
											{ raw.email || '—' }
										</td>
										<td className="aorm-csv-validation__col-phone">
											{ raw.phone || '—' }
										</td>
										<td className="aorm-csv-validation__col-status">
											<span className={ config.className }>
												{ config.label }
											</span>
										</td>
										<td className="aorm-csv-validation__col-reason">
											{ record.validation_message || '' }
										</td>
									</tr>
								);
							} ) }
						</tbody>
					</table>

					{ /* ── Actions (Proceed / Re-upload — AORM-6.19) ─────────── */ }
					<div className="aorm-csv-validation__actions">
						<Button
							variant="primary"
							disabled={ hasProblems }
							onClick={ () => goToStep( 'matching-progress' ) }
						>
							{ __( 'Proceed', 'wicket-aorm' ) }
						</Button>
						<Button
							variant="secondary"
							isBusy={ isAbandoning }
							disabled={ isAbandoning }
							onClick={ handleReupload }
						>
							{ __( 'Re-upload', 'wicket-aorm' ) }
						</Button>
					</div>
				</>
			) }

			{ /* Empty state — only shown when there is no conflict notice and no records */ }
			{ ! isLoading && ! error && ! conflictSessionId && records.length === 0 && (
				<Notice status="info" isDismissible={ false }>
					{ __( 'No rows were found for this upload session.', 'wicket-aorm' ) }
				</Notice>
			) }

		</div>
	);
}
