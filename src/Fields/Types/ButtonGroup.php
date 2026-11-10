<?php
/**
 * Button group field.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Fields\Types;

defined( 'ABSPATH' ) || exit;

/**
 * Radio buttons styled as a segmented control.
 */
class ButtonGroup extends Radio {

	public string $name = 'button_group';

	public function label(): string {
		return __( 'Button Group', 'freeform-fields' );
	}

	public function defaults(): array {
		return array_merge( parent::defaults(), array( 'layout' => 'horizontal' ) );
	}

	public function render_input( array $field ): void {
		$selected = self::selected( $field['value'] );
		$choices  = self::normalize_choices( $field['choices'] );
		if ( array() === $selected && empty( $field['allow_null'] ) && array() !== $choices ) {
			$selected = array( (string) array_key_first( $choices ) );
		}

		printf( '<input type="hidden" name="%s" value="" />', esc_attr( $field['input_name'] ) );
		printf(
			'<div class="wpff-button-group wpff-button-group--%s" role="radiogroup" aria-labelledby="%s">',
			esc_attr( 'vertical' === $field['layout'] ? 'vertical' : 'horizontal' ),
			esc_attr( $field['input_id'] . '-label' )
		);
		$i = 0;
		foreach ( $choices as $value => $label ) {
			printf(
				'<label><input type="radio" id="%s" name="%s" value="%s"%s /><span>%s</span></label>',
				esc_attr( $field['input_id'] . '-' . ( $i++ ) ),
				esc_attr( $field['input_name'] ),
				wpff_value_attr( $value ),
				checked( in_array( (string) $value, $selected, true ), true, false ),
				esc_html( $label )
			);
		}
		echo '</div>';
	}
}
