/**
 * Roster Activity tab — AORM-4.16.
 *
 * Fetches the scoped audit log for this org membership via
 * GET /wicket-aorm/v1/rosters/{org_uuid}/{membership_uuid}/activity
 * (endpoint built in AORM-4.18) and renders a read-only ActivityTable.
 *
 * States:
 *   - Missing params → error Notice
 *   - Loading        → Spinner
 *   - Error          → error Notice with the server message
 *   - Empty          → ActivityTable (shows its own empty-state row)
 *   - Populated      → ActivityTable with one row per log entry
 *
 * @param {{ orgUuid: string, membershipUuid: string }} props
 */

import { __ } from '@wordpress/i18n';
import { Notice, Spinner } from '@wordpress/components';

import { useRestApi } from '../hooks/useRestApi';
import ActivityTable from './ActivityTable';

export default function RosterActivity( { orgUuid, membershipUuid } ) {
	const hasParams = !! orgUuid && !! membershipUuid;

	const path = hasParams
		? `/wicket-aorm/v1/rosters/${ orgUuid }/${ membershipUuid }/activity`
		: null;

	const { data, isLoading, error } = useRestApi( path );

	// ── Guard — missing route params ─────────────────────────────────────────
	if ( ! hasParams ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ __(
					'Missing organisation or membership UUID.',
					'wicket-aorm'
				) }
			</Notice>
		);
	}

	// ── Loading ───────────────────────────────────────────────────────────────
	if ( isLoading ) {
		return <Spinner />;
	}

	// ── Error ─────────────────────────────────────────────────────────────────
	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}

	// ── Data ──────────────────────────────────────────────────────────────────
	const entries = data?.entries ?? [];

	return (
		<div className="aorm-activity">
			<ActivityTable entries={ entries } />
		</div>
	);
}
