/**
 * WP Freeform Fields admin: field inputs, conditional logic, layout fields and pre-save validation.
 *
 * Works on every screen that renders WP Freeform Fields fields (post, term, user). Settings come from
 * `window.wpffAdmin` (see Admin\Assets::enqueue_form()).
 */
import $ from 'jquery';
import { __, sprintf } from '@wordpress/i18n';
import type { AdminSettings } from '../globals';

/* =====================================================================
 * Types of the runtime globals and of the data kept on DOM nodes
 * ================================================================== */

/** One rule of a conditional logic group, as printed in `data-conditions`. */
interface ConditionRule {
	field: string;
	operator: string;
	value: unknown;
}

/** A tab: its button, the fields after it and the tab field itself. */
interface TabPanel {
	button: TabButton;
	fields: HTMLElement[];
	tabField: HTMLElement;
}

/** Tab bar; keeps its panels on the element. */
type TabNav = HTMLDivElement & { _panels: TabPanel[] };

/** Any `.wpff-tabs` element: only bars built by initTabs() carry their panels. */
type MaybeTabNav = HTMLElement & { _panels?: TabPanel[] };

/** Tab button; knows its panel and its tab bar. */
type TabButton = HTMLButtonElement & { _panel: TabPanel; _nav: TabNav };

/** Media library attachment as returned by `wp.media` selections. */
interface Attachment {
	id: number;
	url: string;
	filename?: string;
	alt?: string;
	sizes?: Record< string, { url: string } | undefined >;
}

interface MediaLibrary {
	type?: string;
	uploadedTo?: number;
}

interface MediaFrame {
	on: ( event: string, callback: () => void ) => void;
	open: () => void;
	state: () => {
		get: ( key: 'selection' ) => {
			first: () => { toJSON: () => Attachment };
			toJSON: () => Attachment[];
		};
	};
}

/** TinyMCE editor instance, as far as this script uses it. */
interface TinyMceEditor {
	removed: boolean;
	iframeElement: HTMLIFrameElement;
	on: ( events: string, callback: () => void ) => void;
	save: () => void;
	getBody: () => HTMLElement;
	setContent: ( content: string ) => void;
}

/** Actions of the `core/editor` store used here. */
interface EditorActions {
	lockPostSaving: ( lockName: string ) => void;
	unlockPostSaving: ( lockName: string ) => void;
}

/** Actions of the `core/notices` store used here. */
interface NoticesActions {
	createNotice: (
		status: string,
		content: string,
		options: { id: string; isDismissible: boolean }
	) => void;
	removeNotice: ( id: string ) => void;
}

/** State of the `wpff/fields` store: only a counter bumped on every edit. */
interface StoreState {
	version: number;
}

/** The parts of `window.wp` this script reads; each one may be missing (front-end forms). */
interface WpGlobal {
	/** Missing where wp_enqueue_media() did not run. */
	media?: ( options: {
		title: string;
		button: { text: string };
		library: MediaLibrary;
		multiple: boolean | 'add';
	} ) => MediaFrame;
	editor?: {
		initialize?: ( id: string, settings: object ) => void;
		remove: ( id: string ) => void;
	};
	data?: {
		select: ( (
			store: 'core/edit-post'
		) => { areMetaBoxesInitialized?: () => boolean } | undefined ) &
			( ( store: string ) => unknown );
		dispatch: ( ( store: 'core/editor' ) => EditorActions | undefined ) &
			( ( store: 'core/notices' ) => NoticesActions ) &
			( ( store: 'wpff/fields' ) => { notifyChange: () => void } );
		subscribe: ( listener: () => void ) => () => void;
		createReduxStore?: ( name: string, options: object ) => unknown;
		register: ( store: unknown ) => void;
	};
}

type AdminWindow = Window & {
	tinymce?: {
		triggerSave: () => void;
		get: ( id: string ) => TinyMceEditor | null;
	};
};

/** jQuery with the Iris-based color picker from `wp-color-picker`. */
type ColorPickerJQuery< T = HTMLElement > = JQuery< T > & {
	wpColorPicker: ( ...args: unknown[] ) => JQuery;
};

/** Item of a relational picker. */
interface PickerItem {
	id: number | string;
	text: string;
}

interface PickerResponse {
	results: PickerItem[];
	more: boolean;
}

/** Relational picker; numbers its searches so stale responses are dropped. */
type Picker = HTMLElement & { _token?: number };

/** Result list entry: an item or the "Load more" entry with its page. */
type PickerOption = HTMLElement & { _item?: PickerItem; _page?: number };

/** Body of the `wpff_validate` AJAX response. */
interface ValidateResponse {
	success: boolean;
	data: { valid: boolean; errors: Record< string, string > };
}

interface SelectOption {
	value: string;
	label: string;
	disabled: boolean;
	selected: boolean;
	placeholder: boolean;
}

/** Custom select UI attached to a native select. */
interface SelectState {
	select: HTMLSelectElement;
	ui: HTMLDivElement;
	button: HTMLButtonElement;
	multiple: boolean;
}

/** The custom select popup that is open. */
interface OpenSelect {
	state: SelectState;
	select: HTMLSelectElement;
	popup: HTMLDivElement;
	list: HTMLUListElement;
	search: HTMLInputElement | null;
	query: string;
	active: number;
	visible: SelectOption[];
}

const win = window as AdminWindow;
const wp = window.wp as WpGlobal;
const cfg: Partial< AdminSettings > = window.wpffAdmin || {};

/* =====================================================================
 * Values and conditional logic
 * ================================================================== */

/**
 * Field wrapper by key, preferring siblings of `from` (same group/row).
 *
 * @param key  Field key.
 * @param from Field asking.
 * @return Wrapper.
 */
function findField( key: string, from: Element ): HTMLElement | null {
	const scope =
		from && from.parentElement
			? from.parentElement.closest( '.wpff-group, .wpff-fields, form' )
			: null;
	const selector = `.wpff-field[data-key="${ key }"]`;
	return (
		( scope && scope.querySelector< HTMLElement >( selector ) ) ||
		document.querySelector< HTMLElement >( selector )
	);
}

/**
 * Current values of a field as a list of strings. Hidden fields have no value.
 *
 * @param field Wrapper.
 * @return Values.
 */
function fieldValues( field: HTMLElement | null ): string[] {
	if ( ! field || field.classList.contains( 'wpff-hidden' ) ) {
		return [];
	}
	const type = field.dataset.type;
	const input =
		field.querySelector( ':scope > .wpff-input' ) ||
		field.querySelector( '.wpff-input' );
	if ( ! input ) {
		return [];
	}
	if ( 'true_false' === type ) {
		const box = input.querySelector< HTMLInputElement >(
			'input[type=checkbox]'
		);
		return [ box && box.checked ? '1' : '0' ];
	}
	return $( input )
		.find( ':input' )
		.not( '.wpff-picker__input, .wpff-range__slider' )
		.serializeArray()
		.map( ( item ) => item.value )
		.filter( ( v ) => '' !== v );
}

function matchRule( rule: ConditionRule, values: string[] ): boolean {
	const value = String( rule.value );
	switch ( rule.operator ) {
		case '==':
			return values.includes( value );
		case '!=':
			return ! values.includes( value );
		case '==empty':
			return 0 === values.length;
		case '!=empty':
			return values.length > 0;
		case '==contains':
			return values.some( ( v ) => v.includes( value ) );
		case '!=contains':
			return ! values.some( ( v ) => v.includes( value ) );
		case '>':
			return (
				values.length > 0 &&
				parseFloat( values[ 0 ] ) > parseFloat( value )
			);
		case '<':
			return (
				values.length > 0 &&
				parseFloat( values[ 0 ] ) < parseFloat( value )
			);
	}
	return false;
}

function setVisible( field: HTMLElement, visible: boolean ): boolean {
	if ( field.classList.contains( 'wpff-hidden' ) === ! visible ) {
		return false;
	}
	field.classList.toggle( 'wpff-hidden', ! visible );
	field.hidden = ! visible;
	// Disabled inputs are not submitted, so hidden fields are neither validated nor saved.
	field
		.querySelectorAll<
			| HTMLInputElement
			| HTMLSelectElement
			| HTMLTextAreaElement
			| HTMLButtonElement
		>( 'input, select, textarea, button' )
		.forEach( ( el ) => {
			if ( ! visible ) {
				if ( ! el.disabled ) {
					el.disabled = true;
					el.dataset.wpffDisabled = '1';
				}
			} else if ( el.dataset.wpffDisabled ) {
				el.disabled = false;
				delete el.dataset.wpffDisabled;
			}
		} );
	return true;
}

function applyConditions(): void {
	// Repeat so chains (A shows B, B shows C) settle.
	for ( let pass = 0; pass < 5; pass++ ) {
		let changed = false;
		document
			.querySelectorAll< HTMLElement >( '.wpff-field[data-conditions]' )
			.forEach( ( field ) => {
				let groups: ConditionRule[][];
				try {
					// The selector guarantees the attribute.
					groups = JSON.parse( field.dataset.conditions! );
				} catch {
					return;
				}
				const visible = groups.some( ( rules ) =>
					rules.every( ( rule ) =>
						matchRule(
							rule,
							fieldValues( findField( rule.field, field ) )
						)
					)
				);
				changed = setVisible( field, visible ) || changed;
			} );
		if ( ! changed ) {
			break;
		}
	}
	updateTabVisibility();
	$( document ).trigger( 'wpff:conditions' );
}

$( document ).on( 'input change', '.wpff-field :input', function () {
	applyConditions();
} );

/* =====================================================================
 * Tabs and accordions
 * ================================================================== */

function initTabs( container: Element ): number {
	const children = Array.from( container.children ).filter(
		( el ): el is HTMLElement => el.classList.contains( 'wpff-field' )
	);
	let nav: TabNav | null = null;
	let current: TabPanel | null = null;
	let tabIndex = 0;

	children.forEach( ( field ) => {
		if ( 'tab' === field.dataset.type ) {
			const marker =
				field.querySelector< HTMLElement >( '.wpff-tab-marker' )!;
			if ( ! nav || '1' === marker.dataset.endpoint ) {
				nav = document.createElement( 'div' ) as TabNav;
				nav.className = `wpff-tabs wpff-tabs--${ marker.dataset.placement }`;
				nav.setAttribute( 'role', 'tablist' );
				container.insertBefore( nav, field );
				nav._panels = [];
			}
			const button = document.createElement( 'button' ) as TabButton;
			button.type = 'button';
			button.className = 'wpff-tab';
			button.setAttribute( 'role', 'tab' );
			button.textContent = marker.dataset.label ?? null;
			button.id = `wpff-tab-${ field.dataset.key }`;
			nav.appendChild( button );
			current = { button, fields: [], tabField: field };
			nav._panels.push( current );
			button._panel = current;
			button._nav = nav;
			field.classList.add( 'wpff-tab-field' );
			tabIndex++;
			return;
		}
		if ( current ) {
			current.fields.push( field );
			field.setAttribute( 'aria-labelledby', current.button.id );
		}
	} );

	container
		.querySelectorAll< TabNav >( ':scope > .wpff-tabs' )
		.forEach( ( tabs ) => selectTab( tabs._panels[ 0 ] ) );
	return tabIndex;
}

