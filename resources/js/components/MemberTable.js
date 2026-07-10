/**
 * MemberTable — AORM-4.5 / AORM-4.16 (column sorting).
 *
 * Renders the roster assignment member table with:
 *   - Header checkbox (select all / deselect all, with indeterminate state)
 *   - Per-row checkboxes for bulk-action selection
 *   - Columns: Name, Email Address, Title, Phone, Roles
 *   - Two action columns: Edit Permissions (AORM-4.11), Remove (AORM-4.9)
 *   - Membership owner pinned first with an "Owner" badge in the Name cell
 *   - Client-side sortable Name / Email Address columns (AORM-4.16). Sorting
 *     is scoped to the members already loaded for the current page (data is
 *     paginated server-side by RosterAssignment.js); the owner row always
 *     stays pinned first regardless of the active sort.
 *
 * Uses WP admin list-table CSS classes for consistent styling alongside
 * the rest of the admin UI. Checkboxes are rendered with
 * @wordpress/components CheckboxControl for accessible, consistent markup.
 *
 * @param {{
 *   members: Array<{
 *     person_uuid: string,
 *     name: string,
 *     email: string,
 *     title: string,
 *     phone: string,
 *     roles: string[],
 *   }>,
 *   selectedIds: Set<string>,
 *   onSelectionChange: function(Set<string>): void,
 *   onEditPermissions?: function(member: object): void,
 *   onRemove?: function(personUuid: string): void,
 * }} props
 */

import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, CheckboxControl } from '@wordpress/components';
import '../../css/member-table.css';

/** Sortable column definitions (AORM-4.16). Key matches the member field name. */
const SORTABLE_COLUMNS = [
	{ key: 'name', label: __( 'Name', 'wicket-aorm' ) },
	{ key: 'email', label: __( 'Email Address', 'wicket-aorm' ) },
];

/**
 * Screen-reader-accessible sort direction for aria-sort.
 *
 * @param {string|null}  sortField Currently sorted field.
 * @param {'asc'|'desc'} sortDir   Current direction.
 * @param {string}       colKey    Column being rendered.
 * @return {string}
 */
function ariaSort( sortField, sortDir, colKey ) {
	if ( sortField !== colKey ) {
		return 'none';
	}

	return sortDir === 'asc' ? 'ascending' : 'descending';
}

/**
 * Unicode sort indicator shown after the column label.
 *
 * @param {string|null}  sortField
 * @param {'asc'|'desc'} sortDir
 * @param {string}       colKey
 * @return {string}
 */
function sortIndicator( sortField, sortDir, colKey ) {
	if ( sortField !== colKey ) {
		return '';
	}

	return sortDir === 'asc' ? ' ▲' : ' ▼';
}

/**
 * Compares two members on a given field, case-insensitively.
 *
 * @param {Object}       a
 * @param {Object}       b
 * @param {string}       field
 * @param {'asc'|'desc'} direction
 * @return {number}
 */
function compareMembers( a, b, field, direction ) {
	const va = String( a?.[ field ] ?? '' ).trim().toLowerCase();
	const vb = String( b?.[ field ] ?? '' ).trim().toLowerCase();
	const cmp = va.localeCompare( vb );

	return direction === 'asc' ? cmp : -cmp;
}

/**
 * Sorts members so the roster owner (is_owner: true) always appears first,
 * then applies the active column sort (if any) to the remaining members.
 *
 * @param {Array}        members
 * @param {string|null}  sortField
 * @param {'asc'|'desc'} sortDir
 * @return {Array}
 */
function sortedMembers( members, sortField, sortDir ) {
	return [ ...members ].sort( ( a, b ) => {
		const ownerCmp = ( a.is_owner ? 0 : 1 ) - ( b.is_owner ? 0 : 1 );

		if ( ownerCmp !== 0 ) {
			return ownerCmp;
		}

		if ( ! sortField ) {
			return 0;
		}

		return compareMembers( a, b, sortField, sortDir );
	} );
}

