/**
 * Matches Table — AORM-8B.13 / AORM-8B.14.
 *
 * Fetches full MDP person details for each match candidate from the
 * AORM-8B.12 endpoint and renders them as a table inside the Review Match
 * modal (AORM-8B.10).
 *
 * AORM-8B.14: match-field highlighting. When rawData (the imported record's
 * raw_data object) is provided, cells/spans whose value matches the
 * corresponding imported field receive the HIGHLIGHT_CLASS CSS class.
 * Compared fields:
 *   - First name — imported first_name vs match given_name  (highlighted independently)
 *   - Last name  — imported last_name vs match family_name  (highlighted independently)
 *   - Email      — imported email vs each entry in match.emails (see below)
 *   - Phone      — imported phone vs the displayed phone (see Phone column note below)
 *   - Title      — imported title vs match title
 * Comparison is case-insensitive and whitespace-normalised, except Phone,
 * which is compared digits-only (stripping "+", spaces, dashes, parentheses)
 * so an MDP number in E.164 format (e.g. "+16512343651") still matches a
 * plain imported CSV value ("16512343651") — mirrors the same normalisation
 * MdpClient::searchPersons()/ScoringService use server-side. First and last
 * name are compared and highlighted separately so a partial match (e.g. only
 * the last name lines up) is still visible — the Name cell no longer requires
 * both parts to match before showing any highlight.
 *
 * Email column (updated): previously only the candidate's primary_email was
 * shown. The Email cell now lists every known email address for the
 * candidate (from match.emails — {address, type, primary}[]), not just the
 * primary one, so an admin can spot a match on a secondary address (e.g. a
 * personal email used at import time vs a work email on file). The primary
 * address is always sorted first and carries a "Primary" badge; each entry
 * is independently highlighted when it matches the imported email. Falls
 * back to a single entry built from primary_email when match.emails is
 * empty (e.g. the slim fallback shape used for unresolved candidates in
 * StagedMatchesController).
 *
 * Endpoint: GET /wicket-aorm/v1/staged/{id}/matches
 *
 * While loading, a Spinner is shown. On error, a dismissible Notice is
 * rendered. When the endpoint returns an empty matches array a short
 * "no candidates" message is shown instead of an empty table.
 *
 * Columns (in order):
 *   - Name / ID  — full_name on the first line, UUID below in muted text.
 *   - Email      — every known email address (see above), primary first.
 *   - Location   — city + country from location object, comma-joined.
 *   - Phone      — primary_phone, unless a phone match type is configured
 *                  (window.aormContext.phoneMatchType), in which case the
 *                  candidate's phone of that specific type is shown instead
 *                  (from match.phones — {number, type, primary}[] — falling
 *                  back to "—" when the candidate has no phone of that type,
 *                  even if they have a primary phone of a different type).
 *                  The column header includes the type label when configured,
 *                  e.g. "Phone (Mobile)" — mirrors MemberTable.js's Roster
 *                  Assignment tab column for consistency.
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
import { __, sprintf } from '@wordpress/i18n';
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
 * Base header label for the Phone column, used as-is when no phone match
 * type is configured (window.aormContext.phoneMatchType is empty / "Any
 * type"). Use buildPhoneColumnLabel() to get the type-aware label actually
 * rendered in the header.
 *
 * @type {string}
 */
export const COL_LABEL_PHONE = __( 'Phone', 'wicket-aorm' );

/**
 * Builds the Phone column header label, including the configured phone
 * match type when one is set (e.g. "Phone (Mobile)") — mirrors
 * MemberTable.js's buildPhoneColumnLabel() for the Roster Assignment tab so
 * both surfaces stay consistent.
 *
 * @param {string} phoneMatchTypeLabel
 * @return {string}
 */
export function buildPhoneColumnLabel( phoneMatchTypeLabel ) {
	if ( ! phoneMatchTypeLabel ) {
		return COL_LABEL_PHONE;
	}

	return sprintf(
		/* translators: %s: configured phone type label, e.g. "Mobile" */
		__( 'Phone (%s)', 'wicket-aorm' ),
		phoneMatchTypeLabel
	);
}

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
 * CSS class applied to a table cell (or, for the Name column, an individual
 * first/last name span) whose value matches the corresponding imported
 * record field (AORM-8B.14).
 *
 * @type {string}
 */
export const HIGHLIGHT_CLASS = 'aorm-matches-table__cell--match';

/**
 * CSS class applied to each first/last name span inside the Name cell, so
 * the highlight (HIGHLIGHT_CLASS) can be scoped to just the part that
 * matched rather than the whole name.
 *
 * @type {string}
 */
