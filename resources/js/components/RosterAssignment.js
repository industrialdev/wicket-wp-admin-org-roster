/**
 * Roster Assignment tab — AORM-4, Tab 1.
 *
 * Fetches the list of people currently assigned to the roster via the
 * GET /wicket-aorm/v1/rosters/{org_uuid}/{membership_uuid}/members endpoint
 * (AORM-4.5) and renders a MemberTable with row-level checkboxes.
 *
 * A BulkActionToolbar (AORM-4.8) is always shown above the table offering
 * Edit Roles and Remove from Roster; its buttons are disabled with helper
 * text until a row is selected, and Remove is disabled while the membership
 * owner is selected. Each row also has its own "Edit Roles" button.
 *
 * Edit Roles opens the merged EditPermissionsModal (replaces the old separate
 * Add Role(s) / Remove Role(s) flows) with a snapshot of the selected member
 * objects, so the modal can pre-check the roles they already hold. Its
 * `{add, remove}` result is POSTed as `action: 'update'` with
 * `add_role_slugs`/`remove_role_slugs`; the server only applies real
 * differences and reports no-op people under `unchanged`.
 *
 * When the roster has no members (total === 0) an empty state is shown
 * with a CTA directing the admin to the Roster Upload tab (AORM-4.7) —
 * unless the membership has an owner, in which case the table is shown
 * with just the pinned owner row instead.
 *
 * Pinned owner: the members endpoint returns the membership owner under a
 * separate `owner` key (excluded from `members`/`total`), passed to
 * MemberTable as `owner` so it renders as a sticky first row on every page
 * and every search, whether or not the owner is an active member.
 *
 * Pagination is handled client-side via page state; each page change
 * triggers a new REST request and clears the current selection.
 *
 * EditPermissionsModal save is wired directly to the MDP roles endpoint
 * POST /wicket-aorm/v1/rosters/{orgUuid}/{membershipUuid}/roles (AORM-4.12).
 * On full or partial success the member list is refreshed and the selection
 * is cleared.
 *
 * The "Remove from Roster" bulk action opens a ConfirmRemoveModal (AORM-4.13)
 * before issuing DELETE /wicket-aorm/v1/rosters/{orgUuid}/{membershipUuid}/members.
 * On success the member list is refreshed and the selection is cleared.
 *
 * AORM-4.21 adds a server-side search box (name or email address) above the
 * table. Typing debounces for SEARCH_DEBOUNCE_MS before the committed
 * `search` state updates, which is appended to the REST path as `?search=`
 * (handled by RosterController::get_members() → MdpClient::getRosterMembers()
 * via a Ransack `filter[person_full_name_or_person_emails_address_cont]`
 * param). Committing a new search resets to page 1 and clears the current
 * selection, same as changing pages. Because the fetched `total` is scoped
 * to the active search term, a search with zero matches is distinguished
 * from a genuinely empty roster (`hasActiveSearch`) so the upload CTA empty
 * state only shows when the roster has no members at all. The search box
 * itself is rendered unconditionally (outside the loading/error/empty/data
 * branches below) so it never unmounts while a debounced request is
 * in flight — unmounting would drop focus and cursor position out from
 * under whatever the admin is mid-typing.
 *
 * Bugfix history (post-AORM-4.21) — two earlier attempts at closing the
 * render-timing gap between committing a new `search` value and
 * useRestApi's `isLoading` reflecting the resulting request both lived in
 * this component (a bridging `isCommittingSearch` flag) and both had their
 * own failure modes: the first left a one-render gap where a stale `total`
 * plus an already-flipped `hasActiveSearch` could flash the "no members
 * assigned yet" CTA; fixing that by setting the flag on every debounce
 * settle then caused it to get stuck `true` forever whenever a settle
 * didn't actually change the committed value (no new fetch to ever clear
 * it), and — once THAT was fixed to only flag genuine changes — it could
 * still get stuck whenever a new commit landed while the previous request
 * was still in flight (`isLoading` never dipped back to `false` in between
 * for the flag's clearing effect to catch). All three symptoms traced back
 * to the same root cause: `useRestApi()` didn't know which `path` its
 * `isLoading`/`data` actually corresponded to. That's now fixed at the
 * hook level (see useRestApi.js) — `isLoading` is correct synchronously and
 * safe against overlapping requests, so no bridging state is needed here
 * at all. `committedSearchRef` remains solely as an optimization: it avoids
 * committing (and re-fetching) when a debounce settles on a value that's
 * already the current search — e.g. typing then deleting back to the same
 * term, or the very first settle after mount when nothing was typed.
 *
 * @param {{
 *   orgUuid:        string,
 *   membershipUuid: string,
 *   onGoToUpload?:  function(): void,
 * }} props
 */

