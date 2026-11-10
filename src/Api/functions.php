<?php
/**
 * Public template and developer functions.
 *
 * `$post_id` everywhere accepts the ACF forms: post ID, `term_5`, `user_3`, `comment_7`,
 * `option`, a WP_Post/WP_Term/WP_User object, or false for the current object.
 *
 * @package FreeformFields
 */

use FreeformFields\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Returns a field value.
 *
 * @param string $selector     Field name or key.
 * @param mixed  $post_id      Object id. Default current object.
 * @param bool   $format_value Apply the field's return format. Default true.
 * @return mixed
 */
function wpff_get_field( string $selector, $post_id = false, bool $format_value = true ) {
	return Plugin::instance()->values()->get( $selector, $post_id, $format_value );
}

/**
 * Prints a field value, escaped with wp_kses_post(). Arrays are joined with ", ".
 *
 * @param string $selector     Field name or key.
 * @param mixed  $post_id      Object id.
 * @param bool   $format_value Apply the field's return format.
 */
function wpff_the_field( string $selector, $post_id = false, bool $format_value = true ): void {
	echo wp_kses_post( wpff_value_to_string( wpff_get_field( $selector, $post_id, $format_value ) ) );
}

/**
 * Field settings plus its `value`.
 *
 * @param string $selector     Field name or key.
 * @param mixed  $post_id      Object id.
 * @param bool   $format_value Apply the field's return format.
 * @return array<string, mixed>|false
 */
function wpff_get_field_object( string $selector, $post_id = false, bool $format_value = true ) {
	return Plugin::instance()->values()->get_object( $selector, $post_id, $format_value ) ?? false;
}

/**
 * All field values of an object, keyed by field name.
 *
 * @param mixed $post_id      Object id.
 * @param bool  $format_value Apply return formats.
 * @return array<string, mixed>|false
 */
function wpff_get_fields( $post_id = false, bool $format_value = true ) {
	return Plugin::instance()->values()->get_all( $post_id, $format_value );
}

/**
 * Saves a field value. Use the field key when the field may not have a stored value yet
 * and several groups use the same name.
 *
 * @param string $selector Field name or key.
 * @param mixed  $value    Value.
 * @param mixed  $post_id  Object id.
 */
function wpff_update_field( string $selector, $value, $post_id = false ): bool {
	return Plugin::instance()->values()->update( $selector, $value, $post_id );
}

/**
 * Deletes a field value.
 *
 * @param string $selector Field name or key.
 * @param mixed  $post_id  Object id.
 */
function wpff_delete_field( string $selector, $post_id = false ): bool {
	return Plugin::instance()->values()->delete( $selector, $post_id );
}

/**
 * Whether the repeater / flexible content / group has another row. Use with wpff_the_row().
 *
 * Inside a row, pass a sub field name to loop over a nested repeater.
 *
 * @param string $selector Field name or key.
 * @param mixed  $post_id  Object id.
 */
function wpff_have_rows( string $selector, $post_id = false ): bool {
	return \FreeformFields\Api\RowLoop::have_rows( $selector, $post_id );
}

/**
 * Moves to the next row.
 *
 * @return array<string, mixed>|false The row's unformatted values.
 */
function wpff_the_row() {
	return \FreeformFields\Api\RowLoop::the_row();
}

/**
 * Value of a sub field in the current row.
 *
 * @param string $selector     Sub field name or key.
 * @param bool   $format_value Apply the return format.
 * @return mixed
 */
function wpff_get_sub_field( string $selector, bool $format_value = true ) {
	return \FreeformFields\Api\RowLoop::get_sub_field( $selector, $format_value );
}

/**
 * Prints a sub field value, escaped with wp_kses_post().
 *
 * @param string $selector     Sub field name or key.
 * @param bool   $format_value Apply the return format.
 */
function wpff_the_sub_field( string $selector, bool $format_value = true ): void {
	echo wp_kses_post( wpff_value_to_string( wpff_get_sub_field( $selector, $format_value ) ) );
}

