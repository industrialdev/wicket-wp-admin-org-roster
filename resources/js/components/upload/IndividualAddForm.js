/**
 * Individual add form — AORM-5.1 / AORM-5.2 / AORM-5.7 / AORM-5.8.
 *
 * Renders a form for adding a single member to the roster. The form collects
 * five fields — first_name, last_name, and email are required; phone
 * and title are optional.
 *
 * Client-side validation (AORM-5.2) runs on submit:
 *   - first_name, last_name, email are required.
 *   - first_name, last_name must match NAME_REGEX (letters, spaces, hyphens, apostrophes only).
 *   - email must match EMAIL_REGEX.
 *   - phone, when provided, must match PHONE_REGEX and be a valid number per
 *     libphonenumber (DEFAULT_PHONE_COUNTRY region).
 *
 * On submit (AORM-5.7), POSTs to the individual endpoint, stores the returned
 * session_id via startNewSession(), stores match_category via setMatchCategory(),
 * and surfaces server-side 409 / 422 errors back to the form inline.
 *
 * On success (AORM-5.8), navigates to the validation-review step via goToStep()
 * so the admin can see the newly added record and its match category.
 *
 * @param {{
 *   goToStep:          (step: string) => void,
 *   resetWizard:       () => void,
 *   orgUuid:           string,
 *   membershipUuid:    string,
 *   startNewSession:   (id: string) => void,
 *   setMatchCategory:  (category: string) => void,
 * }} props
 */

import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Notice, Spinner, TextControl } from '@wordpress/components';
import { parsePhoneNumberFromString } from 'libphonenumber-js/max';
import { apiFetch } from '../../utils/apiFetch';
import '../../../css/roster-upload.css';

// ---------------------------------------------------------------------------
// Validation constants — exported so AORM-6 CSV validation can reuse them.
// ---------------------------------------------------------------------------

/**
 * Simple email format regex — same rule applied to CSV rows (AORM-6).
 * Requires at least one non-whitespace/@ character on each side of a single @,
 * and at least one dot in the domain portion.
 *
 * @type {RegExp}
 */
export const EMAIL_REGEX = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

/**
 * Name format regex — same rule applied to CSV rows (AORM-6).
 * Allows letters, spaces, hyphens, and apostrophes only (e.g. "Mary-Jane",
 * "O'Brien"). Rejects digits and other symbols. Letters are restricted to
 * ASCII a-z/A-Z; accented/unicode letters are intentionally out of scope.
 *
 * @type {RegExp}
 */