export const NAME_PART_CLASS = 'aorm-matches-table__name-part';

/**
 * CSS class applied to the <ul> listing a candidate's email addresses in
 * the Email column.
 *
 * @type {string}
 */
export const EMAIL_LIST_CLASS = 'aorm-matches-table__email-list';

/**
 * CSS class applied to each <li> email entry inside EMAIL_LIST_CLASS.
 *
 * @type {string}
 */
export const EMAIL_ITEM_CLASS = 'aorm-matches-table__email-item';

/**
 * CSS class applied to the "Primary" badge shown next to a candidate's
 * primary email address.
 *
 * @type {string}
 */
export const PRIMARY_EMAIL_BADGE_CLASS = 'aorm-matches-table__email-primary-badge';

/**
 * Label text for the "Primary" badge shown next to a candidate's primary
 * email address.
 *
 * @type {string}
 */
export const PRIMARY_EMAIL_LABEL = __( 'Primary', 'wicket-aorm' );

// ── Helpers ────────────────────────────────────────────────────────────────────

/**
 * Build the ordered list of email entries to render for a match candidate.
 *
 * Prefers the full match.emails array ({address, type, primary}[]) so every
 * known email address is shown, not just the primary one. Falls back to a
 * single synthetic entry built from match.primary_email when emails is
 * empty — this covers the slim fallback shape StagedMatchesController
 * returns for a candidate UUID that could not be resolved via the MDP.
 *
 * The primary entry (if any) is always sorted first; remaining entries keep
 * the order returned by the API.
 *
 * @param {{emails?: Object[], primary_email?: string}} match
 * @return {Object[]}  List of {address, type, primary} entries.
 */
export function buildEmailEntries( match ) {
	const emails = Array.isArray( match?.emails ) ? match.emails : [];

	if ( emails.length > 0 ) {
		return [ ...emails ].sort(
			( a, b ) => ( b.primary ? 1 : 0 ) - ( a.primary ? 1 : 0 )
		);
	}

	if ( match?.primary_email ) {
		return [ { address: match.primary_email, type: '', primary: true } ];
	}

	return [];
}

/**
 * Resolve the phone number to display/highlight for a match candidate.
 *
 * When no phone match type is configured (phoneMatchType === ''), returns
 * match.primary_phone — unchanged from before this was configurable. When a
 * type is configured, only phones of that type (from match.phones —
 * {number, type, primary}[]) are considered, preferring whichever is flagged
 * primary among those; returns '' when the candidate has none of that type,
 * even if they have a primary phone of a different type — this keeps the
 * Phone column and its highlighting consistent with what MdpClient actually
 * matched/fetched server-side (see MdpClient::normalizePeopleSearchResults()
 * and ::fetchOrgScopedRolesAndPhones()).
 *
 * @param {{primary_phone?: string, phones?: Object[]}} match
 * @param {string}                                       phoneMatchType
 * @return {string}
 */
export function resolveDisplayPhone( match, phoneMatchType ) {
	if ( ! phoneMatchType ) {
		return match?.primary_phone || '';
	}

	const phonesOfType = ( Array.isArray( match?.phones ) ? match.phones : [] ).filter(
		( phone ) => phone?.type === phoneMatchType
	);

	if ( phonesOfType.length === 0 ) {
		return '';
	}

	const primary = phonesOfType.find( ( phone ) => phone?.primary );

	return ( primary ?? phonesOfType[ 0 ] )?.number || '';
}

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

/**
 * Normalise a phone number for comparison by stripping every non-digit
 * character — mirrors MdpClient::searchPersons()'s / ScoringService's phone
 * normalisation server-side. Without this, an MDP number rendered in E.164
 * format (e.g. "+16512343651") never highlights as a match against a plain
 * imported CSV value ("16512343651"), even though they're the same number —
 * the generic normalizeForCompare() above only trims/lowercases, it doesn't
 * strip the "+" (or spaces/dashes/parentheses).
 *
 * @param {*} value
 * @return {string}
 */
