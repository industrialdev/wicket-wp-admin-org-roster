/**
 * Individual add form — AORM-5.1 / AORM-5.2.
 *
 * Renders a form for adding a single member to the roster. The form collects
 * five fields — first_name, last_name, and email are required; mobile_phone
 * and title are optional.
 *
 * Client-side validation (AORM-5.2) runs on submit:
 *   - first_name, last_name, email are required.
 *   - email must match EMAIL_REGEX.
 *   - mobile_phone, when provided, must match PHONE_REGEX.
 *
 * Only calls `onSubmit( fields )` when all validation passes, so downstream
 * REST submission steps (AORM-5.3 – 5.10) can be wired in without changes.
 *
 * @param {{
 *   goToStep:         (step: string) => void,
 *   resetWizard:      () => void,
 *   orgUuid:          string,
 *   membershipUuid:   string,
 *   startNewSession:  (id: string) => void,
 *   onSubmit:         (fields: {
 *     first_name:    string,
 *     last_name:     string,
 *     email:         string,
 *     mobile_phone:  string,
 *     title:         string,
 *   }) => void,
 * }} props
 */

import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, TextControl } from '@wordpress/components';
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
 *   mobile_phone: string,
 *   title:        string,
 * }} fields
 * @returns {Record<string, string>} Error map (empty = no errors).
 */
export function validateFields( fields ) {
	const errors = {};

	if ( ! fields.first_name.trim() ) {
		errors.first_name = __( 'First name is required.', 'wicket-aorm' );
	}

	if ( ! fields.last_name.trim() ) {
		errors.last_name = __( 'Last name is required.', 'wicket-aorm' );
	}

	if ( ! fields.email.trim() ) {
		errors.email = __( 'Email is required.', 'wicket-aorm' );
	} else if ( ! EMAIL_REGEX.test( fields.email.trim() ) ) {
		errors.email = __( 'Please enter a valid email address.', 'wicket-aorm' );
	}

	if ( fields.mobile_phone.trim() && ! PHONE_REGEX.test( fields.mobile_phone.trim() ) ) {
		errors.mobile_phone = __( 'Please enter a valid phone number.', 'wicket-aorm' );
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
	mobile_phone: '',
	title:        '',
};

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

export default function IndividualAddForm( {
	goToStep,
	onSubmit,
} ) {
	const [ fields, setFields ] = useState( { ...EMPTY_FIELDS } );
	const [ errors, setErrors ] = useState( {} );

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
	 * Validate then forward field values to the parent via onSubmit.
	 * Sets inline errors and aborts if validation fails.
	 */
	function handleSubmit() {
		const validationErrors = validateFields( fields );

		if ( Object.keys( validationErrors ).length > 0 ) {
			setErrors( validationErrors );

			return;
		}

		setErrors( {} );

		if ( typeof onSubmit === 'function' ) {
			onSubmit( { ...fields } );
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

			{ /* ── Fields ── */ }
			<div className="aorm-individual-form__fields">

				<TextControl
					className={ fieldClass( 'first_name' ) }
					// translators: * denotes a required field.
					label={ __( 'First Name *', 'wicket-aorm' ) }
					value={ fields.first_name }
					onChange={ ( value ) => handleChange( 'first_name', value ) }
					autoComplete="given-name"
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
					aria-describedby={ errors.email ? 'aorm-error-email' : undefined }
					help={
						errors.email
							? <span id="aorm-error-email" className="aorm-individual-form__error" role="alert">{ errors.email }</span>
							: undefined
					}
				/>

				<TextControl
					className={ fieldClass( 'mobile_phone' ) }
					label={ __( 'Mobile Phone', 'wicket-aorm' ) }
					type="tel"
					value={ fields.mobile_phone }
					onChange={ ( value ) => handleChange( 'mobile_phone', value ) }
					autoComplete="tel"
					aria-describedby={ errors.mobile_phone ? 'aorm-error-mobile_phone' : undefined }
					help={
						errors.mobile_phone
							? <span id="aorm-error-mobile_phone" className="aorm-individual-form__error" role="alert">{ errors.mobile_phone }</span>
							: undefined
					}
				/>

				<TextControl
					className="aorm-individual-form__field"
					label={ __( 'Title', 'wicket-aorm' ) }
					value={ fields.title }
					onChange={ ( value ) => handleChange( 'title', value ) }
				/>

			</div>

			{ /* ── Actions ── */ }
			<div className="aorm-individual-form__actions">
				<Button
					variant="primary"
					onClick={ handleSubmit }
				>
					{ __( 'Add Member', 'wicket-aorm' ) }
				</Button>
			</div>

		</div>
	);
}
