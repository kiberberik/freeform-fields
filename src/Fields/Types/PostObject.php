<?php
/**
 * Post Object field.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Fields\Types;

defined( 'ABSPATH' ) || exit;

/**
 * Picks one or more posts. Returns WP_Post objects or IDs.
 */
class PostObject extends RelationalField {

	public string $name = 'post_object';

	public function label(): string {
		return __( 'Post Object', 'freeform-fields' );
	}

	public function defaults(): array {
		return array(
			// Empty means all post types.
			'post_type'            => array(),
			'allow_null'           => 0,
			'multiple'             => 0,
			// 'object' or 'id'.
			'return_format'        => 'object',
			'bidirectional'        => 0,
			'bidirectional_target' => array(),
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
			self::multiple_settings(),
			array( $this->return_setting() ),
			\FreeformFields\Fields\Bidirectional::settings()
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function return_setting(): array {
		return self::setting(
			'return_format',
			__( 'Return Format', 'freeform-fields' ),
			'select',
			array(
				'options' => array(
					'object' => __( 'Post Object', 'freeform-fields' ),
					'id'     => __( 'Post ID', 'freeform-fields' ),
				),
			)
		);
	}

	/**
	 * Post types this field may reference.
	 *
	 * @param array<string, mixed> $field Field.
	 * @return string[]
	 */
	protected function post_types( array $field ): array {
		$types = array_values( array_filter( (array) ( $field['post_type'] ?? array() ) ) );
		return array() !== $types ? $types : array_keys( self::post_type_options() );
	}

	public function query( array $field, string $search, int $page ): array {
		$args = array(
			'post_type'      => $this->post_types( $field ),
			// Unpublished posts only for users who may edit others' posts; `readable` limits private ones.
			'post_status'    => current_user_can( 'edit_others_posts' ) ? array( 'publish', 'private', 'draft', 'pending', 'future' ) : array( 'publish', 'private' ),
			'perm'           => 'readable',
			's'              => $search,
			'posts_per_page' => self::PER_PAGE,
			'paged'          => max( 1, $page ),
			'orderby'        => '' === $search ? 'title' : 'relevance',
			'order'          => 'ASC',
			'no_found_rows'  => false,
		);
		/**
		 * Filters the search query of post fields: `wpff/fields/{type}/query` (also ACF's `acf/fields/{type}/query`),
		 * with /name= and /key= variants. Args: ( $args, $field, $post_id ).
		 */
		$query   = new \WP_Query( self::query_filter( $args, $field ) );
		$results = array();
		foreach ( $query->posts as $post ) {
			$results[] = array(
				'id'   => $post->ID,
				'text' => $this->post_text( $post ),
			);
		}
		return array(
			'results' => $results,
			'more'    => $page < $query->max_num_pages,
		);
	}

	protected function item_text( int $id, array $field ): ?string {
		$post = get_post( $id );
		if ( ! $post || ! in_array( $post->post_type, $this->post_types( $field ), true ) || ! current_user_can( 'read_post', $id ) ) {
			return null;
		}
		return $this->post_text( $post );
	}

	/**
	 * Title with status and type hints.
	 *
	 * @param \WP_Post $post Post.
	 */
	protected function post_text( \WP_Post $post ): string {
		$title = '' !== $post->post_title ? $post->post_title : sprintf( '#%d', $post->ID );
		if ( 'publish' !== $post->post_status ) {
			$status = get_post_status_object( $post->post_status );
			$title .= ' (' . ( $status ? $status->label : $post->post_status ) . ')';
		}
		return $title;
	}

	public function format_value( $value, $object_id, array $field ) {
		$ids = self::ids( $value );
		$out = array();
		if ( count( $ids ) > 1 ) {
			_prime_post_caches( $ids, false, false );
		}
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( $post && 'trash' !== $post->post_status ) {
				$out[] = $this->format_post( $post, $field );
			}
		}
		if ( $this->is_multiple( $field ) ) {
			return $out;
		}
		return $out[0] ?? ( null === $value ? null : false );
	}

	/**
	 * One formatted item.
	 *
	 * @param \WP_Post             $post  Post.
	 * @param array<string, mixed> $field Field.
	 * @return mixed
	 */
	protected function format_post( \WP_Post $post, array $field ) {
		return 'id' === $field['return_format'] ? $post->ID : $post;
	}
}
