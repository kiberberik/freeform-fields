<?php
/**
 * Select field.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Fields\Types;

defined( 'ABSPATH' ) || exit;

/**
 * Native <select>, single or multiple.
 */
class Select extends ChoiceField {

	public string $name = 'select';

	public function label(): string {
		return __( 'Select', 'freeform-fields' );
	}

	public function defaults(): array {
		return parent::defaults() + array(
			'multiple'    => 0,
			'placeholder' => '',
		);
	}

	public function settings(): array {
		return array_merge(
			parent::settings(),
			array(
				self::setting( 'default_value', __( 'Default Value', 'freeform-fields' ), 'lines', array( 'help' => __( 'Enter each default value on a new line.', 'freeform-fields' ) ) ),
				self::setting( 'multiple', __( 'Select Multiple', 'freeform-fields' ), 'toggle' ),
				self::setting( 'placeholder', __( 'Placeholder Text', 'freeform-fields' ), 'text', array( 'show_if' => array( 'multiple' => 0 ) ) ),
			)
		);
	}

	protected function is_multiple( array $field ): bool {
		return ! empty( $field['multiple'] );
	}

	public function render_input( array $field ): void {
		$multiple = $this->is_multiple( $field );
		$selected = self::selected( $field['value'] );

		if ( $multiple ) {
			// Lets the user clear every option and still submit the field.
			printf( '<input type="hidden" name="%s" value="" />', esc_attr( $field['input_name'] ) );
		}

		$attrs = self::attrs(
			array(
				'id'            => $field['input_id'],
				'name'          => $field['input_name'] . ( $multiple ? '[]' : '' ),
				'multiple'      => $multiple,
				'aria-required' => ! empty( $field['required'] ) ? 'true' : null,
				'class'         => 'wpff-control',
			)
		);
		echo '<select' . $attrs . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attrs() escapes.
		if ( ! $multiple && ( ! empty( $field['allow_null'] ) || array() === $selected ) ) {
			printf( '<option value="">%s</option>', esc_html( $field['placeholder'] ?: __( '— Select —', 'freeform-fields' ) ) );
		}
		foreach ( self::normalize_choices( $field['choices'] ) as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				wpff_value_attr( $value ),
				selected( in_array( (string) $value, $selected, true ), true, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}
}