/**
 * Sub field settings plus `value`.
 *
 * @param string $selector     Sub field name or key.
 * @param bool   $format_value Apply the return format.
 * @return array<string, mixed>|false
 */
function wpff_get_sub_field_object( string $selector, bool $format_value = true ) {
	return \FreeformFields\Api\RowLoop::get_sub_field_object( $selector, $format_value );
}

/**
 * Layout name of the current flexible content row.
 *
 * @return string|false
 */
function wpff_get_row_layout() {
	return \FreeformFields\Api\RowLoop::get_row_layout();
}

/**
 * 1-based index of the current row.
 */
function wpff_get_row_index(): int {
	return \FreeformFields\Api\RowLoop::get_row_index();
}

/**
 * The current row.
 *
 * @param bool $format_value Format sub values.
 * @return array<string, mixed>|false
 */
function wpff_get_row( bool $format_value = false ) {
	return \FreeformFields\Api\RowLoop::get_row( $format_value );
}

/**
 * Ends the innermost loop early.
 */
function wpff_reset_rows(): void {
	\FreeformFields\Api\RowLoop::reset_rows();
}

/**
 * Appends a row to a repeater or flexible content field.
 *
 * @param string               $selector Field name or key.
 * @param array<string, mixed> $row      Values by sub field name or key (flexible: with `acf_fc_layout`).
 * @param mixed                $post_id  Object id.
 * @return int|false New number of rows.
 */
function wpff_add_row( string $selector, array $row, $post_id = false ) {
	$rows = wpff_raw_rows( $selector, $post_id );
	if ( false === $rows ) {
		return false;
	}
	$rows[] = $row;
	return wpff_update_field( $selector, $rows, $post_id ) ? count( $rows ) : false;
}

/**
 * Updates (merges) a row.
 *
 * @param string               $selector Field name or key.
 * @param int                  $i        1-based row index.
 * @param array<string, mixed> $row      Values by sub field name or key.
 * @param mixed                $post_id  Object id.
 */
function wpff_update_row( string $selector, int $i, array $row, $post_id = false ): bool {
	$rows = wpff_raw_rows( $selector, $post_id );
	if ( false === $rows || ! isset( $rows[ $i - 1 ] ) ) {
		return false;
	}
	$rows[ $i - 1 ] = array_merge( $rows[ $i - 1 ], $row );
	return wpff_update_field( $selector, $rows, $post_id );
}

/**
 * Deletes a row.
 *
 * @param string $selector Field name or key.
 * @param int    $i        1-based row index.
 * @param mixed  $post_id  Object id.
 */
function wpff_delete_row( string $selector, int $i, $post_id = false ): bool {
	$rows = wpff_raw_rows( $selector, $post_id );
	if ( false === $rows || ! isset( $rows[ $i - 1 ] ) ) {
		return false;
	}
	array_splice( $rows, $i - 1, 1 );
	return wpff_update_field( $selector, $rows, $post_id );
}

/**
 * Unformatted rows of a repeater / flexible content field, or false when it is not one.
 *
 * @internal
 * @param string $selector Field name or key.
 * @param mixed  $post_id  Object id.
 * @return array<int, array<string, mixed>>|false
 */
function wpff_raw_rows( string $selector, $post_id ) {
	$values = Plugin::instance()->values();
	$ref    = \FreeformFields\Storage\ObjectRef::parse( $post_id );
	$field  = $ref ? $values->resolve_field( $selector, $ref ) : null;
	if ( ! $field || ! in_array( $values->kind_of( $field ), array( 'rows', 'layouts' ), true ) ) {
		return false;
	}
	$rows = $values->load( $field, $ref );
	return is_array( $rows ) ? array_values( $rows ) : array();
}

/**
 * Adds an options page (admin menu entry whose fields are stored as options).
 *
 * Settings as in ACF: page_title, menu_title, menu_slug, capability (default edit_posts),
 * position, parent_slug, icon_url, redirect, post_id (default `options`), autoload,
 * update_button, updated_message.
 *
 * @param array<string, mixed>|string $page Settings, or a page title.
 * @return array<string, mixed> Normalized page.
 */
