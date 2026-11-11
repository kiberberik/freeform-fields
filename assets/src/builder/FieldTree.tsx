/**
 * The group's fields as a tree: every level visible, drag and drop between levels,
 * types dragged in from the palette. Settings of the selected field live in the inspector.
 */
import { createContext, useContext } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	DropdownMenu,
	MenuGroup,
	MenuItem,
} from '@wordpress/components';
import {
	arrowDown,
	arrowUp,
	chevronDown,
	chevronRight,
	copy,
	dragHandle,
	moreVertical,
	trash,
} from '@wordpress/icons';
import { useDraggable, useDroppable } from '@dnd-kit/core';
import { TypeIcon } from './ui';
import { childLists } from './tree';
import { typeOf } from './utils';
import type { Field } from '../types';
import type { DragData, DropData, TreeState } from './types';

interface FieldListProps {
	fields: Field[];
	path: string;
	depth?: number;
}

interface FieldNodeProps {
	field: Field;
	index: number;
	listPath: string;
	siblingsCount: number;
	previous: Field | undefined;
	depth: number;
}

/**
 * Shared state of the tree: selection, errors, drop target and actions.
 */
export const TreeContext = createContext< TreeState >( {} as TreeState );

/**
 * @param props
 * @param props.fields Fields of this list.
 * @param props.path   List path ('fields', 'fields.2.sub_fields', …).
 * @param props.depth  Nesting level.
 */
export function FieldList( { fields, path, depth = 0 }: FieldListProps ) {
	const { drop, readOnly } = useContext( TreeContext );
	const { setNodeRef, isOver } = useDroppable( {
		id: `list:${ path }`,
		data: {
			kind: 'list',
			listPath: path,
			length: fields.length,
		} satisfies DropData,
		disabled: readOnly,
	} );
	const line = ( i: number ) =>
		drop && drop.listPath === path && drop.index === i ? (
			<div className="wpff-drop-line" key={ `line-${ i }` } />
		) : null;

	return (
		<div
			ref={ setNodeRef }
			className={ [
				'wpff-tree__list',
				0 === fields.length && 'is-empty',
				isOver && 'is-over',
			]
				.filter( Boolean )
				.join( ' ' ) }
			data-depth={ depth }
		>
			{ fields.map( ( field, i ) => [
				line( i ),
				<FieldNode
					key={ field.key }
					field={ field }
					index={ i }
					listPath={ path }
					siblingsCount={ fields.length }
					previous={ fields[ i - 1 ] }
					depth={ depth }
				/>,
			] ) }
			{ line( fields.length ) }
			{ 0 === fields.length && (
				<p className="wpff-tree__placeholder">
					{ depth
						? __(
								'Drag fields here, or pick a type in the “Add field” panel.',
								'freeform-fields'
							)
						: __(
								'No fields yet. Drag a field type here from the “Add field” panel, or click one to add it.',
								'freeform-fields'
							) }
				</p>
			) }
		</div>
	);
}

