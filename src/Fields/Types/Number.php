<?php
/**
 * Number field.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Fields\Types;

defined( 'ABSPATH' ) || exit;

/**
 * Numeric input with min/max/step. Stored as a string, like ACF.
 */
class Number extends Text {

	public string $name = 'number';

	protected string $input_type = 'number';

	public function label(): string {
		return __( 'Number', 'freeform-fields' );
	}

	public function defaults(): array {
		return array(
			'placeholder' => '',
			'prepend'     => '',
			'append'      => '',
			'min'         => '',
			'max'         => '',
			'step'        => '',
		);
	}

	public function settings(): array {
		return array(
			self::setting( 'default_value', __( 'Default Value', 'freeform-fields' ), 'number' ),
			self::setting( 'placeholder', __( 'Placeholder Text', 'freeform-fields' ) ),
			self::setting( 'prepend', __( 'Prepend', 'freeform-fields' ) ),
			self::setting( 'append', __( 'Append', 'freeform-fields' ) ),
			self::setting( 'min', __( 'Minimum Value', 'freeform-fields' ), 'number' ),
			self::setting( 'max', __( 'Maximum Value', 'freeform-fields' ), 'number' ),
			self::setting( 'step', __( 'Step Size', 'freeform-fields' ), 'number' ),
		);
	}

	protected function extra_attrs( array $field ): array {
		return array(
			'min'  => $field['min'],
			'max'  => $field['max'],
			'step' => $field['step'] ?: 'any',
		);
	}

	public function sanitize( $value, array $field ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		// Accept a comma as decimal separator, strip spaces used as thousands separators.
		$value = str_replace( array( ' ', ',' ), array( '', '.' ), trim( (string) $value ) );
		return $value;
	}

	public function validate_value( $value, array $field ) {
		if ( '' === $value ) {
			return true;
		}
		if ( ! is_numeric( $value ) ) {
			return __( 'Value must be a number.', 'freeform-fields' );
		}
		$number = (float) $value;
		if ( '' !== $field['min'] && $number < (float) $field['min'] ) {
			/* translators: %s: minimum value. */
			return sprintf( __( 'Value must be equal to or higher than %s.', 'freeform-fields' ), $field['min'] );
		}
		if ( '' !== $field['max'] && $number > (float) $field['max'] ) {
			/* translators: %s: maximum value. */
			return sprintf( __( 'Value must be equal to or lower than %s.', 'freeform-fields' ), $field['max'] );
		}
		return true;
	}
}
