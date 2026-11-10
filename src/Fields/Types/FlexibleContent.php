<?php
/**
 * Flexible Content field.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Fields\Types;

use FreeformFields\Storage\ValueManager;

defined( 'ABSPATH' ) || exit;

/**
 * Rows built from different layouts, each with its own sub fields.
 *
 * Stored like ACF: `{name}` = list of layout names (one per row), values in `{name}_{i}_{sub}`.
 * Rows carry `acf_fc_layout` in both the form and the loaded value.
 */
class FlexibleContent extends Repeater {

	public string $name = 'flexible_content';

	public function label(): string {
		return __( 'Flexible Content', 'freeform-fields' );
	}

	public function compound(): ?string {
		return 'layouts';
	}

	public function defaults(): array {
		return array(
			'layouts'      => array(),
			'min'          => '',
			'max'          => '',
			'button_label' => '',
		);
	}

	public function settings(): array {
		return array(
			self::setting( 'min', __( 'Minimum Layouts', 'freeform-fields' ), 'number' ),
			self::setting( 'max', __( 'Maximum Layouts', 'freeform-fields' ), 'number' ),
			self::setting( 'button_label', __( 'Button Label', 'freeform-fields' ), 'text', array( 'help' => __( 'Default: "Add Row".', 'freeform-fields' ) ) ),
		);
	}

	/**
	 * Per-layout limits.
	 *
	 * @param array<string, int>   $counts Rows per layout name.
	 * @param array<string, mixed> $field  Field.
	 * @return true|string
	 */
	public function validate_layout_counts( array $counts, array $field ) {
		foreach ( $field['layouts'] as $layout ) {
			$count = $counts[ $layout['name'] ] ?? 0;
			$min   = (int) $layout['min'];
			$max   = (int) $layout['max'];
			if ( $min > 0 && $count < $min ) {
				/* translators: 1: minimum number, 2: layout label. */
				return sprintf( __( 'Add at least %1$d "%2$s" layout(s).', 'freeform-fields' ), $min, $layout['label'] );
			}
			if ( $max > 0 && $count > $max ) {
				/* translators: 1: maximum number, 2: layout label. */
				return sprintf( __( 'Use at most %1$d "%2$s" layout(s).', 'freeform-fields' ), $max, $layout['label'] );
			}
		}
		return true;
	}

	public function render_input( array $field ): void {
		$rows = is_array( $field['value'] ) ? array_values( $field['value'] ) : array();

		printf(
			'<div class="wpff-flexible wpff-rows-field" data-min="%d" data-max="%d">',
			(int) $field['min'],
			(int) $field['max']
		);
		printf( '<input type="hidden" name="%s[__present]" value="1" />', esc_attr( $field['input_name'] ) );

		echo '<div class="wpff-rows">';
		foreach ( $rows as $i => $row ) {
			$layout = ValueManager::find_layout( $field, (string) ( $row['acf_fc_layout'] ?? '' ) );
			if ( $layout ) {
				$this->render_layout_row( $field, $layout, 'row-' . $i, $row );
			}
		}
		echo '</div>';

		if ( array() === $rows ) {
			echo '<p class="wpff-rows__empty">' . esc_html__( 'Click the button below to start creating your layout.', 'freeform-fields' ) . '</p>';
		}

		foreach ( $field['layouts'] as $layout ) {
			printf(
				'<template class="wpff-row-template" data-layout="%s" data-placeholder="%s">',
				esc_attr( $layout['name'] ),
				esc_attr( self::placeholder( $field ) )
			);
			$this->render_layout_row( $field, $layout, self::placeholder( $field ), array() );
			echo '</template>';
		}

		echo '<div class="wpff-rows__footer wpff-flexible__footer">';
		printf(
			'<button type="button" class="button wpff-flexible__add" aria-haspopup="true" aria-expanded="false">%s</button>',
			esc_html( '' !== $field['button_label'] ? $field['button_label'] : __( 'Add Row', 'freeform-fields' ) )
		);
		echo '<ul class="wpff-flexible__menu" role="menu" hidden>';
		foreach ( $field['layouts'] as $layout ) {
			printf(
				'<li role="none"><button type="button" role="menuitem" data-layout="%s" data-max="%d">%s</button></li>',
				esc_attr( $layout['name'] ),
				(int) $layout['max'],
				esc_html( $layout['label'] )
			);
		}
		echo '</ul></div></div>';
	}

	/**
	 * One row of a layout.
	 *
	 * @param array<string, mixed> $field  Field.
	 * @param array<string, mixed> $layout Layout.
	 * @param string               $row_id Row id.
	 * @param array<string, mixed> $values Row values.
	 */
	private function render_layout_row( array $field, array $layout, string $row_id, array $values ): void {
		$extra   = sprintf(
			'<input type="hidden" name="%s" value="%s" class="wpff-row__layout" />',
			esc_attr( $field['input_name'] . '[' . $row_id . '][acf_fc_layout]' ),
			esc_attr( $layout['name'] )
		);
		$display = in_array( $layout['display'], array( 'table', 'block', 'row' ), true ) ? $layout['display'] : 'block';
		$this->render_row( $field, $row_id, $values, $display, $extra, $layout['sub_fields'], $layout['label'] );
	}
}
