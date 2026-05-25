/**
 * Ready to Sync accordion panel content — AORM-8.4 / 8.5 / 8.6.
 *
 * Renders the full content area for the "Ready to Sync" PanelBody in the
 * ValidationReviewStep accordion.
 *
 * AORM-8.4: Session header — shows the uploaded file name (when available)
 * and the action type label ("Add to Roster" or "Replace Roster").
 *
 * AORM-8.5: "Records being added" table (New Record, Exact Match,
 * Merging to Record, Already on Roster) — added in AORM-8.5.
 *
 * AORM-8.6: "Records being removed" table (Remove Existing Record,
 * Replace mode only) — added in AORM-8.6.
 *
 * @param {{
 *   records:    Array<Object>,  — all ready_to_sync staged records
 *   actionType: string,        — 'add' | 'replace' from the staged API response
 *   fileName:   string|null,   — original CSV file name (null after a page reload)
 * }} props
 */

import { __ } from '@wordpress/i18n';
import RecordsTable from './RecordsTable';

// ── Constants ─────────────────────────────────────────────────────────────────

/**
 * Human-readable labels for the action_type values returned by the
 * GET /wicket-aorm/v1/uploads/{session_id}/staged endpoint.
 *
 * Used by the session header (AORM-8.4) and available to tests for
 * display-string assertions.
 *
 * @type {Record<string, string>}
 */
export const ACTION_TYPE_LABELS = {
	add:     __( 'Add to Roster',   'wicket-aorm' ),
	replace: __( 'Replace Roster', 'wicket-aorm' ),
};

/**
 * Human-readable labels for record_status values surfaced in the
 * "Records being added" table (AORM-8.5).
 *
 * Exported so tests can assert display strings without duplicating them.
 *
 * @type {Record<string, string>}
 */
export const RECORD_STATUS_LABELS = {
	new_record:        __( 'New Record',        'wicket-aorm' ),
	exact_match:       __( 'Exact Match',       'wicket-aorm' ),
	merging_to_record: __( 'Merging to Record', 'wicket-aorm' ),
	already_on_roster: __( 'Already on Roster', 'wicket-aorm' ),
};

/**
 * record_status values that belong in the "Records being added" table.
 * Excludes 'remove_existing', which belongs in the AORM-8.6 removal table.
 *
 * @type {string[]}
 */
export const ADDED_STATUSES = Object.keys( RECORD_STATUS_LABELS );

// ── Component ─────────────────────────────────────────────────────────────────

export default function ReadyToSyncPanel( { records, actionType, fileName } ) {
	const actionLabel = ACTION_TYPE_LABELS[ actionType ] ?? actionType;

	// ── AORM-8.5: Derive "Records being added" subset ────────────────────────

	const addedRecords = records.filter(
		( r ) => ADDED_STATUSES.includes( r.record_status )
	);

	/**
	 * Extra column injected into the RecordsTable for AORM-8.5.
	 * Displays the human-readable status label for each record.
	 */
	const addedExtraColumns = [
		{
			key:    'record_status',
			label:  __( 'Status', 'wicket-aorm' ),
			render: ( record ) =>
				RECORD_STATUS_LABELS[ record.record_status ] ?? record.record_status,
		},
	];

	return (
		<div className="aorm-ready-to-sync-panel">

			{ /* AORM-8.4: Session header */ }
			<div className="aorm-ready-to-sync-panel__header">
				<dl className="aorm-ready-to-sync-panel__header-details">

					{ fileName && (
						<>
							<dt className="aorm-ready-to-sync-panel__header-term">
								{ __( 'File', 'wicket-aorm' ) }
							</dt>
							<dd className="aorm-ready-to-sync-panel__header-value">
								{ fileName }
							</dd>
						</>
					) }

					<dt className="aorm-ready-to-sync-panel__header-term">
						{ __( 'Action', 'wicket-aorm' ) }
					</dt>
					<dd className="aorm-ready-to-sync-panel__header-value">
						{ actionLabel }
					</dd>

				</dl>
			</div>

			{ /* AORM-8.5: "Records being added" table */ }
			<div className="aorm-ready-to-sync-panel__section aorm-ready-to-sync-panel__section--added">
				<h3 className="aorm-ready-to-sync-panel__section-heading">
					{ __( 'Records being added', 'wicket-aorm' ) }
				</h3>
				<RecordsTable
					records={ addedRecords }
					extraColumns={ addedExtraColumns }
					noRecordsText={ __( 'No records to add.', 'wicket-aorm' ) }
				/>
			</div>

			{ /* AORM-8.6: "Records being removed" table (Replace mode only) — added in AORM-8.6. */ }

		</div>
	);
}
