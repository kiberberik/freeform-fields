<?php
/**
 * Time picker field.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Fields\Types;

defined( 'ABSPATH' ) || exit;

/**
 * Native time input. Stored as `H:i:s` like ACF.
 */
class TimePicker extends DatePicker {

	public string $name = 'time_picker';

	protected string $store_format = 'H:i:s';
	protected string $input_type   = 'time';
	protected string $input_format = 'H:i:s';

	public function label(): string {
		return __( 'Time Picker', 'freeform-fields' );
	}

	public function defaults(): array {
		return array(
			'display_format' => 'g:i a',
			'return_format'  => 'g:i a',
		);
	}

	public function validate_value( $value, array $field ) {
		return $this->parse( $value ) ? true : __( 'Please enter a valid time.', 'freeform-fields' );
	}

	protected function accepted_formats(): array {
		return array( 'H:i:s', 'H:i' );
	}
}
