/**
 * Field group editor: the field tree on the left, an inspector on the right
 * (add field / selected field / group settings), drag and drop throughout.
 */
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	Notice,
	Spinner,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { plus } from '@wordpress/icons';
import {
	DndContext,
	DragOverlay,
	PointerSensor,
	pointerWithin,
	useSensor,
	useSensors,
} from '@dnd-kit/core';
import { NumberInput } from './fieldsShared';
import { createGroup, errorsFromResponse, getGroup, updateGroup } from './api';
import { DragPreview, FieldList, TreeContext } from './FieldTree';
import FieldSettings from './FieldSettings';
import LocationRules from './LocationRules';
import TypePalette from './TypePalette';
import { PageHeader, Pill } from './ui';
import {
	findField,
	flatten,
	getAt,
	insertAt,
	moveField,
	removeField,
	replaceField,
} from './tree';
import {
	newField,
	newGroup,
	rekeyFields,
	toPayload,
	validateGroup,
} from './utils';
import Select from './Select';

/**
 * Pointer collisions, innermost droppable first, so nested lists win over their parents.
 *
 * @param {Object} args Collision arguments.
 * @return {Array} Collisions.
 */
function innermost( args ) {
	const area = ( id ) => {
		const r = args.droppableRects.get( id );
		return r ? r.width * r.height : Infinity;
	};
	return [ ...pointerWithin( args ) ].sort(
		( a, b ) => area( a.id ) - area( b.id )
	);
}

/**
 * Path of the field an error belongs to ('fields.2.sub_fields.0.name' → 'fields.2.sub_fields.0').
 *
 * @param {Object} group Group.
 * @param {string} path  Error path.
 * @return {string|null} Field path.
 */
function fieldPathOf( group, path ) {
	const parts = path.split( '.' );
	for ( let n = parts.length; n > 1; n-- ) {
		const candidate = parts.slice( 0, n ).join( '.' );
		const node = getAt( group, candidate );
		if (
			node &&
			'object' === typeof node &&
			'string' === typeof node.key &&
			node.key.startsWith( 'field_' )
		) {
			return candidate;
		}
	}
	return null;
}

