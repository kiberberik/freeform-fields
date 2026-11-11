/**
 * Shared building blocks of the admin screens.
 */
import { Icon } from '@wordpress/components';
import { arrowLeft } from '@wordpress/icons';
import { typeIcon } from './icons';
import { typeOf } from './utils';

/**
 * Screen header: optional back link, title, subtitle and actions on the right.
 *
 * @param {Object} props
 * @param {string} props.title       Title.
 * @param {string} [props.back]      Back link URL.
 * @param {string} [props.backLabel] Back link text.
 * @param {*}      [props.subtitle]  Line under the title.
 * @param {*}      [props.actions]   Buttons.
 * @param {*}      [props.children]  Extra content under the header row (tabs, filters).
 */
export function PageHeader( {
	title,
	back,
	backLabel,
	subtitle,
	actions,
	children,
} ) {
	return (
		<header className="wpff-page-header">
			<div className="wpff-page-header__row">
				<div className="wpff-page-header__titles">
					{ back && (
						<a className="wpff-page-header__back" href={ back }>
							<Icon icon={ arrowLeft } size={ 16 } />
							{ backLabel }
						</a>
					) }
					<h1 className="wpff-page-header__title">{ title }</h1>
					{ subtitle && (
						<p className="wpff-page-header__subtitle">
							{ subtitle }
						</p>
					) }
				</div>
				{ actions && (
					<div className="wpff-page-header__actions">{ actions }</div>
				) }
			</div>
			{ children }
		</header>
	);
}

/**
 * Field type icon in a tinted square; the tint follows the type's category.
 *
 * @param {Object} props
 * @param {string} props.type   Type name.
 * @param {number} [props.size] Square size in px.
 */
export function TypeIcon( { type, size = 28 } ) {
	const info = typeOf( type );
	return (
		<span
			className={ `wpff-type-icon wpff-type-icon--${
				info?.group || 'basic'
			}` }
			style={ { width: size, height: size } }
			aria-hidden="true"
		>
			<Icon
				icon={ typeIcon( type ) }
				size={ Math.round( size * 0.68 ) }
			/>
		</span>
	);
}

/**
 * Centered empty state with an icon, text and a call to action.
 *
 * @param {Object} props
 * @param {Object} props.icon       Icon.
 * @param {string} props.title      Title.
 * @param {string} [props.text]     Description.
 * @param {*}      [props.children] Actions.
 */
export function EmptyState( { icon, title, text, children } ) {
	return (
		<div className="wpff-empty-state">
			{ icon && (
				<span className="wpff-empty-state__icon">
					<Icon icon={ icon } size={ 32 } />
				</span>
			) }
			<h2>{ title }</h2>
			{ text && <p>{ text }</p> }
			{ children && (
				<div className="wpff-empty-state__actions">{ children }</div>
			) }
		</div>
	);
}

/**
 * Small colored status label.
 *
 * @param {Object} props
 * @param {string} props.tone     neutral, success, warning, info.
 * @param {*}      props.children Text.
 */
export function Pill( { tone = 'neutral', children } ) {
	return (
		<span className={ `wpff-pill wpff-pill--${ tone }` }>{ children }</span>
	);
}
