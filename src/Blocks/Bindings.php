<?php
/**
 * Block Bindings source for field values.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Blocks;

use FreeformFields\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the `wpff/field` Block Bindings source (WordPress 6.5+), so core blocks can show
 * field values without a template:
 *
 *     <!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"wpff/field","args":{"key":"subtitle"}}}}} -->
 *
 * Only fields with "Allow in Block Bindings" turned on are exposed, and only for posts the
 * visitor may read.
 *
 * `args.object` reads other objects: `term` is the term of a taxonomy archive (category, tag or
 * custom taxonomy template), and an options page's storage key (`options`, or the page's own
 * `post_id`) reads that page's values. Without it, outside a post (for example the header of an
 * archive template), the archive's term is used.
 */
final class Bindings {

	public const SOURCE = 'wpff/field';

	public function __construct( private Plugin $plugin ) {}

	public function register(): void {
		add_action( 'init', array( $this, 'register_source' ) );
	}

	public function register_source(): void {
		if ( ! function_exists( 'register_block_bindings_source' ) ) {
			return;
		}
		register_block_bindings_source(
			self::SOURCE,
			array(
				'label'              => __( 'Custom field', 'freeform-fields' ),
				'get_value_callback' => array( $this, 'value' ),
				'uses_context'       => array( 'postId', 'postType' ),
			)
		);
	}

	/**
	 * Value for a bound block attribute.
	 *
	 * @param array<string, mixed> $args      Binding args: `key` (field name or key).
	 * @param \WP_Block|null       $block     Block instance.
	 * @param string               $attribute Bound attribute, e.g. content, url, alt.
	 * @return string|null Null leaves the attribute as saved.
	 */
	public function value( $args, $block = null, $attribute = '' ): ?string {
		$selector = is_array( $args ) && isset( $args['key'] ) && is_string( $args['key'] ) ? $args['key'] : '';
		if ( '' === $selector ) {
			return null;
		}
		$target = $this->target( is_array( $args ) ? $args : array(), $block );
		if ( null === $target ) {
			return null;
		}
		$object = $this->plugin->values()->get_object( $selector, $target, true );
		if ( ! $object || empty( $object['allow_in_bindings'] ) ) {
			return null;
		}
		return self::to_attribute( $object['value'], (string) $attribute );
	}

	/**
	 * ACF-style object id the binding reads, or null when there is nothing the visitor may see.
	 *
	 * @param array<string, mixed> $args  Binding args.
	 * @param \WP_Block|null       $block Block instance.
	 * @return int|string|null
	 */
	private function target( array $args, $block ) {
		$object = isset( $args['object'] ) && is_string( $args['object'] ) ? $args['object'] : '';
		if ( 'term' === $object ) {
			return self::archive_term();
		}
		if ( '' !== $object ) {
			// Only storage keys of registered options pages, never arbitrary option names.
			foreach ( $this->plugin->options_pages()->all() as $page ) {
				if ( $page['post_id'] === $object ) {
					return $object;
				}
			}
			return null;
		}
		$post_id = $block instanceof \WP_Block && isset( $block->context['postId'] ) ? (int) $block->context['postId'] : 0;
		if ( ! $post_id ) {
			// Outside a post, on a taxonomy archive: the archive's term.
			$term = self::archive_term();
			if ( null !== $term ) {
				return $term;
			}
			$post_id = (int) get_the_ID();
		}
		return $post_id && self::can_read( $post_id ) ? $post_id : null;
	}

	/**
	 * `term_{id}` of the term a public taxonomy archive shows, else null.
	 */
	private static function archive_term(): ?string {
		if ( ! ( is_category() || is_tag() || is_tax() ) ) {
			return null;
		}
		$term = get_queried_object();
		if ( ! $term instanceof \WP_Term ) {
			return null;
		}
		$taxonomy = get_taxonomy( $term->taxonomy );
		return $taxonomy && $taxonomy->public ? 'term_' . $term->term_id : null;
	}

	/**
	 * @param int $post_id Post.
	 */
	private static function can_read( int $post_id ): bool {
		$post = get_post( $post_id );
		if ( ! $post || post_password_required( $post ) ) {
			return false;
		}
		return is_post_publicly_viewable( $post ) || current_user_can( 'read_post', $post_id );
	}

	/**
	 * Rich text attributes sit inside a `<p>` or heading already, so the paragraphs of a WYSIWYG
	 * value would nest and the browser would break them apart. Paragraphs become line breaks.
	 *
	 * @param string $html Formatted HTML.
	 */
	public static function unwrap_paragraphs( string $html ): string {
		$html = trim( $html );
		if ( ! preg_match( '/^<p[\s>]/i', $html ) ) {
			return $html;
		}
		$html = preg_replace( '/^<p[^>]*>|<\/p>$/i', '', $html );
		$html = preg_replace( '/<\/p>\s*<p[^>]*>/i', '<br><br>', (string) $html );
		return trim( (string) $html );
	}

	/**
	 * Picks a string for the attribute from a formatted value.
	 *
	 * @param mixed  $value     Formatted value.
	 * @param string $attribute Attribute name.
	 */
	public static function to_attribute( $value, string $attribute ): ?string {
		$is_url = in_array( $attribute, array( 'url', 'href', 'src' ), true );
		if ( $value instanceof \WP_Post ) {
			$value = $is_url ? get_permalink( $value ) : get_the_title( $value );
		} elseif ( $value instanceof \WP_Term ) {
			$value = $is_url ? get_term_link( $value ) : $value->name;
		} elseif ( is_array( $value ) ) {
			// Image, file and link arrays.
			$map   = array(
				'url'   => 'url',
				'href'  => 'url',
				'src'   => 'url',
				'alt'   => 'alt',
				'title' => 'title',
				'id'    => 'ID',
			);
			$part  = $map[ $attribute ] ?? ( isset( $value['title'] ) ? 'title' : null );
			$value = null !== $part && isset( $value[ $part ] ) ? $value[ $part ] : ( array_is_list( $value ) && array_filter( $value, 'is_scalar' ) === $value ? implode( ', ', $value ) : null );
		}
		if ( is_bool( $value ) ) {
			$value = $value ? '1' : '';
		}
		if ( ! is_scalar( $value ) ) {
			return null;
		}
		$value = (string) $value;
		if ( $is_url ) {
			return esc_url_raw( $value );
		}
		if ( 'content' === $attribute ) {
			$value = self::unwrap_paragraphs( $value );
		}
		return wp_kses_post( $value );
	}
}
