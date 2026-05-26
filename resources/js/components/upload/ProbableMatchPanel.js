/**
 * Probable Match accordion panel content — AORM-8.8.
 *
 * Renders the full content area for the "Probable Match" PanelBody in the
 * ValidationReviewStep accordion.
 *
 * AORM-8.8: Displays a RecordsTable with a "# Matches" extra column that
 * shows the match_count for each record. Display only — no View Match action
 * is available at this stage (deferred to AORM-8B).
 *
 * @param {{
 *   records: Array<Object>,  — all probable_match staged records
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
export const PROBABLE_MATCH_STATUS = 'probable_match';

/**
 * Label for the "# Matches" extra column.
 * Exported for test assertions.
 *
 * @type {string}
 */
export const MATCHES_COLUMN_LABEL = __( '# Matches', 'wicket-aorm' );

// ── Component ─────────────────────────────────────────────────────────────────

export default function ProbableMatchPanel( { records } ) {
	/**
	 * Extra column injected into the RecordsTable for AORM-8.8.
	 * Displays the match_count for each record (integer, display only).
	 */
	const extraColumns = [
		{
			key:    'match_count',
			label:  MATCHES_COLUMN_LABEL,
			render: ( record ) => record.match_count ?? 0,
		},
	];

	return (
		<div className="aorm-probable-match-panel">
			<RecordsTable
				records={ records }
				extraColumns={ extraColumns }
				noRecordsText={ __( 'No probable matches found.', 'wicket-aorm' ) }
			/>
		</div>
	);
}
