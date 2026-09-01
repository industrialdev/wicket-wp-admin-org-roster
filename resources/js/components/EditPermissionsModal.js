/**
 * EditPermissionsModal — AORM-4.11.
 *
 * A @wordpress/components Modal that lets an admin select which roles to add
 * or remove for the currently selected roster members.
 *
 * One role is available:
 *   • membership_manager → "Membership Manager"
 *
 * The modal is intentionally unaware of the underlying REST call; it simply
 * calls `onSave( roleSlugs )` with the array of checked slugs when the admin
 * clicks Save.  AORM-4.12 wires that callback to the
 * POST /wicket-aorm/v1/rosters/{org}/{mem}/roles endpoint.
 *
 * The Save button is disabled when no roles are checked so admins cannot
 * submit an empty selection.
 *
 * Checkbox state is reset every time the modal transitions from closed to
 * open, so re-opening always starts with a clean slate.
 *
 * @param {{
 *   isOpen:   boolean,
 *   mode:     'add' | 'remove',
 *   onSave:   function(roleSlugs: string[]): void,
 *   onClose:  function(): void,
 * }} props
 */

import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, CheckboxControl, Modal } from '@wordpress/components';
import '../../css/edit-permissions-modal.css';

/** Available permission roles for org rosters. */
const ROLES = [
	{ slug: 'membership_manager', label: __( 'Membership Manager', 'wicket-aorm' ) },
];

export default function EditPermissionsModal( {
	isOpen  = false,
	mode    = 'add',
	onSave,
	onClose,
} ) {
	// Track which role slugs are checked. Reset when the modal opens.
	const [ checked, setChecked ] = useState( new Set() );

	useEffect( () => {
		if ( isOpen ) {
			setChecked( new Set() );
		}
	}, [ isOpen ] );

	if ( ! isOpen ) {
		return null;
	}

	const title =
		mode === 'remove'
			? __( 'Remove Role(s)', 'wicket-aorm' )
			: __( 'Add Role(s)',    'wicket-aorm' );

	function handleToggle( slug, value ) {
		setChecked( ( prev ) => {
			const next = new Set( prev );
			if ( value ) {
				next.add( slug );
			} else {
				next.delete( slug );
			}

			return next;
		} );
	}

	function handleSave() {
		if ( typeof onSave === 'function' ) {
			onSave( [ ...checked ] );
		}
	}

	const noneChecked = checked.size === 0;

	return (
		<Modal
			title={ title }
			onRequestClose={ onClose }
			className="aorm-permissions-modal"
		>
			<div className="aorm-permissions-modal__roles">
				{ ROLES.map( ( { slug, label } ) => (
					<CheckboxControl
						key={ slug }
						label={ label }
						checked={ checked.has( slug ) }
						onChange={ ( value ) => handleToggle( slug, value ) }
					/>
				) ) }
			</div>

			<div className="aorm-permissions-modal__actions">
				<Button
					variant="primary"
					disabled={ noneChecked }
					onClick={ handleSave }
				>
					{ __( 'Save', 'wicket-aorm' ) }
				</Button>

				<Button
					variant="secondary"
					onClick={ onClose }
				>
					{ __( 'Cancel', 'wicket-aorm' ) }
				</Button>
			</div>
		</Modal>
	);
}
