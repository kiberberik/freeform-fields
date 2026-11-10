<?php
/**
 * ACF function names, defined only when ACF itself is not loaded.
 *
 * Lets themes written for ACF run on WP Freeform Fields without changes. Disable with
 * `define( 'WPFF_ACF_COMPAT', false );` or the `wpff/acf_compat` filter.
 *
 * @package FreeformFields
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'get_field' ) ) {
	/**
	 * @see wpff_get_field()
	 */
	function get_field( $selector, $post_id = false, $format_value = true, $escape_html = false ) {
		$value = wpff_get_field( (string) $selector, $post_id, (bool) $format_value );
		return $escape_html && is_string( $value ) ? wp_kses_post( $value ) : $value;
	}
}

if ( ! function_exists( 'the_field' ) ) {
	/**
	 * @see wpff_the_field()
	 */
	function the_field( $selector, $post_id = false, $format_value = true ) {
		wpff_the_field( (string) $selector, $post_id, (bool) $format_value );
	}
}

if ( ! function_exists( 'get_field_object' ) ) {
	/**
	 * @see wpff_get_field_object()
	 */
	function get_field_object( $selector, $post_id = false, $format_value = true, $load_value = true ) {
		return wpff_get_field_object( (string) $selector, $post_id, (bool) $format_value );
	}
}

if ( ! function_exists( 'get_fields' ) ) {
	/**
	 * @see wpff_get_fields()
	 */
	function get_fields( $post_id = false, $format_value = true ) {
		return wpff_get_fields( $post_id, (bool) $format_value );
	}
}

if ( ! function_exists( 'update_field' ) ) {
	/**
	 * @see wpff_update_field()
	 */
	function update_field( $selector, $value, $post_id = false ) {
		return wpff_update_field( (string) $selector, $value, $post_id );
	}
}

if ( ! function_exists( 'delete_field' ) ) {
	/**
	 * @see wpff_delete_field()
	 */
	function delete_field( $selector, $post_id = false ) {
		return wpff_delete_field( (string) $selector, $post_id );
	}
}

if ( ! function_exists( 'acf_add_local_field_group' ) ) {
	/**
	 * @see wpff_add_local_field_group()
	 */
	function acf_add_local_field_group( $field_group ) {
		wpff_add_local_field_group( (array) $field_group );
		return true;
	}
}

if ( ! function_exists( 'acf_remove_local_field_group' ) ) {
	/**
	 * @see wpff_remove_local_field_group()
	 */
	function acf_remove_local_field_group( $key ) {
		wpff_remove_local_field_group( (string) $key );
		return true;
	}
}

if ( ! function_exists( 'have_rows' ) ) {
	/**
	 * @see wpff_have_rows()
	 */
	function have_rows( $selector, $post_id = false ) {
		return wpff_have_rows( (string) $selector, $post_id );
	}
}

if ( ! function_exists( 'the_row' ) ) {
	/**
	 * @see wpff_the_row()
	 */
	function the_row( $format = false ) {
		return wpff_the_row();
	}
}

if ( ! function_exists( 'get_sub_field' ) ) {
	/**
	 * @see wpff_get_sub_field()
	 */
	function get_sub_field( $selector = '', $format_value = true ) {
		return wpff_get_sub_field( (string) $selector, (bool) $format_value );
	}
}

if ( ! function_exists( 'the_sub_field' ) ) {
	/**
	 * @see wpff_the_sub_field()
	 */
	function the_sub_field( $field_name, $format_value = true ) {
		wpff_the_sub_field( (string) $field_name, (bool) $format_value );
	}
}

if ( ! function_exists( 'get_sub_field_object' ) ) {
	/**
	 * @see wpff_get_sub_field_object()
	 */
	function get_sub_field_object( $selector, $format_value = true, $load_value = true ) {
		return wpff_get_sub_field_object( (string) $selector, (bool) $format_value );
	}
}

if ( ! function_exists( 'get_row_layout' ) ) {
	/**
	 * @see wpff_get_row_layout()
	 */
	function get_row_layout() {
		return wpff_get_row_layout();
	}
}

if ( ! function_exists( 'get_row_index' ) ) {
	/**
	 * @see wpff_get_row_index()
	 */
	function get_row_index() {
		return wpff_get_row_index();
	}
}

if ( ! function_exists( 'get_row' ) ) {
	/**
	 * @see wpff_get_row()
	 */
	function get_row( $format = false ) {
		return wpff_get_row( (bool) $format );
	}
}

if ( ! function_exists( 'reset_rows' ) ) {
	/**
	 * @see wpff_reset_rows()
	 */
	function reset_rows() {
		wpff_reset_rows();
		return true;
	}
}

if ( ! function_exists( 'add_row' ) ) {
	/**
	 * @see wpff_add_row()
	 */
	function add_row( $selector, $row = false, $post_id = false ) {
		return wpff_add_row( (string) $selector, (array) $row, $post_id );
	}
}

if ( ! function_exists( 'update_row' ) ) {
	/**
	 * @see wpff_update_row()
	 */
	function update_row( $selector, $i = 1, $row = false, $post_id = false ) {
		return wpff_update_row( (string) $selector, (int) $i, (array) $row, $post_id );
	}
}

