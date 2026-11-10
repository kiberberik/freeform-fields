<?php
/**
 * Taxonomy field.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Fields\Types;

use FreeformFields\Storage\ObjectRef;

defined( 'ABSPATH' ) || exit;

/**
 * Picks terms of one taxonomy as checkboxes, radio buttons or a (multi) select.
 *
 * With `save_terms` the selection is also assigned to the post; with `load_terms`
 * the value is read from the post's terms instead of the field.
 */
class Taxonomy extends RelationalField {

	public string $name = 'taxonomy';

	public function label(): string {
		return __( 'Taxonomy', 'freeform-fields' );
	}

	public function defaults(): array {
		return array(
			'taxonomy'             => 'category',
			// 'checkbox', 'multi_select', 'radio' or 'select'.
			'field_type'           => 'checkbox',
			'allow_null'           => 0,
			'save_terms'           => 0,
			'load_terms'           => 0,
			// 'id' or 'object'.
			'return_format'        => 'id',
			'bidirectional'        => 0,
			'bidirectional_target' => array(),
		);
	}

	public function settings(): array {
		return array_merge( $this->own_settings(), \FreeformFields\Fields\Bidirectional::settings() );
	}

	/**
	 * Settings of this type without the shared bidirectional ones.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function own_settings(): array {
		$taxonomies = array();
		foreach ( get_taxonomies( array( 'show_ui' => true ), 'objects' ) as $tax ) {
			$taxonomies[ $tax->name ] = $tax->labels->singular_name;
		}
		return array(
			self::setting( 'taxonomy', __( 'Taxonomy', 'freeform-fields' ), 'select', array( 'options' => $taxonomies ) ),
			self::setting(
				'field_type',
				__( 'Appearance', 'freeform-fields' ),
				'select',
				array(
					'options' => array(
						'checkbox'     => __( 'Checkbox', 'freeform-fields' ),
						'multi_select' => __( 'Multi Select', 'freeform-fields' ),
						'radio'        => __( 'Radio Buttons', 'freeform-fields' ),
						'select'       => __( 'Select', 'freeform-fields' ),
					),
				)
			),
			self::setting( 'allow_null', __( 'Allow Null', 'freeform-fields' ), 'toggle' ),
			self::setting( 'save_terms', __( 'Save Terms', 'freeform-fields' ), 'toggle', array( 'help' => __( 'Connect selected terms to the post.', 'freeform-fields' ) ) ),
			self::setting( 'load_terms', __( 'Load Terms', 'freeform-fields' ), 'toggle', array( 'help' => __( 'Load value from the post terms.', 'freeform-fields' ) ) ),
			self::setting(
				'return_format',
				__( 'Return Value', 'freeform-fields' ),
				'select',
				array(
					'options' => array(
						'id'     => __( 'Term ID', 'freeform-fields' ),
						'object' => __( 'Term Object', 'freeform-fields' ),
					),
				)
			),
		);
	}

	protected function is_multiple( array $field ): bool {
		return in_array( $field['field_type'], array( 'checkbox', 'multi_select' ), true );
	}

	public function render_input( array $field ): void {
		if ( in_array( $field['field_type'], array( 'select', 'multi_select' ), true ) ) {
			parent::render_input( $field );
			return;
		}
		$multiple = $this->is_multiple( $field );
		$selected = self::ids( $field['value'] );
		$terms    = get_terms(
			array(
				'taxonomy'   => $field['taxonomy'],
				'hide_empty' => false,
				'number'     => 500,
			)
		);
		if ( ! is_array( $terms ) || array() === $terms ) {
			echo '<p class="description">' . esc_html__( 'No terms yet.', 'freeform-fields' ) . '</p>';
			return;
		}
		printf( '<input type="hidden" name="%s" value="" />', esc_attr( $field['input_name'] ) );
		echo '<ul class="wpff-choices wpff-terms">';
		$this->render_term_level( $terms, 0, $field, $selected, $multiple );
		echo '</ul>';
	}

	/**
	 * Prints terms of one parent, then their children nested.
	 *
	 * @param \WP_Term[]           $terms    All terms.
	 * @param int                  $parent   Parent term ID.
	 * @param array<string, mixed> $field    Field.
	 * @param int[]                $selected Selected IDs.
	 * @param bool                 $multiple Checkboxes (true) or radios.
	 */
	private function render_term_level( array $terms, int $parent, array $field, array $selected, bool $multiple ): void {
		foreach ( $terms as $term ) {
			if ( (int) $term->parent !== $parent ) {
				continue;
			}
			printf(
				'<li><label><input type="%s" name="%s" value="%d"%s /> %s</label>',
				$multiple ? 'checkbox' : 'radio',
				esc_attr( $field['input_name'] . ( $multiple ? '[]' : '' ) ),
				(int) $term->term_id,
				checked( in_array( (int) $term->term_id, $selected, true ), true, false ),
				esc_html( $term->name )
			);
			$has_children = array_filter( $terms, static fn( $t ) => (int) $t->parent === (int) $term->term_id );
			if ( $has_children ) {
				echo '<ul class="children">';
				$this->render_term_level( $terms, (int) $term->term_id, $field, $selected, $multiple );
				echo '</ul>';
			}
			echo '</li>';
		}
	}