export const NAME_REGEX = /^[a-zA-Z\s'-]+$/;

/**
 * Phone format regex — same rule applied to CSV rows (AORM-6).
 * Accepts an optional leading +, then any combination of digits, spaces,
 * dashes, dots, and parentheses, with a total length of 7–20 characters
 * (after stripping the optional leading +).
 *
 * Examples accepted:  +1 (800) 555-0100 · 0044 7700 900123 · 555.867.5309
 *
 * @type {RegExp}
 */
export const PHONE_REGEX = /^[+]?[\d\s\-().]{7,20}$/;

/**
 * Default region used to parse numbers entered without a leading "+country
 * code". "CA" resolves a bare 10-digit number against the North American
 * Numbering Plan (+1), which covers both Canadian and US numbers. Numbers
 * entered with a "+" prefix are parsed against their own country code.
 *
 * @type {string}
 */
export const DEFAULT_PHONE_COUNTRY = 'CA';

/**
 * Whether a non-empty phone value is valid.
 *
 * Two checks, both required:
 *   1. Character shape — PHONE_REGEX. Kept because libphonenumber accepts
 *      inline extensions ("613-237-5000 x123"), which the server rejects.
 *   2. Numbering-plan validity via libphonenumber (full "max" metadata), the
 *      same approach MDP uses (it parses each number against a numbering
 *      plan to derive E.164 / national / international formats). This
 *      covers length per country (partial numbers like "613-202-00" fail)
 *      and rejects numbers that can't exist, e.g. an unassigned area code
 *      ("555-867-5309") or an exchange starting with 0/1 ("613-123-4526").
 *
 * No separate digit-count check: libphonenumber already enforces per-country
 * length. The server's ValidationService still requires 10–15 digits, so a
 * valid short international number (e.g. Andorra "+376 312 345", 9 digits)
 * passes here and is rejected by the server with a 422, which the form shows
 * inline under the phone field.
 *
 * @param {string} phone Trimmed phone value.
 * @returns {boolean} True when valid.
 */
export function isValidPhone( phone ) {
	if ( ! PHONE_REGEX.test( phone ) ) {
		return false;
	}

	const parsed = parsePhoneNumberFromString( phone, DEFAULT_PHONE_COUNTRY );

	return Boolean( parsed && parsed.isValid() );
}

// ---------------------------------------------------------------------------
// Pure validation helper — exported for unit testing.
// ---------------------------------------------------------------------------

/**
 * Validate a set of IndividualAddForm field values.
 *
 * Returns a plain object whose keys are field names and whose values are
 * translated error strings. An empty object means the fields are valid.
 *
 * @param {{
 *   first_name:   string,
 *   last_name:    string,
 *   email:        string,
 *   phone: string,
 *   title:        string,
 * }} fields
 * @returns {Record<string, string>} Error map (empty = no errors).
 */
export function validateFields( fields ) {
	const errors = {};

	if ( ! fields.first_name.trim() ) {
		errors.first_name = __( 'First name is required.', 'wicket-aorm' );
	} else if ( ! NAME_REGEX.test( fields.first_name.trim() ) ) {
		errors.first_name = __( 'First name contains invalid characters. Only letters, spaces, hyphens, and apostrophes are allowed.', 'wicket-aorm' );
	}

	if ( ! fields.last_name.trim() ) {
		errors.last_name = __( 'Last name is required.', 'wicket-aorm' );
	} else if ( ! NAME_REGEX.test( fields.last_name.trim() ) ) {
		errors.last_name = __( 'Last name contains invalid characters. Only letters, spaces, hyphens, and apostrophes are allowed.', 'wicket-aorm' );
	}

	if ( ! fields.email.trim() ) {
		errors.email = __( 'Email is required.', 'wicket-aorm' );
	} else if ( ! EMAIL_REGEX.test( fields.email.trim() ) ) {
		errors.email = __( 'Please enter a valid email address.', 'wicket-aorm' );
	}

	if ( fields.phone.trim() && ! isValidPhone( fields.phone.trim() ) ) {
		errors.phone = __( 'Please enter a valid phone number.', 'wicket-aorm' );
	}

	return errors;
}

// ---------------------------------------------------------------------------
// Initial state
// ---------------------------------------------------------------------------

/** Initial/empty field state. */
const EMPTY_FIELDS = {
	first_name:   '',
	last_name:    '',
	email:        '',
	phone: '',
	title:        '',
};

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

export default function IndividualAddForm( {
	goToStep,
	orgUuid,
	membershipUuid,
	startNewSession,
	setMatchCategory,
} ) {
	const [ fields, setFields ]       = useState( { ...EMPTY_FIELDS } );
	const [ errors, setErrors ]       = useState( {} );
	const [ isSubmitting, setIsSubmitting ] = useState( false );
	const [ serverError, setServerError ]   = useState( null );

	/**
	 * Update a single field by name and clear any existing error for it.
	 *
	 * @param {string} name  Field key.
	 * @param {string} value New value.
	 */
	function handleChange( name, value ) {
		setFields( ( prev ) => ( { ...prev, [ name ]: value } ) );

		// Clear the error for this field as soon as the user starts correcting it.
		if ( errors[ name ] ) {
			setErrors( ( prev ) => {
				const next = { ...prev };
				delete next[ name ];

				return next;
			} );
		}
	}

	/**
	 * Run client-side validation, then POST to the individual endpoint (AORM-5.7).
	 *
	 * On success  — stores the session ID and match_category in shared wizard state.
	 * On 422      — merges server-returned field errors into inline error state.
	 * On 409      — shows a session-conflict notice above the form.
	 * On any other error — shows a generic error notice above the form.
	 */
	async function handleSubmit() {
		const validationErrors = validateFields( fields );

		if ( Object.keys( validationErrors ).length > 0 ) {
			setErrors( validationErrors );

			return;
		}

		setErrors( {} );
		setServerError( null );
		setIsSubmitting( true );

		try {
			const response = await apiFetch( {
				path:   `/wicket-aorm/v1/rosters/${ orgUuid }/${ membershipUuid }/individual`,
				method: 'POST',
				data:   fields,
			} );

			if ( typeof startNewSession === 'function' ) {
				startNewSession( response.session_id );
			}

			if ( typeof setMatchCategory === 'function' ) {
				setMatchCategory( response.match_category );
			}

			// AORM-5.8: Navigate to the validation-review step so the admin can
			// see the newly added record and its match category.
			if ( typeof goToStep === 'function' ) {
				goToStep( 'validation-review' );
			}
		} catch ( err ) {
			// 422 — server-side field validation errors: merge into inline errors.
			if ( err?.data?.status === 422 && err?.data?.errors ) {
				setErrors( err.data.errors );
			} else if ( err?.data?.status === 409 ) {
				// 409 — an active session already exists for this roster.
				setServerError(
					err?.message
						?? __( 'An active upload session already exists for this roster. Please resolve it before adding individual records.', 'wicket-aorm' )
				);
			} else {
				// Unexpected error.
				setServerError(
					err?.message ?? __( 'An unexpected error occurred. Please try again.', 'wicket-aorm' )
				);
			}
		} finally {
			setIsSubmitting( false );
		}
	}

	/** Navigate back to the wizard landing screen. */
	function handleBack() {
		if ( typeof goToStep === 'function' ) {
			goToStep( 'landing' );
		}
	}

	/**
	 * Return the CSS class string for a field wrapper, adding the error
	 * modifier when an error exists for that field.
	 *
	 * @param {string} name Field key.
	 * @returns {string}
	 */
	function fieldClass( name ) {
		return errors[ name ]
			? 'aorm-individual-form__field aorm-individual-form__field--error'
			: 'aorm-individual-form__field';
	}

	return (
		<div className="aorm-wizard-step aorm-wizard-step--individual-form">

			{ /* ── Back navigation ── */ }
			<div className="aorm-wizard-step__back">
				<Button
					variant="tertiary"
					onClick={ handleBack }
					disabled={ isSubmitting }
				>
					{ __( '← Back', 'wicket-aorm' ) }
				</Button>
			</div>

			{ /* ── Form heading ── */ }
			<h2 className="aorm-individual-form__heading">
				{ __( 'Add Individual Member', 'wicket-aorm' ) }
			</h2>

			<p className="aorm-individual-form__description">
				{ __(
					'Enter the details of the member you would like to add to the roster.',
					'wicket-aorm'
				) }
			</p>

			{ /* ── Server error notice ── */ }
			{ serverError && (
				<Notice
					status="error"
					isDismissible={ true }
					onRemove={ () => setServerError( null ) }
					className="aorm-individual-form__server-error"
				>
					{ serverError }
				</Notice>
			) }

			{ /* ── Fields ── */ }
			<div className="aorm-individual-form__fields">

				<TextControl
					className={ fieldClass( 'first_name' ) }
					// translators: * denotes a required field.
					label={ __( 'First Name *', 'wicket-aorm' ) }
					value={ fields.first_name }
					onChange={ ( value ) => handleChange( 'first_name', value ) }
					autoComplete="given-name"
					disabled={ isSubmitting }
					aria-describedby={ errors.first_name ? 'aorm-error-first_name' : undefined }
					help={
						errors.first_name
							? <span id="aorm-error-first_name" className="aorm-individual-form__error" role="alert">{ errors.first_name }</span>
							: undefined
					}
				/>

				<TextControl
					className={ fieldClass( 'last_name' ) }
					// translators: * denotes a required field.
					label={ __( 'Last Name *', 'wicket-aorm' ) }
					value={ fields.last_name }
					onChange={ ( value ) => handleChange( 'last_name', value ) }
					autoComplete="family-name"
					disabled={ isSubmitting }
					aria-describedby={ errors.last_name ? 'aorm-error-last_name' : undefined }
					help={
						errors.last_name
							? <span id="aorm-error-last_name" className="aorm-individual-form__error" role="alert">{ errors.last_name }</span>
							: undefined
					}
				/>

				<TextControl
					className={ fieldClass( 'email' ) }
					// translators: * denotes a required field.
					label={ __( 'Email *', 'wicket-aorm' ) }
					type="email"
					value={ fields.email }
					onChange={ ( value ) => handleChange( 'email', value ) }
					autoComplete="email"
					disabled={ isSubmitting }
					aria-describedby={ errors.email ? 'aorm-error-email' : undefined }
					help={
						errors.email
							? <span id="aorm-error-email" className="aorm-individual-form__error" role="alert">{ errors.email }</span>
							: undefined
					}
				/>

				<TextControl
					className={ fieldClass( 'phone' ) }
					label={ __( 'Mobile Phone', 'wicket-aorm' ) }
					type="tel"
					value={ fields.phone }
					onChange={ ( value ) => handleChange( 'phone', value ) }
					autoComplete="tel"
					disabled={ isSubmitting }
					aria-describedby={ errors.phone ? 'aorm-error-phone' : undefined }
					help={
						errors.phone
							? <span id="aorm-error-phone" className="aorm-individual-form__error" role="alert">{ errors.phone }</span>
							: undefined
					}
				/>

				<TextControl
					className="aorm-individual-form__field"
					label={ __( 'Title', 'wicket-aorm' ) }
					value={ fields.title }
					onChange={ ( value ) => handleChange( 'title', value ) }
					disabled={ isSubmitting }
				/>

			</div>

			{ /* ── Actions ── */ }
			<div className="aorm-individual-form__actions">
				<Button
					variant="primary"
					onClick={ handleSubmit }
					disabled={ isSubmitting }
					aria-disabled={ isSubmitting }
				>
					{ isSubmitting
						? <>
							<Spinner />
							{ __( 'Adding…', 'wicket-aorm' ) }
						</>
						: __( 'Add Member', 'wicket-aorm' )
					}
				</Button>
			</div>

		</div>
	);
}
