/**
 * Post types and taxonomies: list and editor.
 */
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	CheckboxControl,
	Notice,
	Spinner,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { plus, post as postIcon, tag as tagIcon } from '@wordpress/icons';
import { EmptyState, PageHeader } from './ui';
import { errorsFromResponse } from './api';
import { NumberInput } from './fieldsShared';
import { nameFromLabel } from './utils';

const data = () => window.wpffBuilder.content;
const path = ( key = '' ) =>
	`${ window.wpffBuilder.restPath }/content-types/${ data().kind }${
		key ? `/${ key }` : ''
	}`;
const isPostType = () => 'post_type' === data().kind;

export function ContentTypeList() {
	const cfg = data();
	const [ items, setItems ] = useState( null );
	const [ notice, setNotice ] = useState( null );

	const load = () => apiFetch( { path: path() } ).then( setItems );
	useEffect( () => {
		load();
	}, [] );

	const remove = ( item ) => {
		const question = isPostType()
			? /* translators: %s: label. */ __(
					'Delete the post type "%s"? Its posts stay in the database but are hidden until it is registered again.',
					'freeform-fields'
			  )
			: /* translators: %s: label. */ __(
					'Delete the taxonomy "%s"? Its terms stay in the database but are hidden until it is registered again.',
					'freeform-fields'
			  );
		// eslint-disable-next-line no-alert -- a native confirm is enough for this rare action.
		if ( ! window.confirm( sprintf( question, item.plural ) ) ) {
			return;
		}
		apiFetch( { path: path( item.key ), method: 'DELETE' } )
			.then( () => {
				setNotice( {
					status: 'success',
					text: __( 'Deleted.', 'freeform-fields' ),
				} );
				load();
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: e.message } )
			);
	};

	return (
		<div className="wpff-list">
			<PageHeader
				title={
					isPostType()
						? __( 'Post Types', 'freeform-fields' )
						: __( 'Taxonomies', 'freeform-fields' )
				}
				subtitle={
					isPostType()
						? __(
								'Content types like Products or Events, each with its own admin menu.',
								'freeform-fields'
						  )
						: __(
								'Ways to group content, like Genres or Locations.',
								'freeform-fields'
						  )
				}
				actions={
					<Button
						variant="primary"
						icon={ plus }
						href={ `${ cfg.listUrl }&item=new` }
						__next40pxDefaultSize
					>
						{ isPostType()
							? __( 'Add post type', 'freeform-fields' )
							: __( 'Add taxonomy', 'freeform-fields' ) }
					</Button>
				}
			/>
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.text }
				</Notice>
			) }
			{ null === items && <Spinner /> }
			{ items && 0 === items.length && (
				<div className="wpff-panel">
					<EmptyState
						icon={ isPostType() ? postIcon : tagIcon }
						title={
							isPostType()
								? __( 'No post types yet', 'freeform-fields' )
								: __( 'No taxonomies yet', 'freeform-fields' )
						}
					>
						<Button
							variant="primary"
							icon={ plus }
							href={ `${ cfg.listUrl }&item=new` }
						>
							{ isPostType()
								? __( 'Add post type', 'freeform-fields' )
								: __( 'Add taxonomy', 'freeform-fields' ) }
						</Button>
					</EmptyState>
				</div>
			) }
			{ items && items.length > 0 && (
				<div className="wpff-panel">
					<table className="wpff-table">
						<thead>
							<tr>
								<th className="column-primary">
									{ __( 'Label', 'freeform-fields' ) }
								</th>
								<th>{ __( 'Key', 'freeform-fields' ) }</th>
								<th>
									{ isPostType()
										? __( 'Taxonomies', 'freeform-fields' )
										: __(
												'Post Types',
												'freeform-fields'
										  ) }
								</th>
								<th className="wpff-col-narrow">
									{ isPostType()
										? __( 'Posts', 'freeform-fields' )
										: __( 'Terms', 'freeform-fields' ) }
								</th>
								<th className="wpff-col-narrow">
									{ __( 'Status', 'freeform-fields' ) }
								</th>
							</tr>
						</thead>
						<tbody>
							{ items.map( ( item ) => (
								<tr key={ item.key }>
									<td className="column-primary">
										<strong>
											<a
												href={ `${ cfg.listUrl }&item=${ item.key }` }
											>
												{ item.plural }
											</a>
										</strong>
										<div className="row-actions visible">
											<a
												href={ `${ cfg.listUrl }&item=${ item.key }` }
											>
												{ __(
													'Edit',
													'freeform-fields'
												) }
											</a>{ ' ' }
											|{ ' ' }
											<Button
												variant="link"
												isDestructive
												onClick={ () => remove( item ) }
											>
												{ __(
													'Delete',
													'freeform-fields'
												) }
											</Button>
										</div>
									</td>
									<td>
										<code>{ item.key }</code>
									</td>
									<td>
										{ ( isPostType()
											? item.taxonomies
											: item.object_type
										).join( ', ' ) || '—' }
									</td>
									<td className="wpff-col-narrow">
										{ item.count }
									</td>
									<td className="wpff-col-narrow">
										{ item.active ? (
											__( 'Active', 'freeform-fields' )
										) : (
											<em>
												{ __(
													'Inactive',
													'freeform-fields'
												) }
											</em>
										) }
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) }
		</div>
	);
}

const NEW_ITEM = () =>
	isPostType()
		? {
				key: '',
				singular: '',
				plural: '',
				description: '',
				public: true,
				hierarchical: false,
				show_in_rest: true,
				rewrite_slug: '',
				menu_icon: 'dashicons-admin-post',
				menu_position: '',
				supports: [ 'title', 'editor', 'thumbnail' ],
				taxonomies: [],
				has_archive: false,
				exclude_from_search: false,
				active: true,
		  }
		: {
				key: '',
				singular: '',
				plural: '',
				description: '',
				public: true,
				hierarchical: false,
				show_in_rest: true,
				rewrite_slug: '',
				object_type: [ 'post' ],
				show_admin_column: true,
				active: true,
		  };

function Checklist( { label, options, value, onChange } ) {
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
						checked={ value.includes( key ) }
						onChange={ ( on ) =>
							onChange(
								on
									? [ ...value, key ]
									: value.filter( ( k ) => k !== key )
							)
						}
						__nextHasNoMarginBottom
					/>
				) ) }
			</div>
		</fieldset>
	);
}

