/**
 * Probable Match accordion panel content — AORM-8.8 / AORM-8B.5.
 *
 * Renders the full content area for the "Probable Match" PanelBody in the
 * ValidationReviewStep accordion.
 *
 * AORM-8.8: Displays a RecordsTable with a "# Matches" extra column that
 * shows the match_count for each record.
 *
 * AORM-8B.5: Adds an "Actions" extra column with a per-row "View Match"
 * button that fires the onOpenReviewModal(record) prop callback (wired to
 * the Review Match modal in AORM-8B.10). The Actions column is only added
 * when onOpenReviewModal is provided; when absent the panel renders exactly
 * as it did in AORM-8.8.
 *
 * @param {{
 *   records:             Array<Object>,    — all probable_match staged records
 *   onOpenReviewModal?:  (record: Object) => void,
 * }} props
 */

import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
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

/**
 * Record statuses eligible for the "View Match" action in this panel.
 * Exported for test assertions.
 *
 * @type {string[]}
 */
export const VIEW_MATCH_STATUSES = [ 'probable_match' ];

// ── Component ─────────────────────────────────────────────────────────────────

export default function ProbableMatchPanel( { records, onOpenReviewModal } ) {
	/**
	 * "# Matches" column — always present (AORM-8.8).
	 */
	const matchCountColumn = {
		key:    'match_count',
		label:  MATCHES_COLUMN_LABEL,
		render: ( record ) => record.match_count ?? 0,
	};

	/**
	 * "Actions" column — only injected when onOpenReviewModal is provided
	 * (AORM-8B.5). Contains a "View Match" button per row.
	 */
	const actionsColumn = {
		key:    'actions',
		label:  __( 'Actions', 'wicket-aorm' ),
		render: ( record ) => (
			<Button
				variant="tertiary"
				isSmall
				onClick={ () => onOpenReviewModal?.( record ) }
			>
				{ __( 'View Match', 'wicket-aorm' ) }
			</Button>
		),
	};

	const extraColumns = [
		matchCountColumn,
		...( onOpenReviewModal ? [ actionsColumn ] : [] ),
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
