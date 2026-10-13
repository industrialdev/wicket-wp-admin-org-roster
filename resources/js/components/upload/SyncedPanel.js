/**
 * Synced accordion panel content — multi-visit sessions.
 *
 * Renders the read-only "Synced" PanelBody in the ValidationReviewStep
 * accordion. An admin can sync part of a session, come back later, and keep
 * reviewing the rest; rows pushed to the MDP on an earlier visit are returned
 * by GET /uploads/{id}/staged in a separate `synced` bucket
 * (StagedRecordsTable::getGroupedByCategory()) and shown here instead of
 * under Ready to Sync.
 *
 * There are no row actions or selection: synced records can't be
 * re-categorised or deleted (the REST endpoints return 409 for them).
 * Remove-existing rows (Replace mode) are labelled "Removed from Roster".
 *
 * Props: `records` — all synced staged records.
 */

import { __ } from '@wordpress/i18n';
import RecordsTable from './RecordsTable';
import { RECORD_STATUS_LABELS } from './ReadyToSyncPanel';

// ── Constants (exported for test assertions) ──────────────────────────────────

/** Bucket key returned by the staged endpoint for already-synced rows. */
export const SYNCED_BUCKET = 'synced';

/** Label for the "Synced As" extra column. */
export const SYNCED_AS_COLUMN_LABEL = __( 'Synced As', 'wicket-aorm' );

/** Label for remove_existing rows, which aren't in RECORD_STATUS_LABELS. */
export const REMOVED_FROM_ROSTER_LABEL = __( 'Removed from Roster', 'wicket-aorm' );

/** Shown above the table, explaining why these rows can't be changed. */
export const SYNCED_PANEL_DESCRIPTION = __(
	'These records were already synced to the MDP in this session. They are shown for reference and can no longer be changed.',
	'wicket-aorm'
);

/**
 * Human-readable label for a synced record's record_status.
 *
 * @param {Object} record Synced staged record.
 * @return {string} Display label for the record's record_status.
 */
export function syncedAsLabel( record ) {
	if ( record?.record_status === 'remove_existing' ) {
		return REMOVED_FROM_ROSTER_LABEL;
	}

	return RECORD_STATUS_LABELS[ record?.record_status ] ?? record?.record_status ?? '—';
}

// ── Component ─────────────────────────────────────────────────────────────────

export default function SyncedPanel( { records = [] } ) {
	const extraColumns = [
		{
			key:    'synced_as',
			label:  SYNCED_AS_COLUMN_LABEL,
			render: ( record ) => syncedAsLabel( record ),
		},
	];

	return (
		<div className="aorm-synced-panel">
			<p className="aorm-synced-panel__description">
				{ SYNCED_PANEL_DESCRIPTION }
			</p>
			<RecordsTable records={ records } extraColumns={ extraColumns } />
		</div>
	);
}
