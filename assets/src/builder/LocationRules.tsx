/**
 * Location rules editor: OR-groups of AND-rules.
 */
import { __, sprintf } from '@wordpress/i18n';
import { Button, TextControl } from '@wordpress/components';
import { closeSmall, plus } from '@wordpress/icons';
import Select from './Select';
import type { Location, LocationParam, LocationRule } from '../types';
import type { SelectOption } from './types';

interface LocationRulesProps {
	location: Location;
	onChange: ( location: Location ) => void;
}

const params = () => window.wpffBuilder!.location;
const findParam = ( name: string ) => params().find( ( p ) => p.name === name );

/**
 * One-line human summary, e.g. "Post Type is Post or Page Template is Home".
 *
 * @param location Rules.
 * @return Summary.
 */
export function describeLocation(
	location: Location | null | undefined
): string {
	return ( location || [] )
		.map( ( rules ) =>
			rules
				.map( ( rule ) => {
					const param = findParam( rule.param );
					const label = param ? param.label : rule.param;
					const value =
						param && param.values && param.values[ rule.value ]
							? param.values[ rule.value ]
							: rule.value;
					return '==' === rule.operator
						? /* translators: 1: rule name, 2: value. */ sprintf(
								__( '%1$s is %2$s', 'freeform-fields' ),
								label,
								value
							)
						: /* translators: 1: rule name, 2: value. */ sprintf(
								__( '%1$s is not %2$s', 'freeform-fields' ),
								label,
								value
							);
				} )
				.join( ` ${ __( 'and', 'freeform-fields' ) } ` )
		)
		.join( ` ${ __( 'or', 'freeform-fields' ) } ` );
}

function firstValue( param: LocationParam | undefined ): string {
	if ( ! param || ! param.values ) {
		return '';
	}
	const keys = Object.keys( param.values );
	return keys.length ? keys[ 0 ] : '';
}

function paramOptions(): SelectOption[] {
	// Grouped <optgroup>-like list: "Post › Post Type".
	return params().map( ( p ) => ( {
		value: p.name,
		label: p.label,
		group: p.group,
	} ) );
}

export default function LocationRules( {
	location,
	onChange,
}: LocationRulesProps ) {
	const setRule = (
		gi: number,
		ri: number,
		patch: Partial< LocationRule >
	) => {
		const next = location.map( ( rules, g ) =>
			g === gi
				? rules.map( ( rule, r ) =>
						r === ri ? { ...rule, ...patch } : rule
					)
				: rules
		);
		onChange( next );
	};

	const addRule = ( gi: number, ri: number ) => {
		const next = location.map( ( rules, g ) => {
			if ( g !== gi ) {
				return rules;
			}
			const copy = [ ...rules ];
			copy.splice( ri + 1, 0, { ...rules[ ri ] } );
			return copy;
		} );
		onChange( next );
	};

	const removeRule = ( gi: number, ri: number ) => {
		const next = location
			.map( ( rules, g ) =>
				g === gi ? rules.filter( ( _, r ) => r !== ri ) : rules
			)
			.filter( ( rules ) => rules.length > 0 );
		onChange( next );
	};

	const addGroup = () => {
		const param = params()[ 0 ];
		onChange( [
			...location,
			[
				{
					param: param.name,
					operator: '==',
					value: firstValue( param ),
				},
			],
		] );
	};

	return (
		<div className="wpff-location">
			<p className="description">
				{ __(
					'Show this field group if all rules of any rule group match.',
					'freeform-fields'
				) }
			</p>
			{ 0 === location.length && (
				<p className="wpff-location__empty">
					{ __(
						'No rules: this field group is not shown anywhere.',
						'freeform-fields'
					) }
				</p>
			) }
			{ location.map( ( rules, gi ) => (
				<div className="wpff-location__group" key={ gi }>
					{ gi > 0 && (
						<div className="wpff-location__or">
							{ __( 'or', 'freeform-fields' ) }
						</div>
					) }
					{ rules.map( ( rule, ri ) => {
						const param = findParam( rule.param );
						return (
							<div className="wpff-location__rule" key={ ri }>
								<span className="wpff-location__and">
									{ 0 === ri
										? __( 'Show if', 'freeform-fields' )
										: __( 'and', 'freeform-fields' ) }
								</span>
								<Select
									label={ __( 'Rule', 'freeform-fields' ) }
									hideLabelFromVision
									value={ rule.param }
									options={ paramOptions() }
									onChange={ ( name ) =>
										setRule( gi, ri, {
											param: name,
											value: firstValue(
												findParam( name )
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
									options={ [
										{
											value: '==',
											label: __(
												'is equal to',
												'freeform-fields'
											),
										},
										{
											value: '!=',
											label: __(
												'is not equal to',
												'freeform-fields'
											),
										},
									] }
									onChange={ ( operator ) =>
										setRule( gi, ri, { operator } )
									}
									__next40pxDefaultSize
									__nextHasNoMarginBottom
								/>
								{ param && param.values ? (
									<Select
										label={ __(
											'Value',
											'freeform-fields'
										) }
										hideLabelFromVision
										value={ rule.value }
										options={ valueOptions(
											param,
											rule.value
										) }
										onChange={ ( value ) =>
											setRule( gi, ri, { value } )
										}
										__next40pxDefaultSize
										__nextHasNoMarginBottom
									/>
								) : (
									<TextControl
										label={ __(
											'Value',
											'freeform-fields'
										) }
										hideLabelFromVision
										value={ rule.value }
										onChange={ ( value ) =>
											setRule( gi, ri, { value } )
										}
										__next40pxDefaultSize
										__nextHasNoMarginBottom
									/>
								) }
								<Button
									icon={ plus }
									label={ __(
										'Add "and" rule',
										'freeform-fields'
									) }
									onClick={ () => addRule( gi, ri ) }
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
						);
					} ) }
				</div>
			) ) }
			<Button variant="secondary" onClick={ addGroup }>
				{ __( 'Add rule group', 'freeform-fields' ) }
			</Button>
		</div>
	);
}

function valueOptions( param: LocationParam, current: string ): SelectOption[] {
	const options = Object.entries( param.values! ).map(
		( [ value, label ] ) => ( { value, label } )
	);
	// Keep a saved value visible even if it is no longer in the list (e.g. a deleted page).
	if ( current && ! ( current in param.values! ) ) {
		options.unshift( { value: current, label: current } );
	}
	if ( 0 === options.length ) {
		options.push( {
			value: '',
			label: __( '— No values —', 'freeform-fields' ),
		} );
	}
	return options;
}
