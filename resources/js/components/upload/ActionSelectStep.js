/**
 * Action selection step — AORM-6.11 (Step 2 of bulk upload wizard).
 *
 * Admin chooses "Add to Roster" or "Replace Existing Roster".
 * "Replace" is only available when the roster already has members
 * (assigned_count > 0). The member count is fetched from the members
 * endpoint on mount so the UI can gate the Replace option without any
 * additional props.
 *
 * If the roster returns 0 members, the Replace radio is disabled and
 * labelled with an explanatory note. If `uploadAction` was previously
 * set to 'replace' before navigating back to this step, it is
 * automatically reset to 'add' when the count is found to be 0.
 *
 * On confirm the wizard advances to the csv-validation step.
 *
 * Exported constants
 * ------------------
 * ACTION_ADD     — 'add'     (default)
 * ACTION_REPLACE — 'replace'
 *
 * @param {{
 *   goToStep:        (step: string) => void,
 *   uploadAction:    string,
 *   setUploadAction: (action: string) => void,
 *   orgUuid:         string,
 *   membershipUuid:  string,
 * }} props
 */

import { useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Spinner } from '@wordpress/components';

import { useRestApi } from '../../hooks/useRestApi';

/** Action value for "Add to Roster" mode. */
export const ACTION_ADD = 'add';

/** Action value for "Replace Existing Roster" mode. */
export const ACTION_REPLACE = 'replace';

export default function ActionSelectStep( {
	goToStep,
	uploadAction,
	setUploadAction,
	orgUuid,
	membershipUuid,
} ) {
	// Fetch current member count to gate the Replace option.
	// per_page=1 keeps the payload minimal — we only need `total`.
	const { data, isLoading } = useRestApi(
		orgUuid && membershipUuid
			? `/wicket-aorm/v1/rosters/${ orgUuid }/${ membershipUuid }/members?per_page=1`
			: null
	);

	const assignedCount = data?.total ?? 0;
	const canReplace    = assignedCount > 0;

	// If the previously chosen action was 'replace' but the roster turns out
	// to have no members, silently reset to 'add'.
	useEffect( () => {
		if ( ! isLoading && ! canReplace && uploadAction === ACTION_REPLACE ) {
			setUploadAction( ACTION_ADD );
		}
	}, [ isLoading, canReplace, uploadAction, setUploadAction ] );

	return (
		<div className="aorm-wizard-step aorm-wizard-step--action-select">

			{ /* Back navigation */ }
			<div className="aorm-wizard-step__back">
				<Button
					variant="tertiary"
					onClick={ () => goToStep( 'upload-file' ) }
				>
					{ __( '← Back', 'wicket-aorm' ) }
				</Button>
			</div>

			<h2 className="aorm-action-select__heading">
				{ __( 'Choose Upload Action', 'wicket-aorm' ) }
			</h2>

			<p className="aorm-action-select__description">
				{ __(
					'How should the uploaded members be applied to this roster?',
					'wicket-aorm'
				) }
			</p>

			{ isLoading ? (
				<Spinner />
			) : (
				<div
					className="aorm-action-select__options"
					role="group"
					aria-label={ __( 'Upload action', 'wicket-aorm' ) }
				>
					{ /* ── Add to Roster ── */ }
					<label
						className={ [
							'aorm-action-select__option',
							uploadAction === ACTION_ADD && 'aorm-action-select__option--selected',
						].filter( Boolean ).join( ' ' ) }
					>
						<input
							type="radio"
							name="aorm-upload-action"
							value={ ACTION_ADD }
							checked={ uploadAction === ACTION_ADD }
							onChange={ () => setUploadAction( ACTION_ADD ) }
							className="aorm-action-select__radio"
						/>
						<span className="aorm-action-select__option-content">
							<strong className="aorm-action-select__option-title">
								{ __( 'Add to Roster', 'wicket-aorm' ) }
							</strong>
							<span className="aorm-action-select__option-description">
								{ __(
									'Uploaded members will be added to the roster. Existing members will not be affected.',
									'wicket-aorm'
								) }
							</span>
						</span>
					</label>

					{ /* ── Replace Existing Roster ── */ }
					<label
						className={ [
							'aorm-action-select__option',
							! canReplace && 'aorm-action-select__option--disabled',
							uploadAction === ACTION_REPLACE && 'aorm-action-select__option--selected',
						].filter( Boolean ).join( ' ' ) }
					>
						<input
							type="radio"
							name="aorm-upload-action"
							value={ ACTION_REPLACE }
							checked={ uploadAction === ACTION_REPLACE }
							disabled={ ! canReplace }
							onChange={ () => setUploadAction( ACTION_REPLACE ) }
							className="aorm-action-select__radio"
						/>
						<span className="aorm-action-select__option-content">
							<strong className="aorm-action-select__option-title">
								{ __( 'Replace Existing Roster', 'wicket-aorm' ) }
							</strong>
							<span className="aorm-action-select__option-description">
								{ __(
									'The current roster will be replaced by the uploaded file. Existing members not in the CSV will be end-dated.',
									'wicket-aorm'
								) }
							</span>
							{ ! canReplace && (
								<span className="aorm-action-select__option-note">
									{ __(
										'Not available — this roster currently has no members to replace.',
										'wicket-aorm'
									) }
								</span>
							) }
							{ canReplace && (
								<span className="aorm-action-select__option-note aorm-action-select__option-note--count">
									{ sprintf(
										/* translators: %d: number of currently assigned members */
										__( '%d member(s) currently assigned.', 'wicket-aorm' ),
										assignedCount
									) }
								</span>
							) }
						</span>
					</label>
				</div>
			) }

			{ /* Primary action — always enabled once an action is chosen (default: 'add') */ }
			<div className="aorm-action-select__actions">
				<Button
					variant="primary"
					disabled={ isLoading }
					onClick={ () => goToStep( 'csv-validation' ) }
				>
					{ __( 'Continue', 'wicket-aorm' ) }
				</Button>
			</div>

		</div>
	);
}
