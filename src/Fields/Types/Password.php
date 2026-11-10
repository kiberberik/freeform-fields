<?php
/**
 * Password field.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Fields\Types;

defined( 'ABSPATH' ) || exit;

/**
 * Masked text input. Stored as entered (like ACF): not a place for real secrets.
 */
class Password extends Text {

	public string $name = 'password';

	protected string $input_type = 'password';

	public function label(): string {
		return __( 'Password', 'freeform-fields' );
	}

	public function defaults(): array {
		return array(
			'placeholder' => '',
			'prepend'     => '',
			'append'      => '',
		);
	}

	public function settings(): array {
		return array(
			self::setting( 'placeholder', __( 'Placeholder Text', 'freeform-fields' ) ),
			self::setting( 'prepend', __( 'Prepend', 'freeform-fields' ) ),
			self::setting( 'append', __( 'Append', 'freeform-fields' ) ),
		);
	}

	protected function extra_attrs( array $field ): array {
		return array( 'autocomplete' => 'new-password' );
	}

	public function sanitize( $value, array $field ) {
		return is_scalar( $value ) ? (string) $value : '';
	}
}