function selectTab( panel: TabPanel | undefined ): void {
	if ( ! panel ) {
		return;
	}
	panel.button._nav._panels.forEach( ( p ) => {
		const active = p === panel;
		p.button.classList.toggle( 'is-active', active );
		p.button.setAttribute( 'aria-selected', active ? 'true' : 'false' );
		p.button.tabIndex = active ? 0 : -1;
		// Only hidden, never disabled: fields on other tabs are still submitted.
		p.fields.forEach( ( f ) =>
			f.classList.toggle( 'wpff-tab-hidden', ! active )
		);
	} );
}

function updateTabVisibility(): void {
	document
		.querySelectorAll< MaybeTabNav >( '.wpff-tabs' )
		.forEach( ( nav ) => {
			( nav._panels || [] ).forEach( ( p ) => {
				p.button.hidden =
					p.tabField.classList.contains( 'wpff-hidden' );
			} );
		} );
}

$( document ).on( 'click', '.wpff-tab', function ( this: TabButton ) {
	selectTab( this._panel );
} );

$( document ).on( 'keydown', '.wpff-tab', function ( this: TabButton, e ) {
	const buttons = Array.from(
		this._nav.querySelectorAll< TabButton >( '.wpff-tab:not([hidden])' )
	);
	const i = buttons.indexOf( this );
	let next: TabButton | null = null;
	if ( 'ArrowRight' === e.key || 'ArrowDown' === e.key ) {
		next = buttons[ ( i + 1 ) % buttons.length ];
	} else if ( 'ArrowLeft' === e.key || 'ArrowUp' === e.key ) {
		next = buttons[ ( i - 1 + buttons.length ) % buttons.length ];
	}
	if ( next ) {
		e.preventDefault();
		next.focus();
		selectTab( next._panel );
	}
} );

function initAccordions( container: Element ): void {
	const children = Array.from( container.children ).filter(
		( el ): el is HTMLElement => el.classList.contains( 'wpff-field' )
	);
	let body: HTMLDivElement | null = null;
	children.forEach( ( field ) => {
		if ( 'accordion' === field.dataset.type ) {
			const marker = field.querySelector< HTMLElement >(
				'.wpff-accordion-marker'
			)!;
			if ( '1' === marker.dataset.endpoint ) {
				field.classList.add( 'wpff-accordion-end' );
				body = null;
				return;
			}
			field.classList.add( 'wpff-accordion' );
			const button = document.createElement( 'button' );
			button.type = 'button';
			button.className = 'wpff-accordion__toggle';
			button.textContent = marker.dataset.label ?? null;
			body = document.createElement( 'div' );
			body.className = 'wpff-accordion__body wpff-fields';
			body.id = `wpff-acc-${ field.dataset.key }`;
			button.setAttribute( 'aria-controls', body.id );
			// Copies the marker's attribute, present or not, as the plain JS did.
			field.dataset.multi = marker.dataset.multi as string;
			field.querySelector( '.wpff-label' )!.replaceChildren( button );
			field.querySelector( '.wpff-input' )!.replaceChildren( body );
			toggleAccordion( field, '1' === marker.dataset.open );
			return;
		}
		if ( body ) {
			body.appendChild( field );
		}
	} );
}

function toggleAccordion( field: Element, open: boolean ): void {
	field.classList.toggle( 'is-open', open );
	field
		.querySelector( '.wpff-accordion__toggle' )!
		.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
	field.querySelector< HTMLElement >( '.wpff-accordion__body' )!.hidden =
		! open;
}

$( document ).on(
	'click',
	'.wpff-accordion__toggle',
	function ( this: HTMLElement ) {
		const field = this.closest< HTMLElement >( '.wpff-accordion' )!;
		const open = ! field.classList.contains( 'is-open' );
		if ( open && '1' !== field.dataset.multi ) {
			field
				.parentElement!.querySelectorAll< HTMLElement >(
					':scope > .wpff-accordion.is-open'
				)
				.forEach( ( other ) => {
					if ( other !== field && '1' !== other.dataset.multi ) {
						toggleAccordion( other, false );
					}
				} );
		}
		toggleAccordion( field, open );
	}
);

/* =====================================================================
 * Media: image and file
 * ================================================================== */

/**
 * ID of the post being edited, or 0 on term, user and new-post forms. PHP prints it as a
 * string, so "0" must not count as a post.
 */
function currentPostId(): number {
	const id = Number( cfg.postId );
	return id > 0 ? id : 0;
}

/**
 * Library filter for a field's "Library" setting: only files uploaded to this post, when there is one.
 *
 * @param setting The field's `data-library` value.
 * @param type    Attachment type to show, or ''.
 */
function mediaLibrary( setting: unknown, type: string ): MediaLibrary {
	const library: MediaLibrary = type ? { type } : {};
	if ( 'uploadedTo' === setting && currentPostId() ) {
		library.uploadedTo = currentPostId();
	}
	return library;
}

/**
 * The media modal, or null where WordPress did not load it (front-end forms for visitors
 * who may not upload files); then the visitor is told why nothing opens.
 */
function mediaModal(): NonNullable< WpGlobal[ 'media' ] > | null {
	if ( 'function' === typeof wp.media ) {
		return wp.media;
	}
	// eslint-disable-next-line no-alert -- the field has no other place to explain this.
	window.alert(
		__( 'You are not allowed to choose files here.', 'freeform-fields' )
	);
	return null;
}

function openMedia(
	$wrap: JQuery< HTMLElement >,
	type: string,
	onSelect: ( attachment: Attachment ) => void
): void {
	const media = mediaModal();
	if ( ! media ) {
		return;
	}
	const library = mediaLibrary( $wrap.data( 'library' ), type );
	const frame = media( {
		title:
			'image' === type
				? __( 'Select Image', 'freeform-fields' )
				: __( 'Select File', 'freeform-fields' ),
		button: {
			text:
				'image' === type
					? __( 'Use Image', 'freeform-fields' )
					: __( 'Use File', 'freeform-fields' ),
		},
		library,
		multiple: false,
	} );
	frame.on( 'select', () =>
		onSelect( frame.state().get( 'selection' ).first().toJSON() )
	);
	frame.open();
}

$( document ).on( 'click', '.wpff-image__select', function ( e ) {
	e.preventDefault();
	const $wrap = $( this as HTMLElement ).closest( '.wpff-image' );
	openMedia( $wrap, 'image', ( attachment ) => {
		const size: string = $wrap.data( 'preview-size' );
		const url =
			( attachment.sizes &&
				attachment.sizes[ size ] &&
				attachment.sizes[ size ].url ) ||
			attachment.url;
		$wrap
			.find( '.wpff-image__value' )
			.val( attachment.id )
			.trigger( 'change' );
		$wrap.find( '.wpff-image__preview img' ).attr( 'src', url );
		$wrap.addClass( 'has-value' );
	} );
} );

$( document ).on( 'click', '.wpff-image__remove', function ( e ) {
	e.preventDefault();
	const $wrap = $( this as HTMLElement ).closest( '.wpff-image' );
	$wrap.find( '.wpff-image__value' ).val( '' ).trigger( 'change' );
	$wrap.find( '.wpff-image__preview img' ).attr( 'src', '' );
	$wrap.removeClass( 'has-value' );
} );

$( document ).on( 'click', '.wpff-file__select', function ( e ) {
	e.preventDefault();
	const $wrap = $( this as HTMLElement ).closest( '.wpff-file' );
	openMedia( $wrap, '', ( attachment ) => {
		$wrap
			.find( '.wpff-file__value' )
			.val( attachment.id )
			.trigger( 'change' );
		$wrap
			.find( '.wpff-file__name' )
			.attr( 'href', attachment.url )
			.text( attachment.filename as string );
		$wrap.addClass( 'has-value' );
	} );
} );

$( document ).on( 'click', '.wpff-file__remove', function ( e ) {
	e.preventDefault();
	const $wrap = $( this as HTMLElement ).closest( '.wpff-file' );
	$wrap.find( '.wpff-file__value' ).val( '' ).trigger( 'change' );
	$wrap.removeClass( 'has-value' );
} );

/* =====================================================================
 * Range, color, WYSIWYG
 * ================================================================== */

$( document ).on(
	'input',
	'.wpff-range__slider',
	function ( this: HTMLInputElement ) {
		$( this )
			.siblings( '.wpff-range__number' )
			.val( this.value )
			.trigger( 'change' );
	}
);
$( document ).on(
	'input',
	'.wpff-range__number',
	function ( this: HTMLInputElement ) {
		$( this ).siblings( '.wpff-range__slider' ).val( this.value );
	}
);

function initColor( root: Element | Document ): void {
	if ( ! ( $.fn as Partial< ColorPickerJQuery > ).wpColorPicker ) {
		return;
	}
	$( root )
		.find( '.wpff-color' )
		.each( function () {
			( $( this ) as ColorPickerJQuery ).wpColorPicker( {
				change: () => setTimeout( () => $( this ).trigger( 'change' ) ),
				clear: () => setTimeout( () => $( this ).trigger( 'change' ) ),
			} );
		} );
}

const TOOLBARS: Record< string, string[] | undefined > & {
	full: string[];
} = {
	full: [
		'formatselect,bold,italic,bullist,numlist,blockquote,alignleft,aligncenter,alignright,link,wp_more,spellchecker,fullscreen,wp_adv',
		'strikethrough,hr,forecolor,pastetext,removeformat,charmap,outdent,indent,undo,redo,wp_help',
	],
	basic: [
		'bold,italic,underline,blockquote,strikethrough,bullist,numlist,alignleft,aligncenter,alignright,undo,redo,link',
		'',
	],
};

function initWysiwyg( root: ParentNode ): void {
	if ( ! wp.editor || ! wp.editor.initialize ) {
		return;
	}
	root.querySelectorAll< HTMLTextAreaElement >(
		'textarea.wpff-wysiwyg'
	).forEach( ( area ) => {
		if ( area.dataset.initialized ) {
			return;
		}
		area.dataset.initialized = '1';
		const tabs = area.dataset.tabs;
		// A missing attribute matches no toolbar, as `undefined` did.
		const toolbar = TOOLBARS[ area.dataset.toolbar ?? '' ] || TOOLBARS.full;
		// Checked above; the callback does not keep the narrowing.
		wp.editor!.initialize!( area.id, {
			tinymce:
				'text' === tabs
					? false
					: {
							wpautop: true,
							toolbar1: toolbar[ 0 ],
							toolbar2: toolbar[ 1 ],
							setup( editor: TinyMceEditor ) {
								// Keep the textarea in sync: the block editor serializes the form itself.
								editor.on( 'change keyup undo redo', () => {
									editor.save();
									$( area ).trigger( 'change' );
								} );
								// Moving the editor in the DOM reloads its iframe and leaves it blank.
								editor.on( 'init', () => {
									editor.iframeElement.addEventListener(
										'load',
										() => reinitWysiwyg( area, editor )
									);
								} );
							},
						},
			quicktags: 'visual' !== tabs,
			mediaButtons: '1' === area.dataset.media,
		} );
	} );
}

