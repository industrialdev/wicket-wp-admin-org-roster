/**
 * Roster Assignment tab — AORM-4, Tab 1.
 *
 * Fetches the list of people currently assigned to the roster via the
 * GET /wicket-aorm/v1/rosters/{org_uuid}/{membership_uuid}/members endpoint
 * (AORM-4.5) and renders a MemberTable with row-level checkboxes for
 * future bulk-action selection (AORM-4.8).
 *
 * Pagination is handled client-side via page state; each page change
 * triggers a new REST request.
 *
 * @param {{ orgUuid: string, membershipUuid: string }} props
 */

import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Notice, Spinner } from '@wordpress/components';

import { useRestApi } from '../hooks/useRestApi';
import MemberTable from './MemberTable';

const PER_PAGE = 10;

export default function RosterAssignment( { orgUuid, membershipUuid } ) {
	const [ selectedIds, setSelectedIds ] = useState( new Set() );
	const [ page, setPage ] = useState( 1 );

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

			{ ! isLoading && ! error && (
				<>
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
		</div>
	);
}
