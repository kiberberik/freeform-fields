<?php
/**
 * Renders field inputs inside admin forms.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Admin;

use FreeformFields\Hooks;

use FreeformFields\Fields\FieldTypeRegistry;
use FreeformFields\Storage\ObjectRef;
use FreeformFields\Storage\ValueManager;

defined( 'ABSPATH' ) || exit;

/**
 * Prints the label/instructions/input wrapper for each field of a group.
 *
 * Layouts:
 *  - `div`   meta boxes (post edit screen);
 *  - `table` rows of a `.form-table` (term edit, user profile);
 *  - `form`  `.form-field` blocks (add term form).
 */
final class FieldRenderer {

	/**
	 * Name of the request array holding submitted values.
	 */
	public const INPUT_NAME = 'wpff';

	/**
	 * Field types whose input area holds several controls.
	 */
	private const GROUP_TYPES = array( 'checkbox', 'radio', 'button_group', 'true_false', 'link', 'range', 'image', 'file', 'gallery', 'taxonomy', 'group', 'clone', 'repeater', 'flexible_content' );

	public function __construct(
		private FieldTypeRegistry $types,
		private ValueManager $values
	) {}

	/**
	 * Prints all fields of a group with their current values.
	 *
	 * @param array<string, mixed> $group  Normalized group.
	 * @param ObjectRef|null       $ref    Object being edited; null renders default values.
	 * @param string               $layout `div`, `table` or `form`.
	 */
	public function render_group( array $group, ?ObjectRef $ref, string $layout = 'div' ): void {
		if ( 'div' === $layout ) {
			printf(
				'<div class="wpff-fields wpff-fields--label-%s" data-group="%s">',
				esc_attr( 'left' === $group['label_placement'] ? 'left' : 'top' ),
				esc_attr( $group['key'] )
			);
		}
		foreach ( $group['fields'] as $field ) {
			$field['value'] = $ref ? $this->values->load( $field, $ref ) : $field['default_value'];
			$this->render_field( $field, $group['instruction_placement'], self::INPUT_NAME, $layout );
		}
		if ( 'div' === $layout ) {
			echo '</div>';
		}
	}

	/**
	 * Prints one field.
	 *
	 * @param array<string, mixed> $field                 Field with `value`.
	 * @param string               $instruction_placement `label` or `field`.
	 * @param string               $name_prefix           Input name of the parent, e.g. `wpff` or `wpff[field_group]`.
	 * @param string               $layout                `div`, `table`, `form` or `cell` (repeater table row; label hidden).
	 * @param string               $path_prefix           Path of the parent with a trailing dot, e.g. `field_rep.row-0.`.
	 */
	public function render_field( array $field, string $instruction_placement = 'label', string $name_prefix = self::INPUT_NAME, string $layout = 'div', string $path_prefix = '' ): void {
		$handler = $this->types->get( $field['type'] );
		if ( ! $handler ) {
			return;
		}

		/**
		 * Filters a field right before it is rendered. Return false to hide it.
		 *
		 * Variants: wpff/prepare_field/type={type}, /name={name}, /key={key}.
		 *
		 * @param array|false $field Field.
		 */
		$field = Hooks::field_filter( 'prepare_field', Hooks::load_field( $field ), $field );
		if ( ! is_array( $field ) ) {
			return;
		}

		// The path identifies the field inside rows and groups; errors are reported by path.
		$field['path']             = $path_prefix . $field['key'];
		$field['input_name']       = $name_prefix . '[' . $field['key'] . ']';
		$field['input_id']         = 'wpff-' . str_replace( '.', '-', $field['path'] );
		$field['render_sub_field'] = function ( array $sub, string $prefix, string $sub_path_prefix, string $sub_layout = 'div' ) use ( $instruction_placement ): void {
			$this->render_field( $sub, $instruction_placement, $prefix, $sub_layout, $sub_path_prefix );
		};

		$classes = array( 'wpff-field', 'wpff-field--' . $field['type'] );
		if ( 'table' === $layout ) {
			$classes[] = 'form-field';
		} elseif ( 'form' === $layout ) {
			$classes[] = 'form-field';
		}
		if ( '' !== $field['wrapper']['class'] ) {
			$classes[] = $field['wrapper']['class'];
		}
		$width = (int) $field['wrapper']['width'];

		$attrs = sprintf(
			' class="%s" data-key="%s" data-path="%s" data-name="%s" data-type="%s"',
			esc_attr( implode( ' ', $classes ) ),
			esc_attr( $field['key'] ),
			esc_attr( $field['path'] ),
			esc_attr( $field['name'] ),
			esc_attr( $field['type'] )
		);
		if ( $field['required'] ) {
			$attrs .= ' data-required="1"';
		}
		if ( ! empty( $field['conditional_logic'] ) && is_array( $field['conditional_logic'] ) ) {
			$attrs .= ' data-conditions="' . esc_attr( (string) wp_json_encode( $field['conditional_logic'] ) ) . '"';
		}
		if ( '' !== $field['wrapper']['id'] ) {
			$attrs .= ' id="' . esc_attr( $field['wrapper']['id'] ) . '"';
		}
		if ( in_array( $layout, array( 'div', 'cell' ), true ) && $width > 0 && $width < 100 ) {
			$attrs .= ' style="width:' . esc_attr( (string) $width ) . '%"';
		}

		$instructions = '' !== $field['instructions']
			? '<p class="description wpff-instructions">' . wp_kses_post( $field['instructions'] ) . '</p>'
			: '';
		$label        = sprintf(
			'<label id="%s" for="%s">%s%s</label>',
			esc_attr( $field['input_id'] . '-label' ),
			esc_attr( $field['input_id'] ),
			esc_html( $field['label'] ),
			$field['required'] ? ' <span class="wpff-required" aria-hidden="true">*</span>' : ''
		);

		$tag = 'table' === $layout ? 'tr' : 'div';
		echo '<' . $tag . $attrs . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $tag is fixed, $attrs escaped above.

		if ( 'cell' === $layout ) {
			$classes_label = 'wpff-label screen-reader-text';
		} else {
			$classes_label = 'wpff-label';
		}
		echo 'table' === $layout ? '<th scope="row" class="wpff-label">' : '<div class="' . esc_attr( $classes_label ) . '">';
		if ( $handler->has_value() || 'message' === $field['type'] ) {
			echo $label; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		}
		if ( 'label' === $instruction_placement ) {
			echo $instructions; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		}
		// Fields made of several controls are announced as a group named by the label.
		$group_attrs = in_array( $field['type'], self::GROUP_TYPES, true )
			? ' role="group" aria-labelledby="' . esc_attr( $field['input_id'] . '-label' ) . '"'
			: '';
		echo 'table' === $layout ? '</th><td class="wpff-input"' . $group_attrs . '>' : '</div><div class="wpff-input"' . $group_attrs . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.

		$handler->render_input( $field );
		if ( 'field' === $instruction_placement ) {
			echo $instructions; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		}
		echo '<p class="wpff-error" role="alert" hidden></p>';

		echo 'table' === $layout ? '</td></tr>' : '</div></div>';
	}
}