export default function MemberTable( {
	members = [],
	selectedIds = new Set(),
	onSelectionChange,
	onEditPermissions,
	onRemove,
} ) {
	const [ sortField, setSortField ] = useState( null );
	const [ sortDir, setSortDir ] = useState( 'asc' );

	const sorted = sortedMembers( members, sortField, sortDir );

	function handleSort( field ) {
		if ( sortField === field ) {
			setSortDir( ( d ) => ( d === 'asc' ? 'desc' : 'asc' ) );
		} else {
			setSortField( field );
			setSortDir( 'asc' );
		}
	}

	const allSelected =
		sorted.length > 0 &&
		sorted.every( ( m ) => selectedIds.has( m.person_uuid ) );

	const someSelected =
		! allSelected && sorted.some( ( m ) => selectedIds.has( m.person_uuid ) );

	function toggleAll() {
		if ( allSelected ) {
			onSelectionChange( new Set() );
		} else {
			onSelectionChange( new Set( sorted.map( ( m ) => m.person_uuid ) ) );
		}
	}

	function toggleRow( personUuid ) {
		const next = new Set( selectedIds );

		if ( next.has( personUuid ) ) {
			next.delete( personUuid );
		} else {
			next.add( personUuid );
		}

		onSelectionChange( next );
	}

	// Total column count: checkbox + 5 data cols + 2 action cols = 8
	const TOTAL_COLS = 8;

	return (
		<table className="wp-list-table widefat fixed striped aorm-member-table">
			<thead>
				<tr>
					<td className="check-column" scope="col">
						<CheckboxControl
							aria-label={ __( 'Select all members', 'wicket-aorm' ) }
							checked={ allSelected }
							indeterminate={ someSelected }
							onChange={ toggleAll }
						/>
					</td>
					{ SORTABLE_COLUMNS.map( ( col ) => (
						<th
							key={ col.key }
							scope="col"
							aria-sort={ ariaSort( sortField, sortDir, col.key ) }
							className={ `aorm-member-table__col--${ col.key }` }
						>
							<Button
								variant="link"
								className="aorm-member-table__sort-btn"
								onClick={ () => handleSort( col.key ) }
								aria-label={ sprintf(
									/* translators: %s: column label */
									__( 'Sort by %s', 'wicket-aorm' ),
									col.label
								) }
							>
								{ col.label }
								<span aria-hidden="true">
									{ sortIndicator( sortField, sortDir, col.key ) }
								</span>
							</Button>
						</th>
					) ) }
					<td>{ __( 'Title', 'wicket-aorm' ) }</td>
					<td>{ __( 'Phone', 'wicket-aorm' ) }</td>
					<td>{ __( 'Roles', 'wicket-aorm' ) }</td>
					<td className="aorm-member-table__col--action">
						<span className="screen-reader-text">
							{ __( 'Edit Permissions', 'wicket-aorm' ) }
						</span>
					</td>
					<td className="aorm-member-table__col--action">
						<span className="screen-reader-text">
							{ __( 'Remove', 'wicket-aorm' ) }
						</span>
					</td>
				</tr>
			</thead>
			<tbody>
				{ sorted.map( ( member ) => {
					const isOwner = !! member.is_owner;

					return (
						<tr
							key={ member.person_uuid }
							className={ [
								selectedIds.has( member.person_uuid )
									? 'aorm-member-table__row--selected'
									: '',
								isOwner ? 'aorm-member-table__row--owner' : '',
							]
								.filter( Boolean )
								.join( ' ' ) }
						>
							<th className="check-column" scope="row">
								<CheckboxControl
									aria-label={ `${ __(
										'Select',
										'wicket-aorm'
									) } ${ member.name }` }
									checked={ selectedIds.has( member.person_uuid ) }
									onChange={ () => toggleRow( member.person_uuid ) }
								/>
							</th>
							<td>
								<span className="aorm-member-table__name">
									{ member.name || '—' }
								</span>
								{ isOwner && (
									<span className="aorm-member-table__owner-badge">
										{ __( 'Owner', 'wicket-aorm' ) }
									</span>
								) }
							</td>
							<td>{ member.email || '—' }</td>
							<td>{ member.title || '—' }</td>
							<td>{ member.phone || '—' }</td>
							<td>
								{ member.roles && member.roles.length > 0
									? member.roles.join( ', ' )
									: '—' }
							</td>
							<td className="aorm-member-table__col--action">
								{/* <Button
									variant="secondary"
									size="small"
									onClick={ () => onEditPermissions?.( member ) }
									aria-label={ `${ __(
										'Edit permissions for',
										'wicket-aorm'
									) } ${ member.name }` }
								>
									{ __( 'Edit Permissions', 'wicket-aorm' ) }
								</Button> */}
								{ isOwner &&
									<>
										&nbsp;
										<Button
											variant="secondary"
											size="small"
											href={ member.membership_details_page_url }
											target="_blank"
										>
											{ __( 'Change Owner', 'wicket-aorm' ) }
										</Button>
									</>
								}
							</td>
							<td className="aorm-member-table__col--action">
								{/* <Button
									variant="secondary"
									size="small"
									isDestructive
									onClick={ () => onRemove?.( member.person_uuid ) }
									aria-label={ `${ __(
										'Remove',
										'wicket-aorm'
									) } ${ member.name }` }
								>
									{ __( 'Remove', 'wicket-aorm' ) }
								</Button> */}
							</td>
						</tr>
					);
				} ) }
				{ sorted.length === 0 && (
					<tr>
						<td
							colSpan={ TOTAL_COLS }
							className="aorm-member-table__empty"
						>
							{ __( 'No members found.', 'wicket-aorm' ) }
						</td>
					</tr>
				) }
			</tbody>
		</table>
	);
}
