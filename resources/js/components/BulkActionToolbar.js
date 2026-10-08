/**
 * BulkActionToolbar — AORM-4.8, reworked for role-editing discoverability.
 *
 * Renders the action toolbar above the member table. It is ALWAYS shown
 * (previously it rendered nothing until a row was selected, so admins
 * couldn't tell role editing existed). Actions:
 *
 *   • Edit Roles          — opens the merged Edit Roles modal (EditPermissionsModal)
 *   • Remove from Roster  — destructive; opens ConfirmRemoveModal (AORM-4.13)
 *
 * Disabled states are explained with helper text, not just greyed out:
 *   - nothing selected → both disabled; hint says to select members (or use
 *     the per-row "Edit Roles" button)
 *   - the membership owner is selected → Remove from Roster disabled, because
 *     the owner can't be removed from their own roster (server-side guard in
 *     MdpClient::removeRosterMembers() still applies). Edit Roles stays enabled.
 *   - a request is in flight (`isBusy`) → both disabled
 *
 * Buttons use `accessibleWhenDisabled` so they stay focusable and screen
 * readers announce the helper text via aria-describedby.
 *
 * @param {{
 *   selectedCount:        number,
 *   ownerSelected?:       boolean,
 *   isBusy?:              boolean,
 *   onRemoveFromRoster?:  function(): void,
 *   onEditRoles?:         function(): void,
 * }} props
 */

import { __, sprintf, _n } from '@wordpress/i18n';
import { Button } from '@wordpress/components';

export const NO_SELECTION_HINT = __(
	'Select one or more members to edit their roles or remove them from the roster. You can also use “Edit Roles” on any row.',
	'wicket-aorm'
);

export const OWNER_SELECTED_HINT = __(
	'The membership owner can’t be removed from the roster. Deselect the owner to remove the other selected members.',
	'wicket-aorm'
);

const HINT_ID = 'aorm-bulk-toolbar-hint';

export default function BulkActionToolbar( {
	selectedCount = 0,
	ownerSelected = false,
	isBusy = false,
	onRemoveFromRoster,
	onEditRoles,
} ) {
	const hasSelection = selectedCount > 0;

	const countLabel = hasSelection
		? sprintf(
				/* translators: %d: number of selected members */
				_n( '%d member selected', '%d members selected', selectedCount, 'wicket-aorm' ),
				selectedCount
		  )
		: __( 'No members selected', 'wicket-aorm' );

	let hint = '';

	if ( ! hasSelection ) {
		hint = NO_SELECTION_HINT;
	} else if ( ownerSelected ) {
		hint = OWNER_SELECTED_HINT;
	}

	const editDisabled = isBusy || ! hasSelection;
	const removeDisabled = isBusy || ! hasSelection || ownerSelected;

	return (
		<div
			className={ [
				'aorm-bulk-toolbar',
				hasSelection ? 'aorm-bulk-toolbar--active' : 'aorm-bulk-toolbar--idle',
			].join( ' ' ) }
			role="toolbar"
			aria-label={ __( 'Bulk actions', 'wicket-aorm' ) }
		>
			<span className="aorm-bulk-toolbar__count" aria-live="polite">
				{ countLabel }
			</span>

			<div className="aorm-bulk-toolbar__actions">
				<Button
					variant="secondary"
					icon="admin-users"
					disabled={ editDisabled }
					accessibleWhenDisabled
					aria-describedby={ hint && ! hasSelection ? HINT_ID : undefined }
					onClick={ editDisabled ? undefined : onEditRoles }
				>
					{ __( 'Edit Roles', 'wicket-aorm' ) }
				</Button>

				<Button
					variant="secondary"
					isDestructive
					disabled={ removeDisabled }
					accessibleWhenDisabled
					aria-describedby={ hint ? HINT_ID : undefined }
					onClick={ removeDisabled ? undefined : onRemoveFromRoster }
				>
					{ __( 'Remove from Roster', 'wicket-aorm' ) }
				</Button>
			</div>

			{ hint && (
				<p id={ HINT_ID } className="aorm-bulk-toolbar__hint">
					{ hint }
				</p>
			) }
		</div>
	);
}
