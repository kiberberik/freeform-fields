<?php
/**
 * URL field.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Fields\Types;

defined( 'ABSPATH' ) || exit;

/**
 * URL input. Accepts absolute URLs and site-relative paths (`/about`).
 */
class Url extends Text {

	public string $name = 'url';

	protected string $input_type = 'url';

	public function label(): string {
		return __( 'URL', 'freeform-fields' );
	}

	public function defaults(): array {
		return array( 'placeholder' => '' );
	}

	public function settings(): array {
		return array(
			self::setting( 'default_value', __( 'Default Value', 'freeform-fields' ) ),
			self::setting( 'placeholder', __( 'Placeholder Text', 'freeform-fields' ) ),
		);
	}

	public function sanitize( $value, array $field ) {
		return is_scalar( $value ) ? trim( sanitize_text_field( (string) $value ) ) : '';
	}

	public function validate_value( $value, array $field ) {
		if ( '' === $value ) {
			return true;
		}
		$is_relative = str_starts_with( $value, '/' ) && ! str_starts_with( $value, '//' );
		$is_absolute = false !== filter_var( $value, FILTER_VALIDATE_URL )
			&& in_array( wp_parse_url( $value, PHP_URL_SCHEME ), wp_allowed_protocols(), true );
		if ( ! $is_relative && ! $is_absolute ) {
			return __( 'Value must be a valid URL.', 'freeform-fields' );
		}
		return true;
	}
}
