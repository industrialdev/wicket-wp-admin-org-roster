/**
 * CSV file upload step — AORM-6.2 / AORM-6.3.
 *
 * Provides two ways to select a CSV file:
 *   1. Drag-and-drop via @wordpress/components DropZone.
 *   2. File picker via @wordpress/components FormFileUpload.
 *
 * Also renders a "Download template" link (AORM-6.3) so administrators can
 * obtain an empty CSV with the correct column headers before uploading.
 * The URL is read from window.aormContext.templateDownloadUrl (injected by
 * Assets::buildLocalizationData()).
 *
 * Only .csv files are accepted. Full client-side validation (size, MIME)
 * is added in AORM-6.4. Session existence gate is added in AORM-6.7.
 * Actual upload + parse is wired in AORM-6.5 / AORM-6.10.
 *
 * On success: navigates to the action-select step (AORM-6.11).
 *
 * @param {{
 *   goToStep: (step: string) => void,
 *   orgUuid: string,
 *   membershipUuid: string,
 *   startNewSession: (id: string) => void,
 * }} props
 */

import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, DropZone, FormFileUpload } from '@wordpress/components';

/** Only .csv files are accepted at this step. */
const ACCEPTED_EXTENSION = '.csv';

/**
 * Returns true when the given File has a .csv extension (case-insensitive).
 *
 * MIME type is intentionally not checked here — browsers and operating
 * systems report CSV files inconsistently (text/csv, text/plain,
 * application/vnd.ms-excel, or an empty string). Extension matching is
 * the most reliable client-side gate; AORM-6.4 adds a richer check.
 *
 * @param {File} file
 * @returns {boolean}
 */
function isCsvFile( file ) {
	return file.name.toLowerCase().endsWith( '.csv' );
}

export default function UploadFileStep( {
	goToStep,
	orgUuid,       // eslint-disable-line no-unused-vars — forwarded in AORM-6.5
	membershipUuid, // eslint-disable-line no-unused-vars — forwarded in AORM-6.5
	startNewSession, // eslint-disable-line no-unused-vars — called in AORM-6.5
} ) {
	/** The file the admin has chosen, or null when nothing is selected. */
	const [ selectedFile, setSelectedFile ] = useState( null );

	/** Non-empty string when the dropped / chosen file is not a CSV. */
	const [ fileError, setFileError ] = useState( '' );

	// -----------------------------------------------------------------------
	// Event handlers
	// -----------------------------------------------------------------------

	/**
	 * Accept a file — clears any prior error and records the file in state.
	 *
	 * @param {File} file
	 */
	function acceptFile( file ) {
		setFileError( '' );
		setSelectedFile( file );
	}

	/**
	 * Called by DropZone when files are dropped onto the zone.
	 * Only the first file is used; non-CSV files are rejected with an error.
	 *
	 * @param {File[]} files
	 */
	function handleDrop( files ) {
		const file = files[ 0 ];
		if ( ! file ) {
			return;
		}
		if ( ! isCsvFile( file ) ) {
			setFileError(
				sprintf(
					// translators: %s is the dropped file name.
					__( '"%s" is not a CSV file. Please select a .csv file.', 'wicket-aorm' ),
					file.name
				)
			);
			return;
		}
		acceptFile( file );
	}

	/**
	 * Called by FormFileUpload when the admin picks a file via the OS dialog.
	 *
	 * @param {React.ChangeEvent<HTMLInputElement>} event
	 */
	function handleBrowse( event ) {
		const file = event.target.files?.[ 0 ];
		if ( ! file ) {
			return;
		}
		// The <input accept> attribute already restricts the picker on most
		// browsers, but we validate defensively in case the user bypassed it.
		if ( ! isCsvFile( file ) ) {
			setFileError(
				sprintf(
					// translators: %s is the file name the user chose.
					__( '"%s" is not a CSV file. Please select a .csv file.', 'wicket-aorm' ),
					file.name
				)
			);
			return;
		}
		acceptFile( file );
	}

	/** Remove the currently selected file and reset any error. */
	function handleRemove() {
		setSelectedFile( null );
		setFileError( '' );
	}

	// -----------------------------------------------------------------------
	// Render
	// -----------------------------------------------------------------------

	return (
		<div className="aorm-wizard-step aorm-wizard-step--upload-file">

			{ /* Back navigation */ }
			<div className="aorm-wizard-step__back">
				<Button
					variant="tertiary"
					onClick={ () => goToStep( 'landing' ) }
				>
					{ __( '← Back', 'wicket-aorm' ) }
				</Button>
			</div>

			<h2 className="aorm-upload-file__heading">
				{ __( 'Upload CSV Roster File', 'wicket-aorm' ) }
			</h2>

			<p className="aorm-upload-file__description">
				{ __(
					'Drag and drop a CSV file into the area below, or click "Browse files" to open the file picker.',
					'wicket-aorm'
				) }
			</p>

			{ /* Template download link — AORM-6.3 */ }
			{ window.aormContext?.templateDownloadUrl && (
				<p className="aorm-upload-file__template-link">
					<a
						href={ window.aormContext.templateDownloadUrl }
						className="aorm-upload-file__template-anchor"
						download="roster-template.csv"
					>
						<span
							className="dashicons dashicons-download"
							aria-hidden="true"
						/>
						{ __( 'Download empty CSV template', 'wicket-aorm' ) }
					</a>
				</p>
			) }

			{ /* Drop zone — position:relative required for DropZone overlay */ }
			<div
				className="aorm-upload-file__drop-zone"
				aria-label={ __( 'CSV file drop zone', 'wicket-aorm' ) }
			>
				{ /* DropZone renders its overlay inside this positioned container */ }
				<DropZone onFilesDrop={ handleDrop } />

				{ ! selectedFile ? (
					<div className="aorm-upload-file__drop-zone-content">
						<span
							className="dashicons dashicons-upload aorm-upload-file__drop-icon"
							aria-hidden="true"
						/>
						<p className="aorm-upload-file__drop-label">
							{ __( 'Drop your CSV file here, or', 'wicket-aorm' ) }
						</p>
						<FormFileUpload
							accept={ ACCEPTED_EXTENSION }
							onChange={ handleBrowse }
						>
							{ __( 'Browse files', 'wicket-aorm' ) }
						</FormFileUpload>
						<p className="aorm-upload-file__drop-hint">
							{ __( 'Accepted format: .csv', 'wicket-aorm' ) }
						</p>
					</div>
				) : (
					<div className="aorm-upload-file__selected-file">
						<span
							className="dashicons dashicons-media-spreadsheet aorm-upload-file__file-icon"
							aria-hidden="true"
						/>
						<span className="aorm-upload-file__file-name">
							{ selectedFile.name }
						</span>
						<Button
							variant="tertiary"
							isDestructive
							onClick={ handleRemove }
							aria-label={ __( 'Remove selected file', 'wicket-aorm' ) }
						>
							{ __( 'Remove', 'wicket-aorm' ) }
						</Button>
					</div>
				) }
			</div>

			{ /* File type error */ }
			{ fileError && (
				<p
					className="aorm-upload-file__error"
					role="alert"
				>
					{ fileError }
				</p>
			) }

			{ /* Primary action */ }
			<div className="aorm-upload-file__actions">
				<Button
					variant="primary"
					disabled={ ! selectedFile }
					onClick={ () => goToStep( 'action-select' ) }
				>
					{ __( 'Continue', 'wicket-aorm' ) }
				</Button>
			</div>

		</div>
	);
}