import { useState, useEffect, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Notice, SearchControl, Spinner } from '@wordpress/components';

import { apiFetch } from '../utils/apiFetch';
import { useRestApi } from '../hooks/useRestApi';
import BulkActionToolbar from './BulkActionToolbar';
import ConfirmRemoveModal from './ConfirmRemoveModal';
import EditPermissionsModal from './EditPermissionsModal';
import MemberTable from './MemberTable';

export const PER_PAGE = 10;

/** Debounce delay (ms) between typing in the search box and committing the search term (AORM-4.21). */
export const SEARCH_DEBOUNCE_MS = 400;

/**
 * Build the REST API path for the member list, including pagination and an
 * optional search term (AORM-4.21).
 *
 * @param {string} orgUuid
 * @param {string} membershipUuid
 * @param {string} search  Committed search term; omitted from the query string when empty.
 * @param {number} page    Current page number (1-based).
 * @param {number} perPage Items per page.
 * @returns {string}
 */
export function buildMembersPath( orgUuid, membershipUuid, search, page = 1, perPage = PER_PAGE ) {
	const base = `/wicket-aorm/v1/rosters/${ orgUuid }/${ membershipUuid }/members`;

	const params = new URLSearchParams();
	params.set( 'page', String( page ) );
	params.set( 'per_page', String( perPage ) );
	if ( search ) params.set( 'search', search );

	return `${ base }?${ params.toString() }`;
}

/**
 * Build the POST body for the Edit Roles modal's save (`action: 'update'`).
 *
 * @param {string[]} personUuids
 * @param {string[]} addRoleSlugs
 * @param {string[]} removeRoleSlugs
 * @returns {{ person_uuids: string[], action: string, add_role_slugs: string[], remove_role_slugs: string[] }}
 */
export function buildRoleUpdatePayload( personUuids, addRoleSlugs = [], removeRoleSlugs = [] ) {
	return {
		person_uuids:      [ ...personUuids ],
		action:            'update',
		add_role_slugs:    [ ...addRoleSlugs ],
		remove_role_slugs: [ ...removeRoleSlugs ],
	};
}