export function normalizePhoneForCompare( value ) {
	if ( value === null || value === undefined ) {
		return '';
	}

	return String( value ).replace( /\D/g, '' );
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

	const context = window.aormContext ?? {};
	const phoneMatchType = context.phoneMatchType ?? '';
	const phoneColumnLabel = buildPhoneColumnLabel( context.phoneMatchTypeLabel ?? '' );

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
							{ phoneColumnLabel }
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
						<MatchRow
							key={ match.uuid }
							match={ match }
							rawData={ rawData }
							phoneMatchType={ phoneMatchType }
						/>
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
 * First and last name are compared independently (given_name vs first_name,
 * family_name vs last_name) so each name part highlights on its own —
 * matching only the last name (for example) still highlights that part even
 * though the first name differs.
 *
 * @param {{ match: Object, rawData: Object, phoneMatchType?: string }} props
 */
function MatchRow( { match, rawData = {}, phoneMatchType = '' } ) {
	const location = [ match.location?.city, match.location?.country ]
		.filter( Boolean )
		.join( ', ' );

	// ── AORM-8B.14: field highlight helpers ───────────────────────────────────

	/**
	 * Returns HIGHLIGHT_CLASS when the candidate value matches the imported
	 * value; returns an empty string otherwise. Skips comparison when the
	 * imported value is empty (nothing to compare against).
	 *
	 * @param {string}   importedValue — already-normalised imported field value
	 * @param {*}        matchValue    — raw candidate field value
	 * @param {Function} normalizer    — normaliser applied to matchValue before
	 *                                   comparing; must match whichever one was
	 *                                   used to produce importedValue. Defaults
	 *                                   to normalizeForCompare (case/whitespace
	 *                                   only); pass normalizePhoneForCompare for
	 *                                   phone fields.
	 * @return {string}
	 */
	function highlightClass( importedValue, matchValue, normalizer = normalizeForCompare ) {
		if ( ! importedValue ) {
			return '';
		}

		return normalizer( matchValue ) === importedValue
			? HIGHLIGHT_CLASS
			: '';
	}

	// First/last name are compared and highlighted independently — a match on
	// just one part no longer requires the other part to match too.
	const firstNameHighlight = highlightClass(
		normalizeForCompare( rawData.first_name ),
		match.given_name
	);
	const lastNameHighlight = highlightClass(
		normalizeForCompare( rawData.last_name ),
		match.family_name
	);
	const normalizedImportedEmail = normalizeForCompare( rawData.email );
	const emailEntries            = buildEmailEntries( match );
	const displayPhone            = resolveDisplayPhone( match, phoneMatchType );
	const phoneHighlight = highlightClass(
		normalizePhoneForCompare( rawData.phone ),
		displayPhone,
		normalizePhoneForCompare
	);
	const titleHighlight = highlightClass(
		normalizeForCompare( rawData.title ),
		match.title
	);

	// Render given/family name as separate spans (each independently
	// highlightable) when either part is available; fall back to the plain
	// full_name string when neither part is present (no highlighting possible).
	const hasNameParts = Boolean( match.given_name ) || Boolean( match.family_name );

	return (
		<tr className="aorm-matches-table__row">

			<td className="aorm-matches-table__cell aorm-matches-table__cell--name">
				<span className="aorm-matches-table__full-name">
					{ hasNameParts ? (
						<>
							<span className={ `${ NAME_PART_CLASS } ${ firstNameHighlight }`.trim() }>
								{ match.given_name || '' }
							</span>
							{ match.given_name && match.family_name ? ' ' : '' }
							<span className={ `${ NAME_PART_CLASS } ${ lastNameHighlight }`.trim() }>
								{ match.family_name || '' }
							</span>
						</>
					) : (
						match.full_name || '—'
					) }
				</span>
				{ match.uuid && (
					<span className="aorm-matches-table__uuid">
						{ match.uuid }
					</span>
				) }
			</td>

			<td className="aorm-matches-table__cell aorm-matches-table__cell--email">
				{ emailEntries.length === 0 ? (
					'—'
				) : (
					<ul className={ EMAIL_LIST_CLASS }>
						{ emailEntries.map( ( email, index ) => (
							<li
								key={ `${ email.address }-${ index }` }
								className={ `${ EMAIL_ITEM_CLASS } ${ highlightClass( normalizedImportedEmail, email.address ) }`.trim() }
							>
								{ email.address }
								{ email.primary && (
									<span className={ PRIMARY_EMAIL_BADGE_CLASS }>
										{ PRIMARY_EMAIL_LABEL }
									</span>
								) }
							</li>
						) ) }
					</ul>
				) }
			</td>

			<td className="aorm-matches-table__cell aorm-matches-table__cell--location">
				{ location || '—' }
			</td>

			<td className={ `aorm-matches-table__cell aorm-matches-table__cell--phone ${ phoneHighlight }`.trim() }>
				{ displayPhone || '—' }
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
