<?php
/**
 * Email field.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Fields\Types;

defined( 'ABSPATH' ) || exit;

/**
 * Email input.
 */
class Email extends Text {

	public string $name = 'email';

	protected string $input_type = 'email';

	public function label(): string {
		return __( 'Email', 'freeform-fields' );
	}

	public function sanitize( $value, array $field ) {
		return is_scalar( $value ) ? trim( sanitize_text_field( (string) $value ) ) : '';
	}

	public function validate_value( $value, array $field ) {
		if ( '' !== $value && ! is_email( $value ) ) {
			return __( 'Value must be a valid email address.', 'freeform-fields' );
		}
		return true;
	}
}
