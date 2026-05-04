/**
 * Individual add form — AORM-5.1.
 *
 * Renders a form for adding a single member to the roster. The form collects
 * five fields — first_name, last_name, and email are required; mobile_phone
 * and title are optional.
 *
 * This component is intentionally unaware of validation logic (AORM-5.2) and
 * REST submission (AORM-5.3 – 5.10). It simply calls `onSubmit( fields )`
 * when the admin clicks "Add Member", so downstream steps can wire in those
 * concerns without touching this component.
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

/** Initial/empty field state. */
const EMPTY_FIELDS = {
	first_name:   '',
	last_name:    '',
	email:        '',
	mobile_phone: '',
	title:        '',
};

export default function IndividualAddForm( {
	goToStep,
	onSubmit,
} ) {
	const [ fields, setFields ] = useState( { ...EMPTY_FIELDS } );

	/**
	 * Update a single field by name.
	 *
	 * @param {string} name  Field key.
	 * @param {string} value New value.
	 */
	function handleChange( name, value ) {
		setFields( ( prev ) => ( { ...prev, [ name ]: value } ) );
	}

	/** Forward field values to the parent via onSubmit. */
	function handleSubmit() {
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
					className="aorm-individual-form__field"
					// translators: * denotes a required field.
					label={ __( 'First Name *', 'wicket-aorm' ) }
					value={ fields.first_name }
					onChange={ ( value ) => handleChange( 'first_name', value ) }
					autoComplete="given-name"
				/>

				<TextControl
					className="aorm-individual-form__field"
					// translators: * denotes a required field.
					label={ __( 'Last Name *', 'wicket-aorm' ) }
					value={ fields.last_name }
					onChange={ ( value ) => handleChange( 'last_name', value ) }
					autoComplete="family-name"
				/>

				<TextControl
					className="aorm-individual-form__field"
					// translators: * denotes a required field.
					label={ __( 'Email *', 'wicket-aorm' ) }
					type="email"
					value={ fields.email }
					onChange={ ( value ) => handleChange( 'email', value ) }
					autoComplete="email"
				/>

				<TextControl
					className="aorm-individual-form__field"
					label={ __( 'Mobile Phone', 'wicket-aorm' ) }
					type="tel"
					value={ fields.mobile_phone }
					onChange={ ( value ) => handleChange( 'mobile_phone', value ) }
					autoComplete="tel"
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
