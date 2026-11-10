<?php
/**
 * WYSIWYG editor field.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Fields\Types;

use FreeformFields\Fields\AbstractField;

defined( 'ABSPATH' ) || exit;

/**
 * TinyMCE + text editor, initialized in the browser with wp.editor so it also works
 * inside the block editor's meta box area.
 */
class Wysiwyg extends AbstractField {

	public string $name = 'wysiwyg';

	public string $category = 'content';

	public function label(): string {
		return __( 'WYSIWYG Editor', 'freeform-fields' );
	}

	public function defaults(): array {
		return array(
			// 'all', 'visual' or 'text'.
			'tabs'         => 'all',
			// 'full' or 'basic'.
			'toolbar'      => 'full',
			'media_upload' => 1,
		);
	}

	public function settings(): array {
		return array(
			self::setting( 'default_value', __( 'Default Value', 'freeform-fields' ), 'textarea' ),
			self::setting(
				'tabs',
				__( 'Tabs', 'freeform-fields' ),
				'select',
				array(
					'options' => array(
						'all'    => __( 'Visual & Text', 'freeform-fields' ),
						'visual' => __( 'Visual Only', 'freeform-fields' ),
						'text'   => __( 'Text Only', 'freeform-fields' ),
					),
				)
			),
			self::setting(
				'toolbar',
				__( 'Toolbar', 'freeform-fields' ),
				'select',
				array(
					'options' => array(
						'full'  => __( 'Full', 'freeform-fields' ),
						'basic' => __( 'Basic', 'freeform-fields' ),
					),
				)
			),
			self::setting( 'media_upload', __( 'Show Media Upload Buttons', 'freeform-fields' ), 'toggle' ),
		);
	}

	public function enqueue_assets(): void {
		wp_enqueue_editor();
		wp_enqueue_media();
	}

	public function render_input( array $field ): void {
		printf(
			'<textarea id="%s" name="%s" rows="10" class="wpff-wysiwyg" data-tabs="%s" data-toolbar="%s" data-media="%d">%s</textarea>',
			esc_attr( $field['input_id'] ),
			esc_attr( $field['input_name'] ),
			esc_attr( $field['tabs'] ),
			esc_attr( $field['toolbar'] ),
			! empty( $field['media_upload'] ) ? 1 : 0,
			esc_textarea( is_scalar( $field['value'] ) ? (string) $field['value'] : '' )
		);
	}

	public function format_value( $value, $object_id, array $field ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}
		self::add_content_filters();
		/**
		 * Filters WYSIWYG output: embeds, texturize, autop, shortcodes, responsive images — the same
		 * chain and priorities as ACF's `acf_the_content`, without other plugins' the_content hooks.
		 *
		 * @param string $value HTML.
		 */
		$value = apply_filters( 'wpff_the_content', $value );
		if ( \FreeformFields\Plugin::compat_enabled() ) {
			// Themes extend ACF's chain on acf_the_content; ACF itself is absent, so only their callbacks run.
			$value = apply_filters( 'acf_the_content', $value ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- ACF compatibility.
		}
		// As the_content() does.
		return str_replace( ']]>', ']]&gt;', $value );
	}

	/**
	 * Registers the default `wpff_the_content` callbacks once.
	 */
	private static function add_content_filters(): void {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		if ( ! empty( $GLOBALS['wp_embed'] ) ) {
			add_filter( 'wpff_the_content', array( $GLOBALS['wp_embed'], 'run_shortcode' ), 8 );
			add_filter( 'wpff_the_content', array( $GLOBALS['wp_embed'], 'autoembed' ), 8 );
		}
		add_filter( 'wpff_the_content', 'capital_P_dangit', 11 );
		add_filter( 'wpff_the_content', 'wptexturize' );
		add_filter( 'wpff_the_content', 'convert_smilies', 20 );
		add_filter( 'wpff_the_content', 'wpautop' );
		add_filter( 'wpff_the_content', 'shortcode_unautop' );
		add_filter( 'wpff_the_content', 'wp_filter_content_tags' );
		add_filter( 'wpff_the_content', 'do_shortcode', 11 );
	}
}
