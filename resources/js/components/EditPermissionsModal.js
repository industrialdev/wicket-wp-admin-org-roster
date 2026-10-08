/**
 * EditPermissionsModal — "Edit Roles" (originally AORM-4.11 Add/Remove Role(s)).
 *
 * A single modal that replaces the separate Add Role(s) / Remove Role(s)
 * flows. Each editable role is a checkbox whose starting state reflects the
 * roles the selected member(s) already hold (`member.roles`, org-scoped,
 * resolved server-side by MdpClient::fetchOrgScopedRolesAndPhones()):
 *
 *   - checked        → every selected member has the role
 *   - unchecked      → no selected member has the role
 *   - indeterminate  → only some selected members have it ("leave as-is")
 *
 * Checking a role adds it to the members who lack it; unchecking an active
 * role removes it from the members who have it. An indeterminate role cycles
 * indeterminate → checked → unchecked → indeterminate, so the admin can
 * always get back to "leave unchanged". A summary lists exactly what Save
 * will do, and Save stays disabled until something actually changes — so
 * the modal can never submit a duplicate add or a remove of a role nobody has.
 *
 * Calls `onSave( { add: string[], remove: string[] } )` with role slugs.
 * RosterAssignment.js POSTs that as `action: 'update'` to
 * /wicket-aorm/v1/rosters/{org}/{mem}/roles.
 *
 * @param {{
 *   isOpen:   boolean,
 *   members:  Array<{ person_uuid: string, roles?: string[], given_name?: string, family_name?: string, name?: string }>,
 *   onSave:   function({ add: string[], remove: string[] }): void,
 *   onClose:  function(): void,
 * }} props
 */

import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Button, CheckboxControl, Modal } from '@wordpress/components';
import '../../css/edit-permissions-modal.css';

/**
 * Roles an admin can manage from the Roster Assignment tab.
 * Mirrors RosterController::EDITABLE_ROLE_SLUGS (server-side allow-list).
 */
export const EDITABLE_ROLES = [
	{
		slug: 'membership_manager',
		label: __( 'Membership Manager', 'wicket-aorm' ),
		description: __( 'Can add, remove, and edit members in the roster.', 'wicket-aorm' ),
	},
	{
		slug: 'org_editor',
		label: __( 'Org Editor', 'wicket-aorm' ),
		description: __( "Can edit the organization's profile.", 'wicket-aorm' ),
	},
];

export const ROLE_STATE_ALL = 'all';
export const ROLE_STATE_NONE = 'none';
export const ROLE_STATE_SOME = 'some';

export const MODAL_TITLE = __( 'Edit Roles', 'wicket-aorm' );
export const NO_CHANGES_TEXT = __( 'No changes yet. Check a role to add it or uncheck an active role to remove it.', 'wicket-aorm' );

/**
 * How many of the given members hold a role, and whether that's all/none/some.
 *
 * @param {Array}  members
 * @param {string} slug
 * @return {{ state: string, holders: number }}
 */
export function getRoleAssignmentState( members, slug ) {
	const list = Array.isArray( members ) ? members : [];
	const holders = list.filter(
		( m ) => Array.isArray( m?.roles ) && m.roles.includes( slug )
	).length;

	if ( holders === 0 ) {
		return { state: ROLE_STATE_NONE, holders };
	}

	if ( holders === list.length ) {
		return { state: ROLE_STATE_ALL, holders };
	}

	return { state: ROLE_STATE_SOME, holders };
}

/**
 * Next desired value for a role after the admin clicks its checkbox.
 *
 * `desired` is `true` (assign to all), `false` (remove from all) or
 * `undefined` (leave unchanged). Returning to the starting state collapses
 * back to `undefined` so it never counts as a change.
 *
 * @param {string}              initialState ROLE_STATE_*
 * @param {boolean|undefined}   desired
 * @return {boolean|undefined}
 */
export function nextDesiredValue( initialState, desired ) {
	if ( initialState === ROLE_STATE_SOME ) {
		if ( desired === undefined ) {
			return true;
		}

		return desired === true ? false : undefined;
	}

	const initiallyChecked = initialState === ROLE_STATE_ALL;
	const effective = desired === undefined ? initiallyChecked : desired;
	const next = ! effective;

	return next === initiallyChecked ? undefined : next;
}

/**
 * Work out what Save will actually change.
 *
 * @param {Array}  members
 * @param {Object} desiredBySlug slug → true|false|undefined
 * @return {{ add: Array<{slug, label, count}>, remove: Array<{slug, label, count}> }}
 */
export function computeRoleChanges( members, desiredBySlug ) {
	const list = Array.isArray( members ) ? members : [];
	const add = [];
	const remove = [];

	EDITABLE_ROLES.forEach( ( { slug, label } ) => {
		const desired = desiredBySlug?.[ slug ];

		if ( desired === undefined ) {
			return;
		}

		const { state, holders } = getRoleAssignmentState( list, slug );

		if ( desired === true && state !== ROLE_STATE_ALL ) {
			add.push( { slug, label, count: list.length - holders } );
		} else if ( desired === false && state !== ROLE_STATE_NONE ) {
			remove.push( { slug, label, count: holders } );
		}
	} );

	return { add, remove };
}

function memberDisplayName( member ) {
	const combined = [ member?.given_name, member?.family_name ]
		.filter( Boolean )
		.join( ' ' )
		.trim();

	return combined || member?.name || member?.email || '';
}

