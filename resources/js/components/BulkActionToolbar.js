/**
 * BulkActionToolbar — AORM-4.8.
 *
 * Renders a contextual toolbar above the member table whenever one or more
 * rows are selected.  The three bulk actions are:
 *
 *   • Remove from Roster  — destructive; triggers confirmation modal (AORM-4.13)
 *   • Add Role(s)         — opens role-assignment modal (AORM-4.11)
 *   • Remove Role(s)      — opens role-removal modal (AORM-4.11)
 *
 * The component is purely presentational for AORM-4.8.  Callback props are
 * optional stubs that later tickets (AORM-4.9, 4.10, 4.13) will wire to real
 * REST calls and modal flows.
 *
 * Renders nothing when selectedCount === 0 so no DOM node is injected
 * between the table header controls and the table itself.
 *
 * @param {{
 *   selectedCount:        number,
 *   onRemoveFromRoster?:  function(): void,
 *   onAddRoles?:          function(): void,
 *   onRemoveRoles?:       function(): void,
 * }} props
 */

import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';

export default function BulkActionToolbar( {
	selectedCount = 0,
	onRemoveFromRoster,
	onAddRoles,
	onRemoveRoles,
} ) {
	if ( selectedCount === 0 ) {
		return null;
	}

	const countLabel =
		selectedCount === 1
			? __( '1 member selected', 'wicket-aorm' )
			: `${ selectedCount } ${ __( 'members selected', 'wicket-aorm' ) }`;

	return (
		<div
			className="aorm-bulk-toolbar"
			role="toolbar"
			aria-label={ __( 'Bulk actions', 'wicket-aorm' ) }
		>
			<span className="aorm-bulk-toolbar__count" aria-live="polite">
				{ countLabel }
			</span>

			<div className="aorm-bulk-toolbar__actions">
				<Button
					variant="secondary"
					isDestructive
					onClick={ onRemoveFromRoster }
				>
					{ __( 'Remove from Roster', 'wicket-aorm' ) }
				</Button>

				<Button
					variant="secondary"
					onClick={ onAddRoles }
				>
					{ __( 'Add Role(s)', 'wicket-aorm' ) }
				</Button>

				<Button
					variant="secondary"
					onClick={ onRemoveRoles }
				>
					{ __( 'Remove Role(s)', 'wicket-aorm' ) }
				</Button>
			</div>
		</div>
	);
}
