/**
 * Matches Table — AORM-8B.13 / AORM-8B.14.
 *
 * Fetches full MDP person details for each match candidate from the
 * AORM-8B.12 endpoint and renders them as a table inside the Review Match
 * modal (AORM-8B.10).
 *
 * AORM-8B.14: match-field highlighting. When rawData (the imported record's
 * raw_data object) is provided, cells whose value matches the corresponding
 * imported field receive the HIGHLIGHT_CLASS CSS class. Compared fields:
 *   - Name  — imported first_name+last_name vs match full_name
 *   - Email — imported email_address vs match primary_email
 *   - Phone — imported mobile_phone vs match primary_phone
 *   - Title — imported title vs match title
 * Comparison is case-insensitive and whitespace-normalised.
 *
 * Endpoint: GET /wicket-aorm/v1/staged/{id}/matches
 *
 * While loading, a Spinner is shown. On error, a dismissible Notice is
 * rendered. When the endpoint returns an empty matches array a short
 * "no candidates" message is shown instead of an empty table.
 *
 * Columns (in order):
 *   - Name / ID  — full_name on the first line, UUID below in muted text.
 *   - Email      — primary_email.
 *   - Location   — city + country from location object, comma-joined.
 *   - Phone      — primary_phone.
 *   - Title      — job title.
 *   - Employer   — employer name (lazy-loaded by the server via a secondary
 *                  organisations call in AORM-8B.12).
 *   - Membership — membership_status (empty-string renders as "—").
 *   - MDP        — "View in MDP" ExternalLink to mdp_url when available.
 *
 * @param {{
 *   recordId: number,  — staged record ID; drives the endpoint path
 *   rawData?: Object,  — imported record's raw_data for field highlighting (AORM-8B.14)
 * }} props
 */

import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { ExternalLink, Notice, Spinner } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';

// ── Column label constants ─────────────────────────────────────────────────────
// Exported so test assertions can reference the same strings without duplication.

/**
 * Header label for the Name / ID column.
 *
 * @type {string}
 */
export const COL_LABEL_NAME = __( 'Name', 'wicket-aorm' );

/**
 * Header label for the Email column.
 *
 * @type {string}
 */
export const COL_LABEL_EMAIL = __( 'Email', 'wicket-aorm' );

/**
 * Header label for the Location column.
 *
 * @type {string}
 */
export const COL_LABEL_LOCATION = __( 'Location', 'wicket-aorm' );

/**
 * Header label for the Phone column.
 *
 * @type {string}
 */
export const COL_LABEL_PHONE = __( 'Phone', 'wicket-aorm' );

/**
 * Header label for the Title column.
 *
 * @type {string}
 */
export const COL_LABEL_TITLE = __( 'Title', 'wicket-aorm' );

/**
 * Header label for the Employer column.
 *
 * @type {string}
 */
export const COL_LABEL_EMPLOYER = __( 'Employer', 'wicket-aorm' );

/**
 * Header label for the Membership Status column.
 *
 * @type {string}
 */
export const COL_LABEL_MEMBERSHIP_STATUS = __( 'Membership Status', 'wicket-aorm' );

/**
 * Header label for the MDP link column.
 *
 * @type {string}
 */
export const COL_LABEL_MDP_LINK = __( 'MDP', 'wicket-aorm' );

/**
 * CSS class applied to a table cell whose value matches the corresponding
 * imported record field (AORM-8B.14).
 *
 * @type {string}
 */
export const HIGHLIGHT_CLASS = 'aorm-matches-table__cell--match';

// ── Helpers ────────────────────────────────────────────────────────────────────

/**
 * Normalise a value for field comparison: convert to string, trim, lowercase.
 * Returns an empty string for null/undefined/empty values.
 *
 * @param {*} value
 * @return {string}
 */
function normalizeForCompare( value ) {
	if ( value === null || value === undefined ) {
		return '';
	}

	return String( value ).trim().toLowerCase();
}

// ── MatchesTable ───────────────────────────────────────────────────────────────

/**
 * @param {{
 *   recordId:         number,
 *   rawData?:         Object,
 *   onMatchesLoaded?: (matches: Object[]) => void,
 * }} props
 */
