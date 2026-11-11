/**
 * Translation setup of a field group on multilingual sites (WPML, Polylang):
 * the group's mode, and each field's preference in the expert mode.
 *
 * Stored like ACFML stores it, so groups move between the plugins unchanged:
 * group `acfml_field_group_mode`, field `wpml_cf_preferences`.
 */
import { __ } from '@wordpress/i18n';
import Select from './Select';

export const IGNORE = 0;
export const COPY = 1;
export const TRANSLATE = 2;
export const COPY_ONCE = 3;

export const MODES = [ 'translation', 'localization', 'advanced' ];

/**
 * Multilingual settings from the server, or null when no multilingual plugin is active.
 *
 * @return {Object|null} Settings.
 */
export function multilingual() {
	return window.wpffBuilder?.multilingual || null;
}

/**
 * Whether any field (at any depth) has a stored preference.
 *
 * @param {Array} fields Fields.
 * @return {boolean} Found.
 */
function hasPreferences( fields = [] ) {
	return fields.some(
		( field ) =>
			( undefined !== field.wpml_cf_preferences &&
				'' !== field.wpml_cf_preferences &&
				null !== field.wpml_cf_preferences ) ||
			hasPreferences( field.sub_fields ) ||
			( field.layouts || [] ).some( ( layout ) =>
				hasPreferences( layout.sub_fields )
			)
	);
}

/**
 * Mode of a group, as the server decides it.
 *
 * @param {Object} group Group.
 * @return {string} Mode.
 */
export function modeOf( group ) {
	if ( MODES.includes( group.acfml_field_group_mode ) ) {
		return group.acfml_field_group_mode;
	}
	// Same default as the server: nothing is kept in sync until an option is chosen.
	return hasPreferences( group.fields ) ? 'advanced' : 'localization';
}

/**
 * Preference a field gets from its type in a mode.
 *
 * @param {Object} field Field.
 * @param {string} mode  Group mode.
 * @return {number} Preference.
 */
export function defaultPreference( field, mode ) {
	const textTypes = multilingual()?.textTypes || [
		'text',
		'textarea',
		'wysiwyg',
	];
	if ( textTypes.includes( field.type ) ) {
		return TRANSLATE;
	}
	return 'localization' === mode ? COPY_ONCE : COPY;
}

/**
 * Preference that applies to a field.
 *
 * @param {Object} field Field.
 * @param {string} mode  Group mode.
 * @return {number} Preference.
 */
export function preferenceOf( field, mode ) {
	const stored = Number( field.wpml_cf_preferences );
	if (
		'advanced' === mode &&
		'' !== field.wpml_cf_preferences &&
		null !== field.wpml_cf_preferences &&
		[ IGNORE, COPY, TRANSLATE, COPY_ONCE ].includes( stored )
	) {
		return stored;
	}
	return defaultPreference( field, mode );
}

/**
 * Preference labels.
 *
 * @return {Object} Preference => label.
 */
export function preferenceLabels() {
	return {
		[ IGNORE ]: __( 'Don’t translate', 'freeform-fields' ),
		[ COPY ]: __( 'Copy', 'freeform-fields' ),
		[ COPY_ONCE ]: __( 'Copy once', 'freeform-fields' ),
		[ TRANSLATE ]: __( 'Translate', 'freeform-fields' ),
	};
}

/**
 * Mode labels for lists.
 *
 * @return {Object} Mode => label.
 */
export function modeLabels() {
	return {
		translation: __( 'Same fields across languages', 'freeform-fields' ),
		localization: __(
			'Different fields across languages',
			'freeform-fields'
		),
		advanced: __( 'Expert', 'freeform-fields' ),
	};
}

/**
 * Sets every field's stored preference (any depth) to what it gets in `mode`, keeping those already set.
 *
 * @param {Array}  fields Fields.
 * @param {string} mode   Mode whose defaults fill missing preferences.
 * @return {Array} Fields.
 */