/**
 * Recreates an editor whose iframe was reloaded, keeping its content.
 *
 * @param area   Textarea.
 * @param editor TinyMCE editor.
 */
function reinitWysiwyg(
	area: HTMLTextAreaElement,
	editor: TinyMceEditor
): void {
	if (
		editor.removed ||
		editor.getBody() === editor.iframeElement.contentDocument?.body
	) {
		return;
	}
	editor.save();
	wp.editor!.remove( area.id );
	delete area.dataset.initialized;
	initWysiwyg( area.parentElement! );
}

/**
 * Starts editors once meta boxes are in their final place (the block editor moves them after load).
 */
function bootWysiwyg(): void {
	const store =
		cfg.blockEditor && wp.data && wp.data.select( 'core/edit-post' );
	if ( ! store || ! store.areMetaBoxesInitialized ) {
		initWysiwyg( document );
		return;
	}
	const start = () => setTimeout( () => initWysiwyg( document ), 0 );
	if ( store.areMetaBoxesInitialized() ) {
		start();
		return;
	}
	// `wp.data` was checked above; the callbacks do not keep the narrowing.
	const unsubscribe = wp.data!.subscribe( () => {
		if ( wp.data!.select( 'core/edit-post' )!.areMetaBoxesInitialized!() ) {
			unsubscribe();
			start();
		}
	} );
}

function syncEditors(): void {
	if ( win.tinymce ) {
		win.tinymce.triggerSave();
	}
}

/* =====================================================================
 * Relational picker (post object, page link, relationship, taxonomy, user)
 * ================================================================== */

function pickerRequest(
	key: string | undefined,
	search: string,
	page: number
): Promise< PickerResponse > {
	const url = new URL(
		`${ cfg.restUrl }wpff/v1/fields/${ key }/choices`,
		window.location.href
	);
	url.searchParams.set( 's', search );
	url.searchParams.set( 'page', String( page ) );
	return fetch( url, {
		headers: { 'X-WP-Nonce': cfg.restNonce! },
		credentials: 'same-origin',
	} ).then( ( r ) => ( r.ok ? r.json() : Promise.reject( r ) ) );
}

function pickerSelectedIds( picker: Element ): ( string | undefined )[] {
	return Array.from(
		picker.querySelectorAll< HTMLElement >( '.wpff-picker__item' )
	).map( ( li ) => li.dataset.id );
}

function pickerAdd( picker: Picker, item: PickerItem ): void {
	if ( pickerSelectedIds( picker ).includes( String( item.id ) ) ) {
		return;
	}
	const multiple = '1' === picker.dataset.multiple;
	const max = parseInt( picker.dataset.max ?? '', 10 ) || 0;
	const list = picker.querySelector( '.wpff-picker__selected' )!;
	if ( ! multiple ) {
		list.replaceChildren();
	} else if ( max && list.children.length >= max ) {
		const field = picker.closest( '.wpff-field' );
		const msg =
			field && field.querySelector< HTMLElement >( '.wpff-error' );
		if ( msg ) {
			msg.textContent = sprintf(
				/* translators: %d: maximum number of items. */
				__( 'You can select at most %d items.', 'freeform-fields' ),
				max
			);
			msg.hidden = false;
			field!.classList.add( 'has-error' );
		}
		return;
	}
	const li = document.createElement( 'li' );
	li.className = 'wpff-picker__item';
	li.dataset.id = String( item.id );
	const text = document.createElement( 'span' );
	text.className = 'wpff-picker__text';
	text.textContent = item.text;
	const hidden = document.createElement( 'input' );
	hidden.type = 'hidden';
	hidden.name = picker.dataset.inputName as string;
	hidden.value = String( item.id );
	const remove = document.createElement( 'button' );
	remove.type = 'button';
	remove.className = 'wpff-picker__remove';
	remove.setAttribute(
		'aria-label',
		/* translators: %s: item title. */
		sprintf( __( 'Remove %s', 'freeform-fields' ), item.text )
	);
	remove.textContent = '×';
	li.append( text, hidden, remove );
	list.appendChild( li );
	$( hidden ).trigger( 'change' );
}

function pickerClose( picker: Element ): void {
	const results = picker.querySelector< HTMLElement >(
		'.wpff-picker__results'
	)!;
	results.hidden = true;
	picker
		.querySelector( '.wpff-picker__input' )!
		.setAttribute( 'aria-expanded', 'false' );
}

function pickerSearch( picker: Picker, page: number ): void {
	const input = picker.querySelector< HTMLInputElement >(
		'.wpff-picker__input'
	)!;
	const results = picker.querySelector< HTMLElement >(
		'.wpff-picker__results'
	)!;
	const search = input.value.trim();
	const token = ( picker._token = ( picker._token || 0 ) + 1 );
	if ( 1 === page ) {
		results.replaceChildren(
			statusItem( __( 'Searching…', 'freeform-fields' ) )
		);
	}
	results.hidden = false;
	input.setAttribute( 'aria-expanded', 'true' );

	pickerRequest( picker.dataset.fieldKey, search, page )
		.then( ( data ) => {
			if ( token !== picker._token ) {
				return;
			}
			if ( 1 === page ) {
				results.replaceChildren();
			} else {
				results.querySelector( '.wpff-picker__more' )?.remove();
			}
			const selected = pickerSelectedIds( picker );
			data.results.forEach( ( item ) => {
				const li: PickerOption = document.createElement( 'li' );
				li.setAttribute( 'role', 'option' );
				li.className = 'wpff-picker__option';
				li.textContent = item.text;
				li._item = item;
				if ( selected.includes( String( item.id ) ) ) {
					li.classList.add( 'is-selected' );
					li.setAttribute( 'aria-disabled', 'true' );
				}
				results.appendChild( li );
			} );
			if ( 0 === results.children.length ) {
				results.appendChild(
					statusItem( __( 'No matches found.', 'freeform-fields' ) )
				);
			}
			if ( data.more ) {
				const more: PickerOption = statusItem(
					__( 'Load more…', 'freeform-fields' )
				);
				more.className = 'wpff-picker__more';
				more.setAttribute( 'role', 'option' );
				more._page = page + 1;
				results.appendChild( more );
			}
		} )
		.catch( () => {
			results.replaceChildren(
				statusItem( __( 'Could not load results.', 'freeform-fields' ) )
			);
		} );
}

function statusItem( text: string ): HTMLLIElement {
	const li = document.createElement( 'li' );
	li.className = 'wpff-picker__status';
	li.textContent = text;
	return li;
}

function pickerActivate( picker: Picker, li: PickerOption ): void {
	if ( li.classList.contains( 'wpff-picker__more' ) ) {
		pickerSearch( picker, li._page! );
		return;
	}
	if ( ! li._item || li.classList.contains( 'is-selected' ) ) {
		return;
	}
	pickerAdd( picker, li._item );
	const input = picker.querySelector< HTMLInputElement >(
		'.wpff-picker__input'
	)!;
	if ( '1' === picker.dataset.multiple ) {
		li.classList.add( 'is-selected' );
		input.focus();
	} else {
		input.value = '';
		pickerClose( picker );
	}
}

let searchTimer: ReturnType< typeof setTimeout > | undefined;
$( document ).on(
	'input focus',
	'.wpff-picker__input',
	function ( this: HTMLElement ) {
		const picker = this.closest< Picker >( '.wpff-picker' )!;
		clearTimeout( searchTimer );
		searchTimer = setTimeout( () => pickerSearch( picker, 1 ), 250 );
	}
);

$( document ).on(
	'mousedown',
	'.wpff-picker__option, .wpff-picker__more',
	function ( this: PickerOption, e ) {
		e.preventDefault();
		pickerActivate( this.closest< Picker >( '.wpff-picker' )!, this );
	}
);

$( document ).on(
	'keydown',
	'.wpff-picker__input',
	function ( this: HTMLElement, e ) {
		const picker = this.closest< Picker >( '.wpff-picker' )!;
		const options: Array< PickerOption | null > = Array.from(
			picker.querySelectorAll< PickerOption >(
				'.wpff-picker__option, .wpff-picker__more'
			)
		);
		const current = picker.querySelector< PickerOption >( '.is-active' );
		let i = options.indexOf( current );
		if ( 'ArrowDown' === e.key || 'ArrowUp' === e.key ) {
			e.preventDefault();
			i =
				'ArrowDown' === e.key
					? Math.min( options.length - 1, i + 1 )
					: Math.max( 0, i - 1 );
			current?.classList.remove( 'is-active' );
			const option = options[ i ];
			if ( option ) {
				option.classList.add( 'is-active' );
				option.scrollIntoView( { block: 'nearest' } );
			}
		} else if ( 'Enter' === e.key ) {
			// Never submit the post form from the search box.
			e.preventDefault();
			if ( current ) {
				pickerActivate( picker, current );
			}
		} else if ( 'Escape' === e.key ) {
			pickerClose( picker );
		}
	}
);

$( document ).on(
	'blur',
	'.wpff-picker__input',
	function ( this: HTMLElement ) {
		const picker = this.closest( '.wpff-picker' )!;
		setTimeout( () => pickerClose( picker ), 150 );
	}
);

$( document ).on(
	'click',
	'.wpff-picker__remove',
	function ( this: HTMLElement ) {
		const li = this.closest( '.wpff-picker__item' )!;
		const picker = li.closest( '.wpff-picker' )!;
		li.remove();
		$( picker ).find( 'input[type=hidden]' ).first().trigger( 'change' );
	}
);

// Drag to reorder selected items in multiple pickers.
let dragged: HTMLElement | null = null;
$( document ).on(
	'dragstart',
	'.wpff-picker[data-sortable="1"] .wpff-picker__item',
	function ( this: HTMLElement, e ) {
		dragged = this;
		e.originalEvent!.dataTransfer!.effectAllowed = 'move';
		this.classList.add( 'is-dragging' );
	}
);
$( document ).on(
	'dragover',
	'.wpff-picker[data-sortable="1"] .wpff-picker__item',
	function ( this: HTMLElement, e ) {
		if (
			! dragged ||
			dragged === this ||
			dragged.parentElement !== this.parentElement
		) {
			return;
		}
		e.preventDefault();
		const rect = this.getBoundingClientRect();
		const after = e.originalEvent!.clientY > rect.top + rect.height / 2;
		this.parentElement!.insertBefore(
			dragged,
			after ? this.nextSibling : this
		);
	}
);
$( document ).on(
	'dragend',
	'.wpff-picker__item',
	function ( this: HTMLElement ) {
		this.classList.remove( 'is-dragging' );
		dragged = null;
		$( this ).find( 'input' ).trigger( 'change' );
	}
);

