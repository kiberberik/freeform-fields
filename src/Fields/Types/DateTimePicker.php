<?php
/**
 * Date-time picker field.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Fields\Types;

defined( 'ABSPATH' ) || exit;

/**
 * Native date-time input. Stored as `Y-m-d H:i:s` like ACF.
 */
class DateTimePicker extends DatePicker {

	public string $name = 'date_time_picker';

	protected string $store_format = 'Y-m-d H:i:s';
	protected string $input_type   = 'datetime-local';
	protected string $input_format = 'Y-m-d\TH:i:s';

	public function label(): string {
		return __( 'Date Time Picker', 'freeform-fields' );
	}

	public function defaults(): array {
		return array(
			'display_format' => 'd/m/Y g:i a',
			'return_format'  => 'd/m/Y g:i a',
			'first_day'      => 1,
		);
	}

	public function validate_value( $value, array $field ) {
		return $this->parse( $value ) ? true : __( 'Please enter a valid date and time.', 'freeform-fields' );
	}

	protected function accepted_formats(): array {
		return array( 'Y-m-d H:i:s', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i', 'Y-m-d H:i' );
	}
}