function fillPreferences( fields = [], mode ) {
	return fields.map( ( field ) => {
		const next = {
			...field,
			wpml_cf_preferences: preferenceOf( field, mode ),
		};
		if ( Array.isArray( field.sub_fields ) ) {
			next.sub_fields = fillPreferences( field.sub_fields, mode );
		}
		if ( Array.isArray( field.layouts ) ) {
			next.layouts = field.layouts.map( ( layout ) => ( {
				...layout,
				sub_fields: fillPreferences( layout.sub_fields, mode ),
			} ) );
		}
		return next;
	} );
}

function Pages( { variant } ) {
	const colors =
		'different' === variant
			? [ '#e8f3ef', '#fdf2e3', '#fbe9e7' ]
			: [ '#e8f3ef', '#e8f3ef', '#e8f3ef' ];
	const ink =
		'different' === variant
			? [ '#2e8b6e', '#d58b25', '#c94a3c' ]
			: [ '#2e8b6e', '#2e8b6e', '#2e8b6e' ];
	return (
		<svg viewBox="0 0 150 72" aria-hidden="true" focusable="false">
			{ [ 0, 1, 2 ].map( ( i ) => {
				const x = 4 + i * 50;
				const lines = 'different' === variant ? [ 3, 4, 2 ][ i ] : 3;
				return (
					<g key={ i }>
						<rect
							x={ x }
							y="4"
							width="42"
							height="64"
							rx="4"
							fill={ colors[ i ] }
						/>
						<rect
							x={ x + 6 }
							y="10"
							width="30"
							height="18"
							rx="2"
							fill={ ink[ i ] }
							opacity="0.35"
						/>
						{ Array.from( { length: lines } ).map( ( _, l ) => (
							<rect
								key={ l }
								x={ x + 6 }
								y={ 34 + l * 8 }
								width={ 0 === l ? 18 : 30 - l * 2 }
								height="3"
								rx="1.5"
								fill={ ink[ i ] }
								opacity={ 0 === l ? 0.9 : 0.5 }
							/>
						) ) }
					</g>
				);
			} ) }
		</svg>
	);
}

function Gear() {
	return (
		<svg viewBox="0 0 150 72" aria-hidden="true" focusable="false">
			<rect x="30" y="4" width="90" height="64" rx="4" fill="#eef1f4" />
			{ [ 22, 36, 50 ].map( ( y, i ) => (
				<g key={ y }>
					<rect
						x="46"
						y={ y }
						width="58"
						height="3"
						rx="1.5"
						fill="#8a96a3"
					/>
					<circle
						cx={ [ 60, 88, 70 ][ i ] }
						cy={ y + 1.5 }
						r="4.5"
						fill="#fff"
						stroke="#5b6b7c"
						strokeWidth="2"
					/>
				</g>
			) ) }
		</svg>
	);
}

/**
 * Panel with the three translation modes.
 *
 * @param {Object}   props       Props.
 * @param {Object}   props.group Group.
 * @param {Function} props.set   Receives a patch for the group.
 */
