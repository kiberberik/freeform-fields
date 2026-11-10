<?php
/**
 * Range (slider) field.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Fields\Types;

defined( 'ABSPATH' ) || exit;

/**
 * Slider with a number box. Validation is the same as Number.
 */
class Range extends Number {

	public string $name = 'range';

	public function label(): string {
		return __( 'Range', 'freeform-fields' );
	}

	public function defaults(): array {
		return array(
			'prepend' => '',
			'append'  => '',
			'min'     => '0',
			'max'     => '100',
			'step'    => '1',
		);
	}

	public function settings(): array {
		return array_values(
			array_filter( parent::settings(), static fn( $s ) => 'placeholder' !== $s['name'] )
		);
	}

	public function render_input( array $field ): void {
		$min   = '' !== $field['min'] ? $field['min'] : '0';
		$max   = '' !== $field['max'] ? $field['max'] : '100';
		$value = is_scalar( $field['value'] ) && '' !== (string) $field['value'] ? (string) $field['value'] : $min;
		$input = sprintf(
			'<div class="wpff-range"><input type="range" id="%1$s" min="%3$s" max="%4$s" step="%5$s" value="%6$s" aria-hidden="true" tabindex="-1" class="wpff-range__slider" />'
			. '<input type="number" name="%2$s" min="%3$s" max="%4$s" step="%5$s" value="%6$s" class="wpff-range__number" aria-label="%7$s" /></div>',
			esc_attr( $field['input_id'] ),
			esc_attr( $field['input_name'] ),
			esc_attr( $min ),
			esc_attr( $max ),
			esc_attr( $field['step'] ?: '1' ),
			esc_attr( $value ),
			esc_attr( $field['label'] )
		);
		self::print_affixed( $field, $input );
	}
}
