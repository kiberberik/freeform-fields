<?php
/**
 * Multi-line text field.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Fields\Types;

use FreeformFields\Fields\AbstractField;

defined( 'ABSPATH' ) || exit;

/**
 * Textarea with optional new-line formatting on output.
 */
class Textarea extends AbstractField {

	public string $name = 'textarea';

	public function label(): string {
		return __( 'Text Area', 'freeform-fields' );
	}

	public function defaults(): array {
		return array(
			'placeholder' => '',
			'maxlength'   => '',
			'rows'        => 8,
			// '' (none), 'wpautop' (paragraphs) or 'br'.
			'new_lines'   => '',
		);
	}

	public function settings(): array {
		return array(
			self::setting( 'default_value', __( 'Default Value', 'freeform-fields' ), 'textarea' ),
			self::setting( 'placeholder', __( 'Placeholder Text', 'freeform-fields' ) ),
			self::setting( 'rows', __( 'Rows', 'freeform-fields' ), 'number' ),
			self::setting( 'maxlength', __( 'Character Limit', 'freeform-fields' ), 'number' ),
			self::setting(
				'new_lines',
				__( 'New Lines', 'freeform-fields' ),
				'select',
				array(
					'options' => array(
						''        => __( 'No formatting', 'freeform-fields' ),
						'wpautop' => __( 'Automatically add paragraphs', 'freeform-fields' ),
						'br'      => __( 'Automatically add <br>', 'freeform-fields' ),
					),
					'help'    => __( 'How new lines are rendered on output.', 'freeform-fields' ),
				)
			),
		);
	}

	public function render_input( array $field ): void {
		$attrs = self::attrs(
			array(
				'id'            => $field['input_id'],
				'name'          => $field['input_name'],
				'rows'          => (int) $field['rows'] ?: 8,
				'placeholder'   => $field['placeholder'],
				'maxlength'     => $field['maxlength'],
				'aria-required' => ! empty( $field['required'] ) ? 'true' : null,
				'class'         => 'wpff-control',
			)
		);
		$value = esc_textarea( is_scalar( $field['value'] ) ? (string) $field['value'] : '' );
		echo '<textarea' . $attrs . '>' . $value . '</textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attrs() escapes.
	}

	public function validate_value( $value, array $field ) {
		$max = (int) ( $field['maxlength'] ?? 0 );
		if ( $max > 0 && mb_strlen( (string) $value ) > $max ) {
			/* translators: %d: maximum number of characters. */
			return sprintf( __( 'Value must not exceed %d characters.', 'freeform-fields' ), $max );
		}
		return true;
	}

	public function format_value( $value, $object_id, array $field ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}
		switch ( $field['new_lines'] ) {
			case 'wpautop':
				return wpautop( $value );
			case 'br':
				return nl2br( $value );
			default:
				return $value;
		}
	}
}
