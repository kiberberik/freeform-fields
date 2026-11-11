<?php
/**
 * Parsed object identifier.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Turns ACF-style `$post_id` arguments into a storage target.
 *
 * Accepted forms: `123`, `'123'`, `'post_123'`, `'term_5'`, `'{taxonomy}_5'`, `'user_3'`,
 * `'comment_7'`, `'option'` / `'options'`, `WP_Post`, `WP_Term`, `WP_User`, `WP_Comment`,
 * and `false` / `null` for the current object.
 */
final class ObjectRef {

	/**
	 * @param string         $type     One of post, term, user, comment, option.
	 * @param int|string     $id       Numeric id, or the option prefix for options.
	 * @param ObjectRef|null $fallback Read when this object has no value of its own
	 *                                 (options of a language without their own values).
	 */
	public function __construct(
		public readonly string $type,
		public readonly int|string $id,
		public readonly ?ObjectRef $fallback = null
	) {}

	/**
	 * Parses any accepted identifier. Returns null when it cannot be resolved.
	 *
	 * @param mixed $post_id Identifier.
	 */
	public static function parse( $post_id ): ?self {
		if ( false === $post_id || null === $post_id || '' === $post_id || 0 === $post_id ) {
			return self::current();
		}
		if ( $post_id instanceof \WP_Post ) {
			return new self( 'post', $post_id->ID );
		}
		if ( $post_id instanceof \WP_Term ) {
			return new self( 'term', $post_id->term_id );
		}
		if ( $post_id instanceof \WP_User ) {
			return new self( 'user', $post_id->ID );
		}
		if ( $post_id instanceof \WP_Comment ) {
			return new self( 'comment', (int) $post_id->comment_ID );
		}
		if ( is_int( $post_id ) || ( is_string( $post_id ) && ctype_digit( $post_id ) ) ) {
			return new self( 'post', (int) $post_id );
		}
		if ( ! is_string( $post_id ) ) {
			return null;
		}
		if ( 'option' === $post_id || 'options' === $post_id ) {
			// One set of values per language on multilingual sites (`options_{lang}`, as ACF stores them).
			return \FreeformFields\Multilingual\Multilingual::options_ref( 'options' );
		}
		if ( str_starts_with( $post_id, 'block_' ) ) {
			return new self( 'block', $post_id );
		}
		if ( preg_match( '/^(.+)_(\d+)$/', $post_id, $m ) ) {
			$prefix = $m[1];
			$id     = (int) $m[2];
			if ( in_array( $prefix, array( 'post', 'term', 'user', 'comment' ), true ) ) {
				return new self( $prefix, $id );
			}
			if ( taxonomy_exists( $prefix ) ) {
				return new self( 'term', $id );
			}
		}
		// Any other string is treated as a custom options prefix, as ACF does.
		return \FreeformFields\Multilingual\Multilingual::options_ref( $post_id );
	}

	/**
	 * The object for the current request: the post in the loop, or the queried term/user.
	 */
	private static function current(): ?self {
		// Inside a block template, fields belong to the block.
		$block = \FreeformFields\Blocks\BlockContext::current();
		if ( null !== $block ) {
			return new self( 'block', $block );
		}
		$post_id = get_the_ID();
		if ( $post_id ) {
			return new self( 'post', (int) $post_id );
		}
		$queried = get_queried_object();
		if ( $queried instanceof \WP_Term ) {
			return new self( 'term', $queried->term_id );
		}
		if ( $queried instanceof \WP_User ) {
			return new self( 'user', $queried->ID );
		}
		if ( $queried instanceof \WP_Post ) {
			return new self( 'post', $queried->ID );
		}
		return null;
	}

	/**
	 * String form accepted by ACF functions, e.g. `term_5`.
	 */
	public function to_string(): string {
		return match ( $this->type ) {
			'post'   => (string) $this->id,
			'option' => (string) $this->id,
			'block'  => (string) $this->id,
			default  => $this->type . '_' . $this->id,
		};
	}
}
