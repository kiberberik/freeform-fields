/**
 * REST calls to wpff/v1.
 */
import apiFetch from '@wordpress/api-fetch';
import type { FieldGroup, GroupSummary } from '../types';
import type { DeleteResult, EditorGroup, TrashedGroup } from './types';

const base = () => window.wpffBuilder!.restPath;

export const listGroups = () =>
	apiFetch< GroupSummary[] >( { path: `${ base() }/field-groups` } );

export const getGroup = ( key: string ) =>
	apiFetch< EditorGroup >( { path: `${ base() }/field-groups/${ key }` } );

export const createGroup = ( group: FieldGroup ) =>
	apiFetch< EditorGroup >( {
		path: `${ base() }/field-groups`,
		method: 'POST',
		data: group,
	} );

export const updateGroup = ( group: FieldGroup ) =>
	apiFetch< EditorGroup >( {
		path: `${ base() }/field-groups/${ group.key }`,
		method: 'PUT',
		data: group,
	} );

export const trashGroup = ( key: string ) =>
	apiFetch< DeleteResult >( {
		path: `${ base() }/field-groups/${ key }`,
		method: 'DELETE',
	} );

export const listTrash = () =>
	apiFetch< TrashedGroup[] >( { path: `${ base() }/field-groups/trash` } );

export const deleteGroup = ( key: string ) =>
	apiFetch< DeleteResult >( {
		path: `${ base() }/field-groups/${ key }?force=1`,
		method: 'DELETE',
	} );

export const restoreGroup = ( key: string ) =>
	apiFetch< EditorGroup >( {
		path: `${ base() }/field-groups/${ key }/restore`,
		method: 'POST',
	} );

export const syncGroup = ( key: string ) =>
	apiFetch< EditorGroup >( {
		path: `${ base() }/field-groups/${ key }/sync`,
		method: 'POST',
	} );

export const duplicateGroup = ( key: string ) =>
	apiFetch< EditorGroup >( {
		path: `${ base() }/field-groups/${ key }/duplicate`,
		method: 'POST',
	} );

/**
 * Error paths from a failed save: { 'fields.0.name': message }.
 *
 * @param error apiFetch error.
 * @return Errors by path.
 */
export function errorsFromResponse( error: unknown ): Record< string, string > {
	const err = error as { data?: { errors?: unknown } } | null | undefined;
	const list = err && err.data && err.data.errors;
	if ( ! Array.isArray( list ) ) {
		return {};
	}
	return ( list as Array< { path: string; message: string } > ).reduce<
		Record< string, string >
	>( ( acc, item ) => ( { ...acc, [ item.path ]: item.message } ), {} );
}
