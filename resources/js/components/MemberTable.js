/**
 * MemberTable — AORM-4.5.
 *
 * Renders the roster assignment member table with:
 *   - Header checkbox (select all / deselect all, with indeterminate state)
 *   - Per-row checkboxes for bulk-action selection
 *   - Columns: Name, Email Address, Title, Phone, Roles
 *   - Two action columns: Edit Permissions (AORM-4.11), Remove (AORM-4.9)
 *   - Membership owner pinned first with an "Owner" badge in the Name cell
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

import { __ } from '@wordpress/i18n';
import { Button, CheckboxControl } from '@wordpress/components';
import '../../css/member-table.css';

/** Sorts members so the roster owner (is_owner: true) always appears first. */
function sortedMembers( members ) {
	return [ ...members ].sort( ( a, b ) => {
		return ( a.is_owner ? 0 : 1 ) - ( b.is_owner ? 0 : 1 );
	} );
}

export default function MemberTable( {
	members = [],
	selectedIds = new Set(),
	onSelectionChange,
	onEditPermissions,
	onRemove,
} ) {
	const sorted = sortedMembers( members );

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
					<td>{ __( 'Name', 'wicket-aorm' ) }</td>
					<td>{ __( 'Email Address', 'wicket-aorm' ) }</td>
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