function wpff_add_options_page( $page = '' ): array {
	return Plugin::instance()->options_pages()->add_local( '' === $page ? array() : $page );
}

/**
 * Adds an options sub page; without parent_slug it goes under the last page added.
 *
 * @param array<string, mixed>|string $page Settings, or a page title.
 * @return array<string, mixed> Normalized page.
 */
function wpff_add_options_sub_page( $page = '' ): array {
	return Plugin::instance()->options_pages()->add_local_sub( '' === $page ? array() : $page );
}

/**
 * Registers a block rendered by a PHP template, with fields from groups whose location is
 * "Block == {name}". Accepts ACF's settings (name, title, render_template, render_callback, …).
 *
 * @param array<string, mixed> $block Settings.
 * @return array<string, mixed>|false
 */
function wpff_register_block_type( array $block ) {
	return Plugin::instance()->blocks()->register( $block );
}

/**
 * Registers a field group from code.
 *
 * @param array<string, mixed> $group Group settings: key, title, fields, location, position, ...
 * @return string Group key.
 */
function wpff_add_local_field_group( array $group ): string {
	return Plugin::instance()->fields()->add_local_group( $group );
}

/**
 * Removes a field group registered from code.
 *
 * @param string $key Group key.
 */
function wpff_remove_local_field_group( string $key ): void {
	Plugin::instance()->fields()->remove_local_group( $key );
}

/**
 * Registers a custom field type.
 *
 * @param \FreeformFields\Fields\AbstractField $type Field type handler.
 */
function wpff_register_field_type( \FreeformFields\Fields\AbstractField $type ): void {
	Plugin::instance()->types()->register( $type );
}

/**
 * Converts a formatted value to printable text.
 *
 * @internal
 * @param mixed $value Value.
 */
function wpff_value_to_string( $value ): string {
	if ( is_bool( $value ) ) {
		return $value ? '1' : '';
	}
	if ( is_scalar( $value ) ) {
		return (string) $value;
	}
	if ( is_array( $value ) ) {
		// Image arrays print their URL; choice arrays print their labels.
		if ( isset( $value['url'] ) && is_string( $value['url'] ) ) {
			return $value['url'];
		}
		if ( isset( $value['label'] ) && is_string( $value['label'] ) ) {
			return $value['label'];
		}
		return implode( ', ', array_filter( array_map( 'wpff_value_to_string', $value ), 'strlen' ) );
	}
	return '';
}

/**
 * Sets a plugin setting for this request, e.g. `wpff_update_setting( 'google_api_key', '…' )`.
 *
 * Settings are not stored in the database: call this from a theme or plugin on every load.
 *
 * @param string $name  Setting name.
 * @param mixed  $value Value.
 */
function wpff_update_setting( string $name, $value ): void {
	$GLOBALS['wpff_settings'][ $name ] = $value;
}

/**
 * Reads a plugin setting.
 *
 * The value can be changed with the `wpff/settings/{name}` filter.
 *
 * @param string $name    Setting name.
 * @param mixed  $fallback Value when the setting is not set.
 * @return mixed
 */
function wpff_get_setting( string $name, $fallback = null ) {
	$settings = isset( $GLOBALS['wpff_settings'] ) && is_array( $GLOBALS['wpff_settings'] ) ? $GLOBALS['wpff_settings'] : array();
	// Also acf/settings/{name} in ACF compatibility mode.
	return \FreeformFields\Hooks::filter( "settings/{$name}", $settings[ $name ] ?? $fallback );
}

/**
 * Field objects (settings plus `value`) of every field stored on an object, keyed by name.
 *
 * @param mixed $post_id      Object id.
 * @param bool  $format_value Apply return formats.
 * @return array<string, array<string, mixed>>|false
 */
function wpff_get_field_objects( $post_id = false, bool $format_value = true ) {
	$values = wpff_get_fields( $post_id, false );
	if ( ! is_array( $values ) ) {
		return false;
	}
	$objects = array();
	foreach ( array_keys( $values ) as $name ) {
		$object = wpff_get_field_object( (string) $name, $post_id, $format_value );
		if ( $object ) {
			$objects[ $name ] = $object;
		}
	}
	return array() === $objects ? false : $objects;
}

