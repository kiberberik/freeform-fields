/**
 * Edit UI for field blocks: a server-rendered preview in the canvas and the fields form in the sidebar.
 *
 * The form is the same PHP-rendered markup as on other screens. On every change its values are
 * sent to `wpff/v1/blocks/data`, which validates them and returns the block's flat `data`
 * (ACF's layout), stored in the `data` attribute.
 */
import apiFetch from '@wordpress/api-fetch';
import * as blocksApi from '@wordpress/blocks';
import {
	InnerBlocks,
	InspectorControls,
	useBlockProps,
} from '@wordpress/block-editor';
import { Notice, PanelBody, Spinner } from '@wordpress/components';
import {
	createPortal,
	useEffect,
	useLayoutEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import ServerSideRender from '@wordpress/server-side-render';

const cfg = window.wpffBlocks || { blocks: [], restPath: '/wpff/v1' };

/**
 * Form inputs (`wpff[field_x][row][field_y]`) as a nested object, keyed like the server expects.
 *
 * @param {Element} container Form container.
 * @return {Object} Values keyed by field key.
 */
export function serialize( container ) {
	const out = {};
	window
		.jQuery( container )
		.find( ':input' )
		.serializeArray()
		.forEach( ( { name, value } ) => {
			const parts = name.replace( /\]/g, '' ).split( '[' );
			if ( 'wpff' !== parts.shift() ) {
				return;
			}
			let node = out;
			parts.forEach( ( part, i ) => {
				const last = i === parts.length - 1;
				const nextIsList = ! last && '' === parts[ i + 1 ];
				if ( '' === part ) {
					node.push( value );
					return;
				}
				if ( last ) {
					node[ part ] = value;
				} else {
					if (
						typeof node[ part ] !== 'object' ||
						null === node[ part ]
					) {
						node[ part ] = nextIsList ? [] : {};
					} else if (
						nextIsList &&
						! Array.isArray( node[ part ] )
					) {
						// A hidden "empty" input came first; the list replaces it.
						node[ part ] = [];
					}
					node = node[ part ];
				}
			} );
		} );
	return out;
}