function initPickers( root: ParentNode ): void {
	root.querySelectorAll< HTMLElement >(
		'.wpff-picker[data-sortable="1"] .wpff-picker__item'
	).forEach( ( li ) => {
		li.draggable = true;
	} );
}

const observer = new window.MutationObserver( ( mutations ) => {
	mutations.forEach( ( m ) =>
		m.addedNodes.forEach( ( added ) => {
			// Text and comment nodes have no classList.
			const node = added as Partial< HTMLElement > & Node;
			if (
				node.classList &&
				node.classList.contains( 'wpff-picker__item' ) &&
				node.closest!( '[data-sortable="1"]' )
			) {
				node.draggable = true;
			}
		} )
	);
} );

/* =====================================================================
 * Errors and validation
 * ================================================================== */

/**
 * Field wrapper by error path (`field_x`, `field_g.field_s`, `field_r.row-0.field_s`).
 *
 * @param path Path.
 * @return Wrapper.
 */
function fieldByKey( path: string ): HTMLElement | null {
	return document.querySelector< HTMLElement >(
		`.wpff-field[data-path="${ path }"]`
	);
}

function clearErrors(): void {
	document.querySelectorAll( '.wpff-field.has-error' ).forEach( ( field ) => {
		field.classList.remove( 'has-error' );
		const msg = field.querySelector< HTMLElement >( '.wpff-error' )!;
		msg.hidden = true;
		msg.textContent = '';
	} );
}

function revealField( field: HTMLElement ): void {
	showRowPage( field );
	// Open the tab and accordion holding the field.
	document.querySelectorAll< MaybeTabNav >( '.wpff-tabs' ).forEach( ( nav ) =>
		( nav._panels || [] ).forEach( ( p ) => {
			if (
				p.fields.includes( field ) ||
				p.fields.some( ( f ) => f.contains( field ) )
			) {
				selectTab( p );
			}
		} )
	);
	let acc = field.closest( '.wpff-accordion' );
	while ( acc ) {
		toggleAccordion( acc, true );
		acc = acc.parentElement!.closest( '.wpff-accordion' );
	}
}

/**
 * @param errors Messages keyed by field key or path.
 * @param reveal Open and scroll to the first invalid field.
 */
function showErrors( errors: Record< string, string >, reveal = true ): void {
	clearErrors();
	// Set inside the callback below, which control flow analysis does not follow.
	let first = null as HTMLElement | null;
	Object.keys( errors ).forEach( ( key ) => {
		const field = fieldByKey( key );
		if ( ! field ) {
			return;
		}
		field.classList.add( 'has-error' );
		const msg = field.querySelector< HTMLElement >( '.wpff-error' )!;
		msg.textContent = errors[ key ];
		msg.hidden = false;
		first = first || field;
	} );
	if ( first && reveal ) {
		revealField( first );
		first.scrollIntoView( { behavior: 'smooth', block: 'center' } );
	}
}

$( document ).on(
	'input change',
	'.wpff-field.has-error :input',
	function ( this: HTMLElement ) {
		const field = this.closest( '.wpff-field.has-error' )!;
		field.classList.remove( 'has-error' );
		field.querySelector< HTMLElement >( '.wpff-error' )!.hidden = true;
	}
);

/** Required fields that are visible and empty, keyed by field key. Mirrors the server check. */
function emptyRequired(): Record< string, string > {
	const errors: Record< string, string > = {};
	document
		.querySelectorAll< HTMLElement >( '.wpff-field[data-required="1"]' )
		.forEach( ( field ) => {
			// Block fields are checked by their block, not before saving the post.
			if (
				field.closest( '.wpff-hidden, .wpff-block-form' ) ||
				'group' === field.dataset.type
			) {
				return;
			}
			const values = fieldValues( field ).filter(
				( v ) => ! ( 'true_false' === field.dataset.type && '0' === v )
			);
			if ( 0 === values.length ) {
				const label = field.querySelector( '.wpff-label label' );
				const text = label
					? label.childNodes[ 0 ].textContent!.trim()
					: field.dataset.name;
				errors[ field.dataset.key! ] = sprintf(
					/* translators: %s: field label. */
					__( '%s is required.', 'freeform-fields' ),
					// Unlabeled fields without a name print "undefined", as before.
					text as string
				);
			}
		} );
	return errors;
}

/**
 * Validates the form over AJAX before it is submitted (classic post editor, term and user forms).
 *
 * @param $form jQuery-wrapped form.
 */
function initAjaxValidation( $form: JQuery< HTMLFormElement > ): void {
	let validated = false;
	let pending = false;

	function resetButtons() {
		$( '#publish, #save-post' ).removeClass( 'disabled' );
		$( '#publishing-action .spinner, #save-action .spinner' ).removeClass(
			'is-active'
		);
	}

	$form.on( 'submit', function ( e ) {
		if ( validated || ! this.querySelector( '.wpff-field' ) ) {
			return;
		}
		e.preventDefault();
		if ( pending ) {
			return;
		}
		syncEditors();
		const submitter =
			e.originalEvent && ( e.originalEvent as SubmitEvent ).submitter;
		const data = $form.find( '.wpff-field :input' ).serializeArray();
		data.push(
			{ name: 'action', value: 'wpff_validate' },
			{ name: 'nonce', value: cfg.nonce! },
			{ name: 'object', value: cfg.object! }
		);
		pending = true;
		$.post( cfg.ajaxUrl!, $.param( data ) )
			.done( function ( response: ValidateResponse | null ) {
				if ( response && response.success && ! response.data.valid ) {
					showErrors( response.data.errors );
					resetButtons();
					return;
				}
				// Valid, or validation unavailable: the server validates again on save.
				validated = true;
				clearErrors();
				// requestSubmit() is missing in older browsers.
				if (
					submitter &&
					( $form[ 0 ] as Partial< HTMLFormElement > ).requestSubmit
				) {
					$form[ 0 ].requestSubmit( submitter );
				} else {
					$form.trigger( 'submit' );
				}
			} )
			.fail( function () {
				validated = true;
				$form.trigger( 'submit' );
			} )
			.always( function () {
				pending = false;
			} );
	} );
}

function initBlockEditor(): void {
	if ( ! wp.data || ! wp.data.dispatch( 'core/editor' ) ) {
		return;
	}
	const editor = wp.data.dispatch( 'core/editor' )!;
	const notices = wp.data.dispatch( 'core/notices' );
	let locked = false;

	function check() {
		const hasErrors = Object.keys( emptyRequired() ).length > 0;
		if ( hasErrors && ! locked ) {
			editor.lockPostSaving( 'wpff' );
			notices.createNotice(
				'warning',
				__( 'Fill in the required fields to save.', 'freeform-fields' ),
				{
					id: 'wpff-required',
					isDismissible: false,
				}
			);
			locked = true;
		} else if ( ! hasErrors && locked ) {
			editor.unlockPostSaving( 'wpff' );
			notices.removeNotice( 'wpff-required' );
			locked = false;
		}
	}

	$( document ).on( 'input change wpff:conditions', check );
	check();
	initBlockEditorValidation( editor, notices );
}

/**
 * Server-side checks (email, URL, number limits, custom `wpff/validate_value` filters) in the block
 * editor. The editor saves meta boxes in the background and cannot show the errors the server
 * returns, so a rejected value would be dropped without a word. The form is validated over AJAX
 * while the user edits it: saving is locked while a value is invalid, and the errors are shown
 * next to the fields and in an editor notice.
 *
 * @param editor  `core/editor` actions.
 * @param notices `core/notices` actions.
 */
function initBlockEditorValidation(
	editor: EditorActions,
	notices: NoticesActions
): void {
	// Fields of the post's meta boxes; block fields are checked by their block.
	const selector = '.wpff-field :input';
	const inputs = () =>
		$( selector ).filter( ( i, el ) => ! el.closest( '.wpff-block-form' ) );
	let timer: ReturnType< typeof setTimeout > | undefined;
	let request: JQuery.jqXHR | null = null;
	let seq = 0;
	let locked = false;
	let checking = false;

	// Saving waits for a pending check, so a value typed just before clicking Save is checked too.
	function setChecking( on: boolean ) {
		if ( on && ! checking ) {
			editor.lockPostSaving( 'wpff-checking' );
		} else if ( ! on && checking ) {
			editor.unlockPostSaving( 'wpff-checking' );
		}
		checking = on;
	}

	function schedule( delay: number ) {
		setChecking( true );
		clearTimeout( timer );
		timer = setTimeout( validate, delay );
	}

	function setInvalid( errors: Record< string, string > ) {
		const keys = Object.keys( errors );
		if ( keys.length ) {
			if ( ! locked ) {
				editor.lockPostSaving( 'wpff-invalid' );
				locked = true;
			}
			notices.createNotice(
				'error',
				__( 'Fix the highlighted fields to save.', 'freeform-fields' ) +
					' ' +
					keys.map( ( key ) => errors[ key ] ).join( ' ' ),
				{ id: 'wpff-invalid', isDismissible: false }
			);
			showErrors( errors, false );
		} else if ( locked ) {
			editor.unlockPostSaving( 'wpff-invalid' );
			notices.removeNotice( 'wpff-invalid' );
			clearErrors();
			locked = false;
		}
	}

	function validate() {
		const $inputs = inputs();
		if ( ! $inputs.length ) {
			setChecking( false );
			return;
		}
		syncEditors();
		const data = $inputs.serializeArray();
		data.push(
			{ name: 'action', value: 'wpff_validate' },
			{ name: 'nonce', value: cfg.nonce! },
			{ name: 'object', value: cfg.object! }
		);
		if ( request ) {
			request.abort();
		}
		const token = ++seq;
		request = $.post( cfg.ajaxUrl!, $.param( data ) )
			.done( function ( response: ValidateResponse | null ) {
				// Required fields are handled by the instant check above.
				const errors: Record< string, string > = {};
				if ( response && response.success ) {
					const all = response.data.errors || {};
					const empty = emptyRequired();
					Object.keys( all ).forEach( ( key ) => {
						if ( ! empty[ key ] ) {
							errors[ key ] = all[ key ];
						}
					} );
				}
				setInvalid( errors );
			} )
			.always( function () {
				// An aborted request finishes after the next one has started.
				if ( token === seq ) {
					request = null;
					setChecking( false );
				}
			} );
	}

	$( document ).on( 'input', selector, () => schedule( 500 ) );
	$( document ).on( 'change', selector, () => schedule( 0 ) );
	$( document ).on( 'wpff:conditions', () => schedule( 500 ) );
	schedule( 0 );
}