export function MultilingualSetup( { group, set } ) {
	const mode = modeOf( group );
	const chosen = MODES.includes( group.acfml_field_group_mode );
	const choose = ( next ) => {
		const patch = { acfml_field_group_mode: next };
		// Entering the expert mode keeps what each field did so far.
		if ( 'advanced' === next && 'advanced' !== mode ) {
			patch.fields = fillPreferences( group.fields, mode );
		}
		set( patch );
	};

	const options = [
		{
			value: 'translation',
			art: <Pages variant="same" />,
			title: __( 'Same fields across languages', 'freeform-fields' ),
			text: [
				__(
					'Every language has the same fields in the same order. Only the content is translated.',
					'freeform-fields'
				),
				__(
					'Text fields are translated; images, choices and links to other content are copied to translations and kept in sync. Works with automatic translation and translation editors.',
					'freeform-fields'
				),
			],
		},
		{
			value: 'localization',
			art: <Pages variant="different" />,
			title: __( 'Different fields across languages', 'freeform-fields' ),
			text: [
				__(
					'Each language can have its own design: different rows, layouts and number of items.',
					'freeform-fields'
				),
				__(
					'A new translation starts as a copy of the original; after that each language is edited on its own in the WordPress editor.',
					'freeform-fields'
				),
			],
		},
		{
			value: 'advanced',
			art: <Gear />,
			title: __( 'Expert', 'freeform-fields' ),
			text: [
				__(
					'Choose for each field whether it is translated, copied, copied once or left alone.',
					'freeform-fields'
				),
				__(
					'Groups migrated from older setups use this mode. Not recommended for new groups.',
					'freeform-fields'
				),
			],
		},
	];

	return (
		<section className="wpff-panel wpff-multilingual">
			<div className="wpff-panel__header">
				<h2>{ __( 'Multilingual setup', 'freeform-fields' ) }</h2>
			</div>
			<div className="wpff-panel__body">
				<p className="wpff-multilingual__lead">
					{ __(
						'Select a translation option for this field group',
						'freeform-fields'
					) }
				</p>
				<div
					className="wpff-multilingual__options"
					role="radiogroup"
					aria-label={ __( 'Translation option', 'freeform-fields' ) }
				>
					{ options.map( ( option ) => {
						const active = option.value === mode;
						return (
							<label
								key={ option.value }
								htmlFor={ `wpff-translation-mode-${ option.value }` }
								className={ `wpff-multilingual__option${
									active ? ' is-active' : ''
								}` }
							>
								<span className="wpff-multilingual__choose">
									{ __( 'Choose', 'freeform-fields' ) }
									<input
										id={ `wpff-translation-mode-${ option.value }` }
										type="radio"
										name="wpff-translation-mode"
										value={ option.value }
										checked={ active }
										onChange={ () =>
											choose( option.value )
										}
									/>
								</span>
								<span className="wpff-multilingual__art">
									{ option.art }
								</span>
								<strong>{ option.title }</strong>
								{ option.text.map( ( line ) => (
									<span
										key={ line }
										className="wpff-multilingual__text"
									>
										{ line }
									</span>
								) ) }
							</label>
						);
					} ) }
				</div>
				{ ! chosen && (
					<p className="wpff-multilingual__hint">
						{ 'advanced' === mode
							? __(
									'No option chosen yet: the preferences set on each field apply.',
									'freeform-fields'
								)
							: __(
									'No option chosen yet: “Different fields across languages” applies, so nothing is kept in sync between translations.',
									'freeform-fields'
								) }
					</p>
				) }
			</div>
		</section>
	);
}

/**
 * Translation preference of one field (field settings).
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.field    Field.
 * @param {string}   props.mode     Group mode.
 * @param {Function} props.onChange Receives the new preference.
 */
export function FieldTranslation( { field, mode, onChange } ) {
	const labels = preferenceLabels();
	const current = preferenceOf( field, mode );
	if ( 'advanced' !== mode ) {
		return (
			<p className="wpff-field-translation">
				<span className="components-base-control__label">
					{ __( 'Translation', 'freeform-fields' ) }
				</span>
				<strong>{ labels[ current ] }</strong>
				<span className="components-base-control__help">
					{ __(
						'Set by the group’s translation option. Choose “Expert” in the group’s Multilingual setup to change it per field.',
						'freeform-fields'
					) }
				</span>
			</p>
		);
	}
	const help = {
		[ IGNORE ]: __(
			'Translations keep their own value; nothing is copied.',
			'freeform-fields'
		),
		[ COPY ]: __(
			'The original’s value is copied to every translation and kept in sync.',
			'freeform-fields'
		),
		[ COPY_ONCE ]: __(
			'Copied when a translation is created; after that each language keeps its own value.',
			'freeform-fields'
		),
		[ TRANSLATE ]: __(
			'Sent to translators and translated for each language.',
			'freeform-fields'
		),
	};
	return (
		<Select
			label={ __( 'Translation', 'freeform-fields' ) }
			value={ String( current ) }
			options={ [ TRANSLATE, COPY, COPY_ONCE, IGNORE ].map( ( p ) => ( {
				value: String( p ),
				label: labels[ p ],
			} ) ) }
			onChange={ ( value ) => onChange( Number( value ) ) }
			help={ help[ current ] }
			__next40pxDefaultSize
			__nextHasNoMarginBottom
		/>
	);
}
