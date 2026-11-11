/**
 * Helpers for the nested field tree of a group.
 *
 * A list path names a list of fields: `fields`, `fields.2.sub_fields`,
 * `fields.3.layouts.0.sub_fields`. Error paths use the same scheme ('fields.2.sub_fields.0.name').
 */

/**
 * @param {Object} root Group.
 * @param {string} path Dot path.
 * @return {*} Value.
 */
export function getAt( root, path ) {
	return path
		.split( '.' )
		.reduce( ( node, part ) => ( node ? node[ part ] : undefined ), root );
}

/**
 * Copy of root with the value at path replaced.
 *
 * @param {Object} root  Group or any nested object.
 * @param {string} path  Dot path.
 * @param {*}      value New value.
 * @return {Object} New root.
 */
export function setAt( root, path, value ) {
	const [ head, ...rest ] = path.split( '.' );
	const copy = Array.isArray( root ) ? [ ...root ] : { ...root };
	copy[ head ] = rest.length
		? setAt( root[ head ] ?? {}, rest.join( '.' ), value )
		: value;
	return copy;
}

/**
 * Child lists of a field: sub fields, or each flexible layout's sub fields.
 *
 * @param {Object} field Field.
 * @param {string} path  Path of the field, e.g. 'fields.2'.
 * @return {Array<{path: string, fields: Array, layout?: Object, layoutIndex?: number}>} Lists.
 */
export function childLists( field, path ) {
	if ( Array.isArray( field.layouts ) ) {
		return field.layouts.map( ( layout, i ) => ( {
			path: `${ path }.layouts.${ i }.sub_fields`,
			fields: layout.sub_fields || [],
			layout,
			layoutIndex: i,
		} ) );
	}
	if ( Array.isArray( field.sub_fields ) ) {
		return [ { path: `${ path }.sub_fields`, fields: field.sub_fields } ];
	}
	return [];
}

/**
 * Finds a field anywhere in the tree.
 *
 * @param {Array}  fields   List to search.
 * @param {string} key      Field key.
 * @param {string} listPath Path of `fields`.
 * @return {{field: Object, listPath: string, index: number, path: string}|null} Location.
 */
export function findField( fields, key, listPath = 'fields' ) {
	for ( let i = 0; i < fields.length; i++ ) {
		const field = fields[ i ];
		const path = `${ listPath }.${ i }`;
		if ( field.key === key ) {
			return { field, listPath, index: i, path };
		}
		for ( const list of childLists( field, path ) ) {
			const found = findField( list.fields, key, list.path );
			if ( found ) {
				return found;
			}
		}
	}
	return null;
}

/**
 * @param {Object} group    Group.
 * @param {string} listPath List path.
 * @param {number} index    Position.
 * @param {Object} field    Field to insert.
 * @return {Object} New group.
 */
export function insertAt( group, listPath, index, field ) {
	const list = [ ...( getAt( group, listPath ) || [] ) ];
	list.splice( Math.max( 0, Math.min( index, list.length ) ), 0, field );
	return setAt( group, listPath, list );
}

/**
 * @param {Object} group Group.
 * @param {string} key   Field key.
 * @return {{group: Object, field: Object|null, listPath: string, index: number}} Result.
 */
export function removeField( group, key ) {
	const found = findField( group.fields, key );
	if ( ! found ) {
		return { group, field: null, listPath: '', index: -1 };
	}
	const list = getAt( group, found.listPath ).filter(
		( _, i ) => i !== found.index
	);
	return {
		group: setAt( group, found.listPath, list ),
		field: found.field,
		listPath: found.listPath,
		index: found.index,
	};
}

/**
 * @param {Object} group Group.
 * @param {string} key   Field key.
 * @param {Object} field Replacement.
 * @return {Object} New group.
 */
export function replaceField( group, key, field ) {
	const found = findField( group.fields, key );
	return found ? setAt( group, found.path, field ) : group;
}

/**
 * Moves a field to a list position. Dropping a field into its own sub fields is refused.
 *
 * @param {Object} group    Group.
 * @param {string} key      Field key.
 * @param {string} listPath Target list.
 * @param {number} index    Target position in the list as it is now (before removal).
 * @return {Object} New group.
 */
export function moveField( group, key, listPath, index ) {
	const from = findField( group.fields, key );
	if ( ! from || listPath.startsWith( `${ from.path }.` ) ) {
		return group;
	}
	let target = index;
	if ( from.listPath === listPath && from.index < index ) {
		target -= 1;
	}
	// Removing the field may shift indexes inside the target path (an earlier sibling of an ancestor).
	const removed = removeField( group, key );
	const adjusted = adjustPath( listPath, from.listPath, from.index );
	return insertAt( removed.group, adjusted, target, from.field );
}

/**
 * Fixes a path after the item at `removedIndex` of `removedList` was removed.
 *
 * @param {string} path         Path to fix.
 * @param {string} removedList  List the item was removed from.
 * @param {number} removedIndex Removed position.
 * @return {string} Path.
 */
function adjustPath( path, removedList, removedIndex ) {
	const prefix = `${ removedList }.`;
	if ( ! path.startsWith( prefix ) ) {
		return path;
	}
	const rest = path.slice( prefix.length ).split( '.' );
	const i = Number( rest[ 0 ] );
	if ( i > removedIndex ) {
		rest[ 0 ] = String( i - 1 );
	}
	return prefix + rest.join( '.' );
}

/**
 * Every field in the tree, depth first.
 *
 * @param {Array} fields Fields.
 * @return {Array} Fields.
 */
export function flatten( fields ) {
	return fields.flatMap( ( f, i ) => [
		f,
		...childLists( f, String( i ) ).flatMap( ( l ) => flatten( l.fields ) ),
	] );
}
