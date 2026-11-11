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
import {
	IconPicker,
	MenuPreview,
	SettingsGrid,
	SettingsSection,
	SideCard,
	ToggleRows,
} from './settings';
import type { Choices } from '../types';

/**
 * A post type or taxonomy as the REST API returns it (Content\ContentTypes).
 * Which of the optional settings are present depends on the kind.
 */
interface ContentItem {
	key: string;
	singular: string;
	plural: string;
	description: string;
	public: boolean;
	hierarchical: boolean;
	show_in_rest: boolean;
	rewrite_slug: string;
	active: boolean;
	// Post types.
	menu_icon?: string;
	/** Number from the server (null when unset); '' or a string while editing. */
	menu_position?: number | string | null;
	supports?: string[];
	taxonomies?: string[];
	has_archive?: boolean;
	exclude_from_search?: boolean;
	// Taxonomies.
	object_type?: string[];
	show_admin_column?: boolean;
}

/** A row of the list: the item and its number of posts or terms. */
interface ContentItemRow extends ContentItem {
	count: number;
}

interface NoticeState {
	status: 'success' | 'error';
	text: string;
}

/** Settings edited with a plain text input. */
type TextProp = 'plural' | 'singular' | 'key' | 'rewrite_slug';

const data = () => window.wpffBuilder!.content;
const path = ( key = '' ) =>
	`${ window.wpffBuilder!.restPath }/content-types/${ data().kind }${
		key ? `/${ key }` : ''
	}`;
const isPostType = () => 'post_type' === data().kind;

