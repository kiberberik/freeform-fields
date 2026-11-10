/**
 * Building blocks of the settings screens (post types, taxonomies, options pages):
 * titled sections, toggle rows, the menu icon picker and the menu preview.
 */
import { useMemo, useState } from '@wordpress/element';
import { useInstanceId } from '@wordpress/compose';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	BaseControl,
	Button,
	Dropdown,
	SearchControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { DASHICONS } from './dashicons';

/**
 * A titled panel with a short explanation and its controls.
 *
 * @param {Object} props
 * @param {string} props.title         Section title.
 * @param {string} [props.description] One line under the title.
 * @param {*}      props.children      Controls.
 */
export function SettingsSection( { title, description, children } ) {
	return (
		<section className="wpff-panel wpff-settings-section">
			<header className="wpff-settings-section__header">
				<h2>{ title }</h2>
				{ description && <p>{ description }</p> }
			</header>
			<div className="wpff-settings-section__body">{ children }</div>
		</section>
	);
}

/**
 * Two columns of controls that stack on narrow screens.
 *
 * @param {Object} props
 * @param {*}      props.children Controls.
 */
export function SettingsGrid( { children } ) {
	return <div className="wpff-settings-grid">{ children }</div>;
}

/**
 * Toggles as rows: label and explanation on the left, the switch on the right.
 *
 * @param {Object} props
 * @param {Array}  props.items [{ label, help, checked, onChange, hidden }].
 */
export function ToggleRows( { items } ) {
	return (
		<div className="wpff-toggle-rows">
			{ items
				.filter( ( item ) => ! item.hidden )
				.map( ( item ) => (
					<div className="wpff-toggle-row" key={ item.label }>
						<ToggleControl
							label={ item.label }
							help={ item.help }
							checked={ !! item.checked }
							onChange={ item.onChange }
							__nextHasNoMarginBottom
						/>
					</div>
				) ) }
		</div>
	);
}

/**
 * Whether a menu icon value is a dashicon (as opposed to an image URL or data URI).
 *
 * @param {string} value Icon value.
 */
const isDashicon = ( value ) => /^dashicons-[a-z0-9-]+$/.test( value || '' );

/**
 * The icon as WordPress shows it in the menu: a dashicon or an image.
 *
 * @param {Object} props
 * @param {string} props.value Dashicons class, image URL or data URI.
 */
export function MenuIcon( { value } ) {
	if ( isDashicon( value ) ) {
		return <span className={ `dashicons ${ value }` } aria-hidden="true" />;
	}
	if ( value && /^(https?:|data:image\/)/.test( value ) ) {
		return <img className="wpff-menu-icon__image" src={ value } alt="" />;
	}
	return (
		<span
			className="dashicons dashicons-admin-generic"
			aria-hidden="true"
		/>
	);
}

/**
 * Menu icon: pick a dashicon from a searchable grid, or type a class or an image URL.
 *
 * @param {Object}   props
 * @param {string}   props.label      Label.
 * @param {string}   props.value      Current value.
 * @param {Function} props.onChange   Called with the new value.
 * @param {string}   [props.help]     Help text.
 * @param {boolean}  [props.allowUrl] Whether image URLs are accepted (options pages).
 */
export function IconPicker( { label, value, onChange, help, allowUrl } ) {
	const id = useInstanceId( IconPicker, 'wpff-icon-picker' );
	const [ search, setSearch ] = useState( '' );
	const query = search
		.trim()
		.toLowerCase()
		.replace( /^dashicons-/, '' );
	const icons = useMemo(
		() =>
			query
				? DASHICONS.filter( ( name ) => name.includes( query ) )
				: DASHICONS,
		[ query ]
	);

	return (
		<BaseControl
			id={ id }
			className="wpff-icon-picker"
			label={ label }
			help={ help }
			__nextHasNoMarginBottom
		>
			<div className="wpff-icon-picker__row">
				<Dropdown
					className="wpff-icon-picker__dropdown"
					contentClassName="wpff-icon-picker__popover"
					popoverProps={ { placement: 'bottom-start' } }
					renderToggle={ ( { isOpen, onToggle } ) => (
						<Button
							className="wpff-icon-picker__toggle"
							variant="secondary"
							onClick={ onToggle }
							aria-expanded={ isOpen }
							__next40pxDefaultSize
						>
							<MenuIcon value={ value } />
							{ __( 'Choose…', 'freeform-fields' ) }
						</Button>
					) }
					renderContent={ ( { onClose } ) => (
						<div className="wpff-icon-picker__panel">
							<SearchControl
								value={ search }
								onChange={ setSearch }
								placeholder={ __(
									'Search icons (book, cart, star…)',
									'freeform-fields'
								) }
								__nextHasNoMarginBottom
							/>
							<p className="wpff-icon-picker__count">
								{ sprintf(
									/* translators: %d: number of icons. */
									_n(
										'%d icon',
										'%d icons',
										icons.length,
										'freeform-fields'
									),
									icons.length
								) }
							</p>
							<div
								className="wpff-icon-picker__grid"
								role="listbox"
								aria-label={ label }
							>
								{ icons.map( ( name ) => {
									const cls = `dashicons-${ name }`;
									return (
										<button
											key={ name }
											type="button"
											role="option"
											aria-selected={ cls === value }
											className={
												cls === value
													? 'is-selected'
													: ''
											}
											title={ name }
											aria-label={ name }
											onClick={ () => {
												onChange( cls );
												onClose();
											} }
										>
											<span
												className={ `dashicons ${ cls }` }
												aria-hidden="true"
											/>
										</button>
									);
								} ) }
								{ 0 === icons.length && (
									<p className="wpff-icon-picker__empty">
										{ __(
											'No icons found. Search uses English names.',
											'freeform-fields'
										) }
									</p>
								) }
							</div>
						</div>
					) }
				/>
				<TextControl
					id={ id }
					className="wpff-icon-picker__input"
					hideLabelFromVision
					label={ __( 'Icon class or image URL', 'freeform-fields' ) }
					value={ value || '' }
					onChange={ onChange }
					placeholder={
						allowUrl
							? 'dashicons-admin-generic / https://…'
							: 'dashicons-admin-post'
					}
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>
			</div>
		</BaseControl>
	);
}

/**
 * How the item will look in the admin menu: icon and label on the dark menu bar.
 *
 * @param {Object} props
 * @param {string} props.icon    Menu icon value.
 * @param {string} props.label   Menu label.
 * @param {string} [props.under] Parent menu name when the item is a sub item.
 */
export function MenuPreview( { icon, label, under } ) {
	const name = label || __( '(no title)', 'freeform-fields' );
	return (
		<div className="wpff-menu-preview" aria-hidden="true">
			{ under ? (
				<>
					<div className="wpff-menu-preview__item is-parent">
						<span className="dashicons dashicons-menu" />
						<span>{ under }</span>
					</div>
					<div className="wpff-menu-preview__sub">{ name }</div>
				</>
			) : (
				<div className="wpff-menu-preview__item is-current">
					<MenuIcon value={ icon } />
					<span>{ name }</span>
				</div>
			) }
		</div>
	);
}

/**
 * Sidebar card: a title and its content.
 *
 * @param {Object} props
 * @param {string} props.title    Card title.
 * @param {*}      props.children Content.
 */
export function SideCard( { title, children } ) {
	return (
		<div className="wpff-panel wpff-side-card">
			<h2 className="wpff-side-card__title">{ title }</h2>
			<div className="wpff-side-card__body">{ children }</div>
		</div>
	);
}
