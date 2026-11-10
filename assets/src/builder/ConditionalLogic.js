/**
 * Conditional logic editor for one field: show it only when rules on other fields match.
 */
import { __ } from '@wordpress/i18n';
import { Button, TextControl, ToggleControl } from '@wordpress/components';
import { closeSmall, plus } from '@wordpress/icons';
import { parseChoices, typeOf } from './utils';
import Select from './Select';

const OPERATORS = () => [
	{ value: '==', label: __( 'Value is equal to', 'freeform-fields' ) },
	{ value: '!=', label: __( 'Value is not equal to', 'freeform-fields' ) },
	{ value: '==empty', label: __( 'Has no value', 'freeform-fields' ) },
	{ value: '!=empty', label: __( 'Has any value', 'freeform-fields' ) },
	{ value: '==contains', label: __( 'Value contains', 'freeform-fields' ) },
	{
		value: '!=contains',
		label: __( 'Value does not contain', 'freeform-fields' ),
	},
	{ value: '>', label: __( 'Value is greater than', 'freeform-fields' ) },
	{ value: '<', label: __( 'Value is less than', 'freeform-fields' ) },
];

const NO_VALUE = [ '==empty', '!=empty' ];

/**
 * @param {Object}   props
 * @param {Object}   props.field    Field being edited.
 * @param {Array}    props.siblings Fields the rules may refer to.
 * @param {Function} props.onChange Receives the new conditional_logic (array or 0).
 */
export default function ConditionalLogic( { field, siblings, onChange } ) {
	const candidates = siblings.filter(
		( f ) =>
			f.key !== field.key &&
			typeOf( f.type )?.hasValue &&
			! typeOf( f.type )?.hasSubs
	);
	const rules = Array.isArray( field.conditional_logic )
		? field.conditional_logic
		: null;

	const newRule = () => ( {
		field: candidates[ 0 ]?.key || '',
		operator: '==',
		value: firstValue( candidates[ 0 ] ),
	} );

	const setRule = ( gi, ri, patch ) =>
		onChange(
			rules.map( ( group, g ) =>
				g === gi
					? group.map( ( r, i ) =>
							i === ri ? { ...r, ...patch } : r
					  )
					: group
			)
		);

	const addRule = ( gi ) =>
		onChange(
			rules.map( ( group, g ) =>
				g === gi ? [ ...group, newRule() ] : group
			)
		);

	const removeRule = ( gi, ri ) => {
		const next = rules
			.map( ( group, g ) =>
				g === gi ? group.filter( ( _, i ) => i !== ri ) : group
			)
			.filter( ( g ) => g.length );
		onChange( next.length ? next : 0 );
	};

	return (
		<div className="wpff-conditions">
			<ToggleControl
				label={ __( 'Conditional Logic', 'freeform-fields' ) }
				help={
					candidates.length
						? __(
								'Show this field only when other fields have certain values.',
								'freeform-fields'
						  )
						: __(
								'Add another field to this group to use conditions.',
								'freeform-fields'
						  )
				}
				checked={ !! rules }
				disabled={ ! candidates.length }
				onChange={ ( on ) => onChange( on ? [ [ newRule() ] ] : 0 ) }
				__nextHasNoMarginBottom
			/>
			{ rules &&
				rules.map( ( group, gi ) => (
					<div className="wpff-conditions__group" key={ gi }>
						{ gi > 0 && (
							<div className="wpff-location__or">
								{ __( 'or', 'freeform-fields' ) }
							</div>
						) }
						{ group.map( ( rule, ri ) => (
							<div className="wpff-conditions__rule" key={ ri }>
								<span className="wpff-location__and">
									{ 0 === ri
										? __( 'Show if', 'freeform-fields' )
										: __( 'and', 'freeform-fields' ) }
								</span>
								<Select
									label={ __( 'Field', 'freeform-fields' ) }
									hideLabelFromVision
									value={ rule.field }
									options={ candidates.map( ( f ) => ( {
										value: f.key,
										label: f.label || f.name || f.key,
									} ) ) }
									onChange={ ( key ) =>
										setRule( gi, ri, {
											field: key,
											value: firstValue(
												candidates.find(
													( f ) => f.key === key
												)
											),
										} )
									}
									__next40pxDefaultSize
									__nextHasNoMarginBottom
								/>
								<Select
									label={ __(
										'Operator',
										'freeform-fields'
									) }
									hideLabelFromVision
									value={ rule.operator }
									options={ OPERATORS() }
									onChange={ ( operator ) =>
										setRule( gi, ri, { operator } )
									}
									__next40pxDefaultSize
									__nextHasNoMarginBottom
								/>
								{ NO_VALUE.includes( rule.operator ) ? (
									<span />
								) : (
									<RuleValue
										target={ candidates.find(
											( f ) => f.key === rule.field
										) }
										rule={ rule }
										onChange={ ( value ) =>
											setRule( gi, ri, { value } )
										}
									/>
								) }
								<Button
									icon={ plus }
									label={ __(
										'Add "and" rule',
										'freeform-fields'
									) }
									onClick={ () => addRule( gi ) }
									size="compact"
								/>
								<Button
									icon={ closeSmall }
									label={ __(
										'Remove rule',
										'freeform-fields'
									) }
									onClick={ () => removeRule( gi, ri ) }
									size="compact"
									isDestructive
								/>
							</div>
						) ) }
					</div>
				) ) }
			{ rules && (
				<Button
					variant="secondary"
					onClick={ () => onChange( [ ...rules, [ newRule() ] ] ) }
				>
					{ __( 'Add rule group', 'freeform-fields' ) }
				</Button>
			) }
		</div>
	);
}

/**
 * Sensible starting value for a rule on a field: its first choice, "checked", or empty.
 *
 * @param {Object|undefined} target Field.
 * @return {string} Value.
 */
function firstValue( target ) {
	if ( ! target ) {
		return '';
	}
	if ( 'true_false' === target.type ) {
		return '1';
	}
	const keys = target.choices
		? Object.keys( parseChoices( target.choices ) )
		: [];
	return keys.length ? keys[ 0 ] : '';
}

function RuleValue( { target, rule, onChange } ) {
	const common = {
		label: __( 'Value', 'freeform-fields' ),
		hideLabelFromVision: true,
		__next40pxDefaultSize: true,
		__nextHasNoMarginBottom: true,
	};
	if ( target && 'true_false' === target.type ) {
		return (
			<Select
				{ ...common }
				value={ rule.value || '1' }
				options={ [
					{ value: '1', label: __( 'Checked', 'freeform-fields' ) },
					{ value: '0', label: __( 'Unchecked', 'freeform-fields' ) },
				] }
				onChange={ onChange }
			/>
		);
	}
	const choices =
		target && target.choices ? parseChoices( target.choices ) : null;
	if (
		choices &&
		Object.keys( choices ).length &&
		[ '==', '!=' ].includes( rule.operator )
	) {
		const options = Object.entries( choices ).map(
			( [ value, label ] ) => ( { value, label } )
		);
		return (
			<Select
				{ ...common }
				value={ rule.value || options[ 0 ].value }
				options={ options }
				onChange={ onChange }
			/>
		);
	}
	return (
		<TextControl { ...common } value={ rule.value } onChange={ onChange } />
	);
}