export default function RosterAssignment( {
	orgUuid,
	membershipUuid,
	onGoToUpload,
} ) {
	const [ selectedIds, setSelectedIds ] = useState( new Set() );
	const [ page, setPage ] = useState( 1 );

	// Search box state (AORM-4.21). `searchInput` tracks every keystroke for
	// a responsive text field; `search` is the debounced value actually sent
	// to the API, committed SEARCH_DEBOUNCE_MS after the person stops typing.
	const [ searchInput, setSearchInput ] = useState( '' );
	const [ search, setSearch ] = useState( '' );

	// Tracks the last-committed search value via a ref (rather than reading
	// `search` state directly) so this effect can compare against it without
	// needing `search` as a dependency — which would otherwise re-schedule
	// the timer every time a commit fires. Read at call time inside the
	// timeout, so it's never stale. Purely an optimization now that
	// useRestApi() handles its own loading-state correctness (see the
	// bugfix history above) — this just avoids re-fetching when a debounce
	// settles on a value that's already the current search.
	const committedSearchRef = useRef( '' );

	useEffect( () => {
		const handle = setTimeout( () => {
			const trimmed = searchInput.trim();

			if ( trimmed === committedSearchRef.current ) {
				return;
			}

			committedSearchRef.current = trimmed;
			setSearch( trimmed );
			setPage( 1 );
			setSelectedIds( new Set() );
		}, SEARCH_DEBOUNCE_MS );

		return () => clearTimeout( handle );
	}, [ searchInput ] );

	// Edit Roles modal state. `members` is a snapshot of the member objects
	// (with their current roles) at the moment the modal opened.
	const [ permissionsModal, setPermissionsModal ] = useState( {
		isOpen:  false,
		members: [],
	} );

	// Confirm-Remove modal state (AORM-4.13).
	// ids holds the selection snapshot at the moment the modal opened.
	const [ confirmRemoveModal, setConfirmRemoveModal ] = useState( {
		isOpen: false,
		ids:    new Set(),
	} );

	// Submission state shared by roles (AORM-4.12) and remove (AORM-4.13).
	const [ isSubmitting, setIsSubmitting ]   = useState( false );
	const [ actionNotice, setActionNotice ]   = useState( null ); // { status: 'success'|'warning'|'error', message: string } | null

	/**
	 * Open the Edit Roles modal for the given member objects.
	 *
	 * @param {Array} targetMembers
	 */
	function openPermissionsModal( targetMembers ) {
		if ( isSubmitting || ! targetMembers.length ) {
			return;
		}

		setPermissionsModal( { isOpen: true, members: targetMembers } );
	}

	function closePermissionsModal() {
		setPermissionsModal( ( prev ) => ( { ...prev, isOpen: false } ) );
	}

	// ---------------------------------------------------------------------------
	// Confirm-Remove modal handlers (AORM-4.13)
	// ---------------------------------------------------------------------------

	function openConfirmRemoveModal() {
		setConfirmRemoveModal( { isOpen: true, ids: new Set( selectedIds ) } );
	}

	function closeConfirmRemoveModal() {
		setConfirmRemoveModal( ( prev ) => ( { ...prev, isOpen: false } ) );
	}

	/**
	 * Called by ConfirmRemoveModal when the admin clicks Remove.
	 * DELETEs the snapshotted selection from the members endpoint and
	 * refreshes the list on full or partial success.
	 */
	function handleConfirmRemove() {
		const { ids } = confirmRemoveModal;
		closeConfirmRemoveModal();
		setActionNotice( null );
		setIsSubmitting( true );

		apiFetch( {
			path:   `/wicket-aorm/v1/rosters/${ orgUuid }/${ membershipUuid }/members`,
			method: 'DELETE',
			data:   { person_uuids: [ ...ids ] },
		} )
			.then( ( response ) => {
				const removedCount = response?.removed?.length ?? 0;
				const failedCount  = response?.failed?.length  ?? 0;

				if ( failedCount > 0 && removedCount === 0 ) {
					setActionNotice( {
						status:  'error',
						message: sprintf(
							/* translators: %d: number of members that could not be removed */
							__( 'Could not remove %d member(s) from the roster. Please try again.', 'wicket-aorm' ),
							failedCount
						),
					} );
				} else if ( failedCount > 0 ) {
					setActionNotice( {
						status:  'warning',
						message: sprintf(
							/* translators: 1: removed count, 2: failed count */
							__( '%1$d member(s) removed; %2$d could not be removed.', 'wicket-aorm' ),
							removedCount,
							failedCount
						),
					} );
					setSelectedIds( new Set() );
					refresh();
				} else {
					setActionNotice( {
						status:  'success',
						message: sprintf(
							/* translators: %d: number of members removed */
							__( '%d member(s) removed from the roster.', 'wicket-aorm' ),
							removedCount
						),
					} );
					setSelectedIds( new Set() );
					refresh();
				}
			} )
			.catch( ( err ) => {
				setActionNotice( {
					status:  'error',
					message: err?.message ?? __( 'An unexpected error occurred. Please try again.', 'wicket-aorm' ),
				} );
			} )
			.finally( () => {
				setIsSubmitting( false );
			} );
	}

	/**
	 * Called by EditPermissionsModal when the admin clicks Save.
	 * POSTs the role diff to the roles endpoint (`action: 'update'`) and
	 * refreshes the member list when anything changed.
	 *
	 * @param {{ add: string[], remove: string[] }} changes
	 */
	function handlePermissionsSave( { add = [], remove = [] } = {} ) {
		const personUuids = permissionsModal.members.map( ( m ) => m.person_uuid );
		closePermissionsModal();
		setActionNotice( null );

		if ( personUuids.length === 0 || ( add.length === 0 && remove.length === 0 ) ) {
			return;
		}

		setIsSubmitting( true );

		apiFetch( {
			path:   `/wicket-aorm/v1/rosters/${ orgUuid }/${ membershipUuid }/roles`,
			method: 'POST',
			data:   buildRoleUpdatePayload( personUuids, add, remove ),
		} )
			.then( ( response ) => {
				const updatedCount   = response?.updated?.length   ?? 0;
				const failedCount    = response?.failed?.length    ?? 0;
				const unchangedCount = response?.unchanged?.length ?? 0;

				if ( updatedCount === 0 && failedCount === 0 ) {
					setActionNotice( {
						status:  'info',
						message: __( 'No changes were needed — the selected member(s) already had these roles.', 'wicket-aorm' ),
					} );
					setSelectedIds( new Set() );
				} else if ( failedCount > 0 && updatedCount === 0 ) {
					setActionNotice( {
						status:  'error',
						message: sprintf(
							/* translators: %d: number of members that could not be updated */
							__( 'Could not update roles for %d member(s). Please try again.', 'wicket-aorm' ),
							failedCount
						),
					} );
				} else if ( failedCount > 0 ) {
					setActionNotice( {
						status:  'warning',
						message: sprintf(
							/* translators: 1: updated count, 2: failed count */
							__( 'Roles updated for %1$d member(s); %2$d could not be updated.', 'wicket-aorm' ),
							updatedCount,
							failedCount
						),
					} );
					// Some roles did change — refresh so the Roles column is
					// accurate. Selection is kept so the admin can retry.
					refresh();
				} else {
					setActionNotice( {
						status:  'success',
						message: unchangedCount > 0
							? sprintf(
								/* translators: 1: updated count, 2: unchanged count */
								__( 'Roles updated for %1$d member(s); %2$d already had the selected roles.', 'wicket-aorm' ),
								updatedCount,
								unchangedCount
							)
							: sprintf(
								/* translators: %d: number of members updated */
								__( 'Roles updated for %d member(s).', 'wicket-aorm' ),
								updatedCount
							),
					} );
					// Clear selection and refresh list on full success.
					setSelectedIds( new Set() );
					refresh();
				}
			} )
			.catch( ( err ) => {
				setActionNotice( {
					status:  'error',
					message: err?.message ?? __( 'An unexpected error occurred. Please try again.', 'wicket-aorm' ),
				} );
			} )
			.finally( () => {
				setIsSubmitting( false );
			} );
	}

	const { data, isLoading, error, refresh } = useRestApi(
		orgUuid && membershipUuid
			? buildMembersPath( orgUuid, membershipUuid, search, page, PER_PAGE )
			: null
	);

	const members    = data?.members    ?? [];
	const total      = data?.total      ?? 0;
	const totalPages = data?.total_pages ?? 1;

	// Membership owner, resolved server-side independently of pagination and
	// search (MdpClient::getRosterMembers() `pin_owner`) and excluded from
	// `members`/`total`. Always rendered as a sticky first row, and on its
	// own when the roster has no other members.
	const owner    = data?.owner ?? null;
	const hasOwner = !! owner;

	// A committed search term is active (AORM-4.21). Used to distinguish
	// "search matched nothing" from a genuinely empty roster, since `total`
	// above is always scoped to the current search.
	const hasActiveSearch = search !== '';

	// Every row currently rendered (pinned owner + page members), used to
	// resolve the selection back to member objects for the Edit Roles modal.
	// Selection is cleared on page/search changes, so it is always a subset.
	const visibleRows    = owner ? [ { ...owner, is_owner: true }, ...members ] : members;
	const selectedRows   = visibleRows.filter( ( m ) => selectedIds.has( m.person_uuid ) );
	const ownerSelected  = selectedRows.some( ( m ) => m.is_owner );

	function goToPage( next ) {
		setPage( next );
		// Clear selection when navigating pages.
		setSelectedIds( new Set() );
	}

	return (
		<div className="aorm-assignment">
			{ /* Always visible (AORM-4.21) — must not unmount while a debounced
			     search request is loading, or the input loses focus/cursor
			     position mid-keystroke. */ }
			<div className="aorm-assignment__search">
				<SearchControl
					label={ __( 'Search members', 'wicket-aorm' ) }
					hideLabelFromVision
					placeholder={ __( 'Search by name or email…', 'wicket-aorm' ) }
					value={ searchInput }
					onChange={ setSearchInput }
				/>
			</div>

			{ isLoading && <Spinner /> }

			{ ! isLoading && error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ actionNotice && (
				<Notice
					status={ actionNotice.status }
					isDismissible={ true }
					onRemove={ () => setActionNotice( null ) }
					className="aorm-assignment__action-notice"
				>
					{ actionNotice.message }
				</Notice>
			) }

			{ ! isLoading && ! error && total === 0 && ! hasActiveSearch && ! hasOwner && (
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

		{ ! isLoading && ! error && ( total > 0 || hasActiveSearch || hasOwner ) && (
				<>
					<BulkActionToolbar
						selectedCount={ selectedRows.length }
						ownerSelected={ ownerSelected }
						isBusy={ isSubmitting }
						onRemoveFromRoster={ openConfirmRemoveModal }
						onEditRoles={ () => openPermissionsModal( selectedRows ) }
					/>

					<MemberTable
						members={ members }
						owner={ owner }
						selectedIds={ selectedIds }
						onSelectionChange={ setSelectedIds }
						onEditPermissions={ ( member ) => openPermissionsModal( [ member ] ) }
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

		<ConfirmRemoveModal
			isOpen={ confirmRemoveModal.isOpen }
			memberCount={ confirmRemoveModal.ids.size }
			onConfirm={ handleConfirmRemove }
			onClose={ closeConfirmRemoveModal }
		/>

		<EditPermissionsModal
			isOpen={ permissionsModal.isOpen }
			members={ permissionsModal.members }
			onSave={ handlePermissionsSave }
			onClose={ closePermissionsModal }
		/>
		</div>
	);
}