function editorTitle( current ) {
	if ( isPostType() ) {
		return current
			? __( 'Edit Post Type', 'freeform-fields' )
			: __( 'Add Post Type', 'freeform-fields' );
	}
	return current
		? __( 'Edit Taxonomy', 'freeform-fields' )
		: __( 'Add Taxonomy', 'freeform-fields' );
}

export function ContentTypeEditor() {
	const cfg = data();
	const isNew = 'new' === cfg.item;
	const [ item, setItem ] = useState( isNew ? NEW_ITEM() : null );
	const [ current, setCurrent ] = useState( isNew ? '' : cfg.item );
	const [ keyTouched, setKeyTouched ] = useState( ! isNew );
	const [ errors, setErrors ] = useState( {} );
	const [ notice, setNotice ] = useState( null );
	const [ saving, setSaving ] = useState( false );

	useEffect( () => {
		if ( ! isNew ) {
			apiFetch( { path: path( cfg.item ) } )
				.then( ( loaded ) =>
					setItem( {
						...loaded,
						menu_position: loaded.menu_position ?? '',
					} )
				)
				.catch( ( e ) =>
					setNotice( { status: 'error', text: e.message } )
				);
		}
	}, [ isNew, cfg.item ] );

	if ( ! item ) {
		return notice ? (
			<Notice status="error">{ notice.text }</Notice>
		) : (
			<Spinner />
		);
	}
	const set = ( patch ) => setItem( { ...item, ...patch } );

	const save = () => {
		setSaving( true );
		setErrors( {} );
		setNotice( null );
		const request = current
			? apiFetch( { path: path( current ), method: 'PUT', data: item } )
			: apiFetch( { path: path(), method: 'POST', data: item } );
		request
			.then( ( saved ) => {
				setItem( {
					...saved,
					menu_position: saved.menu_position ?? '',
				} );
				setCurrent( saved.key );
				setKeyTouched( true );
				window.history.replaceState(
					null,
					'',
					`${ cfg.listUrl }&item=${ saved.key }`
				);
				setNotice( {
					status: 'success',
					text: __(
						'Saved. Reload the admin to see it in the menu.',
						'freeform-fields'
					),
				} );
			} )
			.catch( ( e ) => {
				setErrors( errorsFromResponse( e ) );
				setNotice( { status: 'error', text: e.message } );
			} )
			.finally( () => setSaving( false ) );
	};

	const text = ( label, prop, help, onChange ) => (
		<TextControl
			label={ label }
			value={ item[ prop ] ?? '' }
			onChange={ onChange || ( ( value ) => set( { [ prop ]: value } ) ) }
			help={ errors[ prop ] || help }
			className={ errors[ prop ] ? 'has-error' : '' }
			__next40pxDefaultSize
			__nextHasNoMarginBottom
		/>
	);
	const toggle = ( label, prop, help ) => (
		<ToggleControl
			label={ label }
			help={ help }
			checked={ !! item[ prop ] }
			onChange={ ( on ) => set( { [ prop ]: on } ) }
			__nextHasNoMarginBottom
		/>
	);

	return (
		<div className="wpff-editor">
			<PageHeader
				back={ cfg.listUrl }
				backLabel={
					isPostType()
						? __( 'Post Types', 'freeform-fields' )
						: __( 'Taxonomies', 'freeform-fields' )
				}
				title={ editorTitle( current ) }
				actions={
					<Button
						variant="primary"
						onClick={ save }
						isBusy={ saving }
						disabled={ saving }
						__next40pxDefaultSize
					>
						{ __( 'Save changes', 'freeform-fields' ) }
					</Button>
				}
			/>
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.text }
				</Notice>
			) }
			<div className="wpff-panel">
				<div className="wpff-panel__body">
					<div className="wpff-field-settings__grid">
						{ text(
							__( 'Plural Label', 'freeform-fields' ),
							'plural',
							__( 'e.g. Books', 'freeform-fields' ),
							( plural ) =>
								set(
									keyTouched
										? { plural }
										: {
												plural,
												key: nameFromLabel(
													plural
												).slice(
													0,
													isPostType() ? 20 : 32
												),
										  }
								)
						) }
						{ text(
							__( 'Singular Label', 'freeform-fields' ),
							'singular',
							__( 'e.g. Book', 'freeform-fields' )
						) }
						{ text(
							isPostType()
								? __( 'Post Type Key', 'freeform-fields' )
								: __( 'Taxonomy Key', 'freeform-fields' ),
							'key',
							isPostType()
								? __(
										'Up to 20 characters: a–z, 0–9, _ and -. Changing it hides existing posts.',
										'freeform-fields'
								  )
								: __(
										'Up to 32 characters: a–z, 0–9, _ and -.',
										'freeform-fields'
								  ),
							( key ) => {
								setKeyTouched( true );
								set( {
									key: key
										.toLowerCase()
										.replace( /[^a-z0-9_-]/g, '' ),
								} );
							}
						) }
						{ text(
							__( 'URL Slug', 'freeform-fields' ),
							'rewrite_slug',
							__( 'Defaults to the key.', 'freeform-fields' )
						) }
						{ isPostType() &&
							text(
								__( 'Menu Icon', 'freeform-fields' ),
								'menu_icon',
								__(
									'A dashicons class, e.g. dashicons-book.',
									'freeform-fields'
								)
							) }
						{ isPostType() && (
							<NumberInput
								label={ __(
									'Menu Position',
									'freeform-fields'
								) }
								value={ item.menu_position }
								onChange={ ( v ) =>
									set( { menu_position: v } )
								}
								__next40pxDefaultSize
							/>
						) }
						<TextareaControl
							label={ __( 'Description', 'freeform-fields' ) }
							value={ item.description }
							onChange={ ( d ) => set( { description: d } ) }
							rows={ 2 }
							__nextHasNoMarginBottom
						/>
					</div>
					<div className="wpff-field-settings__grid">
						{ toggle(
							__( 'Active', 'freeform-fields' ),
							'active'
						) }
						{ toggle(
							__( 'Public', 'freeform-fields' ),
							'public',
							__(
								'Visible on the site and in the admin.',
								'freeform-fields'
							)
						) }
						{ toggle(
							__( 'Hierarchical', 'freeform-fields' ),
							'hierarchical',
							isPostType()
								? __(
										'Like pages (parents and children).',
										'freeform-fields'
								  )
								: __(
										'Like categories (parents and children).',
										'freeform-fields'
								  )
						) }
						{ toggle(
							__( 'Show in REST API', 'freeform-fields' ),
							'show_in_rest',
							__(
								'Needed for the block editor.',
								'freeform-fields'
							)
						) }
						{ isPostType() &&
							toggle(
								__( 'Archive', 'freeform-fields' ),
								'has_archive',
								__(
									'A page listing all items.',
									'freeform-fields'
								)
							) }
						{ isPostType() &&
							toggle(
								__( 'Exclude from Search', 'freeform-fields' ),
								'exclude_from_search'
							) }
						{ ! isPostType() &&
							toggle(
								__( 'Admin Column', 'freeform-fields' ),
								'show_admin_column',
								__(
									'Show the terms in post lists.',
									'freeform-fields'
								)
							) }
					</div>
					<div className="wpff-field-settings__grid">
						{ isPostType() ? (
							<>
								<Checklist
									label={ __(
										'Supports',
										'freeform-fields'
									) }
									options={ cfg.supports }
									value={ item.supports }
									onChange={ ( v ) => set( { supports: v } ) }
								/>
								<Checklist
									label={ __(
										'Taxonomies',
										'freeform-fields'
									) }
									options={ cfg.taxonomies }
									value={ item.taxonomies }
									onChange={ ( v ) =>
										set( { taxonomies: v } )
									}
								/>
							</>
						) : (
							<Checklist
								label={ __( 'Post Types', 'freeform-fields' ) }
								options={ cfg.postTypes }
								value={ item.object_type }
								onChange={ ( v ) => set( { object_type: v } ) }
							/>
						) }
					</div>
				</div>
			</div>
		</div>
	);
}
