/**
 * Discard accordion panel content — AORM-8.10.
 *
 * Renders the full content area for the "Discard" PanelBody in the
 * ValidationReviewStep accordion.
 *
 * AORM-8.10: Displays a RecordsTable with a "Previous Category" extra column
 * that shows the previous_category value for each record. Display only —
 * records in this bucket have been discarded and require no further action
 * until AORM-8B.7 adds per-row and bulk reinstate/remove actions.
 *
 * @param {{
 *   records: Array<Object>,  — all discard staged records
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
export const DISCARD_STATUS = 'discard';

/**
 * Label for the "Previous Category" extra column.
 * Exported for test assertions.
 *
 * @type {string}
 */
export const PREVIOUS_CATEGORY_COLUMN_LABEL = __( 'Previous Category', 'wicket-aorm' );

// ── Component ─────────────────────────────────────────────────────────────────

export default function DiscardPanel( { records } ) {
	/**
	 * Extra column injected into the RecordsTable for AORM-8.10.
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
		<div className="aorm-discard-panel">
			<RecordsTable
				records={ records }
				extraColumns={ extraColumns }
				noRecordsText={ __( 'No records have been discarded.', 'wicket-aorm' ) }
			/>
		</div>
	);
}