export default function MatchesTable( { recordId, rawData = {}, onMatchesLoaded } ) {
	const [ matches, setMatches ]     = useState( null );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ error, setError ]         = useState( null );

	useEffect( () => {
		if ( ! recordId ) {
			return;
		}

		let cancelled = false;

		setIsLoading( true );
		setError( null );
		setMatches( null );

		apiFetch( { path: `/wicket-aorm/v1/staged/${ recordId }/matches` } )
			.then( ( data ) => {
				if ( ! cancelled ) {
					const loaded = data.matches ?? [];
					setMatches( loaded );
					onMatchesLoaded?.( loaded );
				}
			} )
			.catch( ( err ) => {
				if ( ! cancelled ) {
					setError(
						err?.message ??
							__( 'Failed to load match candidates.', 'wicket-aorm' )
					);
				}
			} )
			.finally( () => {
				if ( ! cancelled ) {
					setIsLoading( false );
				}
			} );

		return () => {
			cancelled = true;
		};
	}, [ recordId ] );

	if ( isLoading ) {
		return (
			<div className="aorm-matches-table__loading">
				<Spinner />
			</div>
		);
	}

	if ( error ) {
		return (
			<Notice
				status="error"
				isDismissible={ false }
				className="aorm-matches-table__error"
			>
				{ error }
			</Notice>
		);
	}

	if ( matches === null ) {
		return null;
	}

	if ( matches.length === 0 ) {
		return (
			<p className="aorm-matches-table__empty">
				{ __( 'No match candidates found.', 'wicket-aorm' ) }
			</p>
		);
	}

	return (
		<div className="aorm-matches-table__wrapper">
			<table className="aorm-matches-table wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th scope="col" className="aorm-matches-table__th aorm-matches-table__th--name">
							{ COL_LABEL_NAME }
						</th>
						<th scope="col" className="aorm-matches-table__th aorm-matches-table__th--email">
							{ COL_LABEL_EMAIL }
						</th>
						<th scope="col" className="aorm-matches-table__th aorm-matches-table__th--location">
							{ COL_LABEL_LOCATION }
						</th>
						<th scope="col" className="aorm-matches-table__th aorm-matches-table__th--phone">
							{ COL_LABEL_PHONE }
						</th>
						<th scope="col" className="aorm-matches-table__th aorm-matches-table__th--title">
							{ COL_LABEL_TITLE }
						</th>
						<th scope="col" className="aorm-matches-table__th aorm-matches-table__th--employer">
							{ COL_LABEL_EMPLOYER }
						</th>
						<th scope="col" className="aorm-matches-table__th aorm-matches-table__th--membership-status">
							{ COL_LABEL_MEMBERSHIP_STATUS }
						</th>
						<th scope="col" className="aorm-matches-table__th aorm-matches-table__th--mdp-link">
							{ COL_LABEL_MDP_LINK }
						</th>
					</tr>
				</thead>
				<tbody>
					{ matches.map( ( match ) => (
						<MatchRow key={ match.uuid } match={ match } rawData={ rawData } />
					) ) }
				</tbody>
			</table>
		</div>
	);
}

// ── MatchRow ───────────────────────────────────────────────────────────────────

/**
 * Renders a single candidate row inside the MatchesTable.
 *
 * AORM-8B.14: When rawData is provided, cells are highlighted with
 * HIGHLIGHT_CLASS wherever the candidate's value matches the imported field.
 *
 * @param {{ match: Object, rawData: Object }} props
 */
function MatchRow( { match, rawData = {} } ) {
	const location = [ match.location?.city, match.location?.country ]
		.filter( Boolean )
		.join( ', ' );

	// ── AORM-8B.14: field highlight helpers ───────────────────────────────────

	/** Imported full name derived from raw_data (mirrors ImportedRecordSummary). */
	const importedFullName = normalizeForCompare(
		[ rawData.first_name, rawData.last_name ].filter( Boolean ).join( ' ' )
	);

	/**
	 * Returns HIGHLIGHT_CLASS when the candidate value matches the imported
	 * value; returns an empty string otherwise. Skips comparison when the
	 * imported value is empty (nothing to compare against).
	 *
	 * @param {string} importedValue  — already-normalised imported field value
	 * @param {*}      matchValue     — raw candidate field value
	 * @return {string}
	 */
	function highlightClass( importedValue, matchValue ) {
		if ( ! importedValue ) {
			return '';
		}

		return normalizeForCompare( matchValue ) === importedValue
			? HIGHLIGHT_CLASS
			: '';
	}

	const nameHighlight  = highlightClass( importedFullName, match.full_name );
	const emailHighlight = highlightClass(
		normalizeForCompare( rawData.email_address ),
		match.primary_email
	);
	const phoneHighlight = highlightClass(
		normalizeForCompare( rawData.mobile_phone ),
		match.primary_phone
	);
	const titleHighlight = highlightClass(
		normalizeForCompare( rawData.title ),
		match.title
	);

	return (
		<tr className="aorm-matches-table__row">

			<td className={ `aorm-matches-table__cell aorm-matches-table__cell--name ${ nameHighlight }`.trim() }>
				<span className="aorm-matches-table__full-name">
					{ match.full_name || '—' }
				</span>
				{ match.uuid && (
					<span className="aorm-matches-table__uuid">
						{ match.uuid }
					</span>
				) }
			</td>

			<td className={ `aorm-matches-table__cell aorm-matches-table__cell--email ${ emailHighlight }`.trim() }>
				{ match.primary_email || '—' }
			</td>

			<td className="aorm-matches-table__cell aorm-matches-table__cell--location">
				{ location || '—' }
			</td>

			<td className={ `aorm-matches-table__cell aorm-matches-table__cell--phone ${ phoneHighlight }`.trim() }>
				{ match.primary_phone || '—' }
			</td>

			<td className={ `aorm-matches-table__cell aorm-matches-table__cell--title ${ titleHighlight }`.trim() }>
				{ match.title || '—' }
			</td>

			<td className="aorm-matches-table__cell aorm-matches-table__cell--employer">
				{ match.employer || '—' }
			</td>

			<td className="aorm-matches-table__cell aorm-matches-table__cell--membership-status">
				{ match.membership_status || '—' }
			</td>

			<td className="aorm-matches-table__cell aorm-matches-table__cell--mdp-link">
				{ match.mdp_url ? (
					<ExternalLink href={ match.mdp_url }>
						{ __( 'View in MDP', 'wicket-aorm' ) }
					</ExternalLink>
				) : (
					'—'
				) }
			</td>

		</tr>
	);
}
