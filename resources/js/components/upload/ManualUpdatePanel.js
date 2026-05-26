/**
 * Manual Update accordion panel content — AORM-8.9.
 *
 * Renders the full content area for the "Manual Updates" PanelBody in the
 * ValidationReviewStep accordion.
 *
 * AORM-8.9: Displays a RecordsTable with a "Previous Category" extra column
 * that shows the previous_category value for each record. Display only —
 * records in this bucket require manual administrator review before syncing.
 *
 * @param {{
 *   records: Array<Object>,  — all manual_update staged records
 * }} props
 */

import { __ } from '@wordpress/i18n';
import RecordsTable from './RecordsTable';

// ── Constants ─────────────────────────────────────────────────────────────────

/**
 * The record_status value used for all records in this panel.
 * Exported for test assertions.
 *
 * @type {string}
 */
export const MANUAL_UPDATE_STATUS = 'manual_update';

/**
 * Label for the "Previous Category" extra column.
 * Exported for test assertions.
 *
 * @type {string}
 */
export const PREVIOUS_CATEGORY_COLUMN_LABEL = __( 'Previous Category', 'wicket-aorm' );

// ── Component ─────────────────────────────────────────────────────────────────

export default function ManualUpdatePanel( { records } ) {
	/**
	 * Extra column injected into the RecordsTable for AORM-8.9.
	 * Displays the previous_category for each record (null/empty → "—").
	 */
	const extraColumns = [
		{
			key:    'previous_category',
			label:  PREVIOUS_CATEGORY_COLUMN_LABEL,
			render: ( record ) => record.previous_category ?? '—',
		},
	];

	return (
		<div className="aorm-manual-update-panel">
			<RecordsTable
				records={ records }
				extraColumns={ extraColumns }
				noRecordsText={ __( 'No records require manual updates.', 'wicket-aorm' ) }
			/>
		</div>
	);
}
