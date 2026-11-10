<?php
/**
 * Date picker field.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Fields\Types;

use FreeformFields\Fields\AbstractField;

defined( 'ABSPATH' ) || exit;

/**
 * Native date input. Stored as `Ymd` like ACF, returned in `return_format`.
 *
 * Subclasses change the storage and input formats for date-time and time.
 */
class DatePicker extends AbstractField {

	public string $name = 'date_picker';

	public string $category = 'advanced';

	/**
	 * PHP format of the stored value.
	 *
	 * @var string
	 */
	protected string $store_format = 'Ymd';

	/**
	 * HTML input type and the PHP format of its value.
	 *
	 * @var string
	 */
	protected string $input_type   = 'date';
	protected string $input_format = 'Y-m-d';

	public function label(): string {
		return __( 'Date Picker', 'freeform-fields' );
	}

	public function defaults(): array {
		return array(
			'display_format' => 'd/m/Y',
			'return_format'  => 'd/m/Y',
			'first_day'      => 1,
		);
	}

	public function settings(): array {
		return array(
			self::setting(
				'return_format',
				__( 'Return Format', 'freeform-fields' ),
				'text',
				array( 'help' => __( 'PHP date format of the value returned by get_field(), e.g. d/m/Y or Ymd.', 'freeform-fields' ) )
			),
		);
	}

	public function render_input( array $field ): void {
		$date = $this->parse( $field['value'] );
		printf(
			'<input type="%s" id="%s" name="%s" value="%s" class="wpff-control wpff-date"%s />',
			esc_attr( $this->input_type ),
			esc_attr( $field['input_id'] ),
			esc_attr( $field['input_name'] ),
			esc_attr( $date ? $date->format( $this->input_format ) : '' ),
			'time' === $this->input_type || 'datetime-local' === $this->input_type ? ' step="1"' : ''
		);
	}

	public function sanitize( $value, array $field ) {
		if ( ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
			return '';
		}
		$date = $this->parse( (string) $value );
		// Keep unparsable input so validation can reject it with a message.
		return $date ? $date->format( $this->store_format ) : (string) $value;
	}

	public function validate_value( $value, array $field ) {
		return $this->parse( $value ) ? true : __( 'Please enter a valid date.', 'freeform-fields' );
	}

	public function format_value( $value, $object_id, array $field ) {
		$date = $this->parse( $value );
		if ( ! $date ) {
			return $value;
		}
		$format = (string) ( $field['return_format'] ?: $this->store_format );
		// Stored values are wall-clock times; format them without a time zone shift.
		return wp_date( $format, $date->getTimestamp(), new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * Parses a stored or submitted value.
	 *
	 * @param mixed $value Value.
	 */
	protected function parse( $value ): ?\DateTimeImmutable {
		if ( ! is_scalar( $value ) || '' === (string) $value ) {
			return null;
		}
		$value = (string) $value;
		$utc   = new \DateTimeZone( 'UTC' );
		foreach ( $this->accepted_formats() as $format ) {
			$date = \DateTimeImmutable::createFromFormat( '!' . $format, $value, $utc );
			if ( $date && $date->format( $format ) === $value ) {
				return $date;
			}
		}
		return null;
	}

	/**
	 * Formats accepted from storage and from the input.
	 *
	 * @return string[]
	 */
	protected function accepted_formats(): array {
		return array( 'Ymd', 'Y-m-d' );
	}
}
