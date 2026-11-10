/**
 * Helpers shared by the builder components.
 */

const CYRILLIC = {
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
 * @param {string} label Field label.
 * @return {string} Name.
 */
export function nameFromLabel( label ) {
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
 * @param {string} name Raw name.
 * @return {string} Clean name.
 */
export function cleanName( name ) {
	return String( name )
		.toLowerCase()
		.replace( /[^a-z0-9_-]/g, '' );
}

/**
 * Random 13-character id, like the server's keys.
 *
 * @return {string} Id.
 */
export function uid() {
	const bytes = new Uint8Array( 8 );
	window.crypto.getRandomValues( bytes );
	return Array.from( bytes, ( b ) => b.toString( 16 ).padStart( 2, '0' ) )
		.join( '' )
		.slice( 0, 13 );
}

/**
 * A new field of a type, with the type's default settings.
 *
 * @param {Object} type Field type description from the server.
 * @return {Object} Field.
 */
export function newField( type ) {
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
 * @param {Object} field Field.
 * @param {Object} type  New type description.
 * @return {Object} Field.
 */
export function changeType( field, type ) {
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
	const kept = {};
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
 * @return {Object} Group.
 */
export function newGroup() {
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
	};
}

/**
 * Field type description by name.
 *
 * @param {string} name Type name.
 * @return {Object|undefined} Type.
 */
export function typeOf( name ) {
	return window.wpffBuilder.fieldTypes.find( ( t ) => t.name === name );
}

/**
 * Copies fields (and sub fields) with new keys; conditions follow the new keys.
 *
 * @param {Array} fields Fields.
 * @return {Array} Copies.
 */
export function rekeyFields( fields ) {
	const map = {};
	const rekey = ( list ) =>
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
	const remap = ( list ) =>
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
			if ( field.collapsed && map[ field.collapsed ] ) {
				copy.collapsed = map[ field.collapsed ];
			}
			return copy;
		} );
	return remap( rekey( fields ) );
}

/**
 * Removes client-only props (starting with `_`) before saving, recursively.
 *
 * @param {Object} group Group.
 * @return {Object} Group ready for the API.
 */
export function toPayload( group ) {
	const clean = ( fields ) =>
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
				copy.layouts = copy.layouts.map( ( layout ) => {
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
 * @param {Object}   group Group.
 * @param {Function} __    Translate function.
 * @return {Object} Errors: { 'title': msg, 'fields.0.name': msg, 'fields.1.sub_fields.0.label': msg }.
 */
export function validateGroup( group, __ ) {
	const errors = {};
	if ( ! group.title.trim() ) {
		errors.title = __(
			'Field group title is required.',
			'freeform-fields'
		);
	}
	const checkList = ( fields, path ) => {
		const seen = {};
		fields.forEach( ( field, i ) => {
			const type = typeOf( field.type );
			const hasValue = ! type || type.hasValue;
			if ( ! field.label.trim() ) {
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
				const layoutNames = {};
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
 * @param {Object|string} choices Choices.
 * @return {Object} { value: label }.
 */
export function parseChoices( choices ) {
	if ( 'string' !== typeof choices ) {
		return choices || {};
	}
	const out = {};
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
 * @param {Object|string} choices Choices.
 * @return {string} Lines.
 */
export function choicesToText( choices ) {
	if ( 'string' === typeof choices ) {
		return choices;
	}
	return Object.entries( choices || {} )
		.map( ( [ value, label ] ) =>
			value === label ? value : `${ value } : ${ label }`
		)
		.join( '\n' );
}