/* =====================================================================
 * Repeater, flexible content and gallery
 * ================================================================== */

let rowCounter = 0;

function newRowId(): string {
	rowCounter++;
	return `r${ Date.now().toString( 36 ) }${ rowCounter }`;
}

function rowsOf( field: Element ): HTMLElement[] {
	return Array.from(
		field.querySelector( ':scope > .wpff-rows' )!.children
	).filter( ( el ): el is HTMLElement =>
		el.classList.contains( 'wpff-row' )
	);
}

/**
 * Renames a row id inside input names, ids, labels and paths.
 *
 * @param row   Row element (detached or in the DOM).
 * @param oldId Current id.
 * @param newId New id.
 */
function renameRow( row: HTMLElement, oldId: string, newId: string ): void {
	row.dataset.id = newId;
	const replace = ( value: string ) =>
		value
			.split( `[${ oldId }]` )
			.join( `[${ newId }]` )
			.split( `.${ oldId }.` )
			.join( `.${ newId }.` )
			.split( `-${ oldId }-` )
			.join( `-${ newId }-` );
	const attrs = [
		'name',
		'id',
		'for',
		'data-path',
		'aria-labelledby',
		'aria-controls',
	];
	const walk = ( root: ParentNode ): void =>
		root.querySelectorAll( '*' ).forEach( ( el ) => {
			attrs.forEach( ( attr ) => {
				if ( el.hasAttribute( attr ) ) {
					el.setAttribute(
						attr,
						replace( el.getAttribute( attr )! )
					);
				}
			} );
			// Templates of nested repeaters also carry this row's id.
			if ( 'TEMPLATE' === el.tagName ) {
				walk( ( el as HTMLTemplateElement ).content );
			}
		} );
	walk( row );
}

/**
 * Starts every widget inside a newly added piece of form.
 *
 * @param root Container.
 */
function initFieldsIn( root: Element ): void {
	root.querySelectorAll( '.wpff-fields, .wpff-group' ).forEach( ( c ) =>
		initAccordions( c )
	);
	root.querySelectorAll( '.wpff-fields, .wpff-group' ).forEach( ( c ) =>
		initTabs( c )
	);
	initColor( root );
	initWysiwyg( root );
	initPickers( root );
	initSelects( root );
	root.querySelectorAll< HTMLElement >( '.wpff-rows-field' ).forEach(
		refreshRows
	);
	applyConditions();
	// Lets field types with their own scripts (e.g. the map) start in new rows.
	$( document ).trigger( 'wpff:init', [ root ] );
}

function rowFromTemplate( field: Element, layout?: string ): HTMLElement {
	const selector = layout
		? `:scope > template.wpff-row-template[data-layout="${ layout }"]`
		: ':scope > template.wpff-row-template';
	const template = field.querySelector< HTMLTemplateElement >( selector )!;
	// Only this level's placeholder: nested repeaters keep theirs for their own rows.
	const html = template.innerHTML
		.split( template.dataset.placeholder! )
		.join( newRowId() );
	const holder = document.createElement( 'template' );
	holder.innerHTML = html.trim();
	return holder.content.firstElementChild as HTMLElement;
}

function rowError( field: Element, message: string ): void {
	const wrap = field.closest( '.wpff-field' );
	const msg =
		wrap &&
		wrap.querySelector< HTMLElement >(
			':scope > .wpff-input > .wpff-error'
		);
	if ( msg ) {
		msg.textContent = message;
		msg.hidden = ! message;
		wrap!.classList.toggle( 'has-error', !! message );
	}
}

/**
 * Layout name of a flexible content row (its own input, not one of a nested field's rows).
 *
 * @param row Row.
 */
function rowLayout( row: Element ): string | undefined {
	return row.querySelector< HTMLInputElement >(
		':scope > .wpff-row__body > .wpff-row__layout'
	)?.value;
}

function canAdd( field: HTMLElement, layout?: string ): boolean {
	const max = parseInt( field.dataset.max ?? '', 10 ) || 0;
	const rows = rowsOf( field );
	if ( max && rows.length >= max ) {
		rowError(
			field,
			sprintf(
				/* translators: %d: maximum number of rows. */
				__( 'At most %d rows are allowed.', 'freeform-fields' ),
				max
			)
		);
		return false;
	}
	if ( layout ) {
		const item = field.querySelector< HTMLElement >(
			`:scope > .wpff-flexible__footer > .wpff-flexible__menu [data-layout="${ layout }"]`
		);
		const layoutMax = item
			? parseInt( item.dataset.max ?? '', 10 ) || 0
			: 0;
		const used = rows.filter( ( r ) => rowLayout( r ) === layout ).length;
		if ( layoutMax && used >= layoutMax ) {
			rowError(
				field,
				sprintf(
					/* translators: %d: maximum number of this layout. */
					__(
						'This layout can be used at most %d times.',
						'freeform-fields'
					),
					layoutMax
				)
			);
			return false;
		}
	}
	return true;
}

function addRow(
	field: HTMLElement,
	before: Element | null,
	layout?: string
): HTMLElement | null {
	if ( ! canAdd( field, layout ) ) {
		return null;
	}
	const row = rowFromTemplate( field, layout );
	const list = field.querySelector( ':scope > .wpff-rows' )!;
	list.insertBefore( row, before || null );
	initFieldsIn( row );
	refreshRows( field );
	showRowPage( row );
	row.querySelector< HTMLElement >(
		'input:not([type=hidden]), select, textarea'
	)?.focus();
	$( row ).find( ':input' ).first().trigger( 'change' );
	return row;
}

function removeEditors( root: ParentNode ): void {
	root.querySelectorAll< HTMLTextAreaElement >(
		'textarea.wpff-wysiwyg'
	).forEach( ( area ) => {
		if ( area.dataset.initialized && wp.editor ) {
			wp.editor.remove( area.id );
		}
	} );
}

function duplicateRow( row: HTMLElement ): void {
	const field = row.closest< HTMLElement >( '.wpff-rows-field' )!;
	if ( ! canAdd( field, rowLayout( row ) ) ) {
		return;
	}
	syncEditors();
	const copy = row.cloneNode( true ) as HTMLElement;
	// cloneNode() keeps typed values but not the chosen options of selects.
	const sourceSelects = row.querySelectorAll( 'select' );
	copy.querySelectorAll( 'select' ).forEach( ( select, i ) => {
		Array.from( select.options ).forEach( ( option, j ) => {
			option.selected = sourceSelects[ i ].options[ j ].selected;
		} );
	} );
	// Turn initialized widgets back into plain inputs; they are started again below.
	copy.querySelectorAll( '.wp-editor-wrap' ).forEach( ( wrap ) => {
		const original = wrap.querySelector< HTMLTextAreaElement >(
			'textarea.wpff-wysiwyg'
		)!;
		const area = document.createElement( 'textarea' );
		[
			'id',
			'name',
			'rows',
			'class',
			'data-tabs',
			'data-toolbar',
			'data-media',
		].forEach( ( attr ) => {
			if ( original.hasAttribute( attr ) ) {
				area.setAttribute( attr, original.getAttribute( attr )! );
			}
		} );
		area.value = original.value;
		wrap.replaceWith( area );
	} );
	copy.querySelectorAll( '.wp-picker-container' ).forEach( ( picker ) => {
		const input =
			picker.querySelector< HTMLInputElement >( 'input.wpff-color' )!;
		input.classList.remove( 'wp-color-picker' );
		input.removeAttribute( 'style' );
		picker.replaceWith( input );
	} );
	copy.querySelectorAll( '.wpff-tabs' ).forEach( ( tabs ) => tabs.remove() );
	copy.querySelectorAll( '.wpff-tab-hidden' ).forEach( ( el ) =>
		el.classList.remove( 'wpff-tab-hidden' )
	);
	renameRow( copy, row.dataset.id!, newRowId() );
	row.after( copy );
	initFieldsIn( copy );
	refreshRows( field );
	showRowPage( copy );
}

function removeRow( row: HTMLElement ): void {
	const field = row.closest< HTMLElement >( '.wpff-rows-field' )!;
	const min = parseInt( field.dataset.min ?? '', 10 ) || 0;
	if ( min && rowsOf( field ).length <= min ) {
		rowError(
			field,
			sprintf(
				/* translators: %d: minimum number of rows. */
				__( 'At least %d rows are required.', 'freeform-fields' ),
				min
			)
		);
		return;
	}
	removeEditors( row );
	row.remove();
	refreshRows( field );
	applyConditions();
	$( field ).find( 'input[type=hidden]' ).first().trigger( 'change' );
}

function rowSummary( row: HTMLElement, field: HTMLElement ): string {
	const key = field.dataset.collapsed;
	let source = key
		? row.querySelector< HTMLElement >(
				`:scope .wpff-row__fields > .wpff-field[data-key="${ key }"]`
			)
		: null;
	source =
		source ||
		row.querySelector< HTMLElement >(
			':scope .wpff-row__fields > .wpff-field'
		);
	if ( ! source ) {
		return '';
	}
	const values = fieldValues( source );
	return values.join( ', ' ).slice( 0, 80 );
}

function refreshRows( field: HTMLElement ): void {
	const rows = rowsOf( field );
	rows.forEach( ( row, i ) => {
		const index = row.querySelector(
			':scope > .wpff-row__handle .wpff-row__index'
		);
		if ( index ) {
			index.textContent = String( i + 1 );
		}
		const summary = row.querySelector(
			':scope > .wpff-row__body .wpff-row__summary'
		);
		if ( summary ) {
			summary.textContent = row.classList.contains( 'is-collapsed' )
				? rowSummary( row, field )
				: '';
		}
		row.querySelector< HTMLElement >(
			':scope > .wpff-row__handle'
		)!.draggable = true;
	} );
	paginate( field, rows );
	const max = parseInt( field.dataset.max ?? '', 10 ) || 0;
	const add = field.querySelector< HTMLButtonElement >(
		':scope > .wpff-rows__footer > .wpff-rows__add, :scope > .wpff-rows__footer > .wpff-flexible__add'
	);
	if ( add ) {
		add.disabled = !! max && rows.length >= max;
	}
	const empty = field.querySelector< HTMLElement >(
		':scope > .wpff-rows__empty'
	);
	if ( empty ) {
		empty.hidden = rows.length > 0;
	}
	rowError( field, '' );
}

/**
 * Repeater pagination: shows one page of rows at a time. Every row stays in the
 * form, so saving, validation and conditional logic work as without paging.
 *
 * @param field Repeater.
 * @param rows  Its rows.
 */
