/**
 * Commit & MDP sync progress step — AORM-9.29.
 *
 * Renders two phases:
 *
 * 1. **Syncing** — polls GET /wicket-aorm/v1/uploads/{sessionId}/sync-status
 *    every POLL_INTERVAL_MS ms and shows a Spinner + progress bar while
 *    `pending > 0`.  Stops automatically when `is_complete === true`.
 *
 * 2. **Results** — once `is_complete` is true, shows:
 *    - A success Notice with the synced count.
 *    - An error Notice + failed-records list when `failed > 0`.
 *    - A **Retry Failed Records** button that re-fires
 *      POST /wicket-aorm/v1/uploads/{sessionId}/commit with only the
 *      failed record IDs, then resets to the Syncing phase.
 *    - A **Done** button that clears the session (DELETE /uploads/{sessionId},
 *      errors ignored — idempotent, same pattern as CsvValidationStep's
 *      abandonSession()), calls `resetWizard()` so the wizard is back at
 *      "landing" whenever the admin next opens the Roster Upload tab, and
 *      then calls `onGoToAssignment()` (when provided) to switch the parent
 *      TabPanel to the Roster Assignment tab — so the admin lands on the
 *      roster they just synced rather than back on the upload wizard.
 *
 * Polling stops immediately on:
 *   - `is_complete === true`   → transitions to Results phase after COMPLETION_DELAY_MS.
 *   - A fetch error            → shows an error Notice with Retry and Start Over buttons.
 *   - Component unmount        → clears the interval for clean teardown.
 *
 * A poll error most commonly means the session is no longer reachable (e.g.
 * "Upload session not found." — abandoned or cleaned up from another tab).
 * Retry alone would just re-poll the same dead session forever, so a
 * "Start Over" button is shown alongside it, calling resetWizard() directly
 * — unlike the Done button, this skips the DELETE call since the session is
 * already confirmed gone server-side.
 *
 * Bugfix ("Current Roster count" not updating after sync): RosterHeading's
 * assigned_count comes from a roster object OrgRosterDetail fetches exactly
 * once, on page mount — nothing re-fetched it once a sync ran, since the
 * admin never leaves the page during upload → sync. The optional
 * `onSyncComplete` prop is OrgRosterDetail's useRestApi() `refresh` for that
 * roster data; it's called as soon as `is_complete` is detected (i.e. the
 * moment the Results phase is reached), not gated behind clicking "Done",
 * so the header count is correct as soon as the admin sees the sync results.
 *
 * @param {{
 *   sessionId:        string|null,
 *   resetWizard:      () => void,
 *   onGoToAssignment: (() => void)|undefined,
 *   onSyncComplete:   (() => void)|undefined,
 * }} props
 */

import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Notice, Spinner } from '@wordpress/components';

import { apiFetch } from '../../utils/apiFetch';

// ── Constants (exported for test assertions) ───────────────────────────────────

/** Polling interval (ms) while sync is in progress. */
export const POLL_INTERVAL_MS = 3000;

/** Delay (ms) after is_complete before transitioning to the Results phase. */
export const COMPLETION_DELAY_MS = 600;

/** CSS class for the results wrapper. */
export const RESULTS_CLASS = 'aorm-sync-progress__results';

/** CSS class for the failed-records list section. */
export const FAILED_RECORDS_CLASS = 'aorm-sync-progress__failed-records';

/** CSS class for the Retry Failed Records button. */
export const RETRY_BTN_CLASS = 'aorm-sync-progress__retry-btn';

/** CSS class for the Done button. */
export const DONE_BTN_CLASS = 'aorm-sync-progress__done-btn';

/** CSS class applied to the success Notice. */
export const SUCCESS_NOTICE_CLASS = 'aorm-sync-progress__notice--success';

/** CSS class applied to the failure Notice. */
export const FAILURE_NOTICE_CLASS = 'aorm-sync-progress__notice--failure';

/**
 * CSS class for the "Start Over" button shown alongside a poll error.
 * Exported so tests can assert presence without duplicating the class name.
 *
 * @type {string}
 */
export const START_OVER_BTN_CLASS = 'aorm-sync-progress__start-over-btn';

// ── Component ──────────────────────────────────────────────────────────────────

