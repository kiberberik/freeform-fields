<?php
/**
 * True / False field.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Fields\Types;

use FreeformFields\Fields\AbstractField;

defined( 'ABSPATH' ) || exit;

/**
 * Single checkbox or toggle. Stored as 1 / 0, returned as bool.
 */
class TrueFalse extends AbstractField {

	public string $name = 'true_false';

	public string $category = 'choice';

	public function label(): string {
		return __( 'True / False', 'freeform-fields' );
	}

	public function defaults(): array {
		return array(
			'message'     => '',
			'ui'          => 0,
			'ui_on_text'  => '',
			'ui_off_text' => '',
		);
	}

	public function settings(): array {
		$ui = array( 'show_if' => array( 'ui' => 1 ) );
		return array(
			self::setting( 'message', __( 'Message', 'freeform-fields' ), 'text', array( 'help' => __( 'Text shown next to the checkbox.', 'freeform-fields' ) ) ),
			self::setting( 'default_value', __( 'Default Value', 'freeform-fields' ), 'toggle' ),
			self::setting( 'ui', __( 'Stylized UI', 'freeform-fields' ), 'toggle' ),
			self::setting( 'ui_on_text', __( 'On Text', 'freeform-fields' ), 'text', $ui ),
			self::setting( 'ui_off_text', __( 'Off Text', 'freeform-fields' ), 'text', $ui ),
		);
	}

	public function render_input( array $field ): void {
		printf( '<input type="hidden" name="%s" value="0" />', esc_attr( $field['input_name'] ) );
		printf(
			'<label class="wpff-true-false%s"><input type="checkbox" id="%s" name="%s" value="1"%s />',
			! empty( $field['ui'] ) ? ' wpff-switch' : '',
			esc_attr( $field['input_id'] ),
			esc_attr( $field['input_name'] ),
			checked( ! empty( $field['value'] ), true, false )
		);
		if ( ! empty( $field['ui'] ) ) {
			printf(
				'<span class="wpff-switch__track" aria-hidden="true"><span class="wpff-switch__on">%s</span><span class="wpff-switch__off">%s</span></span>',
				esc_html( $field['ui_on_text'] ?: __( 'Yes', 'freeform-fields' ) ),
				esc_html( $field['ui_off_text'] ?: __( 'No', 'freeform-fields' ) )
			);
		}
		if ( '' !== $field['message'] ) {
			echo ' <span class="wpff-true-false__message">' . esc_html( $field['message'] ) . '</span>';
		}
		echo '</label>';
	}

	public function sanitize( $value, array $field ) {
		return ! empty( $value ) && '0' !== $value ? 1 : 0;
	}

	public function is_empty( $value ): bool {
		// A required true/false must be switched on.
		return empty( $value );
	}

	public function format_value( $value, $object_id, array $field ) {
		return (bool) $value;
	}
}