export function ContentTypeList() {
	const cfg = data();
	const [ items, setItems ] = useState< ContentItemRow[] | null >( null );
	const [ notice, setNotice ] = useState< NoticeState | null >( null );

	const load = () =>
		apiFetch< ContentItemRow[] >( { path: path() } ).then( setItems );
	useEffect( () => {
		load();
	}, [] );

	const remove = ( item: ContentItemRow ) => {
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
											? item.taxonomies!
											: item.object_type!
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

const NEW_ITEM = (): ContentItem =>
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

interface ChecklistProps {
	label?: string;
	options: Choices;
	value: string[];
	onChange: ( value: string[] ) => void;
}

function Checklist( { label, options, value, onChange }: ChecklistProps ) {
	return (
		<fieldset className="wpff-checklist">
			{ label && (
				<legend className="wpff-checklist__legend">{ label }</legend>
			) }
			<div className="wpff-checklist__options">
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

function editorTitle( current: string ) {
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
	const [ item, setItem ] = useState< ContentItem | null >(
		isNew ? NEW_ITEM() : null
	);
	const [ current, setCurrent ] = useState( isNew ? '' : cfg.item );
	const [ keyTouched, setKeyTouched ] = useState( ! isNew );
	const [ errors, setErrors ] = useState< Record< string, string > >( {} );
	const [ notice, setNotice ] = useState< NoticeState | null >( null );
	const [ saving, setSaving ] = useState( false );

	useEffect( () => {
		if ( ! isNew ) {
			apiFetch< ContentItem >( { path: path( cfg.item ) } )
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
	const set = ( patch: Partial< ContentItem > ) =>
		setItem( { ...item, ...patch } );

	const save = () => {
		setSaving( true );
		setErrors( {} );
		setNotice( null );
		const request = current
			? apiFetch< ContentItem >( {
					path: path( current ),
					method: 'PUT',
					data: item,
				} )
			: apiFetch< ContentItem >( {
					path: path(),
					method: 'POST',
					data: item,
				} );
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

	const text = (
		label: string,
		prop: TextProp,
		help: string,
		onChange?: ( value: string ) => void
	) => (
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
			<div className="wpff-settings">
				<div className="wpff-settings__main">
					<SettingsSection
						title={ __( 'Names', 'freeform-fields' ) }
						description={
							isPostType()
								? __(
										'How the post type is called in the admin, and its key in code and URLs.',
										'freeform-fields'
									)
								: __(
										'How the taxonomy is called in the admin, and its key in code and URLs.',
										'freeform-fields'
									)
						}
					>
						<SettingsGrid>
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
						</SettingsGrid>
						<TextareaControl
							label={ __( 'Description', 'freeform-fields' ) }
							value={ item.description }
							onChange={ ( d ) => set( { description: d } ) }
							rows={ 2 }
							__nextHasNoMarginBottom
						/>
					</SettingsSection>

					{ isPostType() && (
						<SettingsSection
							title={ __( 'Admin menu', 'freeform-fields' ) }
							description={ __(
								'The icon and place of the post type in the admin menu.',
								'freeform-fields'
							) }
						>
							<SettingsGrid>
								<IconPicker
									label={ __(
										'Menu Icon',
										'freeform-fields'
									) }
									value={ item.menu_icon }
									onChange={ ( icon ) =>
										set( { menu_icon: icon } )
									}
									help={
										errors.menu_icon ||
										__(
											'Pick an icon or type a dashicons class.',
											'freeform-fields'
										)
									}
								/>
								<NumberInput
									label={ __(
										'Menu Position',
										'freeform-fields'
									) }
									value={ item.menu_position }
									onChange={ ( v ) =>
										set( { menu_position: v } )
									}
									help={ __(
										'5 — below Posts, 20 — below Pages, 60 — below Appearance. Empty: at the end.',
										'freeform-fields'
									) }
									__next40pxDefaultSize
								/>
							</SettingsGrid>
						</SettingsSection>
					) }

					<SettingsSection
						title={ __( 'Behavior', 'freeform-fields' ) }
						description={ __(
							'Where it appears and how it works.',
							'freeform-fields'
						) }
					>
						<ToggleRows
							items={ [
								{
									label: __( 'Public', 'freeform-fields' ),
									help: __(
										'Visible on the site and in the admin.',
										'freeform-fields'
									),
									checked: item.public,
									onChange: ( on ) => set( { public: on } ),
								},
								{
									label: __(
										'Hierarchical',
										'freeform-fields'
									),
									help: isPostType()
										? __(
												'Like pages (parents and children).',
												'freeform-fields'
											)
										: __(
												'Like categories (parents and children).',
												'freeform-fields'
											),
									checked: item.hierarchical,
									onChange: ( on ) =>
										set( { hierarchical: on } ),
								},
								{
									label: __(
										'Show in REST API',
										'freeform-fields'
									),
									help: __(
										'Needed for the block editor.',
										'freeform-fields'
									),
									checked: item.show_in_rest,
									onChange: ( on ) =>
										set( { show_in_rest: on } ),
								},
								{
									hidden: ! isPostType(),
									label: __( 'Archive', 'freeform-fields' ),
									help: __(
										'A page listing all items.',
										'freeform-fields'
									),
									checked: item.has_archive,
									onChange: ( on ) =>
										set( { has_archive: on } ),
								},
								{
									hidden: ! isPostType(),
									label: __(
										'Exclude from Search',
										'freeform-fields'
									),
									help: __(
										'Items do not appear in site search results.',
										'freeform-fields'
									),
									checked: item.exclude_from_search,
									onChange: ( on ) =>
										set( { exclude_from_search: on } ),
								},
								{
									hidden: isPostType(),
									label: __(
										'Admin Column',
										'freeform-fields'
									),
									help: __(
										'Show the terms in post lists.',
										'freeform-fields'
									),
									checked: item.show_admin_column,
									onChange: ( on ) =>
										set( { show_admin_column: on } ),
								},
							] }
						/>
					</SettingsSection>

					{ isPostType() ? (
						<>
							<SettingsSection
								title={ __( 'Supports', 'freeform-fields' ) }
								description={ __(
									'Parts of the edit screen.',
									'freeform-fields'
								) }
							>
								<Checklist
									options={ cfg.supports }
									value={ item.supports! }
									onChange={ ( v ) => set( { supports: v } ) }
								/>
							</SettingsSection>
							<SettingsSection
								title={ __( 'Taxonomies', 'freeform-fields' ) }
								description={ __(
									'Taxonomies used to sort the items.',
									'freeform-fields'
								) }
							>
								<Checklist
									options={ cfg.taxonomies }
									value={ item.taxonomies! }
									onChange={ ( v ) =>
										set( { taxonomies: v } )
									}
								/>
							</SettingsSection>
						</>
					) : (
						<SettingsSection
							title={ __( 'Post Types', 'freeform-fields' ) }
							description={ __(
								'Post types the terms can be assigned to.',
								'freeform-fields'
							) }
						>
							<Checklist
								options={ cfg.postTypes }
								value={ item.object_type! }
								onChange={ ( v ) => set( { object_type: v } ) }
							/>
						</SettingsSection>
					) }
				</div>

				<aside className="wpff-settings__side">
					<SideCard title={ __( 'Status', 'freeform-fields' ) }>
						<ToggleControl
							label={ __( 'Active', 'freeform-fields' ) }
							help={ __(
								'Inactive ones are not registered.',
								'freeform-fields'
							) }
							checked={ !! item.active }
							onChange={ ( on ) => set( { active: on } ) }
							__nextHasNoMarginBottom
						/>
						{ item.key && (
							<p className="wpff-side-card__meta">
								{ __( 'Key:', 'freeform-fields' ) }{ ' ' }
								<code>{ item.key }</code>
							</p>
						) }
					</SideCard>
					<SideCard
						title={ __( 'In the admin menu', 'freeform-fields' ) }
					>
						<MenuPreview
							icon={ item.menu_icon }
							label={ item.plural }
							under={
								isPostType()
									? ''
									: cfg.menuNames?.[
											item.object_type![ 0 ]
										] || __( 'Posts', 'freeform-fields' )
							}
						/>
					</SideCard>
				</aside>
			</div>
		</div>
	);
}
