/**
 * MDP background matching progress step — AORM-7.13.
 *
 * Polls GET /wicket-aorm/v1/uploads/{sessionId}/status every 3 s while the
 * Action Scheduler matching job processes staged records. Renders a custom
 * progress bar and @wordpress/components Spinner so the admin has clear
 * feedback that work is happening. Navigates to validation-review
 * automatically once is_complete is true.
 *
 * Polling stops immediately on:
 *   - is_complete === true  → advances to validation-review after 800 ms.
 *   - A fetch error         → shows a Notice with a Retry button.
 *   - Component unmount     → clears the interval for clean teardown.
 *
 * @param {{
 *   goToStep:       (step: string) => void,
 *   sessionId:      string|null,
 * }} props
 */

import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Notice, Spinner } from '@wordpress/components';

import { apiFetch } from '../../utils/apiFetch';

// ── Constants ─────────────────────────────────────────────────────────────────

/** How often (ms) to re-query the status endpoint. */
export const POLL_INTERVAL_MS = 3000;

/** Delay (ms) after is_complete before advancing to the next step. */
export const COMPLETION_DELAY_MS = 800;

// ── Component ─────────────────────────────────────────────────────────────────

export default function MatchingProgressStep( { goToStep, sessionId } ) {
	const [ percentage, setPercentage ] = useState( 0 );
	const [ total,      setTotal      ] = useState( null );
	const [ processed,  setProcessed  ] = useState( 0 );
	const [ isComplete, setIsComplete ] = useState( false );
	const [ error,      setError      ] = useState( null );

	/** Holds the setInterval return value so we can clear it. */
	const intervalRef   = useRef( null );

	/** Tracks whether the component is still mounted to avoid setState after unmount. */
	const isMountedRef  = useRef( true );

	// ── Polling helpers ───────────────────────────────────────────────────────

	/** Cancel the active poll interval (no-op when none is running). */
	const clearPollInterval = useCallback( () => {
		if ( intervalRef.current !== null ) {
			clearInterval( intervalRef.current );
			intervalRef.current = null;
		}
	}, [] );

	/**
	 * Fetch the status endpoint once and update state.
	 * Stops polling on completion or error.
	 */
	const pollOnce = useCallback( async () => {
		if ( ! sessionId ) {
			clearPollInterval();
			setError(
				__(
					'No upload session found. Please go back and upload a file.',
					'wicket-aorm'
				)
			);
			return;
		}

		try {
			const data = await apiFetch( {
				path: `/wicket-aorm/v1/uploads/${ encodeURIComponent( sessionId ) }/status`,
			} );

			if ( ! isMountedRef.current ) {
				return;
			}

			setError( null );
			setPercentage( data.percentage ?? 0 );
			setTotal( data.total ?? 0 );
			setProcessed( data.processed ?? 0 );

			if ( data.is_complete ) {
				clearPollInterval();
				setIsComplete( true );
			}
		} catch ( err ) {
			if ( ! isMountedRef.current ) {
				return;
			}

			clearPollInterval();
			setError(
				err?.message ??
					__(
						'An error occurred while checking matching progress.',
						'wicket-aorm'
					)
			);
		}
	}, [ sessionId, clearPollInterval ] );

	/**
	 * (Re-)start the polling cycle.
	 * Fires one request immediately then schedules the interval.
	 * Safe to call on Retry — cancels any existing interval first.
	 */
	const startPolling = useCallback( () => {
		clearPollInterval();
		setError( null );
		setIsComplete( false );

		pollOnce();
		intervalRef.current = setInterval( pollOnce, POLL_INTERVAL_MS );
	}, [ pollOnce, clearPollInterval ] );

	// ── Mount / unmount effect ────────────────────────────────────────────────

	useEffect( () => {
		isMountedRef.current = true;
		startPolling();

		return () => {
			isMountedRef.current = false;
			clearPollInterval();
		};
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps — intentional mount-only

	// ── Advance to validation-review when complete ────────────────────────────

	useEffect( () => {
		if ( ! isComplete ) {
			return;
		}

		const timeout = setTimeout( () => {
			if ( isMountedRef.current ) {
				goToStep( 'validation-review' );
			}
		}, COMPLETION_DELAY_MS );

		return () => clearTimeout( timeout );
	}, [ isComplete, goToStep ] );

	// ── Derived display value ─────────────────────────────────────────────────

	const displayPercentage = isComplete ? 100 : percentage;

	// ── Render ────────────────────────────────────────────────────────────────

	return (
		<div className="aorm-wizard-step aorm-wizard-step--matching-progress">

			<h2 className="aorm-matching-progress__heading">
				{ __( 'Matching Records…', 'wicket-aorm' ) }
			</h2>

			<p className="aorm-matching-progress__description">
				{ __(
					'Member records are being matched against the MDP database. This may take a few minutes.',
					'wicket-aorm'
				) }
			</p>

			{ /* Error notice — shown when a poll request fails */ }
			{ error && (
				<Notice status="error" isDismissible={ false }>
					<p>{ error }</p>
					<Button
						variant="secondary"
						onClick={ startPolling }
					>
						{ __( 'Retry', 'wicket-aorm' ) }
					</Button>
				</Notice>
			) }

			{ /* Progress UI — visible while polling and on completion */ }
			{ ! error && (
				<div
					className="aorm-matching-progress__body"
					aria-live="polite"
					aria-label={ __( 'Matching progress', 'wicket-aorm' ) }
				>

					{ /* Spinner / completion icon + status line */ }
					<div className="aorm-matching-progress__spinner-row">
						{ isComplete ? (
							<span
								className="aorm-matching-progress__complete-icon"
								aria-hidden="true"
							>
								✓
							</span>
						) : (
							<Spinner />
						) }
						<p className="aorm-matching-progress__status-text">
							{ isComplete
								? __( 'Matching complete! Loading results…', 'wicket-aorm' )
								: __( 'Comparing records against MDP…', 'wicket-aorm' ) }
						</p>
					</div>

					{ /* Custom progress bar */ }
					<div
						className="aorm-matching-progress__bar-track"
						role="progressbar"
						aria-valuenow={ displayPercentage }
						aria-valuemin={ 0 }
						aria-valuemax={ 100 }
					>
						<div
							className="aorm-matching-progress__bar-fill"
							style={ { width: `${ displayPercentage }%` } }
						/>
					</div>

					{ /* Percentage / counts line */ }
					<p className="aorm-matching-progress__percentage" aria-live="off">
						{ total !== null
							? sprintf(
								/* translators: 1: processed records, 2: total records, 3: percentage */
								__( '%1$d / %2$d records matched (%3$d%%)', 'wicket-aorm' ),
								processed,
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
