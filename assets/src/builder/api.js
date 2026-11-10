/**
 * REST calls to wpff/v1.
 */
import apiFetch from '@wordpress/api-fetch';

const base = () => window.wpffBuilder.restPath;

export const listGroups = () =>
	apiFetch( { path: `${ base() }/field-groups` } );

export const getGroup = ( key ) =>
	apiFetch( { path: `${ base() }/field-groups/${ key }` } );

export const createGroup = ( group ) =>
	apiFetch( {
		path: `${ base() }/field-groups`,
		method: 'POST',
		data: group,
	} );

export const updateGroup = ( group ) =>
	apiFetch( {
		path: `${ base() }/field-groups/${ group.key }`,
		method: 'PUT',
		data: group,
	} );

export const trashGroup = ( key ) =>
	apiFetch( { path: `${ base() }/field-groups/${ key }`, method: 'DELETE' } );

export const restoreGroup = ( key ) =>
	apiFetch( {
		path: `${ base() }/field-groups/${ key }/restore`,
		method: 'POST',
	} );

export const syncGroup = ( key ) =>
	apiFetch( {
		path: `${ base() }/field-groups/${ key }/sync`,
		method: 'POST',
	} );

export const duplicateGroup = ( key ) =>
	apiFetch( {
		path: `${ base() }/field-groups/${ key }/duplicate`,
		method: 'POST',
	} );

/**
 * Error paths from a failed save: { 'fields.0.name': message }.
 *
 * @param {Object} error apiFetch error.
 * @return {Object} Errors by path.
 */
export function errorsFromResponse( error ) {
	const list = error && error.data && error.data.errors;
	if ( ! Array.isArray( list ) ) {
		return {};
	}
	return list.reduce(
		( acc, item ) => ( { ...acc, [ item.path ]: item.message } ),
		{}
	);
}