/**
 * Field settings by key or name, after `load_field` filters.
 *
 * @param string $selector Field key or name.
 * @return array<string, mixed>|false
 */
function wpff_get_field_settings( string $selector ) {
	$fields = Plugin::instance()->fields();
	$field  = str_starts_with( $selector, 'field_' ) ? $fields->get_field( $selector ) : null;
	$field  = $field ?? $fields->find_field_by_name( $selector );
	return $field ? \FreeformFields\Hooks::load_field( $field ) : false;
}

/**
 * Fields of a group (by key or group array) or sub fields of a field (by field key).
 *
 * @param string|array<string, mixed> $parent_key Group key, group array or field key.
 * @return array<int, array<string, mixed>>
 */
function wpff_get_group_fields( $parent_key ): array {
	if ( is_array( $parent_key ) ) {
		$parent_key = (string) ( $parent_key['key'] ?? '' );
	}
	$fields = Plugin::instance()->fields();
	$group  = $fields->get_group( (string) $parent_key );
	if ( $group ) {
		return array_map( array( \FreeformFields\Hooks::class, 'load_field' ), $group['fields'] );
	}
	$field = $fields->get_field( (string) $parent_key );
	return $field ? array_map( array( \FreeformFields\Hooks::class, 'load_field' ), (array) ( $field['sub_fields'] ?? array() ) ) : array();
}

/**
 * Active field groups, optionally only those shown on a screen.
 *
 * @param array<string, mixed> $filter Screen, e.g. `[ 'post_id' => 5 ]`, `[ 'post_type' => 'page' ]`,
 *                                     `[ 'taxonomy' => 'category' ]`, `[ 'options_page' => 'theme-settings' ]`.
 * @return array<int, array<string, mixed>>
 */
function wpff_get_field_groups( array $filter = array() ): array {
	$plugin = Plugin::instance();
	$groups = array_values( $plugin->fields()->get_groups() );
	if ( array() === $filter ) {
		return $groups;
	}
	return array_values(
		array_filter(
			$groups,
			static fn( $group ) => $plugin->locations()->matches( $group['location'], $filter )
		)
	);
}

/**
 * Sets a sub field. Inside a have_rows() loop: `( $name, $value )` for the current row.
 * Outside a loop: `( [ 'repeater', 2, 'sub' ], $value, $post_id )` with a 1-based row number.
 *
 * @param string|array<int, string|int> $selector Sub field, or [ parent, row, sub ].
 * @param mixed                         $value    Value; null deletes it.
 * @param mixed                         $post_id  Object id (only with an array selector).
 */
function wpff_update_sub_field( $selector, $value, $post_id = false ): bool {
	if ( is_array( $selector ) ) {
		if ( 3 !== count( $selector ) ) {
			return false;
		}
		list( $parent, $i, $sub ) = array_values( $selector );
		$rows                     = wpff_raw_rows( (string) $parent, $post_id );
		if ( false === $rows || ! isset( $rows[ (int) $i - 1 ] ) ) {
			return false;
		}
		if ( null === $value ) {
			unset( $rows[ (int) $i - 1 ][ (string) $sub ] );
		} else {
			$rows[ (int) $i - 1 ][ (string) $sub ] = $value;
		}
		return wpff_update_field( (string) $parent, $rows, $post_id );
	}
	return \FreeformFields\Api\RowLoop::update_sub_field( (string) $selector, $value );
}

/**
 * Deletes a sub field value (see wpff_update_sub_field() for selectors).
 *
 * @param string|array<int, string|int> $selector Sub field, or [ parent, row, sub ].
 * @param mixed                         $post_id  Object id.
 */
function wpff_delete_sub_field( $selector, $post_id = false ): bool {
	return wpff_update_sub_field( $selector, null, $post_id );
}

/**
 * Escapes a value for a `value="…"` attribute so the form sends back exactly what is stored.
 *
 * @param mixed $value Value.
 */
function wpff_value_attr( $value ): string {
	return \FreeformFields\Fields\AbstractField::value_attr( $value );
}