/**
 * Helper text under each role checkbox: description + current assignment.
 */
function roleStatusText( state, holders, total ) {
	if ( total === 1 ) {
		return state === ROLE_STATE_ALL
			? __( 'Currently assigned.', 'wicket-aorm' )
			: __( 'Not assigned.', 'wicket-aorm' );
	}

	if ( state === ROLE_STATE_ALL ) {
		return __( 'Assigned to all selected members.', 'wicket-aorm' );
	}

	if ( state === ROLE_STATE_NONE ) {
		return __( 'Not assigned to any selected member.', 'wicket-aorm' );
	}

	return sprintf(
		/* translators: 1: members with the role, 2: members selected */
		__( 'Assigned to %1$d of %2$d selected members. Leave partly checked to keep it unchanged.', 'wicket-aorm' ),
		holders,
		total
	);
}

export default function EditPermissionsModal( {
	isOpen = false,
	members = [],
	onSave,
	onClose,
} ) {
	// slug → true | false (undefined = unchanged). Reset whenever the modal opens.
	const [ desired, setDesired ] = useState( {} );

	useEffect( () => {
		if ( isOpen ) {
			setDesired( {} );
		}
	}, [ isOpen ] );

	if ( ! isOpen ) {
		return null;
	}

	const total = members.length;
	const changes = computeRoleChanges( members, desired );
	const hasChanges = changes.add.length > 0 || changes.remove.length > 0;

	function handleToggle( slug, initialState ) {
		setDesired( ( prev ) => {
			const next = { ...prev };
			const value = nextDesiredValue( initialState, prev[ slug ] );

			if ( value === undefined ) {
				delete next[ slug ];
			} else {
				next[ slug ] = value;
			}

			return next;
		} );
	}

	function handleSave() {
		if ( ! hasChanges || typeof onSave !== 'function' ) {
			return;
		}

		onSave( {
			add: changes.add.map( ( c ) => c.slug ),
			remove: changes.remove.map( ( c ) => c.slug ),
		} );
	}

	const intro =
		total === 1
			? sprintf(
					/* translators: %s: member name */
					__( 'Editing roles for %s.', 'wicket-aorm' ),
					memberDisplayName( members[ 0 ] )
			  )
			: sprintf(
					/* translators: %d: number of selected members */
					__( 'Editing roles for %d selected members.', 'wicket-aorm' ),
					total
			  );

	return (
		<Modal
			title={ MODAL_TITLE }
			onRequestClose={ onClose }
			className="aorm-permissions-modal"
		>
			<p className="aorm-permissions-modal__intro">{ intro }</p>
			<p className="aorm-permissions-modal__hint">
				{ __(
					'Checked roles are assigned. Check a role to add it, or uncheck an active role to remove it.',
					'wicket-aorm'
				) }
			</p>

			<div className="aorm-permissions-modal__roles">
				{ EDITABLE_ROLES.map( ( { slug, label, description } ) => {
					const { state, holders } = getRoleAssignmentState( members, slug );
					const value = desired[ slug ];
					const checked = value === undefined ? state === ROLE_STATE_ALL : value;
					const indeterminate = value === undefined && state === ROLE_STATE_SOME;

					return (
						<div
							key={ slug }
							className={ [
								'aorm-permissions-modal__role',
								`aorm-permissions-modal__role--${ state }`,
								value !== undefined ? 'aorm-permissions-modal__role--changed' : '',
							]
								.filter( Boolean )
								.join( ' ' ) }
						>
							<CheckboxControl
								__nextHasNoMarginBottom
								label={ label }
								checked={ checked }
								indeterminate={ indeterminate }
								onChange={ () => handleToggle( slug, state ) }
								help={
									<>
										<span className="aorm-permissions-modal__role-description">
											{ description }
										</span>{ ' ' }
										<span className="aorm-permissions-modal__role-status">
											{ roleStatusText( state, holders, total ) }
										</span>
									</>
								}
							/>
						</div>
					);
				} ) }
			</div>

			<div className="aorm-permissions-modal__summary" aria-live="polite">
				{ hasChanges ? (
					<ul>
						{ changes.add.map( ( { slug, label, count } ) => (
							<li key={ `add-${ slug }` } className="aorm-permissions-modal__change--add">
								{ sprintf(
									/* translators: 1: role label, 2: member count */
									_n(
										'Add %1$s to %2$d member',
										'Add %1$s to %2$d members',
										count,
										'wicket-aorm'
									),
									label,
									count
								) }
							</li>
						) ) }
						{ changes.remove.map( ( { slug, label, count } ) => (
							<li key={ `remove-${ slug }` } className="aorm-permissions-modal__change--remove">
								{ sprintf(
									/* translators: 1: role label, 2: member count */
									_n(
										'Remove %1$s from %2$d member',
										'Remove %1$s from %2$d members',
										count,
										'wicket-aorm'
									),
									label,
									count
								) }
							</li>
						) ) }
					</ul>
				) : (
					<p className="aorm-permissions-modal__no-changes">{ NO_CHANGES_TEXT }</p>
				) }
			</div>

			<div className="aorm-permissions-modal__actions">
				<Button
					variant="primary"
					disabled={ ! hasChanges }
					onClick={ handleSave }
				>
					{ __( 'Save', 'wicket-aorm' ) }
				</Button>

				<Button variant="secondary" onClick={ onClose }>
					{ __( 'Cancel', 'wicket-aorm' ) }
				</Button>
			</div>
		</Modal>
	);
}
