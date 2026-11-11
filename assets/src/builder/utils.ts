/**
 * Helpers shared by the builder components.
 */
import type { Choices, Field, FieldGroup, FieldType, Layout } from '../types';
import type { BuilderErrors } from './types';

const CYRILLIC: Record< string, string > = {
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
	// Kazakh letters.
	ә: 'a',
	ғ: 'g',
	қ: 'q',
	ң: 'n',
	ө: 'o',
	ұ: 'u',
	ү: 'u',
	һ: 'h',
	і: 'i',
};

/**
 * Field name from a label: transliterated, lowercase, words joined by `_`.
 *
 * @param label Field label.
 * @return Name.
 */
export function nameFromLabel( label: unknown ): string {
	return String( label )
		.toLowerCase()
		.split( '' )
		.map( ( ch ) => ( ch in CYRILLIC ? CYRILLIC[ ch ] : ch ) )
		.join( '' )
		.normalize( 'NFD' )
		.replace( /[̀-ͯ]/g, '' )
		.replace( /[^a-z0-9]+/g, '_' )
		.replace( /^_+|_+$/g, '' );
}

/**
 * Same rule as the server: lowercase letters, digits, `_` and `-`.
 *
 * @param name Raw name.
 * @return Clean name.
 */
export function cleanName( name: unknown ): string {
	return String( name )
		.toLowerCase()
		.replace( /[^a-z0-9_-]/g, '' );
}

/**
 * Random 13-character id, like the server's keys.
 *
 * @return Id.
 */
export function uid(): string {
	const bytes = new Uint8Array( 8 );
	window.crypto.getRandomValues( bytes );
	return Array.from( bytes, ( b ) => b.toString( 16 ).padStart( 2, '0' ) )
		.join( '' )
		.slice( 0, 13 );
}

/**
 * A new field of a type, with the type's default settings.
 *
 * @param type Field type description from the server.
 * @return Field.
 */
export function newField( type: FieldType ): Field {
	return {
		...( type.defaults || {} ),
		key: `field_${ uid() }`,
		label: '',
		name: '',
		type: type.name,
		instructions: '',
		required: 0,
		default_value:
			type.defaults && 'default_value' in type.defaults
				? type.defaults.default_value
				: '',
		wrapper: { width: '', class: '', id: '' },
		conditional_logic: 0,
		...( type.hasSubs ? { sub_fields: [] } : {} ),
		...( 'layouts' === type.compound ? { layouts: [] } : {} ),
		_isNew: true,
		_nameTouched: false,
	};
}

/**
 * Switches a field to another type, keeping common settings.
 *
 * @param field Field.
 * @param type  New type description.
 * @return Field.
 */
export function changeType( field: Field, type: FieldType ): Field {
	const common = [
		'key',
		'label',
		'name',
		'instructions',
		'required',
		'wrapper',
		'conditional_logic',
		'sub_fields',
		'layouts',
		'_isNew',
		'_nameTouched',
	];
	const kept: Record< string, unknown > = {};
	common.forEach( ( prop ) => {
		if ( prop in field ) {
			kept[ prop ] = field[ prop ];
		}
	} );
	// Choices carry over between select, checkbox and radio.
	if (
		field.choices &&
		type.settings.some( ( s ) => 'choices' === s.name )
	) {
		kept.choices = field.choices;
	}
	return { ...newField( type ), ...kept, type: type.name };
}

/**
 * A new, empty group.
 *
 * @return Group.
 */
export function newGroup(): FieldGroup {
	return {
		key: `group_${ uid() }`,
		title: '',
		fields: [],
		location: [ [ { param: 'post_type', operator: '==', value: 'post' } ] ],
		menu_order: 0,
		position: 'normal',
		style: 'default',
		label_placement: 'top',
		instruction_placement: 'label',
		hide_on_screen: [],
		active: true,
		description: '',
		show_in_rest: 0,
		// New groups on multilingual sites start with "same fields across languages".
		...( window.wpffBuilder?.multilingual
			? { acfml_field_group_mode: 'translation' }
			: {} ),
	};
}

/**
 * Field type description by name.
 *
 * @param name Type name.
 * @return Type.
 */
export function typeOf( name: string ): FieldType | undefined {
	return window.wpffBuilder!.fieldTypes.find( ( t ) => t.name === name );
}

/**
 * Copies fields (and sub fields) with new keys; conditions follow the new keys.
 *
 * @param fields Fields.
 * @return Copies.
 */
export function rekeyFields( fields: Field[] ): Field[] {
	const map: Record< string, string > = {};
	const rekey = ( list: Field[] ): Field[] =>
		list.map( ( field ) => {
			const key = `field_${ uid() }`;
			map[ field.key ] = key;
			const copy = { ...field, key };
			if ( Array.isArray( field.sub_fields ) ) {
				copy.sub_fields = rekey( field.sub_fields );
			}
			if ( Array.isArray( field.layouts ) ) {
				copy.layouts = field.layouts.map( ( layout ) => ( {
					...layout,
					key: `layout_${ uid() }`,
					sub_fields: rekey( layout.sub_fields || [] ),
				} ) );
			}
			return copy;
		} );
	const remap = ( list: Field[] ): Field[] =>
		list.map( ( field ) => {
			const copy = { ...field };
			if ( Array.isArray( field.conditional_logic ) ) {
				copy.conditional_logic = field.conditional_logic.map(
					( group ) =>
						group.map( ( rule ) => ( {
							...rule,
							field: map[ rule.field ] || rule.field,
						} ) )
				);
			}
			if ( Array.isArray( field.sub_fields ) ) {
				copy.sub_fields = remap( field.sub_fields );
			}
			if ( Array.isArray( field.layouts ) ) {
				copy.layouts = field.layouts.map( ( layout ) => ( {
					...layout,
					sub_fields: remap( layout.sub_fields || [] ),
				} ) );
			}
			if ( field.collapsed && map[ field.collapsed as string ] ) {
				copy.collapsed = map[ field.collapsed as string ];
			}
			return copy;
		} );
	return remap( rekey( fields ) );
}

