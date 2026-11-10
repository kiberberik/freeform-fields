<?php
/**
 * Page Link field.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Fields\Types;

defined( 'ABSPATH' ) || exit;

/**
 * Picks posts like Post Object but returns their permalinks.
 */
class PageLink extends PostObject {

	public string $name = 'page_link';

	public function label(): string {
		return __( 'Page Link', 'freeform-fields' );
	}

	public function defaults(): array {
		return array(
			'post_type'  => array(),
			'allow_null' => 0,
			'multiple'   => 0,
		);
	}

	public function settings(): array {
		return array_merge(
			array(
				self::setting(
					'post_type',
					__( 'Filter by Post Type', 'freeform-fields' ),
					'multiselect',
					array(
						'options' => self::post_type_options(),
						'help'    => __( 'None selected: all post types.', 'freeform-fields' ),
					)
				),
			),
			self::multiple_settings()
		);
	}

	protected function format_post( \WP_Post $post, array $field ) {
		return get_permalink( $post );
	}
}
