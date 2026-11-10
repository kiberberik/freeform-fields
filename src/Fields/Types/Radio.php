<?php
/**
 * Radio button field.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Fields\Types;

defined( 'ABSPATH' ) || exit;

/**
 * Group of radio buttons; stores a single value.
 */
class Radio extends ChoiceField {

	public string $name = 'radio';

	public function label(): string {
		return __( 'Radio Button', 'freeform-fields' );
	}

	public function defaults(): array {
		return parent::defaults() + array(
			'layout' => 'vertical',
		);
	}

	public function settings(): array {
		return array_merge(
			parent::settings(),
			array(
				self::setting( 'default_value', __( 'Default Value', 'freeform-fields' ) ),
				self::layout_setting(),
			)
		);
	}

	protected function is_multiple( array $field ): bool {
		return false;
	}

	public function render_input( array $field ): void {
		$selected = self::selected( $field['value'] );
		$choices  = self::normalize_choices( $field['choices'] );

		// Without allow_null, preselect the first choice like a native radio group should.
		if ( array() === $selected && empty( $field['allow_null'] ) && array() !== $choices ) {
			$selected = array( (string) array_key_first( $choices ) );
		}

		printf( '<input type="hidden" name="%s" value="" />', esc_attr( $field['input_name'] ) );
		printf( '<ul class="wpff-choices wpff-choices--%s">', esc_attr( 'horizontal' === $field['layout'] ? 'horizontal' : 'vertical' ) );
		$i = 0;
		foreach ( $choices as $value => $label ) {
			printf(
				'<li><label><input type="radio" id="%s" name="%s" value="%s"%s /> %s</label></li>',
				esc_attr( $field['input_id'] . '-' . ( $i++ ) ),
				esc_attr( $field['input_name'] ),
				wpff_value_attr( $value ),
				checked( in_array( (string) $value, $selected, true ), true, false ),
				esc_html( $label )
			);
		}
		echo '</ul>';
	}
}