export default function GroupEditor( { groupKey } ) {
	const cfg = window.wpffBuilder;
	const isNew = ! groupKey;

	const [ group, setGroup ] = useState( isNew ? newGroup() : null );
	const [ saved, setSaved ] = useState( isNew ? null : '' );
	const [ selected, setSelected ] = useState( '' );
	const [ tab, setTab ] = useState( 'add' );
	const [ collapsed, setCollapsed ] = useState( [] );
	const [ errors, setErrors ] = useState( {} );
	const [ notice, setNotice ] = useState( null );
	const [ saving, setSaving ] = useState( false );
	const [ exists, setExists ] = useState( ! isNew );
	const inspectorRef = useRef( null );
	const hasGroup = null !== group;

	// The sticky inspector fits the window from where it is: before it sticks (page not
	// scrolled), its top is lower than the sticky offset, and a fixed max-height cut off
	// its bottom. Recomputed on scroll, resize and layout changes (notices, field list).
	useEffect( () => {
		const el = inspectorRef.current;
		if ( ! el ) {
			return;
		}
		const fit = () => {
			if ( 'sticky' !== window.getComputedStyle( el ).position ) {
				el.style.maxHeight = '';
				return;
			}
			const top = Math.max( el.getBoundingClientRect().top, 52 );
			el.style.maxHeight = `${ Math.max(
				240,
				window.innerHeight - top - 20
			) }px`;
		};
		fit();
		window.addEventListener( 'scroll', fit, { passive: true } );
		window.addEventListener( 'resize', fit );
		const observer = window.ResizeObserver
			? new window.ResizeObserver( fit )
			: null;
		observer?.observe( document.body );
		return () => {
			window.removeEventListener( 'scroll', fit );
			window.removeEventListener( 'resize', fit );
			observer?.disconnect();
		};
	}, [ hasGroup ] );
	const [ drop, setDrop ] = useState( null );
	const [ dragging, setDragging ] = useState( null );
	const titleRef = useRef();
	const saveRef = useRef();

	const sensors = useSensors(
		useSensor( PointerSensor, { activationConstraint: { distance: 4 } } )
	);

	useEffect( () => {
		if ( isNew ) {
			titleRef.current?.focus();
			return;
		}
		getGroup( groupKey )
			.then( ( data ) => {
				if ( 'php' === data.source || 'acf' === data.source ) {
					setNotice( {
						status: 'warning',
						text:
							'acf' === data.source
								? __(
										'This field group is read from ACF and cannot be edited here. Migrate it in Tools to edit it.',
										'freeform-fields'
									)
								: __(
										'This field group is registered in code and cannot be edited here.',
										'freeform-fields'
									),
					} );
				}
				setGroup( data );
				setSaved( JSON.stringify( toPayload( data ) ) );
				setTab( data.fields.length ? 'group' : 'add' );
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: e.message } )
			);
	}, [ groupKey, isNew ] );

	const dirty = group && JSON.stringify( toPayload( group ) ) !== saved;

	// Warn before leaving with unsaved changes; Ctrl/Cmd+S saves.
	useEffect( () => {
		const beforeUnload = ( e ) => {
			if ( dirty ) {
				e.preventDefault();
				e.returnValue = '';
			}
		};
		const keydown = ( e ) => {
			if ( ( e.metaKey || e.ctrlKey ) && 's' === e.key.toLowerCase() ) {
				e.preventDefault();
				saveRef.current?.();
			}
		};
		window.addEventListener( 'beforeunload', beforeUnload );
		window.addEventListener( 'keydown', keydown );
		return () => {
			window.removeEventListener( 'beforeunload', beforeUnload );
			window.removeEventListener( 'keydown', keydown );
		};
	}, [ dirty ] );

	const update = useCallback(
		( next ) => {
			setGroup( next );
			if ( Object.keys( errors ).length ) {
				setErrors( {} );
			}
		},
		[ errors ]
	);

	if ( ! group ) {
		return notice ? (
			<Notice status={ notice.status } isDismissible={ false }>
				{ notice.text }
			</Notice>
		) : (
			<Spinner />
		);
	}

	const readOnly = 'php' === group.source || 'acf' === group.source;
	const set = ( patch ) => update( { ...group, ...patch } );
	const current = selected ? findField( group.fields, selected ) : null;
	const fieldCount = flatten( group.fields ).length;

	const select = ( key ) => {
		setSelected( key );
		setTab( key ? 'field' : 'group' );
	};

	/* ---- Tree actions ---------------------------------------------- */

	const addType = ( type, listPath, index ) => {
		const field = newField( type );
		let target = listPath;
		let at = index;
		if ( undefined === target ) {
			// After the selected field, else at the end of the group.
			target = current ? current.listPath : 'fields';
			at = current ? current.index + 1 : group.fields.length;
		}
		update( insertAt( group, target, at, field ) );
		setSelected( field.key );
		setTab( 'field' );
	};

	const duplicate = ( key ) => {
		const found = findField( group.fields, key );
		if ( ! found ) {
			return;
		}
		const [ copy ] = rekeyFields( [ found.field ] );
		Object.assign( copy, {
			label: sprintf(
				/* translators: %s: field label. */
				__( '%s (copy)', 'freeform-fields' ),
				found.field.label
			),
			name: found.field.name ? `${ found.field.name }_copy` : '',
			_isNew: true,
			_nameTouched: true,
		} );
		update( insertAt( group, found.listPath, found.index + 1, copy ) );
		select( copy.key );
	};

	const remove = ( key ) => {
		const at = findField( group.fields, key );
		const result = removeField( group, key );
		if ( ! at || ! result.field ) {
			return;
		}
		update( result.group );
		// Deselect when the selected field (or one inside the removed field) is gone.
		if (
			current &&
			( selected === key || current.path.startsWith( `${ at.path }.` ) )
		) {
			select( '' );
		}
		setNotice( {
			status: 'info',
			text: sprintf(
				/* translators: %s: field label. */
				__( 'Deleted “%s”.', 'freeform-fields' ),
				result.field.label || __( '(no label)', 'freeform-fields' )
			),
			undo: () => {
				setGroup( ( g ) =>
					insertAt( g, result.listPath, result.index, result.field )
				);
				setNotice( null );
			},
		} );
	};

	const move = ( key, listPath, index ) =>
		update( moveField( group, key, listPath, index ) );

	const moveOut = ( key ) => {
		const found = findField( group.fields, key );
		const parentPath = found && fieldPathOf( group, found.listPath );
		if ( ! parentPath ) {
			return;
		}
		const parent = findField(
			group.fields,
			getAt( group, parentPath ).key
		);
		move( key, parent.listPath, parent.index + 1 );
	};

	const toggleCollapsed = ( key ) =>
		setCollapsed( ( list ) =>
			list.includes( key )
				? list.filter( ( k ) => k !== key )
				: [ ...list, key ]
		);

	/* ---- Drag and drop ------------------------------------------------ */

	const targetOf = ( { active, over } ) => {
		if ( ! over ) {
			return null;
		}
		const data = over.data.current;
		let target = null;
		if ( 'list' === data.kind ) {
			target = { listPath: data.listPath, index: data.length };
		} else if ( 'row' === data.kind ) {
			const box = active.rect.current.translated;
			const after =
				box &&
				box.top + box.height / 2 > over.rect.top + over.rect.height / 2;
			target = {
				listPath: data.listPath,
				index: data.index + ( after ? 1 : 0 ),
			};
		}
		const dragged = active.data.current;
		if ( target && 'field' === dragged.kind ) {
			const from = findField( group.fields, dragged.key );
			// Not into itself or its own sub fields, and not onto its own place.
			if (
				! from ||
				target.listPath.startsWith( `${ from.path }.` ) ||
				( target.listPath === from.listPath &&
					( target.index === from.index ||
						target.index === from.index + 1 ) )
			) {
				return null;
			}
		}
		return target;
	};

	const onDragStart = ( { active } ) => setDragging( active.data.current );
	const onDragOver = ( event ) => setDrop( targetOf( event ) );
	const onDragMove = ( event ) => {
		const next = targetOf( event );
		if (
			next?.listPath !== drop?.listPath ||
			next?.index !== drop?.index
		) {
			setDrop( next );
		}
	};
	const onDragEnd = ( event ) => {
		const target = targetOf( event );
		const dragged = event.active.data.current;
		setDrop( null );
		setDragging( null );
		if ( ! target ) {
			return;
		}
		if ( 'type' === dragged.kind ) {
			addType( dragged.type, target.listPath, target.index );
		} else {
			move( dragged.key, target.listPath, target.index );
		}
	};
	const onDragCancel = () => {
		setDrop( null );
		setDragging( null );
	};

	/* ---- Saving ------------------------------------------------------- */

	const showErrors = ( found ) => {
		setErrors( found );
		setNotice( {
			status: 'error',
			text: __(
				'Please fix the highlighted problems and save again.',
				'freeform-fields'
			),
		} );
		const first = Object.keys( found )
			.map( ( path ) => fieldPathOf( group, path ) )
			.find( Boolean );
		if ( first ) {
			const field = getAt( group, first );
			setSelected( field.key );
			setTab( 'field' );
			// Expand every ancestor of the field.
			const ancestors = flatten( group.fields )
				.filter( ( f ) => {
					const at = findField( group.fields, f.key );
					return at && first.startsWith( `${ at.path }.` );
				} )
				.map( ( f ) => f.key );
			setCollapsed( ( list ) =>
				list.filter( ( k ) => ! ancestors.includes( k ) )
			);
		} else if ( found.title ) {
			titleRef.current?.focus();
		} else {
			setTab( 'group' );
		}
	};

	const save = () => {
		if ( readOnly || saving ) {
			return;
		}
		const clientErrors = validateGroup( group, __ );
		if ( Object.keys( clientErrors ).length ) {
			showErrors( clientErrors );
			return;
		}
		setSaving( true );
		setNotice( null );
		const request = exists
			? updateGroup( toPayload( group ) )
			: createGroup( toPayload( group ) );
		request
			.then( ( data ) => {
				// Saved fields are no longer "new": their names stop following the label.
				setGroup( data );
				setSaved( JSON.stringify( toPayload( data ) ) );
				setErrors( {} );
				setNotice( {
					status: 'success',
					text: __( 'Field group saved.', 'freeform-fields' ),
				} );
				if ( ! exists ) {
					setExists( true );
					window.history.replaceState(
						null,
						'',
						`${ cfg.listUrl }&group=${ data.key }`
					);
				}
			} )
			.catch( ( e ) => {
				const serverErrors = errorsFromResponse( e );
				if ( Object.keys( serverErrors ).length ) {
					showErrors( serverErrors );
				} else {
					setNotice( { status: 'error', text: e.message } );
				}
			} )
			.finally( () => setSaving( false ) );
	};
	saveRef.current = save;

	/* ---- Render ------------------------------------------------------- */

	const tree = {
		selected,
		select,
		errors,
		drop,
		readOnly,
		collapsed,
		toggleCollapsed,
		duplicate,
		remove,
		move,
		moveOut,
	};

	return (
		<DndContext
			sensors={ sensors }
			collisionDetection={ innermost }
			onDragStart={ onDragStart }
			onDragOver={ onDragOver }
			onDragMove={ onDragMove }
			onDragEnd={ onDragEnd }
			onDragCancel={ onDragCancel }
		>
			<div
				className={ `wpff-editor${ readOnly ? ' is-readonly' : '' }${
					dragging ? ' is-dragging' : ''
				}` }
			>
				<PageHeader
					back={ cfg.listUrl }
					backLabel={ __( 'Field Groups', 'freeform-fields' ) }
					title={
						exists
							? __( 'Edit Field Group', 'freeform-fields' )
							: __( 'New Field Group', 'freeform-fields' )
					}
					actions={
						<>
							{ dirty && (
								<span className="wpff-dirty">
									{ __(
										'Unsaved changes',
										'freeform-fields'
									) }
								</span>
							) }
							{ ! group.active && (
								<Pill tone="warning">
									{ __( 'Inactive', 'freeform-fields' ) }
								</Pill>
							) }
							<Button
								variant="primary"
								onClick={ save }
								isBusy={ saving }
								disabled={ saving || readOnly }
								shortcut="Ctrl+S"
								__next40pxDefaultSize
							>
								{ exists
									? __( 'Save changes', 'freeform-fields' )
									: __(
											'Create field group',
											'freeform-fields'
										) }
							</Button>
						</>
					}
				/>

				{ notice && (
					<Notice
						className="wpff-notice"
						status={ notice.status }
						onRemove={ () => setNotice( null ) }
						actions={
							notice.undo
								? [
										{
											label: __(
												'Undo',
												'freeform-fields'
											),
											onClick: notice.undo,
										},
									]
								: []
						}
					>
						{ notice.text }
					</Notice>
				) }

				<div className="wpff-editor__layout">
					<fieldset
						disabled={ readOnly }
						className="wpff-editor__main"
					>
						<input
							ref={ titleRef }
							className={ `wpff-title-input${
								errors.title ? ' has-error' : ''
							}` }
							value={ group.title }
							onChange={ ( e ) =>
								set( { title: e.target.value } )
							}
							placeholder={ __(
								'Field group title, e.g. Product details',
								'freeform-fields'
							) }
							aria-label={ __(
								'Field Group Title',
								'freeform-fields'
							) }
						/>
						{ errors.title && (
							<p className="wpff-error-text">{ errors.title }</p>
						) }

						<section className="wpff-panel">
							<div className="wpff-panel__header">
								<h2>
									{ __( 'Fields', 'freeform-fields' ) }
									<span className="wpff-count">
										{ fieldCount }
									</span>
								</h2>
								<div className="wpff-panel__tools">
									{ fieldCount > group.fields.length && (
										<Button
											variant="tertiary"
											size="compact"
											onClick={ () =>
												setCollapsed(
													collapsed.length
														? []
														: flatten(
																group.fields
															)
																.filter(
																	( f ) =>
																		f.sub_fields ||
																		f.layouts
																)
																.map(
																	( f ) =>
																		f.key
																)
												)
											}
										>
											{ collapsed.length
												? __(
														'Expand all',
														'freeform-fields'
													)
												: __(
														'Collapse all',
														'freeform-fields'
													) }
										</Button>
									) }
									{ ! readOnly && (
										<Button
											variant="secondary"
											size="compact"
											icon={ plus }
											onClick={ () => setTab( 'add' ) }
										>
											{ __(
												'Add field',
												'freeform-fields'
											) }
										</Button>
									) }
								</div>
							</div>
							<TreeContext.Provider value={ tree }>
								<div className="wpff-tree">
									<FieldList
										fields={ group.fields }
										path="fields"
									/>
								</div>
							</TreeContext.Provider>
						</section>

						<section className="wpff-panel">
							<div className="wpff-panel__header">
								<h2>{ __( 'Location', 'freeform-fields' ) }</h2>
							</div>
							<div className="wpff-panel__body">
								<LocationRules
									location={ group.location }
									onChange={ ( location ) =>
										set( { location } )
									}
								/>
							</div>
						</section>
					</fieldset>

					<aside
						ref={ inspectorRef }
						className="wpff-inspector"
						aria-label={ __( 'Settings', 'freeform-fields' ) }
					>
						<div className="wpff-inspector__tabs" role="tablist">
							{ [
								[ 'add', __( 'Add field', 'freeform-fields' ) ],
								[ 'field', __( 'Field', 'freeform-fields' ) ],
								[ 'group', __( 'Group', 'freeform-fields' ) ],
							].map( ( [ id, label ] ) => (
								<button
									key={ id }
									type="button"
									role="tab"
									aria-selected={ tab === id }
									className={ tab === id ? 'is-active' : '' }
									onClick={ () => setTab( id ) }
								>
									{ label }
								</button>
							) ) }
						</div>
						<div className="wpff-inspector__body">
							<fieldset
								disabled={ readOnly && 'add' !== tab }
								className="wpff-inspector__fieldset"
							>
								{ 'add' === tab && (
									<TypePalette
										onAdd={ ( type ) => addType( type ) }
										disabled={ readOnly }
									/>
								) }
								{ 'field' === tab &&
									( current ? (
										<FieldSettings
											key={ current.field.key }
											field={ current.field }
											path={ current.path }
											errors={ errors }
											siblings={ getAt(
												group,
												current.listPath
											) }
											onChange={ ( f ) =>
												update(
													replaceField(
														group,
														current.field.key,
														f
													)
												)
											}
										/>
									) : (
										<p className="wpff-inspector__empty">
											{ __(
												'Select a field in the list to edit its settings.',
												'freeform-fields'
											) }
										</p>
									) ) }
								{ 'group' === tab && (
									<GroupSettings
										group={ group }
										set={ set }
									/>
								) }
							</fieldset>
						</div>
					</aside>
				</div>
			</div>
			<DragOverlay dropAnimation={ null }>
				<DragPreview data={ dragging } />
			</DragOverlay>
		</DndContext>
	);
}

