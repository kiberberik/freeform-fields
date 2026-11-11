/**
 * Custom select: a button that opens a listbox in a popover, with search for long lists,
 * option groups and icons. Drop-in for SelectControl (same label/value/options/onChange props).
 *
 * Keyboard: ↑/↓ or typing a letter opens it; ↑/↓, Home/End move, Enter picks, Esc closes.
 */
import type { KeyboardEvent, ReactNode } from 'react';
import {
	useEffect,
	useLayoutEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { useInstanceId } from '@wordpress/compose';
import { __ } from '@wordpress/i18n';
import { BaseControl, Icon, Popover } from '@wordpress/components';
import { check, chevronDown } from '@wordpress/icons';
import type { SelectOption } from './types';

export type { SelectOption };

interface SelectProps {
	label: string;
	hideLabelFromVision?: boolean;
	value: string | number | null | undefined;
	options?: SelectOption[];
	onChange: ( value: string ) => void;
	help?: ReactNode;
	className?: string;
	placeholder?: string;
	disabled?: boolean;
	searchable?: boolean;
	/** Accepted for SelectControl compatibility; the control always renders at this size. */
	__next40pxDefaultSize?: boolean;
	/** Accepted for SelectControl compatibility; the control always renders without a margin. */
	__nextHasNoMarginBottom?: boolean;
}

const SEARCH_FROM = 9;

/**
 * @param props
 * @param props.label               Label.
 * @param props.hideLabelFromVision Visually hidden label.
 * @param props.value               Selected value.
 * @param props.options             [{ value, label, group?, icon?, hint?, disabled? }].
 * @param props.onChange            Receives the new value.
 * @param props.help                Help text.
 * @param props.className           Extra class.
 * @param props.placeholder         Shown when no option matches the value.
 * @param props.disabled            Disabled.
 * @param props.searchable          Force the search box on or off.
 */
export default function Select( {
	label,
	hideLabelFromVision,
	value,
	options = [],
	onChange,
	help,
	className = '',
	placeholder,
	disabled,
	searchable,
}: SelectProps ) {
	const id = useInstanceId( Select, 'wpff-select' );
	const buttonRef = useRef< HTMLButtonElement >( null );
	const listRef = useRef< HTMLUListElement >( null );
	const searchRef = useRef< HTMLInputElement >( null );
	const [ open, setOpen ] = useState( false );
	const [ query, setQuery ] = useState( '' );
	const [ active, setActive ] = useState( -1 );
	const [ width, setWidth ] = useState( 0 );

	const withSearch = searchable ?? options.length >= SEARCH_FROM;
	const current = options.find(
		( o ) => String( o.value ) === String( value )
	);

	const visible = useMemo( () => {
		const q = query.trim().toLowerCase();
		// Keep each group together, in the order groups first appear.
		const order: Array< string | undefined > = [];
		options.forEach( ( o ) => {
			if ( ! order.includes( o.group ) ) {
				order.push( o.group );
			}
		} );
		const grouped = order.flatMap( ( g ) =>
			options.filter( ( o ) => o.group === g )
		);
		return grouped.filter(
			( o ) =>
				! q ||
				String( o.label ).toLowerCase().includes( q ) ||
				String( o.group || '' )
					.toLowerCase()
					.includes( q ) ||
				String( o.value ).toLowerCase().includes( q )
		);
	}, [ options, query ] );

	const openList = () => {
		if ( disabled ) {
			return;
		}
		setWidth( buttonRef.current?.offsetWidth || 0 );
		setQuery( '' );
		setActive(
			Math.max(
				0,
				options.findIndex(
					( o ) => String( o.value ) === String( value )
				)
			)
		);
		setOpen( true );
	};

	const close = ( refocus = true ) => {
		setOpen( false );
		if ( refocus ) {
			buttonRef.current?.focus();
		}
	};

	const pick = ( option: SelectOption | undefined ) => {
		if ( ! option || option.disabled ) {
			return;
		}
		if ( String( option.value ) !== String( value ) ) {
			onChange( option.value );
		}
		close();
	};

	// Keep the highlighted option in view and focus the search box or the list.
	useLayoutEffect( () => {
		if ( ! open ) {
			return;
		}
		( withSearch ? searchRef.current : listRef.current )?.focus();
	}, [ open, withSearch ] );

	useEffect( () => {
		if ( ! open || active < 0 ) {
			return;
		}
		listRef.current
			?.querySelector( `[data-index="${ active }"]` )
			?.scrollIntoView( { block: 'nearest' } );
	}, [ open, active ] );

	// On opening, highlight the selected option; while searching, the first match.
	useEffect( () => {
		if ( ! open ) {
			return;
		}
		const selectedIndex = visible.findIndex(
			( o ) => String( o.value ) === String( value )
		);
		setActive(
			'' === query && selectedIndex >= 0
				? selectedIndex
				: visible.findIndex( ( o ) => ! o.disabled )
		);
		// eslint-disable-next-line react-hooks/exhaustive-deps -- recompute on open and on search.
	}, [ open, query ] );

	const step = ( from: number, delta: number ) => {
		let i = from;
		for ( let n = 0; n < visible.length; n++ ) {
			i = ( i + delta + visible.length ) % visible.length;
			if ( ! visible[ i ].disabled ) {
				return i;
			}
		}
		return from;
	};

	const onListKey = ( e: KeyboardEvent ) => {
		switch ( e.key ) {
			case 'ArrowDown':
				e.preventDefault();
				setActive( ( a ) => step( a, 1 ) );
				break;
			case 'ArrowUp':
				e.preventDefault();
				setActive( ( a ) => step( a, -1 ) );
				break;
			case 'Home':
				e.preventDefault();
				setActive( step( -1, 1 ) );
				break;
			case 'End':
				e.preventDefault();
				setActive( step( visible.length, -1 ) );
				break;
			case 'Enter':
				e.preventDefault();
				// Typing and pressing Enter at once can beat the highlight update: take the first match.
				pick(
					visible[ active ] && ! visible[ active ].disabled
						? visible[ active ]
						: visible.find( ( o ) => ! o.disabled )
				);
				break;
			case 'Escape':
				e.preventDefault();
				e.stopPropagation();
				close();
				break;
			case 'Tab':
				close( false );
				break;
			default:
				// Type-ahead without a search box.
				if ( ! withSearch && 1 === e.key.length ) {
					const start = active + 1;
					const letter = e.key.toLowerCase();
					const order = [ ...visible.keys() ].map(
						( k ) => ( k + start ) % visible.length
					);
					const hit = order.find( ( k ) =>
						String( visible[ k ].label )
							.toLowerCase()
							.startsWith( letter )
					);
					if ( undefined !== hit ) {
						setActive( hit );
					}
				}
		}
	};

	const onButtonKey = ( e: KeyboardEvent ) => {
		if ( [ 'ArrowDown', 'ArrowUp', 'Enter', ' ' ].includes( e.key ) ) {
			e.preventDefault();
			openList();
		} else if ( 1 === e.key.length && /\S/.test( e.key ) ) {
			openList();
			if ( withSearch ) {
				setQuery( e.key );
			}
		}
	};

	let lastGroup: string | null = null;
	const listId = `${ id }-list`;

	return (
		<BaseControl
			id={ id }
			label={ label }
			hideLabelFromVision={ hideLabelFromVision }
			help={ help }
			className={ `wpff-select ${ className }` }
			__nextHasNoMarginBottom
		>
			<button
				ref={ buttonRef }
				id={ id }
				type="button"
				className={ `wpff-select__button${ open ? ' is-open' : '' }` }
				role="combobox"
				aria-haspopup="listbox"
				aria-expanded={ open }
				aria-controls={ open ? listId : undefined }
				disabled={ disabled }
				onClick={ () => ( open ? close() : openList() ) }
				onKeyDown={ onButtonKey }
			>
				{ current?.icon && (
					<span className="wpff-select__icon">{ current.icon }</span>
				) }
				<span
					className={ `wpff-select__value${
						current ? '' : ' is-placeholder'
					}` }
				>
					{ current
						? current.label
						: ( placeholder ?? ( value ? String( value ) : '—' ) ) }
				</span>
				<Icon icon={ chevronDown } size={ 20 } />
			</button>
			{ open && (
				<Popover
					anchor={ buttonRef.current }
					placement="bottom-start"
					offset={ 4 }
					onClose={ () => close( false ) }
					onFocusOutside={ () => close( false ) }
					className="wpff-select__popover"
					focusOnMount={ false }
				>
					<div
						className="wpff-select__menu"
						style={ { minWidth: Math.max( width, 200 ) } }
					>
						{ withSearch && (
							<input
								ref={ searchRef }
								type="search"
								className="wpff-select__search"
								placeholder={ __(
									'Search…',
									'freeform-fields'
								) }
								value={ query }
								onChange={ ( e ) => setQuery( e.target.value ) }
								onKeyDown={ onListKey }
								role="searchbox"
								aria-controls={ listId }
								aria-activedescendant={
									active >= 0
										? `${ listId }-${ active }`
										: undefined
								}
								aria-label={ __(
									'Search options',
									'freeform-fields'
								) }
							/>
						) }
						<ul
							ref={ listRef }
							id={ listId }
							className="wpff-select__list"
							role="listbox"
							aria-label={ label }
							tabIndex={ withSearch ? -1 : 0 }
							onKeyDown={ withSearch ? undefined : onListKey }
							aria-activedescendant={
								active >= 0
									? `${ listId }-${ active }`
									: undefined
							}
						>
							{ visible.map( ( option, i ) => {
								const header =
									option.group &&
									option.group !== lastGroup ? (
										<li
											key={ `g-${ option.group }` }
											className="wpff-select__group"
											role="presentation"
										>
											{ option.group }
										</li>
									) : null;
								lastGroup = option.group || null;
								const selected =
									String( option.value ) === String( value );
								return [
									header,
									// Keys are handled by the listbox / search box (aria-activedescendant).
									// eslint-disable-next-line jsx-a11y/click-events-have-key-events
									<li
										key={ `o-${ option.value }` }
										id={ `${ listId }-${ i }` }
										data-index={ i }
										role="option"
										aria-selected={ selected }
										aria-disabled={
											option.disabled || undefined
										}
										className={ [
											'wpff-select__option',
											i === active && 'is-active',
											selected && 'is-selected',
											option.disabled && 'is-disabled',
										]
											.filter( Boolean )
											.join( ' ' ) }
										onMouseEnter={ () => setActive( i ) }
										onMouseDown={ ( e ) =>
											e.preventDefault()
										}
										onClick={ () => pick( option ) }
									>
										{ option.icon && (
											<span className="wpff-select__icon">
												{ option.icon }
											</span>
										) }
										<span className="wpff-select__label">
											{ option.label }
											{ option.hint && (
												<small>{ option.hint }</small>
											) }
										</span>
										{ selected && (
											<Icon
												className="wpff-select__check"
												icon={ check }
												size={ 20 }
											/>
										) }
									</li>,
								];
							} ) }
							{ 0 === visible.length && (
								<li
									className="wpff-select__none"
									role="presentation"
								>
									{ __(
										'Nothing found.',
										'freeform-fields'
									) }
								</li>
							) }
						</ul>
					</div>
				</Popover>
			) }
		</BaseControl>
	);
}
