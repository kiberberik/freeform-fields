/**
 * Field types to add: click a tile to add it, or drag it to a place in the tree.
 */
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { SearchControl } from '@wordpress/components';
import { useDraggable } from '@dnd-kit/core';
import { TypeIcon } from './ui';
import type { FieldType } from '../types';
import type { DragData } from './types';

interface TypePaletteProps {
	onAdd: ( type: FieldType ) => void;
	disabled: boolean;
}

interface TileProps extends TypePaletteProps {
	type: FieldType;
}

/**
 * @param props
 * @param props.onAdd    Receives the type to add after the selected field.
 * @param props.disabled Read-only group.
 */
export default function TypePalette( { onAdd, disabled }: TypePaletteProps ) {
	const [ search, setSearch ] = useState( '' );
	const types = window.wpffBuilder!.fieldTypes;
	const query = search.trim().toLowerCase();
	const matching = types.filter(
		( t ) =>
			! query ||
			t.label.toLowerCase().includes( query ) ||
			t.name.includes( query ) ||
			t.category.toLowerCase().includes( query )
	);
	const categories = Array.from(
		new Set( matching.map( ( t ) => t.category ) )
	);

	return (
		<div className="wpff-palette">
			<SearchControl
				value={ search }
				onChange={ setSearch }
				placeholder={ __( 'Search field types', 'freeform-fields' ) }
				__nextHasNoMarginBottom
			/>
			<p className="wpff-palette__hint">
				{ __(
					'Click to add after the selected field, or drag into place.',
					'freeform-fields'
				) }
			</p>
			{ categories.map( ( category ) => (
				<section className="wpff-palette__group" key={ category }>
					<h3>{ category }</h3>
					<div className="wpff-palette__grid">
						{ matching
							.filter( ( t ) => t.category === category )
							.map( ( type ) => (
								<Tile
									key={ type.name }
									type={ type }
									onAdd={ onAdd }
									disabled={ disabled }
								/>
							) ) }
					</div>
				</section>
			) ) }
			{ 0 === matching.length && (
				<p className="wpff-palette__none">
					{ __( 'No field types found.', 'freeform-fields' ) }
				</p>
			) }
		</div>
	);
}

function Tile( { type, onAdd, disabled }: TileProps ) {
	const { setNodeRef, attributes, listeners, isDragging } = useDraggable( {
		id: `type:${ type.name }`,
		data: { kind: 'type', type } satisfies DragData,
		disabled,
	} );
	return (
		<button
			type="button"
			ref={ setNodeRef }
			className={ `wpff-palette__tile${
				isDragging ? ' is-dragging' : ''
			}` }
			{ ...attributes }
			{ ...listeners }
			onClick={ () => onAdd( type ) }
			disabled={ disabled }
		>
			<TypeIcon type={ type.name } size={ 32 } />
			<span>{ type.label }</span>
		</button>
	);
}
