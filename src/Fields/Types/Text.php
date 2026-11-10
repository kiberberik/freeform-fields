<?php
/**
 * Single-line text field.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Fields\Types;

use FreeformFields\Fields\AbstractField;

defined( 'ABSPATH' ) || exit;

/**
 * Text input.
 */
class Text extends AbstractField {

	public string $name = 'text';

	/**
	 * HTML input type used by render_input().
	 *
	 * @var string
	 */
	protected string $input_type = 'text';

	public function label(): string {
		return __( 'Text', 'freeform-fields' );
	}

	public function defaults(): array {
		return array(
			'placeholder' => '',
			'prepend'     => '',
			'append'      => '',
			'maxlength'   => '',
		);
	}

	public function settings(): array {
		return array(
			self::setting( 'default_value', __( 'Default Value', 'freeform-fields' ), 'text', array( 'help' => __( 'Used when the field has no value yet.', 'freeform-fields' ) ) ),
			self::setting( 'placeholder', __( 'Placeholder Text', 'freeform-fields' ) ),
			self::setting( 'prepend', __( 'Prepend', 'freeform-fields' ) ),
			self::setting( 'append', __( 'Append', 'freeform-fields' ) ),
			self::setting( 'maxlength', __( 'Character Limit', 'freeform-fields' ), 'number' ),
		);
	}

	public function render_input( array $field ): void {
		$input = '<input' . self::attrs(
			array(
				'type'          => $this->input_type,
				'id'            => $field['input_id'],
				'name'          => $field['input_name'],
				'value'         => is_scalar( $field['value'] ) ? (string) $field['value'] : '',
				'placeholder'   => $field['placeholder'],
				'maxlength'     => $field['maxlength'] ?? '',
				'aria-required' => ! empty( $field['required'] ) ? 'true' : null,
				'class'         => 'wpff-control',
			) + $this->extra_attrs( $field )
		) . ' />';
		self::print_affixed( $field, $input );
	}

	/**
	 * Additional input attributes for subclasses.
	 *
	 * @param array<string, mixed> $field Field settings.
	 * @return array<string, scalar|null>
	 */
	protected function extra_attrs( array $field ): array {
		return array();
	}

	public function sanitize( $value, array $field ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		return self::sanitize_html( (string) $value );
	}

	public function validate_value( $value, array $field ) {
		$max = (int) ( $field['maxlength'] ?? 0 );
		if ( $max > 0 && mb_strlen( (string) $value ) > $max ) {
			/* translators: %d: maximum number of characters. */
			return sprintf( __( 'Value must not exceed %d characters.', 'freeform-fields' ), $max );
		}
		return true;
	}
}
