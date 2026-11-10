<?php
/**
 * Checkbox list field.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Fields\Types;

defined( 'ABSPATH' ) || exit;

/**
 * List of checkboxes; always stores an array.
 */
class Checkbox extends ChoiceField {

	public string $name = 'checkbox';

	public function label(): string {
		return __( 'Checkbox', 'freeform-fields' );
	}

	public function defaults(): array {
		return parent::defaults() + array(
			// 'vertical' or 'horizontal'.
			'layout' => 'vertical',
		);
	}

	public function settings(): array {
		$settings = array_values(
			array_filter( parent::settings(), static fn( $s ) => 'allow_null' !== $s['name'] )
		);
		return array_merge(
			$settings,
			array(
				self::setting( 'default_value', __( 'Default Value', 'freeform-fields' ), 'lines', array( 'help' => __( 'Enter each default value on a new line.', 'freeform-fields' ) ) ),
				self::layout_setting(),
			)
		);
	}

	protected function is_multiple( array $field ): bool {
		return true;
	}

	public function render_input( array $field ): void {
		$selected = self::selected( $field['value'] );

		// Submitted even when nothing is checked, so unchecking everything saves.
		printf( '<input type="hidden" name="%s" value="" />', esc_attr( $field['input_name'] ) );

		printf( '<ul class="wpff-choices wpff-choices--%s">', esc_attr( 'horizontal' === $field['layout'] ? 'horizontal' : 'vertical' ) );
		$i = 0;
		foreach ( self::normalize_choices( $field['choices'] ) as $value => $label ) {
			printf(
				'<li><label><input type="checkbox" id="%s" name="%s[]" value="%s"%s /> %s</label></li>',
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