function paginate( field: HTMLElement, rows: HTMLElement[] ): void {
	const perPage = parseInt( field.dataset.perPage ?? '', 10 ) || 0;
	if ( ! perPage ) {
		return;
	}
	const pages = Math.max( 1, Math.ceil( rows.length / perPage ) );
	const page = Math.min(
		parseInt( field.dataset.page ?? '', 10 ) || 0,
		pages - 1
	);
	field.dataset.page = String( page );
	rows.forEach( ( row, i ) => {
		row.hidden = Math.floor( i / perPage ) !== page;
	} );

	let pager = field.querySelector< HTMLElement >(
		':scope > .wpff-rows__pager'
	);
	if ( ! pager ) {
		pager = document.createElement( 'nav' );
		pager.className = 'wpff-rows__pager';
		pager.setAttribute(
			'aria-label',
			__( 'Rows pagination', 'freeform-fields' )
		);
		pager.innerHTML =
			'<button type="button" class="button wpff-rows__page" data-step="-1"></button>' +
			'<span class="wpff-rows__page-label" aria-live="polite"></span>' +
			'<button type="button" class="button wpff-rows__page" data-step="1"></button>';
		pager.firstChild!.textContent = __( 'Previous', 'freeform-fields' );
		pager.lastChild!.textContent = __( 'Next', 'freeform-fields' );
		field
			.querySelector( ':scope > .wpff-rows' )!
			.insertAdjacentElement( 'afterend', pager );
	}
	pager.hidden = pages < 2;
	( pager.firstChild as HTMLButtonElement ).disabled = page === 0;
	( pager.lastChild as HTMLButtonElement ).disabled = page >= pages - 1;
	pager.querySelector( '.wpff-rows__page-label' )!.textContent = sprintf(
		/* translators: 1: current page, 2: number of pages. */
		__( 'Page %1$d of %2$d', 'freeform-fields' ),
		page + 1,
		pages
	);
}

/**
 * Switches every paginated repeater around an element to the page that holds it.
 *
 * @param el Element inside rows.
 */
function showRowPage( el: Element ): void {
	let row = el.closest< HTMLElement >( '.wpff-row' );
	while ( row ) {
		const field = row.closest< HTMLElement >( '.wpff-rows-field' )!;
		const perPage = parseInt( field.dataset.perPage ?? '', 10 ) || 0;
		if ( perPage ) {
			field.dataset.page = String(
				Math.floor( rowsOf( field ).indexOf( row ) / perPage )
			);
			refreshRows( field );
		}
		row = field.parentElement!.closest< HTMLElement >( '.wpff-row' );
	}
}

$( document ).on( 'click', '.wpff-rows__page', function ( this: HTMLElement ) {
	const field = this.closest< HTMLElement >( '.wpff-rows-field' )!;
	field.dataset.page = String(
		( parseInt( field.dataset.page ?? '', 10 ) || 0 ) +
			parseInt( this.dataset.step ?? '', 10 )
	);
	refreshRows( field );
	rowsOf( field )
		.find( ( r ) => ! r.hidden )
		?.querySelector< HTMLElement >(
			'input:not([type=hidden]), select, textarea'
		)
		?.focus();
} );

$( document ).on( 'click', '.wpff-rows__add', function ( this: HTMLElement ) {
	addRow( this.closest< HTMLElement >( '.wpff-rows-field' )!, null );
} );

// Flexible content: the "Add Row" menu, also used to insert above a row.
let insertBefore: HTMLElement | null = null;
function toggleLayoutMenu(
	field: Element,
	open: boolean,
	before?: HTMLElement | null
): void {
	const menu = field.querySelector< HTMLElement >(
		':scope > .wpff-flexible__footer > .wpff-flexible__menu'
	)!;
	const button = field.querySelector(
		':scope > .wpff-flexible__footer > .wpff-flexible__add'
	)!;
	insertBefore = open ? before || null : null;
	menu.hidden = ! open;
	button.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
	if ( open ) {
		menu.querySelector( 'button' )?.focus();
	}
}

$( document ).on(
	'click',
	'.wpff-flexible__add',
	function ( this: HTMLElement ) {
		const field = this.closest( '.wpff-rows-field' )!;
		toggleLayoutMenu(
			field,
			'false' === this.getAttribute( 'aria-expanded' )
		);
	}
);

$( document ).on(
	'click',
	'.wpff-flexible__menu button',
	function ( this: HTMLButtonElement ) {
		const field = this.closest< HTMLElement >( '.wpff-rows-field' )!;
		const before = insertBefore;
		toggleLayoutMenu( field, false );
		addRow( field, before, this.dataset.layout );
	}
);

$( document ).on(
	'keydown',
	'.wpff-flexible__menu',
	function ( this: HTMLElement, e ) {
		if ( 'Escape' === e.key ) {
			const field = this.closest( '.wpff-rows-field' )!;
			toggleLayoutMenu( field, false );
			field
				.querySelector< HTMLElement >(
					':scope > .wpff-flexible__footer > .wpff-flexible__add'
				)!
				.focus();
		}
	}
);

$( document ).on( 'click', '.wpff-row__insert', function ( this: HTMLElement ) {
	const row = this.closest< HTMLElement >( '.wpff-row' )!;
	const field = row.closest< HTMLElement >( '.wpff-rows-field' )!;
	if ( field.classList.contains( 'wpff-flexible' ) ) {
		toggleLayoutMenu( field, true, row );
	} else {
		addRow( field, row );
	}
} );

$( document ).on(
	'click',
	'.wpff-row__duplicate',
	function ( this: HTMLElement ) {
		duplicateRow( this.closest< HTMLElement >( '.wpff-row' )! );
	}
);

$( document ).on( 'click', '.wpff-row__remove', function ( this: HTMLElement ) {
	removeRow( this.closest< HTMLElement >( '.wpff-row' )! );
} );

$( document ).on(
	'click',
	'.wpff-row__collapse',
	function ( this: HTMLElement ) {
		const row = this.closest< HTMLElement >( '.wpff-row' )!;
		const collapsed = row.classList.toggle( 'is-collapsed' );
		this.setAttribute( 'aria-expanded', collapsed ? 'false' : 'true' );
		refreshRows( row.closest< HTMLElement >( '.wpff-rows-field' )! );
	}
);

// Drag rows by their handle; only within the same list.
let draggedRow: HTMLElement | null = null;
$( document ).on(
	'dragstart',
	'.wpff-row__handle',
	function ( this: HTMLElement, e ) {
		draggedRow = this.closest< HTMLElement >( '.wpff-row' )!;
		e.originalEvent!.dataTransfer!.effectAllowed = 'move';
		e.originalEvent!.dataTransfer!.setDragImage( draggedRow, 10, 10 );
		draggedRow.classList.add( 'is-dragging' );
		e.stopPropagation();
	}
);
$( document ).on( 'dragover', '.wpff-row', function ( this: HTMLElement, e ) {
	if (
		! draggedRow ||
		draggedRow === this ||
		draggedRow.parentElement !== this.parentElement
	) {
		return;
	}
	e.preventDefault();
	e.stopPropagation();
	const rect = this.getBoundingClientRect();
	const after = e.originalEvent!.clientY > rect.top + rect.height / 2;
	this.parentElement!.insertBefore(
		draggedRow,
		after ? this.nextSibling : this
	);
} );
$( document ).on( 'dragend', '.wpff-row__handle', function () {
	if ( ! draggedRow ) {
		return;
	}
	draggedRow.classList.remove( 'is-dragging' );
	refreshRows( draggedRow.closest< HTMLElement >( '.wpff-rows-field' )! );
	$( draggedRow ).find( ':input' ).first().trigger( 'change' );
	draggedRow = null;
} );

// Gallery.
$( document ).on(
	'click',
	'.wpff-gallery__add',
	function ( this: HTMLElement, e ) {
		e.preventDefault();
		const gallery = this.closest< HTMLElement >( '.wpff-gallery' )!;
		const max = parseInt( gallery.dataset.max ?? '', 10 ) || 0;
		const media = mediaModal();
		if ( ! media ) {
			return;
		}
		const library = mediaLibrary( gallery.dataset.library, 'image' );
		const frame = media( {
			title: __( 'Add Image to Gallery', 'freeform-fields' ),
			button: { text: __( 'Add to gallery', 'freeform-fields' ) },
			library,
			multiple: 'add',
		} );
		frame.on( 'select', () => {
			const list = gallery.querySelector( '.wpff-gallery__items' )!;
			const existing = Array.from( list.querySelectorAll( 'input' ) ).map(
				( i ) => i.value
			);
			frame
				.state()
				.get( 'selection' )
				.toJSON()
				.forEach( ( attachment ) => {
					if (
						existing.includes( String( attachment.id ) ) ||
						( max && list.children.length >= max )
					) {
						return;
					}
					const li = document.createElement( 'li' );
					li.className = 'wpff-gallery__item';
					li.draggable = true;
					const img = document.createElement( 'img' );
					img.src =
						( attachment.sizes &&
							attachment.sizes.thumbnail &&
							attachment.sizes.thumbnail.url ) ||
						attachment.url;
					img.alt = attachment.alt || '';
					const input = document.createElement( 'input' );
					input.type = 'hidden';
					input.name = gallery.dataset.inputName as string;
					input.value = String( attachment.id );
					const remove = document.createElement( 'button' );
					remove.type = 'button';
					remove.className = 'wpff-gallery__remove';
					remove.setAttribute(
						'aria-label',
						__( 'Remove image', 'freeform-fields' )
					);
					remove.textContent = '×';
					li.append( img, input, remove );
					list.appendChild( li );
				} );
			$( gallery ).find( 'input' ).first().trigger( 'change' );
		} );
		frame.open();
	}
);

$( document ).on(
	'click',
	'.wpff-gallery__remove',
	function ( this: HTMLElement ) {
		const gallery = this.closest( '.wpff-gallery' )!;
		this.closest( '.wpff-gallery__item' )!.remove();
		$( gallery ).find( 'input' ).first().trigger( 'change' );
	}
);

let draggedImage: HTMLElement | null = null;
$( document ).on(
	'dragstart',
	'.wpff-gallery__item',
	function ( this: HTMLElement ) {
		draggedImage = this;
		this.classList.add( 'is-dragging' );
	}
);
$( document ).on(
	'dragover',
	'.wpff-gallery__item',
	function ( this: HTMLElement, e ) {
		if (
			! draggedImage ||
			draggedImage === this ||
			draggedImage.parentElement !== this.parentElement
		) {
			return;
		}
		e.preventDefault();
		const rect = this.getBoundingClientRect();
		const after = e.originalEvent!.clientX > rect.left + rect.width / 2;
		this.parentElement!.insertBefore(
			draggedImage,
			after ? this.nextSibling : this
		);
	}
);
$( document ).on(
	'dragend',
	'.wpff-gallery__item',
	function ( this: HTMLElement ) {
		this.classList.remove( 'is-dragging' );
		draggedImage = null;
	}
);

/* =====================================================================
 * wp.data store: read and write field values from other scripts
 * ================================================================== */

/**
 * Top-level field on the page by name or key.
 *
 * @param selector Field name or key.
 * @return Wrapper.
 */
