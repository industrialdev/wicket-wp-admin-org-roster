/**
 * ActivityFilters — AORM-4.19.
 *
 * Filter bar for the Roster Activity tab.  Provides four controls that let
 * admins narrow the activity log:
 *
 *   - Activity Type (action slug) — server-side filter via `action` param
 *   - Status (log level)          — server-side filter via `level` param
 *   - Date From / Date To         — server-side filters via `date_from` / `date_to`
 *   - Actor                       — client-side substring filter on the actor field
 *
 * The component maintains its own *draft* state for the form fields.  When the
 * admin clicks "Apply Filters" the current draft is forwarded to `onApply`.
 * When they click "Reset" the draft is cleared and `onReset` is called.
 *
 * @param {{
 *   initialFilters?: ActivityFilterValues,
 *   onApply:  (filters: ActivityFilterValues) => void,
 *   onReset:  () => void,
 * }} props
 */

import { useState } from '@wordpress/element';
import { SelectControl, TextControl, Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

// ---------------------------------------------------------------------------
// Constants (exported for test access and consumer reuse)
// ---------------------------------------------------------------------------

/**
 * @typedef {{
 *   action:    string,
 *   level:     string,
 *   date_from: string,
 *   date_to:   string,
 *   actor:     string,
 * }} ActivityFilterValues
 */

/** Empty / cleared filter state. */
export const EMPTY_FILTERS = {
	action:    '',
	level:     '',
	date_from: '',
	date_to:   '',
	actor:     '',
};

/** Options for the Activity Type select. */
export const ACTIVITY_TYPE_OPTIONS = [
	{ value: '', label: __( 'All Activity Types', 'wicket-aorm' ) },
	{ value: 'members_removed', label: __( 'Members Removed', 'wicket-aorm' ) },
	{ value: 'roles_added',     label: __( 'Roles Added',     'wicket-aorm' ) },
	{ value: 'roles_removed',   label: __( 'Roles Removed',   'wicket-aorm' ) },
];

/** Options for the Status (log level) select. */
export const STATUS_OPTIONS = [
	{ value: '',        label: __( 'All Statuses', 'wicket-aorm' ) },
	{ value: 'info',    label: __( 'OK',           'wicket-aorm' ) },
	{ value: 'warning', label: __( 'Warning',      'wicket-aorm' ) },
	{ value: 'error',   label: __( 'Error',        'wicket-aorm' ) },
	{ value: 'debug',   label: __( 'Debug',        'wicket-aorm' ) },
];

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

export default function ActivityFilters( {
	initialFilters,
	onApply,
	onReset,
} ) {
	const [ draft, setDraft ] = useState( {
		...EMPTY_FILTERS,
		...( initialFilters ?? {} ),
	} );

	/** Update a single field in the draft. */
	function setField( key, value ) {
		setDraft( ( prev ) => ( { ...prev, [ key ]: value } ) );
	}

	function handleApply() {
		if ( onApply ) {
			onApply( { ...draft } );
		}
	}

	function handleReset() {
		setDraft( { ...EMPTY_FILTERS } );
		if ( onReset ) {
			onReset();
		}
	}

	return (
		<div className="aorm-activity-filters">
			<div className="aorm-activity-filters__fields">

				{ /* ── Activity Type ─────────────────────────────────────────── */ }
				<div className="aorm-activity-filters__field">
					<SelectControl
						label={ __( 'Activity Type', 'wicket-aorm' ) }
						value={ draft.action }
						options={ ACTIVITY_TYPE_OPTIONS }
						onChange={ ( val ) => setField( 'action', val ) }
						__nextHasNoMarginBottom
					/>
				</div>

				{ /* ── Status ──────────────────────────────────────────────────── */ }
				<div className="aorm-activity-filters__field">
					<SelectControl
						label={ __( 'Status', 'wicket-aorm' ) }
						value={ draft.level }
						options={ STATUS_OPTIONS }
						onChange={ ( val ) => setField( 'level', val ) }
						__nextHasNoMarginBottom
					/>
				</div>

				{ /* ── Date From ───────────────────────────────────────────────── */ }
				<div className="aorm-activity-filters__field">
					<label
						htmlFor="aorm-activity-date-from"
						className="aorm-activity-filters__label"
					>
						{ __( 'Date From', 'wicket-aorm' ) }
					</label>
					<input
						id="aorm-activity-date-from"
						type="date"
						className="aorm-activity-filters__date-input components-text-control__input"
						value={ draft.date_from }
						onChange={ ( e ) => setField( 'date_from', e.target.value ) }
					/>
				</div>

				{ /* ── Date To ─────────────────────────────────────────────────── */ }
				<div className="aorm-activity-filters__field">
					<label
						htmlFor="aorm-activity-date-to"
						className="aorm-activity-filters__label"
					>
						{ __( 'Date To', 'wicket-aorm' ) }
					</label>
					<input
						id="aorm-activity-date-to"
						type="date"
						className="aorm-activity-filters__date-input components-text-control__input"
						value={ draft.date_to }
						onChange={ ( e ) => setField( 'date_to', e.target.value ) }
					/>
				</div>

				{ /* ── Actor (client-side) ─────────────────────────────────────── */ }
				<div className="aorm-activity-filters__field">
					<TextControl
						label={ __( 'Actor', 'wicket-aorm' ) }
						value={ draft.actor }
						placeholder={ __( 'Email or user ID', 'wicket-aorm' ) }
						onChange={ ( val ) => setField( 'actor', val ) }
						__nextHasNoMarginBottom
					/>
				</div>

			</div>

			{ /* ── Actions ────────────────────────────────────────────────────── */ }
			<div className="aorm-activity-filters__actions">
				<Button
					variant="secondary"
					onClick={ handleApply }
				>
					{ __( 'Apply Filters', 'wicket-aorm' ) }
				</Button>
				<Button
					variant="tertiary"
					onClick={ handleReset }
				>
					{ __( 'Reset', 'wicket-aorm' ) }
				</Button>
			</div>
		</div>
	);
}
