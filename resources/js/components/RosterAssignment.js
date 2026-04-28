/**
 * Roster Assignment tab — AORM-4, Tab 1.
 *
 * Fetches the list of people currently assigned to the roster via the
 * GET /wicket-aorm/v1/rosters/{org_uuid}/{membership_uuid}/members endpoint
 * (AORM-4.5) and renders a MemberTable with row-level checkboxes.
 *
 * When one or more rows are selected a BulkActionToolbar (AORM-4.8) appears
 * above the table offering Remove from Roster, Add Role(s), and Remove
 * Role(s) actions.  The callback props are optional stubs; actual REST calls
 * and modal flows are wired in later tickets (AORM-4.9, 4.10, 4.13).
 *
 * When the roster has no members (total === 0) an empty state is shown
 * with a CTA directing the admin to the Roster Upload tab (AORM-4.7).
 *
 * Pagination is handled client-side via page state; each page change
 * triggers a new REST request and clears the current selection.
 *
 * @param {{
 *   orgUuid: string,
 *   membershipUuid: string,
 *   onGoToUpload?: function(): void,
 *   onBulkRemoveFromRoster?: function(selectedIds: Set<string>): void,
 *   onBulkAddRoles?: function(selectedIds: Set<string>, roleSlugs: string[]): void,
 *   onBulkRemoveRoles?: function(selectedIds: Set<string>, roleSlugs: string[]): void,
 * }} props
 */

import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Notice, Spinner } from '@wordpress/components';

import { useRestApi } from '../hooks/useRestApi';
import BulkActionToolbar from './BulkActionToolbar';
import EditPermissionsModal from './EditPermissionsModal';
import MemberTable from './MemberTable';

const PER_PAGE = 10;

export default function RosterAssignment( {
	orgUuid,
	membershipUuid,
	onGoToUpload,
	onBulkRemoveFromRoster,
	onBulkAddRoles,
	onBulkRemoveRoles,
} ) {
	const [ selectedIds, setSelectedIds ] = useState( new Set() );
	const [ page, setPage ] = useState( 1 );

	// Edit-Permissions modal state (AORM-4.11).
	// pendingIds holds the selection snapshot at the moment the modal opened.
	const [ permissionsModal, setPermissionsModal ] = useState( {
		isOpen: false,
		mode:   'add',   // 'add' | 'remove'
		ids:    new Set(),
	} );

	function openPermissionsModal( mode ) {
		setPermissionsModal( { isOpen: true, mode, ids: new Set( selectedIds ) } );
	}

	function closePermissionsModal() {
		setPermissionsModal( ( prev ) => ( { ...prev, isOpen: false } ) );
	}

	function handlePermissionsSave( roleSlugs ) {
		const { mode, ids } = permissionsModal;
		closePermissionsModal();
		if ( mode === 'add' ) {
			onBulkAddRoles?.( ids, roleSlugs );
		} else {
			onBulkRemoveRoles?.( ids, roleSlugs );
		}
	}

	const { data, isLoading, error } = useRestApi(
		orgUuid && membershipUuid
			? `/wicket-aorm/v1/rosters/${ orgUuid }/${ membershipUuid }/members?per_page=${ PER_PAGE }&page=${ page }`
			: null
	);

	const members    = data?.members    ?? [];
	const total      = data?.total      ?? 0;
	const totalPages = data?.total_pages ?? 1;

	function goToPage( next ) {
		setPage( next );
		// Clear selection when navigating pages.
		setSelectedIds( new Set() );
	}

	return (
		<div className="aorm-assignment">
			{ isLoading && <Spinner /> }

			{ ! isLoading && error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ ! isLoading && ! error && total === 0 && (
				<div className="aorm-assignment__empty-state">
					<span
						className="dashicons dashicons-upload aorm-assignment__empty-state-icon"
						aria-hidden="true"
					/>
					<h3 className="aorm-assignment__empty-state-heading">
						{ __( 'No members assigned yet', 'wicket-aorm' ) }
					</h3>
					<p className="aorm-assignment__empty-state-message">
						{ __(
							'Upload a CSV or add members individually to get started.',
							'wicket-aorm'
						) }
					</p>
					<Button
						variant="primary"
						onClick={ onGoToUpload }
					>
						{ __( 'Go to Roster Upload', 'wicket-aorm' ) }
					</Button>
				</div>
			) }

		{ ! isLoading && ! error && total > 0 && (
				<>
					<BulkActionToolbar
						selectedCount={ selectedIds.size }
						onRemoveFromRoster={ () => onBulkRemoveFromRoster?.( selectedIds ) }
						onAddRoles={ () => openPermissionsModal( 'add' ) }
						onRemoveRoles={ () => openPermissionsModal( 'remove' ) }
					/>

					<MemberTable
						members={ members }
						selectedIds={ selectedIds }
						onSelectionChange={ setSelectedIds }
					/>

					{ totalPages > 1 && (
						<div className="aorm-assignment__pagination tablenav">
							<div className="tablenav-pages">
								<span className="displaying-num">
									{ total }{ ' ' }
									{ total === 1
										? __( 'item', 'wicket-aorm' )
										: __( 'items', 'wicket-aorm' ) }
								</span>
								<span className="pagination-links">
									<Button
										variant="secondary"
										disabled={ page <= 1 }
										onClick={ () => goToPage( 1 ) }
										aria-label={ __( 'First page', 'wicket-aorm' ) }
									>
										«
									</Button>
									<Button
										variant="secondary"
										disabled={ page <= 1 }
										onClick={ () => goToPage( page - 1 ) }
										aria-label={ __( 'Previous page', 'wicket-aorm' ) }
									>
										‹
									</Button>
									<span className="paging-input">
										{ page } { __( 'of', 'wicket-aorm' ) }{ ' ' }
										{ totalPages }
									</span>
									<Button
										variant="secondary"
										disabled={ page >= totalPages }
										onClick={ () => goToPage( page + 1 ) }
										aria-label={ __( 'Next page', 'wicket-aorm' ) }
									>
										›
									</Button>
									<Button
										variant="secondary"
										disabled={ page >= totalPages }
										onClick={ () => goToPage( totalPages ) }
										aria-label={ __( 'Last page', 'wicket-aorm' ) }
									>
										»
									</Button>
								</span>
							</div>
						</div>
					) }
				</>
			) }

		<EditPermissionsModal
			isOpen={ permissionsModal.isOpen }
			mode={ permissionsModal.mode }
			onSave={ handlePermissionsSave }
			onClose={ closePermissionsModal }
		/>
		</div>
	);
}