function topField( selector: string ): HTMLElement | undefined {
	return Array.from(
		document.querySelectorAll< HTMLElement >( '.wpff-field' )
	).find(
		( f ) =>
			! f.closest( '.wpff-block-form' ) &&
			! ( f.dataset.path || '' ).includes( '.' ) &&
			( f.dataset.name === selector || f.dataset.key === selector )
	);
}

function isListField( field: Element ): boolean {
	return !! field.querySelector( ':scope > .wpff-input [name$="[]"]' );
}

function readValue( field: HTMLElement ): boolean | string | string[] {
	const values = fieldValues( field );
	if ( 'true_false' === field.dataset.type ) {
		return '1' === values[ 0 ];
	}
	return isListField( field ) ? values : ( values[ 0 ] ?? '' );
}

/**
 * Writes a value into a simple field's inputs and fires change events.
 *
 * @param field Wrapper.
 * @param value Value (array for list fields).
 * @return Whether the field type supports writing.
 */
function writeValue( field: HTMLElement, value: unknown ): boolean {
	const input = field.querySelector( ':scope > .wpff-input' )!;
	const list = ( Array.isArray( value ) ? value : [ value ] ).map( String );
	const type = field.dataset.type as string;
	if ( 'true_false' === type ) {
		input.querySelector< HTMLInputElement >(
			'input[type=checkbox]'
		)!.checked = !! value && '0' !== value;
	} else if ( [ 'checkbox', 'radio', 'button_group' ].includes( type ) ) {
		input
			.querySelectorAll< HTMLInputElement >(
				'input[type=checkbox], input[type=radio]'
			)
			.forEach( ( el ) => {
				el.checked = list.includes( el.value );
			} );
	} else if ( 'select' === type ) {
		Array.from( input.querySelector( 'select' )!.options ).forEach(
			( o ) => {
				o.selected = list.includes( o.value );
			}
		);
	} else if (
		[
			'text',
			'textarea',
			'number',
			'email',
			'url',
			'password',
			'range',
			'date_picker',
			'date_time_picker',
			'time_picker',
			'color_picker',
			'wysiwyg',
			'oembed',
		].includes( type )
	) {
		const el = input.querySelector<
			HTMLInputElement | HTMLTextAreaElement
		>( 'input:not([type=hidden]):not(.wpff-range__slider), textarea' )!;
		el.value = list[ 0 ] ?? '';
		if (
			'color_picker' === type &&
			( $.fn as Partial< ColorPickerJQuery > ).wpColorPicker &&
			$( el ).data( 'wpWpColorPicker' )
		) {
			( $( el ) as ColorPickerJQuery< typeof el > ).wpColorPicker(
				'color',
				el.value
			);
		}
		if ( 'wysiwyg' === type && win.tinymce && win.tinymce.get( el.id ) ) {
			win.tinymce.get( el.id )!.setContent( el.value );
		}
	} else {
		return false;
	}
	$( input ).find( ':input' ).first().trigger( 'change' );
	return true;
}

function initStore(): void {
	if (
		! wp.data ||
		! wp.data.createReduxStore ||
		wp.data.select( 'wpff/fields' )
	) {
		return;
	}
	// Selectors read the form; `version` changes on every edit so subscribers re-render.
	const cache: { version: number; values: Record< string, unknown > } = {
		version: -1,
		values: {},
	};
	const memo = < T >( state: StoreState, key: string, compute: () => T ) => {
		if ( cache.version !== state.version ) {
			cache.version = state.version;
			cache.values = {};
		}
		if ( ! ( key in cache.values ) ) {
			cache.values[ key ] = compute();
		}
		return cache.values[ key ] as T;
	};
	const store = wp.data.createReduxStore( 'wpff/fields', {
		reducer(
			state: StoreState = { version: 0 },
			action: { type: string }
		) {
			return 'CHANGED' === action.type
				? { version: state.version + 1 }
				: state;
		},
		selectors: {
			getFieldValue( state: StoreState, selector: string ) {
				return memo( state, `one:${ selector }`, () => {
					const field = topField( selector );
					return field ? readValue( field ) : undefined;
				} );
			},
			getFieldValues( state: StoreState ) {
				return memo( state, 'all', () => {
					const out: Record<
						string,
						ReturnType< typeof readValue >
					> = {};
					document
						.querySelectorAll< HTMLElement >(
							'.wpff-field[data-name]'
						)
						.forEach( ( field ) => {
							if (
								field.dataset.name &&
								! ( field.dataset.path || '' ).includes(
									'.'
								) &&
								! field.closest( '.wpff-block-form' )
							) {
								out[ field.dataset.name ] = readValue( field );
							}
						} );
					return out;
				} );
			},
		},
		actions: {
			setFieldValue( selector: string, value: unknown ) {
				const field = topField( selector );
				if ( ! field || ! writeValue( field, value ) ) {
					window.console.warn(
						`wpff/fields: cannot set "${ selector }"`
					);
				}
				return { type: 'CHANGED' };
			},
			notifyChange() {
				return { type: 'CHANGED' };
			},
		},
	} );
	wp.data.register( store );
	$( document ).on(
		'input change wpff:conditions',
		'.wpff-field :input',
		() => wp.data!.dispatch( 'wpff/fields' ).notifyChange()
	);
}

// Used by the field block editor script (build/blocks.js).
window.wpff = {
	initFieldsIn,
	applyConditions,
	showErrors,
	clearErrors,
	syncEditors,
	removeEditors,
};

/* =====================================================================
 * Custom selects
 *
 * The native <select> stays in the form (submission, conditional logic,
 * the wpff/fields store); a button and a listbox popup replace its looks.
 * Long lists get a search box; multiple selects show their values as chips.
 * ================================================================== */

const SELECT_SEARCH_FROM = 9;
const selectUi = new WeakMap< HTMLSelectElement, SelectState >();
let openSelect: OpenSelect | null = null;

function selectOptions( select: HTMLSelectElement ): SelectOption[] {
	return Array.from( select.options ).map( ( o ) => ( {
		value: o.value,
		label: o.textContent as string,
		disabled: o.disabled,
		selected: o.selected,
		placeholder: '' === o.value,
	} ) );
}

function initSelects( root: ParentNode ): void {
	root.querySelectorAll< HTMLSelectElement >(
		'.wpff-input select.wpff-control'
	).forEach( ( select ) => {
		if ( select.closest( 'template' ) || selectUi.has( select ) ) {
			return;
		}
		// A duplicated row copies the old UI next to the select: drop it.
		select
			.parentNode!.querySelectorAll( ':scope > .wpff-cselect' )
			.forEach( ( el ) => el.remove() );
		buildSelect( select );
	} );
}

function buildSelect( select: HTMLSelectElement ): void {
	const multiple = select.multiple;
	const ui = document.createElement( 'div' );
	ui.className = `wpff-cselect${ multiple ? ' is-multiple' : '' }`;
	const button = document.createElement( 'button' );
	button.type = 'button';
	button.className = 'wpff-cselect__button';
	button.setAttribute( 'role', 'combobox' );
	button.setAttribute( 'aria-haspopup', 'listbox' );
	button.setAttribute( 'aria-expanded', 'false' );
	const labelEl = select.id
		? document.querySelector< HTMLLabelElement >(
				`label[for="${ select.id }"]`
			)
		: null;
	if ( labelEl ) {
		labelEl.id = labelEl.id || `${ select.id }-label`;
		labelEl.htmlFor = `${ select.id }-ui`;
		button.setAttribute( 'aria-labelledby', labelEl.id );
	}
	button.id = `${ select.id || 'wpff-select' }-ui`;
	if ( select.getAttribute( 'aria-required' ) ) {
		button.setAttribute( 'aria-required', 'true' );
	}
	ui.appendChild( button );

	select.classList.add( 'wpff-cselect__native' );
	select.tabIndex = -1;
	select.setAttribute( 'aria-hidden', 'true' );
	select.after( ui );

	const state: SelectState = { select, ui, button, multiple };
	selectUi.set( select, state );
	renderSelectValue( state );

	button.addEventListener( 'click', () =>
		openSelect && openSelect.select === select
			? closeSelectPopup( true )
			: openSelectPopup( state )
	);
	button.addEventListener( 'keydown', ( e ) => {
		if ( [ 'ArrowDown', 'ArrowUp', 'Enter', ' ' ].includes( e.key ) ) {
			e.preventDefault();
			openSelectPopup( state );
		} else if (
			1 === e.key.length &&
			/\S/.test( e.key ) &&
			! e.metaKey &&
			! e.ctrlKey
		) {
			openSelectPopup( state, e.key );
			e.preventDefault();
		} else if ( 'Backspace' === e.key && multiple ) {
			const last = Array.from( select.selectedOptions ).pop();
			if ( last ) {
				last.selected = false;
				selectChanged( state );
			}
		}
	} );
	// Values set from code (the wpff/fields store, conditional resets) update the button.
	select.addEventListener( 'change', () => renderSelectValue( state ) );
	select.addEventListener( 'focus', () => button.focus() );
}

function renderSelectValue( state: SelectState ): void {
	const { select, button, multiple } = state;
	button.replaceChildren();
	const chosen = selectOptions( select ).filter(
		( o ) => o.selected && ! o.placeholder
	);
	if ( multiple ) {
		chosen.forEach( ( o ) => {
			const chip = document.createElement( 'span' );
			chip.className = 'wpff-cselect__chip';
			chip.textContent = o.label;
			const remove = document.createElement( 'span' );
			remove.className = 'wpff-cselect__chip-remove';
			remove.setAttribute( 'role', 'button' );
			remove.setAttribute(
				'aria-label',
				/* translators: %s: option label. */
				sprintf( __( 'Remove %s', 'freeform-fields' ), o.label )
			);
			remove.textContent = '×';
			remove.addEventListener( 'click', ( e ) => {
				e.stopPropagation();
				Array.from( select.options ).find(
					( opt ) => opt.value === o.value
				)!.selected = false;
				selectChanged( state );
			} );
			chip.appendChild( remove );
			button.appendChild( chip );
		} );
	}
	if ( ! multiple || ! chosen.length ) {
		const text = document.createElement( 'span' );
		const placeholder = selectOptions( select ).find(
			( o ) => o.placeholder
		);
		text.className = `wpff-cselect__value${
			chosen.length ? '' : ' is-placeholder'
		}`;
		if ( chosen.length ) {
			text.textContent = chosen[ 0 ].label;
		} else {
			text.textContent = placeholder
				? placeholder.label
				: __( 'Select…', 'freeform-fields' );
		}
		button.appendChild( text );
	}
	const caret = document.createElement( 'span' );
	caret.className = 'wpff-cselect__caret';
	caret.setAttribute( 'aria-hidden', 'true' );
	button.appendChild( caret );
}

