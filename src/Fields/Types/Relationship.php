<?php
/**
 * Relationship field.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Fields\Types;

defined( 'ABSPATH' ) || exit;

/**
 * An ordered list of posts with min/max limits. Always multiple.
 */
class Relationship extends PostObject {

	public string $name = 'relationship';

	public function label(): string {
		return __( 'Relationship', 'freeform-fields' );
	}

	public function defaults(): array {
		return array(
			'post_type'            => array(),
			'min'                  => '',
			'max'                  => '',
			'return_format'        => 'object',
			'bidirectional'        => 0,
			'bidirectional_target' => array(),
		);
	}

	public function settings(): array {
		return array(
			self::setting(
				'post_type',
				__( 'Filter by Post Type', 'freeform-fields' ),
				'multiselect',
				array(
					'options' => self::post_type_options(),
					'help'    => __( 'None selected: all post types.', 'freeform-fields' ),
				)
			),
			self::setting( 'min', __( 'Minimum Posts', 'freeform-fields' ), 'number' ),
			self::setting( 'max', __( 'Maximum Posts', 'freeform-fields' ), 'number' ),
			$this->return_setting(),
			...\FreeformFields\Fields\Bidirectional::settings(),
		);
	}

	protected function is_multiple( array $field ): bool {
		return true;
	}

	public function validate_value( $value, array $field ) {
		$valid = parent::validate_value( $value, $field );
		if ( true !== $valid ) {
			return $valid;
		}
		$count = count( self::ids( $value ) );
		$min   = (int) $field['min'];
		$max   = (int) $field['max'];
		if ( $min > 0 && $count < $min ) {
			/* translators: %d: minimum number of posts. */
			return sprintf( _n( 'Select at least %d post.', 'Select at least %d posts.', $min, 'freeform-fields' ), $min );
		}
		if ( $max > 0 && $count > $max ) {
			/* translators: %d: maximum number of posts. */
			return sprintf( _n( 'Select at most %d post.', 'Select at most %d posts.', $max, 'freeform-fields' ), $max );
		}
		return true;
	}

	public function is_empty( $value ): bool {
		// With a minimum, an empty list is reported by validate_value() as well.
		return parent::is_empty( $value );
	}
}
