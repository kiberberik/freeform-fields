/**
 * Settings form for one field.
 */
import { __, sprintf } from '@wordpress/i18n';
import {
	CheckboxControl,
	PanelBody,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { ChoiceField, NumberInput } from './fieldsShared';
import ConditionalLogic from './ConditionalLogic';
import LayoutsEditor from './LayoutsEditor';
import {
	changeType,
	choicesToText,
	cleanName,
	nameFromLabel,
	typeOf,
} from './utils';
import Select from './Select';
import { TypeIcon } from './ui';

/**
 * @param {Object}   props
 * @param {Object}   props.field        Field.
 * @param {string}   props.path         Error path of the field, e.g. 'fields.2'.
 * @param {Object}   props.errors       All errors by full path.
 * @param {Array}    props.siblings     Fields in the same list (for conditional logic).
 * @param {Function} props.onChange     Receives the new field.
 * @param {Function} props.SubFieldList FieldList component, passed in to avoid a circular import.
 */
export default function FieldSettings( {
	field,
	path,
	errors,
	siblings,
	onChange,
	SubFieldList,
} ) {
	const types = window.wpffBuilder.fieldTypes;
	const type = typeOf( field.type );
	const own = ( prop ) => errors[ `${ path }.${ prop }` ];
	const hasValue = ! type || type.hasValue;

	const set = ( patch ) => onChange( { ...field, ...patch } );
	const labelRef = useRef();

	// A field just added from the palette starts with its label focused.
	useEffect( () => {
		if ( field._isNew && ! field.label ) {
			labelRef.current?.focus();
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps -- once per field.
	}, [ field.key ] );

	const onLabel = ( label ) => {
		const patch = { label };
		// The name follows the label until the user edits it, only for fields that were never saved.
		if ( hasValue && field._isNew && ! field._nameTouched ) {
			patch.name = nameFromLabel( label );
		}
		set( patch );
	};

	const typeSettings = type
		? type.settings.filter( ( setting ) => isVisible( setting, field ) )
		: [];

	return (
		<div className="wpff-field-settings">
			<PanelBody title={ __( 'General', 'freeform-fields' ) }>
				<div className="wpff-field-settings__grid">
					<TextControl
						ref={ labelRef }
						label={ __( 'Field Label', 'freeform-fields' ) }
						value={ field.label }
						onChange={ onLabel }
						help={
							own( 'label' ) ||
							( hasValue
								? __(
										'Shown on the edit screen.',
										'freeform-fields'
								  )
								: __(
										'Shown as the tab, section or message title.',
										'freeform-fields'
								  ) )
						}
						className={ own( 'label' ) ? 'has-error' : '' }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
					{ hasValue && (
						<TextControl
							label={ __( 'Field Name', 'freeform-fields' ) }
							value={ field.name }
							onChange={ ( name ) =>
								set( {
									name: cleanName( name ),
									_nameTouched: true,
								} )
							}
							help={
								own( 'name' ) ||
								( field._isNew
									? __(
											"Used in code: get_field( 'name' ). Letters a-z, digits, _ and -.",
											'freeform-fields'
									  )
									: __(
											'Changing the name of a saved field hides values already stored under the old name.',
											'freeform-fields'
									  ) )
							}
							className={ `wpff-mono${
								own( 'name' ) ? ' has-error' : ''
							}` }
							__next40pxDefaultSize
							__nextHasNoMarginBottom
						/>
					) }
					<Select
						label={ __( 'Field Type', 'freeform-fields' ) }
						value={ field.type }
						options={ types.map( ( t ) => ( {
							value: t.name,
							label: t.label,
							group: t.category,
							icon: <TypeIcon type={ t.name } size={ 24 } />,
						} ) ) }
						onChange={ ( name ) =>
							onChange( changeType( field, typeOf( name ) ) )
						}
						help={ own( 'type' ) }
					/>
				</div>
			</PanelBody>

			{ typeSettings.length > 0 && (
				<PanelBody
					title={ sprintf(
						/* translators: %s: field type label. */
						__( '%s settings', 'freeform-fields' ),
						type.label
					) }
				>
					<div className="wpff-field-settings__grid">
						{ typeSettings.map( ( setting ) => (
							<SettingControl
								key={ setting.name }
								field={ field }
								setting={ setting }
								value={ field[ setting.name ] }
								onChange={ ( value ) =>
									set( { [ setting.name ]: value } )
								}
							/>
						) ) }
					</div>
				</PanelBody>
			) }

			{ type && 'layouts' === type.compound && (
				<PanelBody title={ __( 'Layouts', 'freeform-fields' ) }>
					<LayoutsEditor
						layouts={ field.layouts || [] }
						path={ path }
						errors={ errors }
						onChange={ ( layouts ) => set( { layouts } ) }
						SubFieldList={ SubFieldList }
					/>
				</PanelBody>
			) }

			{ SubFieldList && type && type.hasSubs && (
				<div className="wpff-sub-fields">
					<h4>{ __( 'Sub Fields', 'freeform-fields' ) }</h4>
					<SubFieldList
						fields={ field.sub_fields || [] }
						errors={ errors }
						path={ `${ path }.sub_fields` }
						onChange={ ( subFields ) =>
							set( { sub_fields: subFields } )
						}
					/>
				</div>
			) }

			{ hasValue && (
				<PanelBody
					title={ __(
						'Validation & presentation',
						'freeform-fields'
					) }
					initialOpen={ false }
				>
					<div className="wpff-field-settings__grid">
						<TextareaControl
							label={ __( 'Instructions', 'freeform-fields' ) }
							value={ field.instructions }
							onChange={ ( instructions ) =>
								set( { instructions } )
							}
							help={ __(
								'Shown to editors under the label.',
								'freeform-fields'
							) }
							rows={ 2 }
							__nextHasNoMarginBottom
						/>
						{ ! type?.hasSubs && (
							<ToggleControl
								label={ __( 'Required', 'freeform-fields' ) }
								checked={ !! Number( field.required ) }
								onChange={ ( on ) =>
									set( { required: on ? 1 : 0 } )
								}
								__nextHasNoMarginBottom
							/>
						) }
						{ ! type?.hasSubs && (
							<ToggleControl
								label={ __(
									'Allow in Block Bindings',
									'freeform-fields'
								) }
								help={ __(
									'Lets editors show this value in core blocks (paragraph, heading, image, button) on the site.',
									'freeform-fields'
								) }
								checked={ !! Number( field.allow_in_bindings ) }
								onChange={ ( on ) =>
									set( { allow_in_bindings: on ? 1 : 0 } )
								}
								__nextHasNoMarginBottom
							/>
						) }
						<NumberInput
							label={ __( 'Width (%)', 'freeform-fields' ) }
							value={ field.wrapper.width }
							min={ 0 }
							max={ 100 }
							onChange={ ( width ) =>
								set( {
									wrapper: {
										...field.wrapper,
										width: width || '',
									},
								} )
							}
							__next40pxDefaultSize
						/>
						<TextControl
							label={ __(
								'Wrapper CSS class',
								'freeform-fields'
							) }
							value={ field.wrapper.class }
							onChange={ ( cls ) =>
								set( {
									wrapper: { ...field.wrapper, class: cls },
								} )
							}
							__next40pxDefaultSize
							__nextHasNoMarginBottom
						/>
						<TextControl
							label={ __( 'Wrapper ID', 'freeform-fields' ) }
							value={ field.wrapper.id }
							onChange={ ( id ) =>
								set( { wrapper: { ...field.wrapper, id } } )
							}
							__next40pxDefaultSize
							__nextHasNoMarginBottom
						/>
					</div>
				</PanelBody>
			) }

			<PanelBody
				title={ __( 'Conditional logic', 'freeform-fields' ) }
				initialOpen={ Array.isArray( field.conditional_logic ) }
			>
				<ConditionalLogic
					field={ field }
					siblings={ siblings }
					onChange={ ( conditionalLogic ) =>
						set( { conditional_logic: conditionalLogic } )
					}
				/>
			</PanelBody>
			<p className="wpff-field-settings__key">
				{ __( 'Field key:', 'freeform-fields' ) }{ ' ' }
				<code>{ field.key }</code>
			</p>
		</div>
	);
}

function isVisible( setting, field ) {
	if ( ! setting.show_if ) {
		return true;
	}
	return Object.entries( setting.show_if ).every( ( [ name, expected ] ) => {
		const actual = field[ name ];
		return (
			Number( actual || 0 ) === Number( expected ) || actual === expected
		);
	} );
}

function SettingControl( { setting, value, onChange, field } ) {
	const common = {
		label: setting.label,
		help: setting.help,
		__nextHasNoMarginBottom: true,
	};

	switch ( setting.control ) {
		case 'toggle':
			return (
				<ToggleControl
					{ ...common }
					checked={ !! Number( value ) }
					onChange={ ( on ) => onChange( on ? 1 : 0 ) }
				/>
			);
		case 'number':
			return (
				<NumberInput
					label={ setting.label }
					help={ setting.help }
					value={ value ?? '' }
					onChange={ ( v ) => onChange( v ?? '' ) }
					__next40pxDefaultSize
				/>
			);
		case 'select':
			return (
				<Select
					{ ...common }
					value={ value ?? '' }
					options={ Object.entries( setting.options || {} ).map(
						( [ v, label ] ) => ( { value: v, label } )
					) }
					onChange={ onChange }
					__next40pxDefaultSize
				/>
			);
		case 'sub_field':
			return (
				<Select
					{ ...common }
					value={ value ?? '' }
					options={ [
						{
							value: '',
							label: __( '— None —', 'freeform-fields' ),
						},
						...( field.sub_fields || [] )
							.filter( ( f ) => f.name )
							.map( ( f ) => ( {
								value: f.key,
								label: f.label || f.name,
							} ) ),
					] }
					onChange={ onChange }
					__next40pxDefaultSize
				/>
			);
		case 'multiselect':
			return (
				<MultiSelectControl
					{ ...common }
					options={ setting.options || {} }
					value={ value }
					onChange={ onChange }
				/>
			);
		case 'textarea':
			return (
				<TextareaControl
					{ ...common }
					value={ value ?? '' }
					onChange={ onChange }
					rows={ 3 }
				/>
			);
		case 'lines':
			return (
				<LinesControl
					{ ...common }
					value={ value }
					onChange={ onChange }
				/>
			);
		case 'choices':
			return (
				<ChoiceField
					{ ...common }
					value={ value }
					onChange={ onChange }
					toText={ choicesToText }
				/>
			);
		default:
			return (
				<TextControl
					{ ...common }
					value={ value ?? '' }
					onChange={ onChange }
					__next40pxDefaultSize
				/>
			);
	}
}

/**
 * Checkbox list storing the checked option keys.
 *
 * @param {Object}   props          Props.
 * @param {string}   props.label    Label.
 * @param {string}   props.help     Help text.
 * @param {Object}   props.options  Options { key: label }.
 * @param {Array}    props.value    Checked keys.
 * @param {Function} props.onChange Receives the new list.
 */
function MultiSelectControl( { label, help, options, value, onChange } ) {
	const checked = Array.isArray( value ) ? value : [];
	return (
		<fieldset className="wpff-multiselect">
			<legend className="components-base-control__label">
				{ label }
			</legend>
			<div className="wpff-multiselect__options">
				{ Object.entries( options ).map( ( [ key, text ] ) => (
					<CheckboxControl
						key={ key }
						label={ text }
						checked={ checked.includes( key ) }
						onChange={ ( on ) =>
							onChange(
								on
									? [ ...checked, key ]
									: checked.filter( ( k ) => k !== key )
							)
						}
						__nextHasNoMarginBottom
					/>
				) ) }
			</div>
			{ help && (
				<p className="components-base-control__help">{ help }</p>
			) }
		</fieldset>
	);
}

/**
 * Textarea editing a list: one value per line.
 *
 * @param {Object}   props          Props.
 * @param {Array}    props.value    Current list.
 * @param {Function} props.onChange Receives the new list.
 */
function LinesControl( { value, onChange, ...props } ) {
	const toText = ( v ) =>
		Array.isArray( v ) ? v.join( '\n' ) : String( v ?? '' );
	const [ text, setText ] = useState( toText( value ) );
	return (
		<TextareaControl
			{ ...props }
			value={ text }
			rows={ 3 }
			onChange={ ( t ) => {
				setText( t );
				onChange(
					t
						.split( '\n' )
						.map( ( l ) => l.trim() )
						.filter( Boolean )
				);
			} }
		/>
	);
}