function selectChanged( state: SelectState ): void {
	// A native event reaches both jQuery handlers and plain listeners (store, conditions).
	state.select.dispatchEvent(
		new window.Event( 'change', { bubbles: true } )
	);
	renderSelectValue( state );
	if ( openSelect && openSelect.state === state ) {
		renderSelectList( openSelect );
	}
}

function openSelectPopup( state: SelectState, firstKey?: string ): void {
	closeSelectPopup( false );
	const { select, button } = state;
	const options = selectOptions( select ).filter(
		( o ) => ! ( state.multiple && o.placeholder )
	);
	const withSearch = options.length >= SELECT_SEARCH_FROM;
	const popup = document.createElement( 'div' );
	popup.className = 'wpff-cselect__popup';
	const listId = `${ button.id }-list`;
	let search: HTMLInputElement | null = null;
	if ( withSearch ) {
		search = document.createElement( 'input' );
		search.type = 'search';
		search.className = 'wpff-cselect__search';
		search.placeholder = __( 'Search…', 'freeform-fields' );
		search.setAttribute(
			'aria-label',
			__( 'Search options', 'freeform-fields' )
		);
		search.setAttribute( 'aria-controls', listId );
		popup.appendChild( search );
	}
	const list = document.createElement( 'ul' );
	list.className = 'wpff-cselect__list';
	list.id = listId;
	list.setAttribute( 'role', 'listbox' );
	list.tabIndex = withSearch ? -1 : 0;
	if ( state.multiple ) {
		list.setAttribute( 'aria-multiselectable', 'true' );
	}
	popup.appendChild( list );
	document.body.appendChild( popup );

	openSelect = {
		state,
		select,
		popup,
		list,
		search,
		query: withSearch && firstKey ? firstKey : '',
		active: -1,
		visible: [],
	};
	if ( search ) {
		search.value = openSelect.query;
	}
	button.setAttribute( 'aria-expanded', 'true' );
	button.setAttribute( 'aria-controls', listId );
	button.classList.add( 'is-open' );
	renderSelectList( openSelect, true );
	positionSelectPopup();
	( search || list ).focus();
	if ( ! withSearch && firstKey ) {
		selectTypeAhead( firstKey );
	}

	const onKey = ( e: KeyboardEvent ) => selectListKey( e );
	( ( search || list ) as HTMLElement ).addEventListener( 'keydown', onKey );
	if ( search ) {
		const input = search;
		input.addEventListener( 'input', () => {
			// Only fires while this popup is open: it is removed with the popup.
			openSelect!.query = input.value;
			renderSelectList( openSelect!, true );
		} );
	}
}

function renderSelectList( open: OpenSelect, resetActive = false ): void {
	const { state, list, query } = open;
	const q = query.trim().toLowerCase();
	open.visible = selectOptions( state.select ).filter(
		( o ) =>
			! ( state.multiple && o.placeholder ) &&
			( ! q || o.label.toLowerCase().includes( q ) )
	);
	if ( resetActive ) {
		const selected = open.visible.findIndex( ( o ) => o.selected );
		open.active =
			! q && selected >= 0
				? selected
				: open.visible.findIndex( ( o ) => ! o.disabled );
	}
	list.replaceChildren();
	open.visible.forEach( ( o, i ) => {
		const li = document.createElement( 'li' );
		li.id = `${ list.id }-${ i }`;
		li.setAttribute( 'role', 'option' );
		li.setAttribute( 'aria-selected', o.selected ? 'true' : 'false' );
		li.className = [
			'wpff-cselect__option',
			i === open.active && 'is-active',
			o.selected && 'is-selected',
			o.disabled && 'is-disabled',
			o.placeholder && 'is-placeholder',
		]
			.filter( Boolean )
			.join( ' ' );
		li.textContent = o.label;
		li.addEventListener( 'mousedown', ( e ) => e.preventDefault() );
		li.addEventListener( 'mouseenter', () => {
			open.active = i;
			highlightSelectOption();
		} );
		li.addEventListener( 'click', () => chooseSelectOption( i ) );
		list.appendChild( li );
	} );
	if ( ! open.visible.length ) {
		const none = document.createElement( 'li' );
		none.className = 'wpff-cselect__none';
		none.textContent = __( 'Nothing found.', 'freeform-fields' );
		list.appendChild( none );
	}
	highlightSelectOption();
}

function highlightSelectOption(): void {
	if ( ! openSelect ) {
		return;
	}
	const { list, active, search } = openSelect;
	list.querySelectorAll( '.wpff-cselect__option' ).forEach( ( li, i ) =>
		li.classList.toggle( 'is-active', i === active )
	);
	const id = active >= 0 ? `${ list.id }-${ active }` : '';
	( search || list ).setAttribute( 'aria-activedescendant', id );
	list.querySelector( `[id="${ id }"]` )?.scrollIntoView( {
		block: 'nearest',
	} );
}

function chooseSelectOption( i: number ): void {
	// Only called from the open popup.
	const { state, visible } = openSelect!;
	const option = visible[ i ];
	if ( ! option || option.disabled ) {
		return;
	}
	const native = Array.from( state.select.options ).find(
		( o ) => o.value === option.value
	)!;
	if ( state.multiple ) {
		// Multiple: toggle and keep the list open.
		native.selected = ! native.selected;
		openSelect!.active = i;
		selectChanged( state );
		return;
	}
	const changed = ! native.selected;
	native.selected = true;
	closeSelectPopup( true );
	if ( changed ) {
		selectChanged( state );
	}
}

function selectTypeAhead( letter: string ): void {
	// Only called while a popup is open.
	const open = openSelect!;
	const { visible, active } = open;
	const lower = letter.toLowerCase();
	for ( let n = 1; n <= visible.length; n++ ) {
		const k = ( active + n ) % visible.length;
		if (
			! visible[ k ].disabled &&
			visible[ k ].label.toLowerCase().startsWith( lower )
		) {
			open.active = k;
			highlightSelectOption();
			return;
		}
	}
}

function selectListKey( e: KeyboardEvent ): void {
	const open = openSelect;
	if ( ! open ) {
		return;
	}
	const move = ( delta: number ) => {
		const count = open.visible.length;
		let i = open.active;
		for ( let n = 0; n < count; n++ ) {
			i = ( i + delta + count ) % count;
			if ( ! open.visible[ i ].disabled ) {
				break;
			}
		}
		open.active = i;
		highlightSelectOption();
	};
	switch ( e.key ) {
		case 'ArrowDown':
			e.preventDefault();
			move( 1 );
			break;
		case 'ArrowUp':
			e.preventDefault();
			move( -1 );
			break;
		case 'Home':
			e.preventDefault();
			open.active = -1;
			move( 1 );
			break;
		case 'End':
			e.preventDefault();
			open.active = open.visible.length;
			move( -1 );
			break;
		case 'Enter':
			e.preventDefault();
			chooseSelectOption( open.active );
			break;
		case ' ':
			if ( ! open.search ) {
				e.preventDefault();
				chooseSelectOption( open.active );
			}
			break;
		case 'Escape':
			e.preventDefault();
			e.stopPropagation();
			closeSelectPopup( true );
			break;
		case 'Tab':
			closeSelectPopup( false );
			break;
		default:
			if ( ! open.search && 1 === e.key.length ) {
				selectTypeAhead( e.key );
			}
	}
}

function positionSelectPopup(): void {
	if ( ! openSelect ) {
		return;
	}
	const { popup, state } = openSelect;
	const rect = state.button.getBoundingClientRect();
	const below = window.innerHeight - rect.bottom;
	popup.style.minWidth = `${ Math.max( rect.width, 200 ) }px`;
	popup.style.left = `${ Math.max(
		8,
		Math.min( rect.left, window.innerWidth - popup.offsetWidth - 8 )
	) }px`;
	if ( below < 260 && rect.top > below ) {
		popup.style.top = '';
		popup.style.bottom = `${ window.innerHeight - rect.top + 4 }px`;
		popup.style.maxHeight = `${ Math.min( 320, rect.top - 16 ) }px`;
	} else {
		popup.style.bottom = '';
		popup.style.top = `${ rect.bottom + 4 }px`;
		popup.style.maxHeight = `${ Math.min( 320, below - 16 ) }px`;
	}
}

function closeSelectPopup( refocus: boolean ): void {
	if ( ! openSelect ) {
		return;
	}
	const { popup, state } = openSelect;
	popup.remove();
	state.button.setAttribute( 'aria-expanded', 'false' );
	state.button.removeAttribute( 'aria-controls' );
	state.button.classList.remove( 'is-open' );
	openSelect = null;
	if ( refocus ) {
		state.button.focus();
	}
}

document.addEventListener( 'mousedown', ( e ) => {
	const target = e.target as Node | null;
	if (
		openSelect &&
		! openSelect.popup.contains( target ) &&
		! openSelect.state.ui.contains( target )
	) {
		closeSelectPopup( false );
	}
} );
window.addEventListener( 'resize', positionSelectPopup );
// Follow the field while the page or a panel scrolls.
document.addEventListener( 'scroll', positionSelectPopup, true );

/* =====================================================================
 * Boot
 * ================================================================== */

/**
 * The "Add term" form stays on the page after a term is added over AJAX. Core clears its
 * own inputs; the fields are put back to their initial state here, as in a fresh form,
 * so the next term does not inherit the previous term's image, color or choices.
 */
function initTermAddReset(): void {
	const box = document.querySelector( '#addtag .wpff-term-fields' );
	if ( ! box ) {
		return;
	}
	const initial = box.innerHTML;
	$( document ).ajaxSuccess( ( event, xhr, settings ) => {
		const data = String( settings && settings.data ? settings.data : '' );
		const text = String( xhr && xhr.responseText ? xhr.responseText : '' );
		if (
			! /(^|&)action=add-tag(&|$)/.test( data ) ||
			text.includes( '<wp_error' )
		) {
			return;
		}
		removeEditors( box );
		box.innerHTML = initial;
		initFieldsIn( box );
	} );
}

$( function () {
	// Before the fields are initialized: the reset puts back this untouched markup.
	initTermAddReset();
	const containers = document.querySelectorAll( '.wpff-fields, .wpff-group' );
	containers.forEach( ( c ) => initAccordions( c ) );
	document
		.querySelectorAll( '.wpff-fields, .wpff-group' )
		.forEach( ( c ) => initTabs( c ) );
	initColor( document );
	bootWysiwyg();
	initPickers( document );
	initSelects( document );
	initStore();
	document
		.querySelectorAll< HTMLElement >( '.wpff-rows-field' )
		.forEach( refreshRows );
	observer.observe( document.body, { childList: true, subtree: true } );
	applyConditions();

	if ( cfg.blockEditor ) {
		initBlockEditor();
		return;
	}
	const form = document.querySelector< HTMLFormElement >(
		cfg.formSelector || '#post'
	);
	if ( form ) {
		initAjaxValidation( $< HTMLFormElement >( form ) );
	}
} );
