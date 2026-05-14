/**
 * CSV row validation results step — AORM-6.18.
 *
 * On mount this component either:
 *   a) POSTs the selected CSV file to POST /wicket-aorm/v1/uploads (when no
 *      session exists yet), then fetches the resulting staged records, OR
 *   b) Fetches staged records directly from GET /wicket-aorm/v1/staged-records/{id}
 *      when a session ID already exists (e.g. after navigating back and forward).
 *
 * Displays:
 *   - Counts summary bar: Total / Valid / Invalid / Duplicate.
 *   - Per-row table: row #, first name, last name, email, phone, status badge,
 *     and the validation reason for invalid or duplicate rows.
 *
 * Proceed / Re-upload action buttons are added in AORM-6.19.
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

import { useState, useEffect, useRef } from '@wordpress/element';
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
	orgUuid,
	membershipUuid,
	sessionId,
	uploadAction,
	selectedFile,
	startNewSession,
} ) {
	const [ isUploading, setIsUploading ] = useState( false );
	const [ isFetching, setIsFetching ] = useState( false );
	const [ records, setRecords ] = useState( [] );
	const [ error, setError ] = useState( null );

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

					const uploadResult = await apiFetch( {
						path: '/wicket-aorm/v1/uploads',
						method: 'POST',
						body: formData,
					} );

					currentSessionId = uploadResult.session_id;
					startNewSession( currentSessionId );
					setIsUploading( false );
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

	// ── Derived counts ────────────────────────────────────────────────────────

	const isLoading     = isUploading || isFetching;
	const validCount    = records.filter( ( r ) => r.validation_status === 'valid' ).length;
	const invalidCount  = records.filter( ( r ) => r.validation_status === 'invalid' ).length;
	const dupCount      = records.filter( ( r ) => r.validation_status === 'duplicate' ).length;
	const hasProblems   = invalidCount > 0 || dupCount > 0;

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
						{ isUploading
							? __( 'Uploading and validating file…', 'wicket-aorm' )
							: __( 'Loading validation results…', 'wicket-aorm' ) }
					</p>
				</div>
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
							{ __( 'All rows passed validation and are ready to sync.', 'wicket-aorm' ) }
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
											{ raw.mobile_phone || '—' }
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
						{ /* Proceed and Re-upload buttons implemented in AORM-6.19 */ }
					</div>
				</>
			) }

			{ /* Empty state */ }
			{ ! isLoading && ! error && records.length === 0 && (
				<Notice status="info" isDismissible={ false }>
					{ __( 'No rows were found for this upload session.', 'wicket-aorm' ) }
				</Notice>
			) }

		</div>
	);
}