function FieldsForm( { name, data, onData } ) {
	const ref = useRef();
	const [ html, setHtml ] = useState( null );
	const [ error, setError ] = useState( '' );

	// Load the form once per block; later data changes come from the form itself.
	useEffect( () => {
		let active = true;
		apiFetch( {
			path: `${ cfg.restPath }/blocks/form`,
			method: 'POST',
			data: { name, data },
		} )
			.then( ( response ) => active && setHtml( response.html ) )
			.catch( ( e ) => active && setError( e.message ) );
		return () => {
			active = false;
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps -- data is only the initial value.
	}, [ name ] );

	useEffect( () => {
		const container = ref.current;
		if ( null === html || ! container ) {
			return;
		}
		const $ = window.jQuery;
		const api = window.wpff;
		container.innerHTML = html;
		api.initFieldsIn( container );

		let timer = null;
		const update = () => {
			clearTimeout( timer );
			timer = setTimeout( () => {
				api.syncEditors();
				apiFetch( {
					path: `${ cfg.restPath }/blocks/data`,
					method: 'POST',
					data: { name, values: serialize( container ) },
				} )
					.then( ( response ) => {
						onData( response.data );
						if ( Object.keys( response.errors ).length ) {
							api.showErrors( response.errors );
						} else {
							api.clearErrors();
						}
					} )
					.catch( ( e ) => setError( e.message ) );
			}, 400 );
		};
		$( container ).on( 'input change', update );
		return () => {
			clearTimeout( timer );
			$( container ).off( 'input change', update );
			api.removeEditors( container );
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps -- onData changes every render.
	}, [ html, name ] );

	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}
	return (
		<>
			{ null === html && <Spinner /> }
			<div ref={ ref } className="wpff-block-form" />
		</>
	);
}

/**
 * Preview for blocks with inner blocks: the server-rendered template, with an editable
 * InnerBlocks area portaled into the placeholder that replaces `<InnerBlocks />`.
 *
 * @param {Object} props            Props.
 * @param {string} props.name       Block name.
 * @param {Object} props.attributes Block attributes.
 */
function InnerBlocksPreview( { name, attributes } ) {
	const ref = useRef();
	const [ html, setHtml ] = useState( null );
	const [ target, setTarget ] = useState( null );
	const key = JSON.stringify( attributes );

	useEffect( () => {
		let active = true;
		const timer = setTimeout( () => {
			apiFetch( {
				path: `/wp/v2/block-renderer/${ name }?context=edit`,
				method: 'POST',
				data: { attributes },
			} )
				.then( ( r ) => active && setHtml( r.rendered || '' ) )
				.catch( () => active && setHtml( '' ) );
		}, 300 );
		return () => {
			active = false;
			clearTimeout( timer );
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps -- key covers attributes.
	}, [ name, key ] );

	// The placeholder is recreated with every new preview: find it again.
	useLayoutEffect( () => {
		setTarget(
			ref.current?.querySelector( '[data-wpff-inner-blocks]' ) || null
		);
	}, [ html ] );

	let props = {};
	try {
		props = target ? JSON.parse( target.dataset.wpffInnerBlocks ) : {};
	} catch {}

	return (
		<>
			{ null === html && <Spinner /> }
			<div
				ref={ ref }
				className="wpff-block-preview"
				dangerouslySetInnerHTML={ { __html: html || '' } }
			/>
			{ target && createPortal( <InnerBlocks { ...props } />, target ) }
			{ /* No <InnerBlocks /> in the template: keep the inner blocks editable below it. */ }
			{ null !== html && ! target && <InnerBlocks /> }
		</>
	);
}

function Edit( { name, attributes, setAttributes, isSelected } ) {
	const blockProps = useBlockProps();
	const info = cfg.blocks.find( ( b ) => b.name === name ) || {};

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<PanelBody
					title={ __( 'Fields', 'freeform-fields' ) }
					initialOpen
				>
					{ info.hasFields ? (
						<FieldsForm
							name={ name }
							data={ attributes.data || {} }
							onData={ ( data ) => setAttributes( { data } ) }
						/>
					) : (
						<p className="description">
							{ __(
								'No field group is assigned to this block. Add one with the location rule "Block".',
								'freeform-fields'
							) }
						</p>
					) }
				</PanelBody>
			</InspectorControls>
			{ info.innerBlocks ? (
				<InnerBlocksPreview name={ name } attributes={ attributes } />
			) : (
				<ServerSideRender
					block={ name }
					attributes={ attributes }
					httpMethod="POST"
					EmptyResponsePlaceholder={ () => (
						<div className="wpff-block-placeholder">
							{ isSelected
								? __(
										'Fill in the fields in the sidebar.',
										'freeform-fields'
									)
								: __(
										'This block has no content yet.',
										'freeform-fields'
									) }
						</div>
					) }
				/>
			) }
		</div>
	);
}

cfg.blocks.forEach( ( block ) => {
	const existing = blocksApi.getBlockType( block.name );
	if ( existing && existing.edit && existing.edit.wpff ) {
		return;
	}
	Edit.wpff = true;
	blocksApi.registerBlockType( block.name, {
		edit: Edit,
		// Inner blocks are saved in the post content; the template is rendered by PHP.
		save: block.innerBlocks ? () => <InnerBlocks.Content /> : () => null,
	} );
} );

// Editor side of the `wpff/field` Block Bindings source (WordPress 6.7+): shows the value
// from the post's field forms when there is one, else the field name.
if ( blocksApi.registerBlockBindingsSource ) {
	blocksApi.registerBlockBindingsSource( {
		name: 'wpff/field',
		label: __( 'Custom field', 'freeform-fields' ),
		usesContext: [ 'postId', 'postType' ],
		getValues( { select, bindings } ) {
			const store = select( 'wpff/fields' );
			const values = {};
			Object.entries( bindings ).forEach( ( [ attribute, binding ] ) => {
				const key = binding.args?.key || '';
				const value = store?.getFieldValue?.( key );
				values[ attribute ] =
					typeof value === 'string' || typeof value === 'number'
						? String( value )
						: key;
			} );
			return values;
		},
		// Fields for the picker in the block's Attributes panel (WordPress 6.9+).
		getFieldsList( { context } ) {
			const postType = context?.postType;
			return ( cfg.bindings || [] )
				.filter(
					( field ) =>
						'' !== field.object ||
						! field.postTypes.length ||
						! postType ||
						field.postTypes.includes( postType )
				)
				.map( ( field ) => ( {
					label: field.label,
					type: 'string',
					args: field.object
						? { key: field.key, object: field.object }
						: { key: field.key },
				} ) );
		},
		canUserEditValue: () => false,
	} );
}