if ( ! function_exists( 'delete_row' ) ) {
	/**
	 * @see wpff_delete_row()
	 */
	function delete_row( $selector, $i = 1, $post_id = false ) {
		return wpff_delete_row( (string) $selector, (int) $i, $post_id );
	}
}

if ( ! function_exists( 'acf_add_options_page' ) ) {
	/**
	 * @see wpff_add_options_page()
	 */
	function acf_add_options_page( $page = '' ) {
		return wpff_add_options_page( $page );
	}
}

if ( ! function_exists( 'acf_add_options_sub_page' ) ) {
	/**
	 * @see wpff_add_options_sub_page()
	 */
	function acf_add_options_sub_page( $page = '' ) {
		return wpff_add_options_sub_page( $page );
	}
}

if ( ! function_exists( 'acf_register_block_type' ) ) {
	/**
	 * @see wpff_register_block_type()
	 */
	function acf_register_block_type( $block ) {
		return \FreeformFields\Plugin::instance()->blocks()->register( (array) $block, 'acf' );
	}
}

if ( ! function_exists( 'acf_register_block' ) ) {
	/**
	 * @see wpff_register_block_type()
	 */
	function acf_register_block( $block ) {
		return \FreeformFields\Plugin::instance()->blocks()->register( (array) $block, 'acf' );
	}
}

if ( ! function_exists( 'acf_update_setting' ) ) {
	/**
	 * @param string $name  Setting name.
	 * @param mixed  $value Value.
	 */
	function acf_update_setting( $name, $value ) {
		wpff_update_setting( (string) $name, $value );
		return true;
	}
}

if ( ! function_exists( 'acf_get_setting' ) ) {
	/**
	 * @param string $name    Setting name.
	 * @param mixed  $fallback Fallback.
	 */
	function acf_get_setting( $name, $fallback = null ) {
		return wpff_get_setting( (string) $name, $fallback );
	}
}

if ( ! function_exists( 'get_field_objects' ) ) {
	/**
	 * @see wpff_get_field_objects()
	 */
	function get_field_objects( $post_id = false, $format_value = true, $load_value = true ) {
		return wpff_get_field_objects( $post_id, (bool) $format_value );
	}
}

if ( ! function_exists( 'acf_get_field' ) ) {
	/**
	 * @see wpff_get_field_settings()
	 */
	function acf_get_field( $selector = '' ) {
		return wpff_get_field_settings( (string) $selector );
	}
}

if ( ! function_exists( 'acf_get_fields' ) ) {
	/**
	 * @see wpff_get_group_fields()
	 */
	function acf_get_fields( $parent_key ) {
		return wpff_get_group_fields( $parent_key );
	}
}

if ( ! function_exists( 'acf_get_field_groups' ) ) {
	/**
	 * @see wpff_get_field_groups()
	 */
	function acf_get_field_groups( $filter = array() ) {
		return wpff_get_field_groups( (array) $filter );
	}
}

if ( ! function_exists( 'acf_get_field_group' ) ) {
	/**
	 * Field group by key.
	 */
	function acf_get_field_group( $key ) {
		$group = \FreeformFields\Plugin::instance()->fields()->get_group( (string) $key );
		return $group ? $group : false;
	}
}

if ( ! function_exists( 'acf_add_local_field' ) ) {
	/**
	 * Adds a field to a group registered from PHP (`parent` = group key).
	 */
	function acf_add_local_field( $field ) {
		return \FreeformFields\Plugin::instance()->fields()->add_local_field( (array) $field );
	}
}

if ( ! function_exists( 'update_sub_field' ) ) {
	/**
	 * @see wpff_update_sub_field()
	 */
	function update_sub_field( $selector, $value, $post_id = false ) {
		return wpff_update_sub_field( $selector, $value, $post_id );
	}
}

if ( ! function_exists( 'delete_sub_field' ) ) {
	/**
	 * @see wpff_delete_sub_field()
	 */
	function delete_sub_field( $selector, $post_id = false ) {
		return wpff_delete_sub_field( $selector, $post_id );
	}
}

if ( ! function_exists( 'acf_esc_html' ) ) {
	/**
	 * HTML escaped like ACF: safe tags kept.
	 */
	function acf_esc_html( $string = '' ) {
		return wp_kses_post( (string) $string );
	}
}

if ( ! function_exists( 'acf_get_options_page' ) ) {
	/**
	 * Options page settings by menu slug.
	 */
	function acf_get_options_page( $slug ) {
		$page = \FreeformFields\Plugin::instance()->options_pages()->get( (string) $slug );
		return $page ? $page : false;
	}
}

if ( ! function_exists( 'acf_get_options_pages' ) ) {
	/**
	 * All options pages.
	 */
	function acf_get_options_pages() {
		$pages = \FreeformFields\Plugin::instance()->options_pages()->all();
		return array() === $pages ? false : $pages;
	}
}

if ( ! function_exists( 'acf_form_head' ) ) {
	/**
	 * @see FreeformFields\Front\Forms::handle()
	 */
	function acf_form_head() {
		\FreeformFields\Front\Forms::instance()->handle();
	}
}

if ( ! function_exists( 'acf_form' ) ) {
	/**
	 * @see FreeformFields\Front\Forms::render()
	 */
	function acf_form( $args = array() ) {
		\FreeformFields\Front\Forms::instance()->render( (array) $args );
	}
}
