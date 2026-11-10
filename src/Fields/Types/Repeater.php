<?php
/**
 * Repeater field.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Fields\Types;

use FreeformFields\Fields\AbstractField;

defined( 'ABSPATH' ) || exit;

/**
 * Rows of the same sub fields. Stored like ACF: `{name}` = row count, values in `{name}_{i}_{sub}`.
 *
 * In the form every row has an id (`row-0`, or a random id for rows added in the browser),
 * so inputs are named `wpff[field_rep][row-0][field_sub]` and paths are `field_rep.row-0.field_sub`.
 */
class Repeater extends AbstractField {

	public string $name = 'repeater';

	public string $category = 'layout';

	public function label(): string {
		return __( 'Repeater', 'freeform-fields' );
	}

	public function compound(): ?string {
		return 'rows';
	}

	public function defaults(): array {
		return array(
			'sub_fields'    => array(),
			'min'           => '',
			'max'           => '',
			// 'table', 'block' or 'row'.
			'layout'        => 'table',
			'button_label'  => '',
			// Show rows page by page in the editor (all rows are still saved together).
			'pagination'    => 0,
			'rows_per_page' => 20,
			// Sub field key whose value labels a collapsed row.
			'collapsed'     => '',
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
						'table' => __( 'Table', 'freeform-fields' ),
						'block' => __( 'Block', 'freeform-fields' ),
						'row'   => __( 'Row', 'freeform-fields' ),
					),
				)
			),
			self::setting( 'min', __( 'Minimum Rows', 'freeform-fields' ), 'number' ),
			self::setting( 'max', __( 'Maximum Rows', 'freeform-fields' ), 'number' ),
			self::setting( 'button_label', __( 'Button Label', 'freeform-fields' ), 'text', array( 'help' => __( 'Default: "Add Row".', 'freeform-fields' ) ) ),
			self::setting( 'collapsed', __( 'Collapsed', 'freeform-fields' ), 'sub_field', array( 'help' => __( 'Sub field shown when a row is collapsed.', 'freeform-fields' ) ) ),
			self::setting( 'pagination', __( 'Pagination', 'freeform-fields' ), 'toggle', array( 'help' => __( 'Show long lists of rows page by page in the editor.', 'freeform-fields' ) ) ),
			self::setting( 'rows_per_page', __( 'Rows Per Page', 'freeform-fields' ), 'number', array( 'show_if' => array( 'pagination' => 1 ) ) ),
		);
	}

	public function sanitize( $value, array $field ) {
		return is_array( $value ) ? $value : array();
	}

	public function is_empty( $value ): bool {
		return ! is_array( $value ) || array() === $value;
	}

	public function format_value( $value, $object_id, array $field ) {
		// Like ACF: no rows formats to false.
		return is_array( $value ) && array() !== $value ? array_values( $value ) : false;
	}

	/**
	 * Row limits check, used by the validator after rows are counted.
	 *
	 * @param int                  $count Number of rows.
	 * @param array<string, mixed> $field Field.
	 * @return true|string
	 */
	public function validate_count( int $count, array $field ) {
		$min = (int) $field['min'];
		$max = (int) $field['max'];
		if ( $min > 0 && $count < $min ) {
			/* translators: %d: minimum number of rows. */
			return sprintf( _n( 'At least %d row is required.', 'At least %d rows are required.', $min, 'freeform-fields' ), $min );
		}
		if ( $max > 0 && $count > $max ) {
			/* translators: %d: maximum number of rows. */
			return sprintf( _n( 'At most %d row is allowed.', 'At most %d rows are allowed.', $max, 'freeform-fields' ), $max );
		}
		return true;
	}

	public function render_input( array $field ): void {
		$rows   = is_array( $field['value'] ) ? array_values( $field['value'] ) : array();
		$min    = (int) $field['min'];
		$layout = in_array( $field['layout'], array( 'table', 'block', 'row' ), true ) ? $field['layout'] : 'table';
		// Show the minimum number of rows from the start.
		$rows = array_pad( $rows, $min, array() );

		printf(
			'<div class="wpff-repeater wpff-rows-field wpff-repeater--%s" data-min="%d" data-max="%d" data-collapsed="%s" data-per-page="%d">',
			esc_attr( $layout ),
			(int) $min,
			(int) $field['max'],
			esc_attr( (string) $field['collapsed'] ),
			(int) ( ! empty( $field['pagination'] ) ? max( 1, (int) $field['rows_per_page'] ) : 0 )
		);
		// Present even with no rows, so removing every row saves an empty repeater.
		printf( '<input type="hidden" name="%s[__present]" value="1" />', esc_attr( $field['input_name'] ) );

		if ( 'table' === $layout && array() !== $field['sub_fields'] ) {
			echo '<div class="wpff-rows__head" aria-hidden="true"><span class="wpff-row__handle"></span><div class="wpff-row__fields">';
			foreach ( $field['sub_fields'] as $sub ) {
				$width = (int) $sub['wrapper']['width'];
				printf(
					'<span class="wpff-rows__th" data-key="%s"%s>%s%s</span>',
					esc_attr( $sub['key'] ),
					$width > 0 && $width < 100 ? ' style="width:' . esc_attr( (string) $width ) . '%"' : '',
					esc_html( $sub['label'] ),
					$sub['required'] ? ' <span class="wpff-required">*</span>' : ''
				);
			}
			echo '</div><span class="wpff-row__actions"></span></div>';
		}

		echo '<div class="wpff-rows">';
		foreach ( $rows as $i => $row ) {
			$this->render_row( $field, 'row-' . $i, is_array( $row ) ? $row : array(), $layout );
		}
		echo '</div>';

		printf( '<template class="wpff-row-template" data-placeholder="%s">', esc_attr( self::placeholder( $field ) ) );
		$this->render_row( $field, self::placeholder( $field ), array(), $layout );
		echo '</template>';

		printf(
			'<div class="wpff-rows__footer"><button type="button" class="button wpff-rows__add">%s</button></div>',
			esc_html( '' !== $field['button_label'] ? $field['button_label'] : __( 'Add Row', 'freeform-fields' ) )
		);
		echo '</div>';
	}

	/**
	 * Row id placeholder used in templates. It names the field, so templates of nested
	 * repeaters keep their own placeholder when an outer row is created.
	 *
	 * @param array<string, mixed> $field Field.
	 */
	protected static function placeholder( array $field ): string {
		return '__ROWID_' . $field['key'] . '__';
	}

	/**
	 * Prints one row.
	 *
	 * @param array<string, mixed> $field  Repeater field (with input_name, path, render_sub_field).
	 * @param string               $row_id Row id used in input names.
	 * @param array<string, mixed> $values Row values by sub name.
	 * @param string               $layout table, block or row.
	 * @param string               $extra  Extra HTML inside the row (e.g. layout input).
	 * @param array|null           $subs   Sub fields (flexible layouts pass their own).
	 * @param string               $title  Row title (flexible layout label).
	 */
	protected function render_row( array $field, string $row_id, array $values, string $layout, string $extra = '', ?array $subs = null, string $title = '' ): void {
		$subs = $subs ?? $field['sub_fields'];
		printf( '<div class="wpff-row wpff-row--%s" data-id="%s">', esc_attr( $layout ), esc_attr( $row_id ) );
		printf(
			'<div class="wpff-row__handle" title="%s"><span class="wpff-row__grip" aria-hidden="true"></span><span class="wpff-row__index"></span></div>',
			esc_attr__( 'Drag to reorder', 'freeform-fields' )
		);
		echo '<div class="wpff-row__body">';
		if ( '' !== $title ) {
			echo '<div class="wpff-row__title">' . esc_html( $title ) . '<span class="wpff-row__summary"></span></div>';
		} else {
			echo '<div class="wpff-row__summary-line"><span class="wpff-row__summary"></span></div>';
		}
		echo $extra; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts by the caller.
		printf( '<div class="wpff-row__fields wpff-fields wpff-fields--label-%s">', 'row' === $layout ? 'left' : 'top' );
		$prefix = $field['input_name'] . '[' . $row_id . ']';
		$path   = $field['path'] . '.' . $row_id . '.';
		foreach ( $subs as $sub ) {
			$sub['value'] = array_key_exists( $sub['name'], $values ) ? $values[ $sub['name'] ] : $sub['default_value'];
			// Only the repeater's own table has a header row with labels; elsewhere labels stay visible.
			call_user_func( $field['render_sub_field'], $sub, $prefix, $path, 'table' === $layout && '' === $title ? 'cell' : 'div' );
		}
		echo '</div></div>';
		printf(
			'<div class="wpff-row__actions">'
			. '<button type="button" class="wpff-row__btn wpff-row__collapse" aria-label="%3$s" title="%3$s" aria-expanded="true"><span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span></button>'
			. '<button type="button" class="wpff-row__btn wpff-row__insert" aria-label="%1$s" title="%1$s"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span></button>'
			. '<button type="button" class="wpff-row__btn wpff-row__duplicate" aria-label="%2$s" title="%2$s"><span class="dashicons dashicons-admin-page" aria-hidden="true"></span></button>'
			. '<button type="button" class="wpff-row__btn wpff-row__remove" aria-label="%4$s" title="%4$s"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button>'
			. '</div>',
			esc_attr__( 'Add row above', 'freeform-fields' ),
			esc_attr__( 'Duplicate row', 'freeform-fields' ),
			esc_attr__( 'Collapse row', 'freeform-fields' ),
			esc_attr__( 'Remove row', 'freeform-fields' )
		);
		echo '</div>';
	}
}