	public function query( array $field, string $search, int $page ): array {
		$args  = array(
			'taxonomy'   => $field['taxonomy'],
			'hide_empty' => false,
			'number'     => self::PER_PAGE + 1,
			'offset'     => ( max( 1, $page ) - 1 ) * self::PER_PAGE,
			'search'     => $search,
			'orderby'    => 'name',
		);
		$terms = get_terms( self::query_filter( $args, $field ) );
		$terms = is_array( $terms ) ? $terms : array();
		$more  = count( $terms ) > self::PER_PAGE;
		$out   = array();
		foreach ( array_slice( $terms, 0, self::PER_PAGE ) as $term ) {
			$out[] = array(
				'id'   => (int) $term->term_id,
				'text' => $term->name,
			);
		}
		return array(
			'results' => $out,
			'more'    => $more,
		);
	}

	protected function item_text( int $id, array $field ): ?string {
		$term = get_term( $id, $field['taxonomy'] );
		return $term instanceof \WP_Term ? $term->name : null;
	}

	public function load_value( $value, $object_id, array $field ) {
		$ref = ObjectRef::parse( $object_id );
		if ( ! empty( $field['load_terms'] ) && $ref && 'post' === $ref->type ) {
			$terms = wp_get_object_terms( (int) $ref->id, $field['taxonomy'], array( 'fields' => 'ids' ) );
			return is_array( $terms ) ? array_map( 'intval', $terms ) : array();
		}
		return $value;
	}

	public function update_value( $value, $object_id, array $field ) {
		$ref = ObjectRef::parse( $object_id );
		if ( ! empty( $field['save_terms'] ) && $ref && 'post' === $ref->type && current_user_can( get_taxonomy( $field['taxonomy'] )->cap->assign_terms ?? 'edit_posts' ) ) {
			wp_set_object_terms( (int) $ref->id, self::ids( $value ), $field['taxonomy'] );
		}
		return $value;
	}

	public function format_value( $value, $object_id, array $field ) {
		$out = array();
		$ids = self::ids( $value );
		if ( count( $ids ) > 1 ) {
			_prime_term_caches( $ids, false );
		}
		foreach ( $ids as $id ) {
			$term = get_term( $id, $field['taxonomy'] );
			if ( $term instanceof \WP_Term ) {
				$out[] = 'object' === $field['return_format'] ? $term : $term->term_id;
			}
		}
		if ( $this->is_multiple( $field ) ) {
			return $out;
		}
		return $out[0] ?? ( null === $value ? null : false );
	}
}
