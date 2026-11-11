/**
 * Helpers for the nested field tree of a group.
 *
 * A list path names a list of fields: `fields`, `fields.2.sub_fields`,
 * `fields.3.layouts.0.sub_fields`. Error paths use the same scheme ('fields.2.sub_fields.0.name').
 */
import type { Field, FieldGroup } from '../types';
import type { ChildList, FieldLocation } from './types';

/**
 * The caller names the type it expects at the path.
 *
 * @param root Group.
 * @param path Dot path.
 * @return Value.
 */
export function getAt< T = unknown >( root: unknown, path: string ): T {
	return path
		.split( '.' )
		.reduce< unknown >(
			( node, part ) =>
				node
					? ( node as Record< string, unknown > )[ part ]
					: undefined,
			root
		) as T;
}

/**
 * Copy of root with the value at path replaced.
 *
 * @param root  Group or any nested object.
 * @param path  Dot path.
 * @param value New value.
 * @return New root.
 */
export function setAt< T extends object >(
	root: T,
	path: string,
	value: unknown
): T {
	const [ head, ...rest ] = path.split( '.' );
	const source = root as Record< string, unknown >;
	const copy = (
		Array.isArray( root ) ? [ ...root ] : { ...root }
	) as Record< string, unknown >;
	copy[ head ] = rest.length
		? setAt( ( source[ head ] ?? {} ) as object, rest.join( '.' ), value )
		: value;
	return copy as T;
}

/**
 * Child lists of a field: sub fields, or each flexible layout's sub fields.
 *
 * @param field Field.
 * @param path  Path of the field, e.g. 'fields.2'.
 * @return Lists.
 */
export function childLists( field: Field, path: string ): ChildList[] {
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
 * @param fields   List to search.
 * @param key      Field key.
 * @param listPath Path of `fields`.
 * @return Location.
 */
export function findField(
	fields: Field[],
	key: string,
	listPath = 'fields'
): FieldLocation | null {
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
 * @param group    Group.
 * @param listPath List path.
 * @param index    Position.
 * @param field    Field to insert.
 * @return New group.
 */
export function insertAt< G extends FieldGroup >(
	group: G,
	listPath: string,
	index: number,
	field: Field
): G {
	const list = [
		...( getAt< Field[] | undefined >( group, listPath ) || [] ),
	];
	list.splice( Math.max( 0, Math.min( index, list.length ) ), 0, field );
	return setAt( group, listPath, list );
}

/**
 * @param group Group.
 * @param key   Field key.
 * @return Result.
 */
export function removeField< G extends FieldGroup >(
	group: G,
	key: string
): { group: G; field: Field | null; listPath: string; index: number } {
	const found = findField( group.fields, key );
	if ( ! found ) {
		return { group, field: null, listPath: '', index: -1 };
	}
	const list = getAt< Field[] >( group, found.listPath ).filter(
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
 * @param group Group.
 * @param key   Field key.
 * @param field Replacement.
 * @return New group.
 */
export function replaceField< G extends FieldGroup >(
	group: G,
	key: string,
	field: Field
): G {
	const found = findField( group.fields, key );
	return found ? setAt( group, found.path, field ) : group;
}

/**
 * Moves a field to a list position. Dropping a field into its own sub fields is refused.
 *
 * @param group    Group.
 * @param key      Field key.
 * @param listPath Target list.
 * @param index    Target position in the list as it is now (before removal).
 * @return New group.
 */
export function moveField< G extends FieldGroup >(
	group: G,
	key: string,
	listPath: string,
	index: number
): G {
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
 * @param path         Path to fix.
 * @param removedList  List the item was removed from.
 * @param removedIndex Removed position.
 * @return Path.
 */
function adjustPath(
	path: string,
	removedList: string,
	removedIndex: number
): string {
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
 * @param fields Fields.
 * @return Fields.
 */
export function flatten( fields: Field[] ): Field[] {
	return fields.flatMap( ( f, i ) => [
		f,
		...childLists( f, String( i ) ).flatMap( ( l ) => flatten( l.fields ) ),
	] );
}
