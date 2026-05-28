/**
 * Imported Record Summary — AORM-8B.11.
 *
 * Renders a definition-list summary of the uploaded record fields for use
 * inside the Review Match modal (ReviewMatchModal.js). Replaces the inline
 * stub that was scaffolded in AORM-8B.10.
 *
 * Displayed fields (drawn from the staged record's raw_data object):
 *   - Full Name   — first_name + last_name joined by a space; falls back to "—"
 *                   when both parts are absent.
 *   - Email       — email_address; falls back to "—" when absent.
 *   - Phone       — mobile_phone; row omitted entirely when absent/empty.
 *   - Title       — title; row omitted entirely when absent/empty.
 *
 * @param {{
 *   rawData: Object,  — the raw_data object from a staged record
 * }} props
 */

import { __ } from '@wordpress/i18n';

// ── Label constants ────────────────────────────────────────────────────────────
// Exported so test assertions can reference the same values without duplication.

/**
 * Label for the full-name row.
 *
 * @type {string}
 */
export const FIELD_LABEL_NAME = __( 'Name', 'wicket-aorm' );

/**
 * Label for the email row.
 *
 * @type {string}
 */
export const FIELD_LABEL_EMAIL = __( 'Email', 'wicket-aorm' );

/**
 * Label for the phone row.
 *
 * @type {string}
 */
export const FIELD_LABEL_PHONE = __( 'Phone', 'wicket-aorm' );

/**
 * Label for the title row.
 *
 * @type {string}
 */
export const FIELD_LABEL_TITLE = __( 'Title', 'wicket-aorm' );

// ── Component ─────────────────────────────────────────────────────────────────

export default function ImportedRecordSummary( { rawData = {} } ) {
	/**
	 * Full name derived from raw_data. Falls back to "—" when both parts are
	 * absent (e.g. a synthetic remove_existing row with no upload data).
	 */
	const fullName = [ rawData.first_name, rawData.last_name ]
		.filter( Boolean )
		.join( ' ' ) || '—';

	return (
		<dl className="aorm-imported-record-summary">

			<dt className="aorm-imported-record-summary__term">
				{ FIELD_LABEL_NAME }
			</dt>
			<dd className="aorm-imported-record-summary__value">
				{ fullName }
			</dd>

			<dt className="aorm-imported-record-summary__term">
				{ FIELD_LABEL_EMAIL }
			</dt>
			<dd className="aorm-imported-record-summary__value">
				{ rawData.email_address || '—' }
			</dd>

			{ rawData.mobile_phone && (
				<>
					<dt className="aorm-imported-record-summary__term">
						{ FIELD_LABEL_PHONE }
					</dt>
					<dd className="aorm-imported-record-summary__value">
						{ rawData.mobile_phone }
					</dd>
				</>
			) }

			{ rawData.title && (
				<>
					<dt className="aorm-imported-record-summary__term">
						{ FIELD_LABEL_TITLE }
					</dt>
					<dd className="aorm-imported-record-summary__value">
						{ rawData.title }
					</dd>
				</>
			) }

		</dl>
	);
}
