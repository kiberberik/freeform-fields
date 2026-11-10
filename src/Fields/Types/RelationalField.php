<?php
/**
 * Shared logic for fields that pick posts, terms or users.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Fields\Types;

use FreeformFields\Fields\AbstractField;

defined( 'ABSPATH' ) || exit;

/**
 * Stores IDs (one, or a list when `multiple`) and renders a searchable picker whose
 * options come from `GET wpff/v1/fields/{key}/choices`.
 */
abstract class RelationalField extends AbstractField {

	public string $category = 'relational';

	/**
	 * Results per page in the picker.
	 */
	public const PER_PAGE = 20;

	/**
	 * Picker options for a search.
	 *
	 * @param array<string, mixed> $field  Field.
	 * @param string               $search Search text.
	 * @param int                  $page   1-based page.
	 * @return array{results: array<int, array{id: int, text: string}>, more: bool}
	 */
	abstract public function query( array $field, string $search, int $page ): array;

	/**
	 * Display text for a selected ID, or null when it no longer exists / is not allowed.
	 *
	 * @param int                  $id    ID.
	 * @param array<string, mixed> $field Field.
	 */
	abstract protected function item_text( int $id, array $field ): ?string;

	/**
	 * Capability needed to search options for this field.
	 */
	public function search_capability(): string {
		return 'edit_posts';
	}

	/**
	 * Whether the value is a list.
	 *
	 * @param array<string, mixed> $field Field.
	 */
	protected function is_multiple( array $field ): bool {
		return ! empty( $field['multiple'] );
	}

	/**
	 * Selected IDs.
	 *
	 * @param mixed $value Stored value.
	 * @return int[]
	 */
	protected static function ids( $value ): array {
		if ( null === $value || '' === $value || false === $value ) {
			return array();
		}
		$ids = array();
		foreach ( (array) $value as $item ) {
			$id = is_object( $item ) ? (int) ( $item->ID ?? $item->term_id ?? 0 ) : absint( is_scalar( $item ) ? $item : 0 );
			if ( $id ) {
				$ids[] = $id;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	public function render_input( array $field ): void {
		$multiple = $this->is_multiple( $field );
		$name     = $field['input_name'] . ( $multiple ? '[]' : '' );

		printf(
			'<div class="wpff-picker" data-field-key="%s" data-multiple="%d" data-max="%d" data-input-name="%s" data-sortable="%d">',
			esc_attr( $field['key'] ),
			$multiple ? 1 : 0,
			(int) ( $field['max'] ?? 0 ),
			esc_attr( $name ),
			$multiple ? 1 : 0
		);
		// Always submitted, so clearing the selection saves an empty value.
		printf( '<input type="hidden" name="%s" value="" />', esc_attr( $field['input_name'] ) );

		echo '<ul class="wpff-picker__selected">';
		foreach ( self::ids( $field['value'] ) as $id ) {
			$text = $this->item_text( $id, $field );
			if ( null === $text ) {
				continue;
			}
			printf(
				'<li class="wpff-picker__item" data-id="%1$d"><span class="wpff-picker__text">%2$s</span><input type="hidden" name="%3$s" value="%1$d" /><button type="button" class="wpff-picker__remove" aria-label="%4$s">&times;</button></li>',
				(int) $id,
				esc_html( $text ),
				esc_attr( $name ),
				/* translators: %s: item title. */
				esc_attr( sprintf( __( 'Remove %s', 'freeform-fields' ), $text ) )
			);
		}
		echo '</ul>';

		printf(
			'<div class="wpff-picker__search"><input type="search" id="%s" class="wpff-control wpff-picker__input" placeholder="%s" autocomplete="off" role="combobox" aria-expanded="false" aria-autocomplete="list" /><ul class="wpff-picker__results" role="listbox" hidden></ul></div>',
			esc_attr( $field['input_id'] ),
			esc_attr__( 'Search…', 'freeform-fields' )
		);
		echo '</div>';
	}

	public function sanitize( $value, array $field ) {
		$ids = self::ids( is_array( $value ) ? array_filter( $value, static fn( $v ) => '' !== $v ) : $value );
		if ( $this->is_multiple( $field ) ) {
			return $ids;
		}
		return $ids[0] ?? '';
	}

	public function is_empty( $value ): bool {
		return array() === self::ids( $value );
	}

	public function validate_value( $value, array $field ) {
		foreach ( self::ids( $value ) as $id ) {
			if ( null === $this->item_text( $id, $field ) ) {
				return __( 'One of the selected items is not available.', 'freeform-fields' );
			}
		}
		return true;
	}

	/**
	 * Settings shared by single/multiple pickers.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	protected static function multiple_settings(): array {
		return array(
			self::setting( 'allow_null', __( 'Allow Null', 'freeform-fields' ), 'toggle' ),
			self::setting( 'multiple', __( 'Select Multiple', 'freeform-fields' ), 'toggle' ),
		);
	}

	/**
	 * Post type options for settings.
	 *
	 * @return array<string, string>
	 */
	protected static function post_type_options(): array {
		$options = array();
		foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $type ) {
			if ( in_array( $type->name, array( 'wp_block', 'wp_navigation', 'wpff-field-group' ), true ) ) {
				continue;
			}
			$options[ $type->name ] = $type->labels->singular_name;
		}
		return $options;
	}
}