/**
 * Removes client-only props (starting with `_`) before saving, recursively.
 *
 * @param group Group.
 * @return Group ready for the API.
 */
export function toPayload< G extends FieldGroup >( group: G ): G {
	const clean = ( fields: Field[] ): Field[] =>
		fields.map( ( field ) => {
			const copy = { ...field };
			Object.keys( copy ).forEach( ( k ) => {
				if ( k.startsWith( '_' ) ) {
					delete copy[ k ];
				}
			} );
			if ( Array.isArray( copy.sub_fields ) ) {
				copy.sub_fields = clean( copy.sub_fields );
			}
			if ( Array.isArray( copy.layouts ) ) {
				copy.layouts = copy.layouts.map( ( layout ): Layout => {
					const { _isNew, _nameTouched, ...rest } = layout;
					return {
						...rest,
						sub_fields: clean( layout.sub_fields || [] ),
					};
				} );
			}
			return copy;
		} );
	return { ...group, fields: clean( group.fields ) };
}

/**
 * Client-side checks mirroring the server's, keyed by error path.
 *
 * @param group Group.
 * @param __    Translate function.
 * @return Errors: { 'title': msg, 'fields.0.name': msg, 'fields.1.sub_fields.0.label': msg }.
 */
export function validateGroup(
	group: FieldGroup,
	__: ( text: string, domain: string ) => string
): BuilderErrors {
	const errors: BuilderErrors = {};
	if ( ! group.title.trim() ) {
		errors.title = __(
			'Field group title is required.',
			'freeform-fields'
		);
	}
	const checkList = ( fields: Field[], path: string ) => {
		const seen: Record< string, boolean > = {};
		fields.forEach( ( field, i ) => {
			const type = typeOf( field.type );
			const hasValue = ! type || type.hasValue;
			// Like ACF, tabs, messages and accordions may go without a label.
			if ( hasValue && ! field.label.trim() ) {
				errors[ `${ path }.${ i }.label` ] = __(
					'Field label is required.',
					'freeform-fields'
				);
			}
			if ( hasValue && ! field.name ) {
				errors[ `${ path }.${ i }.name` ] = __(
					'Field name is required and may contain only a–z, 0–9, _ and -.',
					'freeform-fields'
				);
			} else if ( hasValue && seen[ field.name ] ) {
				errors[ `${ path }.${ i }.name` ] = __(
					'This field name is already used here.',
					'freeform-fields'
				);
			}
			if ( hasValue ) {
				seen[ field.name ] = true;
			}
			if ( Array.isArray( field.sub_fields ) ) {
				checkList( field.sub_fields, `${ path }.${ i }.sub_fields` );
			}
			if ( Array.isArray( field.layouts ) ) {
				const layoutNames: Record< string, boolean > = {};
				field.layouts.forEach( ( layout, l ) => {
					const lpath = `${ path }.${ i }.layouts.${ l }`;
					if ( ! layout.label.trim() ) {
						errors[ `${ lpath }.label` ] = __(
							'Layout label is required.',
							'freeform-fields'
						);
					}
					if ( ! layout.name ) {
						errors[ `${ lpath }.name` ] = __(
							'Layout name is required and may contain only a–z, 0–9, _ and -.',
							'freeform-fields'
						);
					} else if ( layoutNames[ layout.name ] ) {
						errors[ `${ lpath }.name` ] = __(
							'This layout name is already used.',
							'freeform-fields'
						);
					}
					layoutNames[ layout.name ] = true;
					checkList(
						layout.sub_fields || [],
						`${ lpath }.sub_fields`
					);
				} );
			}
		} );
	};
	checkList( group.fields, 'fields' );
	return errors;
}

/**
 * Choices from an object or "value : Label" lines, as an object.
 *
 * @param choices Choices.
 * @return Choices: { value: label }.
 */
export function parseChoices( choices: unknown ): Choices {
	if ( 'string' !== typeof choices ) {
		return ( choices as Choices | null | undefined ) || {};
	}
	const out: Choices = {};
	choices.split( /\r\n|\r|\n/ ).forEach( ( line ) => {
		line = line.trim();
		if ( ! line ) {
			return;
		}
		const at = line.indexOf( ' : ' );
		if ( at > -1 ) {
			out[ line.slice( 0, at ).trim() ] = line.slice( at + 3 ).trim();
		} else {
			out[ line ] = line;
		}
	} );
	return out;
}

/**
 * Choices object -> "value : Label" lines, and back.
 *
 * @param choices Choices.
 * @return Lines.
 */
export function choicesToText( choices: unknown ): string {
	if ( 'string' === typeof choices ) {
		return choices;
	}
	return Object.entries( ( choices as Choices | null | undefined ) || {} )
		.map( ( [ value, label ] ) =>
			value === label ? value : `${ value } : ${ label }`
		)
		.join( '\n' );
}