function FieldNode( {
	field,
	index,
	listPath,
	siblingsCount,
	previous,
	depth,
}: FieldNodeProps ) {
	const ctx = useContext( TreeContext );
	const path = `${ listPath }.${ index }`;
	const type = typeOf( field.type );
	const selected = ctx.selected === field.key;
	const hasErrors = Object.keys( ctx.errors ).some(
		( p ) => p === path || p.startsWith( `${ path }.` )
	);
	const collapsed = ctx.collapsed.includes( field.key );
	const lists = childLists( field, path );
	// Moving into the field above (its sub fields or a layout) without dragging.
	const intoPrevious = previous
		? childLists( previous, `${ listPath }.${ index - 1 }` )
		: [];
	const previousName =
		( previous && ( previous.label || previous.name ) ) ||
		__( '(no label)', 'freeform-fields' );

	const drag = useDraggable( {
		id: `field:${ field.key }`,
		data: { kind: 'field', key: field.key, field } satisfies DragData,
		disabled: ctx.readOnly,
	} );
	const dropRow = useDroppable( {
		id: `row:${ field.key }`,
		data: {
			kind: 'row',
			key: field.key,
			listPath,
			index,
		} satisfies DropData,
		disabled: ctx.readOnly,
	} );

	const label = field.label || __( '(no label)', 'freeform-fields' );

	return (
		<div
			className={ [
				'wpff-node',
				selected && 'is-selected',
				hasErrors && 'has-error',
				drag.isDragging && 'is-dragging',
			]
				.filter( Boolean )
				.join( ' ' ) }
		>
			<div
				ref={ ( el ) => {
					drag.setNodeRef( el );
					dropRow.setNodeRef( el );
				} }
				className="wpff-node__row"
				role="button"
				tabIndex={ 0 }
				aria-pressed={ selected }
				aria-label={ sprintf(
					/* translators: 1: field label, 2: field type. */
					__( '%1$s, %2$s field', 'freeform-fields' ),
					label,
					type ? type.label : field.type
				) }
				onClick={ () => ctx.select( field.key ) }
				onKeyDown={ ( e ) => {
					if (
						( 'Enter' === e.key || ' ' === e.key ) &&
						e.target === e.currentTarget
					) {
						e.preventDefault();
						ctx.select( field.key );
					}
				} }
			>
				{ ! ctx.readOnly && (
					<button
						type="button"
						className="wpff-node__handle"
						ref={ drag.setActivatorNodeRef }
						{ ...drag.attributes }
						{ ...drag.listeners }
						aria-label={ __( 'Drag to move', 'freeform-fields' ) }
						onClick={ ( e ) => e.stopPropagation() }
					>
						{ dragHandle }
					</button>
				) }
				{ lists.length > 0 ? (
					<button
						type="button"
						className="wpff-node__toggle"
						aria-expanded={ ! collapsed }
						aria-label={
							collapsed
								? __( 'Show sub fields', 'freeform-fields' )
								: __( 'Hide sub fields', 'freeform-fields' )
						}
						onClick={ ( e ) => {
							e.stopPropagation();
							ctx.toggleCollapsed( field.key );
						} }
					>
						{ collapsed ? chevronRight : chevronDown }
					</button>
				) : (
					<span className="wpff-node__toggle-spacer" />
				) }
				<TypeIcon type={ field.type } />
				<span className="wpff-node__text">
					<span className="wpff-node__label">
						{ field.label || (
							<em>{ __( '(no label)', 'freeform-fields' ) }</em>
						) }
						{ !! Number( field.required ) && (
							<span
								className="wpff-required"
								title={ __( 'Required', 'freeform-fields' ) }
							>
								*
							</span>
						) }
					</span>
					{ field.name && (
						<code className="wpff-node__name">{ field.name }</code>
					) }
				</span>
				<span className="wpff-node__meta">
					{ Array.isArray( field.conditional_logic ) && (
						<span className="wpff-chip">
							{ __( 'Conditional', 'freeform-fields' ) }
						</span>
					) }
					<span className="wpff-node__type">
						{ type ? type.label : field.type }
					</span>
				</span>
				{ ! ctx.readOnly && (
					<span
						className="wpff-node__actions"
						onClick={ ( e ) => e.stopPropagation() }
						onKeyDown={ ( e ) => e.stopPropagation() }
						role="presentation"
					>
						<Button
							className="wpff-node__quick"
							icon={ copy }
							label={ __( 'Duplicate', 'freeform-fields' ) }
							size="small"
							onClick={ () => ctx.duplicate( field.key ) }
						/>
						<Button
							className="wpff-node__quick"
							icon={ trash }
							label={ __( 'Delete', 'freeform-fields' ) }
							size="small"
							isDestructive
							onClick={ () => ctx.remove( field.key ) }
						/>
						<DropdownMenu
							icon={ moreVertical }
							label={ __( 'More actions', 'freeform-fields' ) }
							toggleProps={ { size: 'small' } }
						>
							{ ( { onClose } ) => (
								<>
									<MenuGroup>
										<MenuItem
											icon={ copy }
											className="wpff-node__menu-compact"
											onClick={ () => {
												ctx.duplicate( field.key );
												onClose();
											} }
										>
											{ __(
												'Duplicate',
												'freeform-fields'
											) }
										</MenuItem>
										<MenuItem
											icon={ arrowUp }
											disabled={ 0 === index }
											onClick={ () => {
												ctx.move(
													field.key,
													listPath,
													index - 1
												);
												onClose();
											} }
										>
											{ __(
												'Move up',
												'freeform-fields'
											) }
										</MenuItem>
										<MenuItem
											icon={ arrowDown }
											disabled={
												index === siblingsCount - 1
											}
											onClick={ () => {
												ctx.move(
													field.key,
													listPath,
													index + 2
												);
												onClose();
											} }
										>
											{ __(
												'Move down',
												'freeform-fields'
											) }
										</MenuItem>
										{ intoPrevious.map( ( list ) => (
											<MenuItem
												key={ list.path }
												onClick={ () => {
													ctx.move(
														field.key,
														list.path,
														list.fields.length
													);
													onClose();
												} }
											>
												{ list.layout
													? sprintf(
															/* translators: 1: field label, 2: layout label. */
															__(
																'Move into “%1$s” › %2$s',
																'freeform-fields'
															),
															previousName,
															list.layout.label ||
																list.layout.name
														)
													: sprintf(
															/* translators: %s: field label. */
															__(
																'Move into “%s”',
																'freeform-fields'
															),
															previousName
														) }
											</MenuItem>
										) ) }
										{ depth > 0 && (
											<MenuItem
												onClick={ () => {
													ctx.moveOut( field.key );
													onClose();
												} }
											>
												{ __(
													'Move out of the parent field',
													'freeform-fields'
												) }
											</MenuItem>
										) }
									</MenuGroup>
									<MenuGroup>
										<MenuItem
											icon={ trash }
											isDestructive
											onClick={ () => {
												ctx.remove( field.key );
												onClose();
											} }
										>
											{ __(
												'Delete',
												'freeform-fields'
											) }
										</MenuItem>
									</MenuGroup>
								</>
							) }
						</DropdownMenu>
					</span>
				) }
			</div>
			{ lists.length > 0 && ! collapsed && (
				<div className="wpff-node__children">
					{ lists.map( ( list ) => (
						<div className="wpff-node__sublist" key={ list.path }>
							{ list.layout && (
								<div className="wpff-node__layout">
									<span className="wpff-node__layout-name">
										{ list.layout.label ||
											__(
												'(no label)',
												'freeform-fields'
											) }
									</span>
									<code>{ list.layout.name }</code>
								</div>
							) }
							<FieldList
								fields={ list.fields }
								path={ list.path }
								depth={ depth + 1 }
							/>
						</div>
					) ) }
					{ Array.isArray( field.layouts ) &&
						0 === field.layouts.length && (
							<p className="wpff-tree__placeholder">
								{ __(
									'Add a layout in the field settings to place fields in it.',
									'freeform-fields'
								) }
							</p>
						) }
				</div>
			) }
		</div>
	);
}

/**
 * What follows the pointer while dragging.
 *
 * @param props
 * @param props.data Drag data: a field, or a type from the palette.
 */
export function DragPreview( { data }: { data: DragData | null } ) {
	if ( ! data ) {
		return null;
	}
	const typeName = 'type' === data.kind ? data.type.name : data.field.type;
	const label =
		'type' === data.kind
			? data.type.label
			: data.field.label || __( '(no label)', 'freeform-fields' );
	return (
		<div className="wpff-drag-preview">
			<TypeIcon type={ typeName } />
			<span>{ label }</span>
		</div>
	);
}
