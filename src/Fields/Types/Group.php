<?php
/**
 * Group field: a set of sub fields saved together.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Fields\Types;

use FreeformFields\Fields\AbstractField;

defined( 'ABSPATH' ) || exit;

/**
 * Stores each sub field as `{group}_{sub}` (plus `_{group}_{sub}` key reference) and the group
 * itself as an empty value with its own reference, exactly like ACF. Loading returns
 * an array of sub values keyed by sub field name.
 *
 * Sub value storage is handled by ValueManager; this class renders and describes the field.
 */
class Group extends AbstractField {

	public string $name = 'group';

	public string $category = 'layout';

	public function label(): string {
		return __( 'Group', 'freeform-fields' );
	}

	public function compound(): ?string {
		return 'group';
	}

	public function defaults(): array {
		return array(
			'sub_fields' => array(),
			// 'block', 'table' or 'row' (table and row behave like block for now).
			'layout'     => 'block',
		);
	}

	public function settings(): array {
		return array(
			self::setting(
				'layout',
				__( 'Layout', 'freeform-fields' ),
				'select',
				array(
					'options' => array(
						'block' => __( 'Block', 'freeform-fields' ),
						'row'   => __( 'Row', 'freeform-fields' ),
					),
				)
			),
		);
	}

	public function render_input( array $field ): void {
		printf( '<div class="wpff-group wpff-group--%s">', esc_attr( 'row' === $field['layout'] ? 'row' : 'block' ) );
		$values = is_array( $field['value'] ) ? $field['value'] : array();
		foreach ( $field['sub_fields'] as $sub ) {
			$sub['value'] = array_key_exists( $sub['name'], $values ) ? $values[ $sub['name'] ] : $sub['default_value'];
			call_user_func( $field['render_sub_field'], $sub, $field['input_name'], $field['path'] . '.' );
		}
		echo '</div>';
	}

	public function sanitize( $value, array $field ) {
		// Sub values are sanitized one by one by the validator.
		return is_array( $value ) ? $value : array();
	}

	public function is_empty( $value ): bool {
		return ! is_array( $value ) || array() === array_filter( $value, static fn( $v ) => '' !== $v && null !== $v && array() !== $v );
	}
}
