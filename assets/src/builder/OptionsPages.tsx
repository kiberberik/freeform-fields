/**
 * Options pages: list and editor.
 */
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	Notice,
	Spinner,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { cog, plus } from '@wordpress/icons';
import { EmptyState, PageHeader, Pill } from './ui';
import { errorsFromResponse } from './api';
import { NumberInput } from './fieldsShared';
import Select from './Select';
import {
	IconPicker,
	MenuPreview,
	SettingsGrid,
	SettingsSection,
	SideCard,
	ToggleRows,
} from './settings';
import type { ComponentProps } from 'react';

/** An options page as the REST API returns it (OptionsPageStore / OptionsPages). */
interface OptionsPage {
	page_title: string;
	menu_title: string;
	menu_slug: string;
	parent_slug: string;
	capability: string;
	/** Number from the server (null when unset); '' or a string while editing. */
	position: number | string | null;
	icon_url: string;
	redirect: boolean;
	post_id: string;
	autoload: boolean;
	update_button: string;
	updated_message: string;
	description: string;
	active: boolean;
	source?: 'php' | 'acf' | 'db';
}

/** A row of the list: the page and the titles of the groups shown on it. */
interface OptionsPageRow extends OptionsPage {
	field_groups: string[];
}

interface NoticeState {
	status: 'success' | 'error' | 'warning';
	text: string;
}

/** Page settings edited with a plain text input. */
type TextProp = 'menu_title' | 'post_id' | 'update_button' | 'updated_message';

const base = () => `${ window.wpffBuilder!.restPath }/options-pages`;

/**
 * Slug like the server's sanitize_title() for latin text; Cyrillic is transliterated.
 *
 * @param text Title.
 * @return Slug.
 */
function slugify( text: string ): string {
	const map: Record< string, string > = {
		а: 'a',
		б: 'b',
		в: 'v',
		г: 'g',
		д: 'd',
		е: 'e',
		ё: 'e',
		ж: 'zh',
		з: 'z',
		и: 'i',
		й: 'y',
		к: 'k',
		л: 'l',
		м: 'm',
		н: 'n',
		о: 'o',
		п: 'p',
		р: 'r',
		с: 's',
		т: 't',
		у: 'u',
		ф: 'f',
		х: 'h',
		ц: 'ts',
		ч: 'ch',
		ш: 'sh',
		щ: 'sch',
		ъ: '',
		ы: 'y',
		ь: '',
		э: 'e',
		ю: 'yu',
		я: 'ya',
	};
	return String( text )
		.toLowerCase()
		.split( '' )
		.map( ( ch ) => ( ch in map ? map[ ch ] : ch ) )
		.join( '' )
		.replace( /[^a-z0-9]+/g, '-' )
		.replace( /^-+|-+$/g, '' );
}