export default function SyncProgressStep( { sessionId, resetWizard, onGoToAssignment, onSyncComplete } ) {

	// ── Phase ─────────────────────────────────────────────────────────────────

	/** 'syncing' while the background job is running; 'complete' after is_complete. */
	const [ phase,         setPhase         ] = useState( 'syncing' );

	// ── Syncing-phase state ───────────────────────────────────────────────────

	const [ percentage,    setPercentage    ] = useState( 0 );
	const [ total,         setTotal         ] = useState( null );
	const [ synced,        setSynced        ] = useState( 0 );
	const [ failed,        setFailed        ] = useState( 0 );
	const [ pollError,     setPollError     ] = useState( null );

	// ── Results-phase state ───────────────────────────────────────────────────

	const [ finalSynced,   setFinalSynced   ] = useState( 0 );
	const [ finalFailed,   setFinalFailed   ] = useState( 0 );
	const [ failedRecords, setFailedRecords ] = useState( [] );

	// ── Retry state ───────────────────────────────────────────────────────────

	/**
	 * Incrementing counter that re-triggers the polling useEffect.
	 * Incremented by handleRetry after a successful commit re-submission.
	 */
	const [ retryKey,    setRetryKey    ] = useState( 0 );
	const [ isRetrying,  setIsRetrying  ] = useState( false );
	const [ retryError,  setRetryError  ] = useState( null );

	// ── Done state ────────────────────────────────────────────────────────────

	const [ isClearingSession, setIsClearingSession ] = useState( false );

	// ── Refs ──────────────────────────────────────────────────────────────────

	const intervalRef  = useRef( null );
	const isMountedRef = useRef( true );

	// ── Polling helpers ───────────────────────────────────────────────────────

	const clearPollInterval = useCallback( () => {
		if ( intervalRef.current !== null ) {
			clearInterval( intervalRef.current );
			intervalRef.current = null;
		}
	}, [] );

	const pollOnce = useCallback( async () => {
		if ( ! sessionId ) {
			clearPollInterval();
			setPollError(
				__( 'No upload session found. Please go back and try again.', 'wicket-aorm' )
			);

			return;
		}

		try {
			const data = await apiFetch( {
				path: `/wicket-aorm/v1/uploads/${ encodeURIComponent( sessionId ) }/sync-status`,
			} );

			if ( ! isMountedRef.current ) {
				return;
			}

			setPollError( null );
			setPercentage( data.percentage ?? 0 );
			setTotal( data.total ?? 0 );
			setSynced( data.synced ?? 0 );
			setFailed( data.failed ?? 0 );

			if ( data.is_complete ) {
				clearPollInterval();

				setFinalSynced( data.synced ?? 0 );
				setFinalFailed( data.failed ?? 0 );
				setFailedRecords( data.failed_records ?? [] );

				// Refresh the roster header's assigned_count (see the bugfix
				// note in the file docblock) as soon as the sync is known to
				// be complete, rather than waiting for the admin to click
				// "Done" — the count should already be correct by the time
				// the Results phase renders.
				onSyncComplete?.();

				setTimeout( () => {
					if ( isMountedRef.current ) {
						setPhase( 'complete' );
					}
				}, COMPLETION_DELAY_MS );
			}
		} catch ( err ) {
			if ( ! isMountedRef.current ) {
				return;
			}

			clearPollInterval();
			setPollError(
				err?.message ??
					__( 'An error occurred while checking sync progress.', 'wicket-aorm' )
			);
		}
	}, [ sessionId, clearPollInterval, onSyncComplete ] );

	const startPolling = useCallback( () => {
		clearPollInterval();
		setPollError( null );
		setPhase( 'syncing' );

		pollOnce();
		intervalRef.current = setInterval( pollOnce, POLL_INTERVAL_MS );
	}, [ pollOnce, clearPollInterval ] );

	// ── Mount / unmount / retryKey effect ─────────────────────────────────────

	useEffect( () => {
		isMountedRef.current = true;
		startPolling();

		return () => {
			isMountedRef.current = false;
			clearPollInterval();
		};
	}, [ retryKey ] ); // eslint-disable-line react-hooks/exhaustive-deps — intentional: re-run on retryKey to restart polling after retry

	// ── Retry handler ─────────────────────────────────────────────────────────

	const handleRetry = useCallback( async () => {
		if ( ! sessionId || failedRecords.length === 0 ) {
			return;
		}

		setIsRetrying( true );
		setRetryError( null );

		const ids = failedRecords.map( ( r ) => r.id );

		try {
			await apiFetch( {
				path:   `/wicket-aorm/v1/uploads/${ encodeURIComponent( sessionId ) }/commit`,
				method: 'POST',
				data:   { ids },
			} );

			if ( ! isMountedRef.current ) {
				return;
			}

			// Reset progress counters before re-entering the Syncing phase.
			setPercentage( 0 );
			setTotal( null );
			setSynced( 0 );
			setFailed( 0 );
			setFinalSynced( 0 );
			setFinalFailed( 0 );
			setFailedRecords( [] );

			// Incrementing retryKey restarts the polling useEffect.
			setRetryKey( ( k ) => k + 1 );
		} catch ( err ) {
			if ( ! isMountedRef.current ) {
				return;
			}

			setRetryError(
				err?.message ??
					__( 'Failed to resubmit records for sync. Please try again.', 'wicket-aorm' )
			);
		} finally {
			if ( isMountedRef.current ) {
				setIsRetrying( false );
			}
		}
	}, [ sessionId, failedRecords ] );

	// ── Done handler ──────────────────────────────────────────────────────────

	/**
	 * Clears the staged-records session on the server before returning the
	 * wizard to its initial state, so a fully-synced session doesn't linger
	 * until the cleanup TTL job purges it. Mirrors CsvValidationStep's
	 * abandonSession(): DELETE errors are ignored — deleting an
	 * already-cleared or unknown session is a no-op on the server, and Done
	 * should always be able to return the admin to the landing step.
	 *
	 * After the wizard is reset, hands off to `onGoToAssignment()` (when
	 * provided) to switch the admin to the Roster Assignment tab, so they
	 * land on the roster they just synced instead of back on the (now blank)
	 * upload wizard.
	 */
	const handleDone = useCallback( async () => {
		if ( sessionId ) {
			setIsClearingSession( true );

			try {
				await apiFetch( {
					path:   `/wicket-aorm/v1/uploads/${ encodeURIComponent( sessionId ) }`,
					method: 'DELETE',
				} );
			} catch {
				// Ignore — idempotent; the session may already be gone.
			} finally {
				if ( isMountedRef.current ) {
					setIsClearingSession( false );
				}
			}
		}

		resetWizard();
		onGoToAssignment?.();
	}, [ sessionId, resetWizard, onGoToAssignment ] );

	// ── Derived display value ─────────────────────────────────────────────────

	const displayPercentage = phase === 'complete' ? 100 : percentage;

	// ── Render — Syncing phase ────────────────────────────────────────────────

	if ( phase === 'syncing' ) {
		return (
			<div className="aorm-wizard-step aorm-wizard-step--sync-progress">

				<h2 className="aorm-sync-progress__heading">
					{ __( 'Syncing to MDP…', 'wicket-aorm' ) }
				</h2>

				<p className="aorm-sync-progress__description">
					{ __(
						'Records are being synced to the MDP database in the background. This may take a few minutes.',
						'wicket-aorm'
					) }
				</p>

				{ /* Poll error — shown when a status request fails */ }
				{ pollError && (
					<Notice status="error" isDismissible={ false }>
						<p>{ pollError }</p>
						<Button variant="secondary" onClick={ startPolling }>
							{ __( 'Retry', 'wicket-aorm' ) }
						</Button>
						{ resetWizard && (
							<Button
								variant="tertiary"
								className={ START_OVER_BTN_CLASS }
								onClick={ resetWizard }
							>
								{ __( 'Start Over', 'wicket-aorm' ) }
							</Button>
						) }
					</Notice>
				) }

				{ ! pollError && (
					<div
						className="aorm-sync-progress__body"
						aria-live="polite"
						aria-label={ __( 'Sync progress', 'wicket-aorm' ) }
					>
						<div className="aorm-sync-progress__spinner-row">
							<Spinner />
							<p className="aorm-sync-progress__status-text">
								{ __( 'Syncing records to MDP…', 'wicket-aorm' ) }
							</p>
						</div>

						<div
							className="aorm-sync-progress__bar-track"
							role="progressbar"
							aria-valuenow={ displayPercentage }
							aria-valuemin={ 0 }
							aria-valuemax={ 100 }
						>
							<div
								className="aorm-sync-progress__bar-fill"
								style={ { width: `${ displayPercentage }%` } }
							/>
						</div>

						<p className="aorm-sync-progress__percentage" aria-live="off">
							{ total !== null
								? sprintf(
									/* translators: 1: processed count, 2: total count, 3: percentage */
									__( '%1$d / %2$d records processed (%3$d%%)', 'wicket-aorm' ),
									synced + failed,
									total,
									displayPercentage
								)
								: sprintf(
									/* translators: %d: completion percentage */
									__( '%d%% complete', 'wicket-aorm' ),
									displayPercentage
								) }
						</p>

					</div>
				) }

			</div>
		);
	}

	// ── Render — Results phase ────────────────────────────────────────────────

	return (
		<div className="aorm-wizard-step aorm-wizard-step--sync-progress">

			<h2 className="aorm-sync-progress__heading">
				{ __( 'Sync Complete', 'wicket-aorm' ) }
			</h2>

			<div className={ RESULTS_CLASS }>

				{ /* Success count */ }
				{ finalSynced > 0 && (
					<Notice
						status="success"
						isDismissible={ false }
						className={ SUCCESS_NOTICE_CLASS }
					>
						{ sprintf(
							/* translators: %d: number of successfully synced records */
							__( '%d record(s) synced successfully.', 'wicket-aorm' ),
							finalSynced
						) }
					</Notice>
				) }

				{ /* Failure count + detail list */ }
				{ finalFailed > 0 && (
					<>
						<Notice
							status="error"
							isDismissible={ false }
							className={ FAILURE_NOTICE_CLASS }
						>
							{ sprintf(
								/* translators: %d: number of records that failed to sync */
								__( '%d record(s) failed to sync.', 'wicket-aorm' ),
								finalFailed
							) }
						</Notice>

						{ failedRecords.length > 0 && (
							<div className={ FAILED_RECORDS_CLASS }>
								<h3 className="aorm-sync-progress__failed-heading">
									{ __( 'Failed records', 'wicket-aorm' ) }
								</h3>

								<table className="aorm-sync-progress__failed-table wp-list-table widefat fixed striped">
									<thead>
										<tr>
											<th>{ __( 'Name', 'wicket-aorm' ) }</th>
											<th>{ __( 'Email', 'wicket-aorm' ) }</th>
											<th>{ __( 'Error', 'wicket-aorm' ) }</th>
										</tr>
									</thead>
									<tbody>
										{ failedRecords.map( ( record ) => {
											const raw  = record.raw_data ?? {};
											const name = [ raw.first_name, raw.last_name ]
												.filter( Boolean )
												.join( ' ' ) || '—';

											return (
												<tr
													key={ record.id }
													className="aorm-sync-progress__failed-row"
												>
													<td>{ name }</td>
													<td>{ raw.email_address || '—' }</td>
													<td className="aorm-sync-progress__error-detail">
														{ record.error_details || '—' }
													</td>
												</tr>
											);
										} ) }
									</tbody>
								</table>
							</div>
						) }

						{ retryError && (
							<Notice status="error" isDismissible={ false }>
								{ retryError }
							</Notice>
						) }

						<Button
							variant="secondary"
							isBusy={ isRetrying }
							disabled={ isRetrying }
							onClick={ handleRetry }
							className={ RETRY_BTN_CLASS }
						>
							{ __( 'Retry Failed Records', 'wicket-aorm' ) }
						</Button>
					</>
				) }

				{ /* Edge case: job ran but nothing was processed */ }
				{ finalSynced === 0 && finalFailed === 0 && (
					<Notice status="info" isDismissible={ false }>
						{ __( 'No records were processed.', 'wicket-aorm' ) }
					</Notice>
				) }

				<div className="aorm-sync-progress__footer">
					<Button
						variant="primary"
						onClick={ handleDone }
						isBusy={ isClearingSession }
						disabled={ isClearingSession }
						className={ DONE_BTN_CLASS }
					>
						{ __( 'Done', 'wicket-aorm' ) }
					</Button>
				</div>

			</div>

		</div>
	);
}
