/**
 * Layouts of a Flexible Content field: each with a label, name, display and its own sub fields.
 */
import type { ComponentType } from 'react';
import { __ } from '@wordpress/i18n';
import { Button, TextControl } from '@wordpress/components';
import { arrowDown, arrowUp, copy, trash } from '@wordpress/icons';
import { NumberInput } from './fieldsShared';
import { cleanName, nameFromLabel, rekeyFields, uid } from './utils';
import Select from './Select';
import type { Layout } from '../types';
import type { BuilderErrors, SubFieldListProps } from './types';

interface LayoutsEditorProps {
	layouts: Layout[];
	path: string;
	errors: BuilderErrors;
	onChange: ( layouts: Layout[] ) => void;
	SubFieldList?: ComponentType< SubFieldListProps >;
}

/**
 * @param props
 * @param props.layouts      Layouts.
 * @param props.path         Error path of the field, e.g. 'fields.3'.
 * @param props.errors       All errors by full path.
 * @param props.onChange     Receives the new layouts.
 * @param props.SubFieldList FieldList component.
 */
export default function LayoutsEditor( {
	layouts,
	path,
	errors,
	onChange,
	SubFieldList,
}: LayoutsEditorProps ) {
	const update = ( i: number, patch: Partial< Layout > ) =>
		onChange(
			layouts.map( ( l, j ) => ( j === i ? { ...l, ...patch } : l ) )
		);

	const move = ( i: number, delta: number ) => {
		const next = [ ...layouts ];
		const [ item ] = next.splice( i, 1 );
		next.splice( i + delta, 0, item );
		onChange( next );
	};

	const add = () =>
		onChange( [
			...layouts,
			{
				key: `layout_${ uid() }`,
				label: '',
				name: '',
				display: 'block',
				min: '',
				max: '',
				sub_fields: [],
				_isNew: true,
			},
		] );

	const duplicate = ( i: number ) => {
		const source = layouts[ i ];
		const next = [ ...layouts ];
		next.splice( i + 1, 0, {
			...source,
			key: `layout_${ uid() }`,
			label: `${ source.label } (copy)`,
			name: `${ source.name }_copy`,
			sub_fields: rekeyFields( source.sub_fields || [] ),
			_isNew: true,
			_nameTouched: true,
		} );
		onChange( next );
	};

	return (
		<div className="wpff-layouts">
			{ 0 === layouts.length && (
				<p className="description">
					{ __( 'Add at least one layout.', 'freeform-fields' ) }
				</p>
			) }
			{ layouts.map( ( layout, i ) => {
				const lpath = `${ path }.layouts.${ i }`;
				return (
					<div className="wpff-layout" key={ layout.key }>
						<div className="wpff-layout__header">
							<strong>
								{ layout.label ||
									__( '(no label)', 'freeform-fields' ) }
							</strong>
							<div className="wpff-field-row__actions">
								<Button
									icon={ arrowUp }
									label={ __( 'Move up', 'freeform-fields' ) }
									size="compact"
									disabled={ 0 === i }
									onClick={ () => move( i, -1 ) }
								/>
								<Button
									icon={ arrowDown }
									label={ __(
										'Move down',
										'freeform-fields'
									) }
									size="compact"
									disabled={ i === layouts.length - 1 }
									onClick={ () => move( i, 1 ) }
								/>
								<Button
									icon={ copy }
									label={ __(
										'Duplicate layout',
										'freeform-fields'
									) }
									size="compact"
									onClick={ () => duplicate( i ) }
								/>
								<Button
									icon={ trash }
									label={ __(
										'Delete layout',
										'freeform-fields'
									) }
									size="compact"
									isDestructive
									onClick={ () =>
										onChange(
											layouts.filter(
												( _, j ) => j !== i
											)
										)
									}
								/>
							</div>
						</div>
						<div className="wpff-field-settings__grid">
							<TextControl
								label={ __( 'Label', 'freeform-fields' ) }
								value={ layout.label }
								onChange={ ( label ) =>
									update(
										i,
										layout._isNew && ! layout._nameTouched
											? {
													label,
													name: nameFromLabel(
														label
													),
												}
											: { label }
									)
								}
								help={ errors[ `${ lpath }.label` ] }
								className={
									errors[ `${ lpath }.label` ]
										? 'has-error'
										: ''
								}
								__next40pxDefaultSize
								__nextHasNoMarginBottom
							/>
							<TextControl
								label={ __( 'Name', 'freeform-fields' ) }
								value={ layout.name }
								onChange={ ( name ) =>
									update( i, {
										name: cleanName( name ),
										_nameTouched: true,
									} )
								}
								help={
									errors[ `${ lpath }.name` ] ||
									__(
										'Returned by get_row_layout().',
										'freeform-fields'
									)
								}
								className={
									errors[ `${ lpath }.name` ]
										? 'has-error'
										: ''
								}
								__next40pxDefaultSize
								__nextHasNoMarginBottom
							/>
							<Select
								label={ __( 'Display', 'freeform-fields' ) }
								value={ layout.display }
								options={ [
									{
										value: 'block',
										label: __( 'Block', 'freeform-fields' ),
									},
									{
										value: 'table',
										label: __( 'Table', 'freeform-fields' ),
									},
									{
										value: 'row',
										label: __( 'Row', 'freeform-fields' ),
									},
								] }
								onChange={ ( display ) =>
									update( i, { display } )
								}
								__next40pxDefaultSize
								__nextHasNoMarginBottom
							/>
							<NumberInput
								label={ __( 'Min', 'freeform-fields' ) }
								value={ layout.min }
								onChange={ ( min ) => update( i, { min } ) }
								__next40pxDefaultSize
							/>
							<NumberInput
								label={ __( 'Max', 'freeform-fields' ) }
								value={ layout.max }
								onChange={ ( max ) => update( i, { max } ) }
								__next40pxDefaultSize
							/>
						</div>
						{ SubFieldList && (
							<SubFieldList
								fields={ layout.sub_fields || [] }
								errors={ errors }
								path={ `${ lpath }.sub_fields` }
								onChange={ ( subFields ) =>
									update( i, { sub_fields: subFields } )
								}
							/>
						) }
					</div>
				);
			} ) }
			<Button variant="secondary" onClick={ add }>
				{ __( 'Add Layout', 'freeform-fields' ) }
			</Button>
		</div>
	);
}