export function OptionsPageList() {
	const cfg = window.wpffBuilder!;
	const [ pages, setPages ] = useState< OptionsPageRow[] | null >( null );
	const [ notice, setNotice ] = useState< NoticeState | null >( null );

	const load = () =>
		apiFetch< OptionsPageRow[] >( { path: base() } )
			.then( setPages )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: e.message } )
			);

	useEffect( () => {
		load();
	}, [] );

	const remove = ( page: OptionsPageRow ) => {
		if (
			// eslint-disable-next-line no-alert -- a native confirm is enough for this rare action.
			! window.confirm(
				sprintf(
					/* translators: %s: page title. */ __(
						'Delete the options page "%s"? Saved values are kept.',
						'freeform-fields'
					),
					page.page_title
				)
			)
		) {
			return;
		}
		apiFetch( {
			path: `${ base() }/${ page.menu_slug }`,
			method: 'DELETE',
		} )
			.then( () => {
				setNotice( {
					status: 'success',
					text: __( 'Options page deleted.', 'freeform-fields' ),
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
				title={ __( 'Options Pages', 'freeform-fields' ) }
				subtitle={ __(
					'An options page adds an admin menu item with fields for site-wide settings. Show a field group on it with the location rule "Options Page".',
					'freeform-fields'
				) }
				actions={
					<Button
						variant="primary"
						icon={ plus }
						href={ `${ cfg.optionsUrl }&options_page=new` }
						__next40pxDefaultSize
					>
						{ __( 'Add options page', 'freeform-fields' ) }
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
			{ null === pages && <Spinner /> }
			{ pages && 0 === pages.length && (
				<div className="wpff-panel">
					<EmptyState
						icon={ cog }
						title={ __(
							'No options pages yet',
							'freeform-fields'
						) }
						text={ __(
							'Use one for things like a phone number, social links or a footer text that the whole site shares.',
							'freeform-fields'
						) }
					>
						<Button
							variant="primary"
							icon={ plus }
							href={ `${ cfg.optionsUrl }&options_page=new` }
						>
							{ __( 'Add options page', 'freeform-fields' ) }
						</Button>
					</EmptyState>
				</div>
			) }
			{ pages && pages.length > 0 && (
				<div className="wpff-panel">
					<table className="wpff-table">
						<thead>
							<tr>
								<th className="column-primary">
									{ __( 'Title', 'freeform-fields' ) }
								</th>
								<th>{ __( 'Menu', 'freeform-fields' ) }</th>
								<th>
									{ __( 'Field groups', 'freeform-fields' ) }
								</th>
								<th className="wpff-col-narrow">
									{ __( 'Status', 'freeform-fields' ) }
								</th>
							</tr>
						</thead>
						<tbody>
							{ pages.map( ( page ) => {
								const isLocal =
									'php' === page.source ||
									'acf' === page.source;
								const editUrl = `${ cfg.optionsUrl }&options_page=${ page.menu_slug }`;
								return (
									<tr key={ page.menu_slug }>
										<td className="column-primary">
											<strong>
												{ isLocal ? (
													page.page_title
												) : (
													<a href={ editUrl }>
														{ page.page_title }
													</a>
												) }
												{ isLocal && (
													<span className="wpff-tag">
														{ __(
															'PHP',
															'freeform-fields'
														) }
													</span>
												) }
											</strong>
											<div className="row-actions visible">
												{ isLocal ? (
													<span>
														{ __(
															'Registered in code (read-only)',
															'freeform-fields'
														) }
													</span>
												) : (
													<>
														<a href={ editUrl }>
															{ __(
																'Edit',
																'freeform-fields'
															) }
														</a>{ ' ' }
														|{ ' ' }
														<Button
															variant="link"
															isDestructive
															onClick={ () =>
																remove( page )
															}
														>
															{ __(
																'Delete',
																'freeform-fields'
															) }
														</Button>
													</>
												) }
											</div>
										</td>
										<td>
											<code>{ page.menu_slug }</code>
											{ page.parent_slug && (
												<span className="description">
													{ ' ' }
													← { page.parent_slug }
												</span>
											) }
										</td>
										<td>
											{ page.field_groups.length
												? page.field_groups.join( ', ' )
												: '—' }
										</td>
										<td className="wpff-col-narrow">
											{ page.active ? (
												<Pill tone="success">
													{ __(
														'Active',
														'freeform-fields'
													) }
												</Pill>
											) : (
												<Pill>
													{ __(
														'Inactive',
														'freeform-fields'
													) }
												</Pill>
											) }
										</td>
									</tr>
								);
							} ) }
						</tbody>
					</table>
				</div>
			) }
		</div>
	);
}

const EMPTY_PAGE: OptionsPage = {
	page_title: '',
	menu_title: '',
	menu_slug: '',
	parent_slug: '',
	capability: 'edit_posts',
	position: '',
	icon_url: 'dashicons-admin-generic',
	redirect: true,
	post_id: 'options',
	autoload: false,
	update_button: '',
	updated_message: '',
	description: '',
	active: true,
};

interface OptionsPageEditorProps {
	/** Menu slug of the page, or 'new'. */
	slug: string;
}

export function OptionsPageEditor( { slug }: OptionsPageEditorProps ) {
	const cfg = window.wpffBuilder!;
	const isNew = 'new' === slug;
	const [ page, setPage ] = useState< OptionsPage | null >(
		isNew ? { ...EMPTY_PAGE } : null
	);
	const [ currentSlug, setCurrentSlug ] = useState( isNew ? '' : slug );
	const [ slugTouched, setSlugTouched ] = useState( ! isNew );
	const [ errors, setErrors ] = useState< Record< string, string > >( {} );
	const [ notice, setNotice ] = useState< NoticeState | null >( null );
	const [ saving, setSaving ] = useState( false );

	useEffect( () => {
		if ( ! isNew ) {
			apiFetch< OptionsPage >( { path: `${ base() }/${ slug }` } )
				.then( ( data ) => {
					if ( 'php' === data.source || 'acf' === data.source ) {
						setNotice( {
							status: 'warning',
							text: __(
								'This options page is registered in code and cannot be edited here.',
								'freeform-fields'
							),
						} );
					}
					setPage( { ...data, position: data.position ?? '' } );
				} )
				.catch( ( e ) =>
					setNotice( { status: 'error', text: e.message } )
				);
		}
	}, [ slug, isNew ] );

	if ( ! page ) {
		return notice ? (
			<Notice status={ notice.status }>{ notice.text }</Notice>
		) : (
			<Spinner />
		);
	}

	const set = ( patch: Partial< OptionsPage > ) =>
		setPage( { ...page, ...patch } );
	const readOnly = 'php' === page.source;

	const save = () => {
		setSaving( true );
		setErrors( {} );
		setNotice( null );
		const request = currentSlug
			? apiFetch< OptionsPage >( {
					path: `${ base() }/${ currentSlug }`,
					method: 'PUT',
					data: page,
				} )
			: apiFetch< OptionsPage >( {
					path: base(),
					method: 'POST',
					data: page,
				} );
		request
			.then( ( data ) => {
				setPage( { ...data, position: data.position ?? '' } );
				setCurrentSlug( data.menu_slug );
				setSlugTouched( true );
				window.history.replaceState(
					null,
					'',
					`${ cfg.optionsUrl }&options_page=${ data.menu_slug }`
				);
				setNotice( {
					status: 'success',
					text: __(
						'Options page saved. Reload the admin to see menu changes.',
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
		extra: Partial< ComponentProps< typeof TextControl > > = {}
	) => (
		<TextControl
			label={ label }
			value={ page[ prop ] ?? '' }
			onChange={ ( value ) => set( { [ prop ]: value } ) }
			help={ errors[ prop ] || help }
			className={ errors[ prop ] ? 'has-error' : '' }
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			{ ...extra }
		/>
	);

	return (
		<div className={ `wpff-editor${ readOnly ? ' is-readonly' : '' }` }>
			<PageHeader
				back={ cfg.optionsUrl }
				backLabel={ __( 'Options Pages', 'freeform-fields' ) }
				title={
					currentSlug
						? __( 'Edit Options Page', 'freeform-fields' )
						: __( 'New Options Page', 'freeform-fields' )
				}
				actions={
					<>
						{ currentSlug && page.active && (
							<Button
								variant="secondary"
								href={ `admin.php?page=${ currentSlug }` }
								__next40pxDefaultSize
							>
								{ __( 'Open page', 'freeform-fields' ) }
							</Button>
						) }
						<Button
							variant="primary"
							onClick={ save }
							isBusy={ saving }
							disabled={ saving || readOnly }
							__next40pxDefaultSize
						>
							{ __( 'Save changes', 'freeform-fields' ) }
						</Button>
					</>
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
			<fieldset disabled={ readOnly } className="wpff-editor__body">
				<div className="wpff-settings">
					<div className="wpff-settings__main">
						<SettingsSection
							title={ __( 'Page', 'freeform-fields' ) }
							description={ __(
								'The title of the page and its address in the admin.',
								'freeform-fields'
							) }
						>
							<SettingsGrid>
								<TextControl
									label={ __(
										'Page Title',
										'freeform-fields'
									) }
									value={ page.page_title }
									onChange={ ( title ) =>
										set(
											slugTouched
												? { page_title: title }
												: {
														page_title: title,
														menu_slug:
															slugify( title ),
													}
										)
									}
									help={ errors.page_title }
									className={
										errors.page_title ? 'has-error' : ''
									}
									__next40pxDefaultSize
									__nextHasNoMarginBottom
								/>
								{ text(
									__( 'Menu Title', 'freeform-fields' ),
									'menu_title',
									__(
										'Defaults to the page title.',
										'freeform-fields'
									)
								) }
								<TextControl
									label={ __(
										'Menu Slug',
										'freeform-fields'
									) }
									value={ page.menu_slug }
									onChange={ ( value ) => {
										setSlugTouched( true );
										set( { menu_slug: slugify( value ) } );
									} }
									help={
										errors.menu_slug ||
										__(
											'Used in the admin URL and in the "Options Page" location rule.',
											'freeform-fields'
										)
									}
									className={
										errors.menu_slug ? 'has-error' : ''
									}
									__next40pxDefaultSize
									__nextHasNoMarginBottom
								/>
							</SettingsGrid>
							<TextareaControl
								label={ __( 'Description', 'freeform-fields' ) }
								value={ page.description }
								onChange={ ( description ) =>
									set( { description } )
								}
								rows={ 2 }
								__nextHasNoMarginBottom
							/>
						</SettingsSection>

						<SettingsSection
							title={ __( 'Admin menu', 'freeform-fields' ) }
							description={ __(
								'Where the page appears: its own menu item or a sub item of another menu.',
								'freeform-fields'
							) }
						>
							<SettingsGrid>
								<Select
									label={ __(
										'Parent Menu',
										'freeform-fields'
									) }
									value={ page.parent_slug }
									options={ Object.entries(
										cfg.options.parents
									)
										.filter(
											( [ value ] ) =>
												value !== currentSlug
										)
										.map( ( [ value, label ] ) => ( {
											value,
											label,
										} ) ) }
									onChange={ ( parent ) =>
										set( { parent_slug: parent } )
									}
									help={ errors.parent_slug }
									__next40pxDefaultSize
									__nextHasNoMarginBottom
								/>
								<NumberInput
									label={ __(
										'Menu Position',
										'freeform-fields'
									) }
									value={ page.position }
									onChange={ ( position ) =>
										set( { position } )
									}
									__next40pxDefaultSize
								/>
								{ ! page.parent_slug && (
									<IconPicker
										label={ __(
											'Menu Icon',
											'freeform-fields'
										) }
										value={ page.icon_url }
										onChange={ ( icon ) =>
											set( { icon_url: icon } )
										}
										help={
											errors.icon_url ||
											__(
												'Pick an icon, or type a dashicons class or an image URL.',
												'freeform-fields'
											)
										}
										allowUrl
									/>
								) }
							</SettingsGrid>
							{ ! page.parent_slug && (
								<ToggleRows
									items={ [
										{
											label: __(
												'Open first sub page',
												'freeform-fields'
											),
											help: __(
												'When the page has sub pages, its menu item opens the first one.',
												'freeform-fields'
											),
											checked: page.redirect,
											onChange: ( redirect ) =>
												set( { redirect } ),
										},
									] }
								/>
							) }
						</SettingsSection>

						<SettingsSection
							title={ __(
								'Access and storage',
								'freeform-fields'
							) }
							description={ __(
								'Who can change the values and where they are saved.',
								'freeform-fields'
							) }
						>
							<SettingsGrid>
								<Select
									label={ __(
										'Who can edit',
										'freeform-fields'
									) }
									value={ page.capability }
									options={ Object.entries(
										cfg.options.capabilities
									).map( ( [ value, label ] ) => ( {
										value,
										label,
									} ) ) }
									onChange={ ( capability ) =>
										set( { capability } )
									}
									__next40pxDefaultSize
									__nextHasNoMarginBottom
								/>
								{ text(
									__(
										'Storage key (post_id)',
										'freeform-fields'
									),
									'post_id',
									__(
										'Values are read with get_field( name, this key ). Default: options.',
										'freeform-fields'
									)
								) }
							</SettingsGrid>
							<ToggleRows
								items={ [
									{
										label: __(
											'Autoload values',
											'freeform-fields'
										),
										help: __(
											'Load values with every request. Use for small settings needed on most pages.',
											'freeform-fields'
										),
										checked: page.autoload,
										onChange: ( autoload ) =>
											set( { autoload } ),
									},
								] }
							/>
						</SettingsSection>

						<SettingsSection
							title={ __( 'Saving', 'freeform-fields' ) }
							description={ __(
								'The save button and the message after saving.',
								'freeform-fields'
							) }
						>
							<SettingsGrid>
								{ text(
									__(
										'Update Button Label',
										'freeform-fields'
									),
									'update_button',
									__( 'Default: Update', 'freeform-fields' )
								) }
								{ text(
									__( 'Updated Message', 'freeform-fields' ),
									'updated_message',
									__(
										'Default: Options Updated',
										'freeform-fields'
									)
								) }
							</SettingsGrid>
						</SettingsSection>
					</div>

					<aside className="wpff-settings__side">
						<SideCard title={ __( 'Status', 'freeform-fields' ) }>
							<ToggleControl
								label={ __( 'Active', 'freeform-fields' ) }
								help={ __(
									'Inactive pages are not added to the menu.',
									'freeform-fields'
								) }
								checked={ !! page.active }
								onChange={ ( active ) => set( { active } ) }
								__nextHasNoMarginBottom
							/>
							{ page.menu_slug && (
								<p className="wpff-side-card__meta">
									{ __( 'Slug:', 'freeform-fields' ) }{ ' ' }
									<code>{ page.menu_slug }</code>
								</p>
							) }
						</SideCard>
						<SideCard
							title={ __(
								'In the admin menu',
								'freeform-fields'
							) }
						>
							<MenuPreview
								icon={ page.icon_url }
								label={ page.menu_title || page.page_title }
								under={
									page.parent_slug
										? cfg.options.parents[
												page.parent_slug
											] || page.parent_slug
										: ''
								}
							/>
						</SideCard>
					</aside>
				</div>
			</fieldset>
		</div>
	);
}
