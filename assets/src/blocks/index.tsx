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
import type { ComponentType } from 'react';
import ServerSideRender from '@wordpress/server-side-render';
import type { BlocksSettings, FieldsApi } from '../globals';

const cfg: BlocksSettings = window.wpffBlocks || {
	blocks: [],
	restPath: '/wpff/v1',
	bindings: [],
};

/** Block attributes: the field values (`data`) plus whatever the block registers. */
type Attributes = Record< string, unknown > & {
	data?: Record< string, unknown >;
};

/** A nested level of serialized form values. */
type Tree = Record< string, unknown > | unknown[];

/**
 * Form inputs (`wpff[field_x][row][field_y]`) as a nested object, keyed like the server expects.
 *
 * @param container Form container.
 * @return Values keyed by field key.
 */
export function serialize( container: Element ): Record< string, unknown > {
	const out: Record< string, unknown > = {};
	window
		.jQuery( container )
		.find( ':input' )
		.serializeArray()
		.forEach( ( { name, value } ) => {
			const parts = name.replace( /\]/g, '' ).split( '[' );
			if ( 'wpff' !== parts.shift() ) {
				return;
			}
			let node: Tree = out;
			parts.forEach( ( part, i ) => {
				const last = i === parts.length - 1;
				const nextIsList = ! last && '' === parts[ i + 1 ];
				if ( '' === part ) {
					( node as unknown[] ).push( value );
					return;
				}
				// Only a level followed by `[]` is a list; this one is keyed.
				const obj = node as Record< string, unknown >;
				if ( last ) {
					obj[ part ] = value;
				} else {
					if (
						typeof obj[ part ] !== 'object' ||
						null === obj[ part ]
					) {
						obj[ part ] = nextIsList ? [] : {};
					} else if ( nextIsList && ! Array.isArray( obj[ part ] ) ) {
						// A hidden "empty" input came first; the list replaces it.
						obj[ part ] = [];
					}
					node = obj[ part ] as Tree;
				}
			} );
		} );
	return out;
}

interface FieldsFormProps {
	name: string;
	data: Record< string, unknown >;
	onData: ( data: Record< string, unknown > ) => void;
}

function FieldsForm( { name, data, onData }: FieldsFormProps ) {
	const ref = useRef< HTMLDivElement >( null );
	const [ html, setHtml ] = useState< string | null >( null );
	const [ error, setError ] = useState( '' );

	// Load the form once per block; later data changes come from the form itself.
	useEffect( () => {
		let active = true;
		apiFetch< { html: string } >( {
			path: `${ cfg.restPath }/blocks/form`,
			method: 'POST',
			data: { name, data },
		} )
			.then( ( response ) => active && setHtml( response.html ) )
			.catch( ( e: Error ) => active && setError( e.message ) );
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
		// Lent by the field form script, a dependency of this one.
		const api = window.wpff as FieldsApi;
		container.innerHTML = html;
		api.initFieldsIn( container );

		let timer: ReturnType< typeof setTimeout > | undefined;
		const update = () => {
			clearTimeout( timer );
			timer = setTimeout( () => {
				api.syncEditors();
				apiFetch< {
					data: Record< string, unknown >;
					errors: Record< string, string >;
				} >( {
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
					.catch( ( e: Error ) => setError( e.message ) );
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
 * @param props            Props.
 * @param props.name       Block name.
 * @param props.attributes Block attributes.
 */
function InnerBlocksPreview( {
	name,
	attributes,
}: {
	name: string;
	attributes: Attributes;
} ) {
	const ref = useRef< HTMLDivElement >( null );
	const [ html, setHtml ] = useState< string | null >( null );
	const [ target, setTarget ] = useState< HTMLElement | null >( null );
	const key = JSON.stringify( attributes );

	useEffect( () => {
		let active = true;
		const timer = setTimeout( () => {
			apiFetch< { rendered?: string } >( {
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
			ref.current?.querySelector< HTMLElement >(
				'[data-wpff-inner-blocks]'
			) || null
		);
	}, [ html ] );

	let props: Record< string, unknown > = {};
	try {
		// The attribute exists: the selector above matched it.
		props = target
			? JSON.parse( target.dataset.wpffInnerBlocks as string )
			: {};
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

/** Edit props: the block editor also passes the block `name`, missing from BlockEditProps. */
type EditProps = blocksApi.BlockEditProps< Attributes > & { name: string };

function Edit( { name, attributes, setAttributes, isSelected }: EditProps ) {
	const blockProps = useBlockProps();
	const info: Partial< BlocksSettings[ 'blocks' ][ number ] > =
		cfg.blocks.find( ( b ) => b.name === name ) || {};

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
	if (
		existing &&
		existing.edit &&
		( existing.edit as { wpff?: boolean } ).wpff
	) {
		return;
	}
	( Edit as typeof Edit & { wpff?: boolean } ).wpff = true;
	const settings: Partial< blocksApi.BlockConfiguration< Attributes > > = {
		edit: Edit as ComponentType< blocksApi.BlockEditProps< Attributes > >,
		// Inner blocks are saved in the post content; the template is rendered by PHP.
		save: block.innerBlocks ? () => <InnerBlocks.Content /> : () => null,
	};
	// Title, category and attributes come from the block's PHP registration.
	blocksApi.registerBlockType< Attributes >(
		block.name,
		settings as Omit< blocksApi.BlockConfiguration< Attributes >, 'name' >
	);
} );

// Editor side of the `wpff/field` Block Bindings source (WordPress 6.7+): shows the value
// from the post's field forms when there is one, else the field name.
if ( blocksApi.registerBlockBindingsSource ) {
	blocksApi.registerBlockBindingsSource( {
		name: 'wpff/field',
		label: __( 'Custom field', 'freeform-fields' ),
		usesContext: [ 'postId', 'postType' ],
		getValues( {
			select,
			bindings,
		}: {
			select: (
				store: string
			) => { getFieldValue?: ( key: string ) => unknown } | undefined;
			bindings: Record< string, { args?: { key?: string } } >;
		} ) {
			const store = select( 'wpff/fields' );
			const values: Record< string, string > = {};
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
			const postType = context?.postType as string | undefined;
			// PHP prints this list (BlockEditor::bindable_fields()); globals types it as unknown.
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
