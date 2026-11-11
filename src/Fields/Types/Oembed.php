<?php
/**
 * oEmbed field.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Fields\Types;

defined( 'ABSPATH' ) || exit;

/**
 * Stores a URL (YouTube, Vimeo, ...); get_field() returns the embed HTML.
 */
class Oembed extends Url {

	public string $name = 'oembed';

	public string $category = 'content';

	public function label(): string {
		return __( 'oEmbed', 'freeform-fields' );
	}

	public function defaults(): array {
		return array(
			'width'  => '',
			'height' => '',
		);
	}

	public function settings(): array {
		return array(
			self::setting( 'width', __( 'Embed Size: width', 'freeform-fields' ), 'number' ),
			self::setting( 'height', __( 'Embed Size: height', 'freeform-fields' ), 'number' ),
		);
	}

	public function render_input( array $field ): void {
		$field['placeholder'] = 'https://www.youtube.com/watch?v=…';
		parent::render_input( $field );
	}

	public function format_value( $value, $object_id, array $field ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}
		$args = array_filter(
			array(
				'width'  => (int) $field['width'],
				'height' => (int) $field['height'],
			)
		);
		$html = self::embed( $value, $args );
		return $html ? $html : $value;
	}

	/**
	 * Embed HTML through WP_Embed, which caches the provider's answer (in the post's meta, or in
	 * an oembed_cache post outside the loop), so only the first view waits on the remote request.
	 *
	 * @param string               $url  URL.
	 * @param array<string, mixed> $args Size.
	 * @return string|false
	 */
	private static function embed( string $url, array $args ) {
		global $wp_embed;
		if ( ! $wp_embed instanceof \WP_Embed ) {
			return wp_oembed_get( $url, $args );
		}
		$previous                       = $wp_embed->return_false_on_fail;
		$wp_embed->return_false_on_fail = true;
		$html                           = $wp_embed->shortcode( $args, $url );
		$wp_embed->return_false_on_fail = $previous;
		return is_string( $html ) && '' !== $html ? $html : false;
	}
}