function GroupSettings( { group, set } ) {
	const select = ( label, prop, options, help ) => (
		<Select
			label={ label }
			value={ group[ prop ] }
			options={ options.map( ( [ value, text ] ) => ( {
				value,
				label: text,
			} ) ) }
			onChange={ ( value ) => set( { [ prop ]: value } ) }
			help={ help }
			__next40pxDefaultSize
			__nextHasNoMarginBottom
		/>
	);

	return (
		<div className="wpff-group-settings">
			<ToggleControl
				label={ __( 'Active', 'freeform-fields' ) }
				help={ __(
					'Inactive groups are not shown on edit screens.',
					'freeform-fields'
				) }
				checked={ !! group.active }
				onChange={ ( active ) => set( { active } ) }
				__nextHasNoMarginBottom
			/>
			<ToggleControl
				label={ __( 'Show in REST API', 'freeform-fields' ) }
				help={ __(
					'Add these fields to WordPress REST responses (property "acf").',
					'freeform-fields'
				) }
				checked={ !! Number( group.show_in_rest ) }
				onChange={ ( on ) => set( { show_in_rest: on ? 1 : 0 } ) }
				__nextHasNoMarginBottom
			/>
			<h3>{ __( 'Presentation', 'freeform-fields' ) }</h3>
			{ select( __( 'Position', 'freeform-fields' ), 'position', [
				[
					'acf_after_title',
					__( 'High (after title)', 'freeform-fields' ),
				],
				[ 'normal', __( 'Normal (after content)', 'freeform-fields' ) ],
				[ 'side', __( 'Side', 'freeform-fields' ) ],
			] ) }
			{ select( __( 'Style', 'freeform-fields' ), 'style', [
				[ 'default', __( 'Standard (meta box)', 'freeform-fields' ) ],
				[
					'seamless',
					__( 'Seamless (no meta box)', 'freeform-fields' ),
				],
			] ) }
			{ select(
				__( 'Label Placement', 'freeform-fields' ),
				'label_placement',
				[
					[ 'top', __( 'Top aligned', 'freeform-fields' ) ],
					[ 'left', __( 'Left aligned', 'freeform-fields' ) ],
				]
			) }
			{ select(
				__( 'Instruction Placement', 'freeform-fields' ),
				'instruction_placement',
				[
					[ 'label', __( 'Below labels', 'freeform-fields' ) ],
					[ 'field', __( 'Below fields', 'freeform-fields' ) ],
				]
			) }
			<NumberInput
				label={ __( 'Order No.', 'freeform-fields' ) }
				help={ __(
					'Groups with a lower number appear first.',
					'freeform-fields'
				) }
				value={ group.menu_order }
				onChange={ ( v ) => set( { menu_order: Number( v ) || 0 } ) }
				__next40pxDefaultSize
			/>
			<TextareaControl
				label={ __( 'Description', 'freeform-fields' ) }
				help={ __(
					'Shown in the field group list.',
					'freeform-fields'
				) }
				value={ group.description }
				onChange={ ( description ) => set( { description } ) }
				rows={ 2 }
				__nextHasNoMarginBottom
			/>
			<p className="wpff-field-settings__key">
				{ __( 'Group key:', 'freeform-fields' ) }{ ' ' }
				<code>{ group.key }</code>
			</p>
		</div>
	);
}
