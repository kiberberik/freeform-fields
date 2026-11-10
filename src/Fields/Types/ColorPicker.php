<?php
/**
 * Color picker field.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Fields\Types;

use FreeformFields\Fields\AbstractField;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress color picker. Stores `#rrggbb` (or `rgba(...)` with opacity enabled).
 */
class ColorPicker extends AbstractField {

	public string $name = 'color_picker';

	public string $category = 'advanced';

	public function label(): string {
		return __( 'Color Picker', 'freeform-fields' );
	}

	public function defaults(): array {
		return array(
			'enable_opacity' => 0,
			// 'string' or 'array' (red, green, blue, alpha).
			'return_format'  => 'string',
		);
	}

	public function settings(): array {
		return array(
			self::setting( 'default_value', __( 'Default Value', 'freeform-fields' ), 'text', array( 'help' => '#ffffff' ) ),
			self::setting( 'enable_opacity', __( 'Enable Transparency', 'freeform-fields' ), 'toggle' ),
			self::setting(
				'return_format',
				__( 'Return Format', 'freeform-fields' ),
				'select',
				array(
					'options' => array(
						'string' => __( 'Hex String', 'freeform-fields' ),
						'array'  => __( 'RGBA Array', 'freeform-fields' ),
					),
				)
			),
		);
	}

	public function enqueue_assets(): void {
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
	}

	public function render_input( array $field ): void {
		printf(
			'<input type="text" id="%s" name="%s" value="%s" class="wpff-color" data-alpha-enabled="%s" />',
			esc_attr( $field['input_id'] ),
			esc_attr( $field['input_name'] ),
			esc_attr( is_scalar( $field['value'] ) ? (string) $field['value'] : '' ),
			! empty( $field['enable_opacity'] ) ? 'true' : 'false'
		);
	}

	public function sanitize( $value, array $field ) {
		return is_scalar( $value ) ? strtolower( trim( (string) $value ) ) : '';
	}

	public function validate_value( $value, array $field ) {
		if ( null === self::parse( $value ) ) {
			return __( 'Value must be a valid color.', 'freeform-fields' );
		}
		return true;
	}

	public function format_value( $value, $object_id, array $field ) {
		if ( 'array' !== $field['return_format'] || ! is_string( $value ) || '' === $value ) {
			return $value;
		}
		return self::parse( $value ) ?? '';
	}

	/**
	 * Parses `#rgb`, `#rrggbb` or `rgba(r, g, b, a)`.
	 *
	 * @param mixed $value Color string.
	 * @return array{red: int, green: int, blue: int, alpha: float}|null
	 */
	public static function parse( $value ): ?array {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $value, $m ) ) {
			$hex = 3 === strlen( $m[1] ) ? preg_replace( '/(.)/', '$1$1', $m[1] ) : $m[1];
			return array(
				'red'   => hexdec( substr( $hex, 0, 2 ) ),
				'green' => hexdec( substr( $hex, 2, 2 ) ),
				'blue'  => hexdec( substr( $hex, 4, 2 ) ),
				'alpha' => 1.0,
			);
		}
		if ( preg_match( '/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*(?:,\s*([01]|0?\.\d+))?\s*\)$/i', $value, $m ) ) {
			if ( max( (int) $m[1], (int) $m[2], (int) $m[3] ) > 255 ) {
				return null;
			}
			return array(
				'red'   => (int) $m[1],
				'green' => (int) $m[2],
				'blue'  => (int) $m[3],
				'alpha' => isset( $m[4] ) ? (float) $m[4] : 1.0,
			);
		}
		return null;
	}
}
