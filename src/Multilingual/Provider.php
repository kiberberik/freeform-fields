<?php
/**
 * Contract for a multilingual plugin adapter.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Multilingual;

defined( 'ABSPATH' ) || exit;

/**
 * What WP Freeform Fields needs from WPML or Polylang. Language codes are the plugin's own
 * codes (WPML `code`, Polylang `slug`).
 */
interface Provider {

	/**
	 * `wpml` or `polylang`.
	 */
	public function name(): string;

	/**
	 * Language of the current request, or null when it is not one language (e.g. "All languages" in the admin).
	 */
	public function current_language(): ?string;

	/**
	 * The site's default language.
	 */
	public function default_language(): ?string;

	/**
	 * Active languages, code => display name.
	 *
	 * @return array<string, string>
	 */
	public function languages(): array;

	/**
	 * Language of a post (any post type), or null when it has none.
	 *
	 * @param int $post_id Post ID.
	 */
	public function post_language( int $post_id ): ?string;

	/**
	 * Language of a term, or null when it has none.
	 *
	 * @param int $term_id Term ID.
	 */
	public function term_language( int $term_id ): ?string;

	/**
	 * Translations of a post, language => post ID, the post itself included. Empty when the post type is not translated.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, int>
	 */
	public function post_translations( int $post_id ): array;

	/**
	 * Translations of a term, language => term ID, the term itself included.
	 *
	 * @param int $term_id Term ID.
	 * @return array<string, int>
	 */
	public function term_translations( int $term_id ): array;

	/**
	 * The post a translation was made from, or null when the post is an original
	 * (or the plugin does not track it).
	 *
	 * @param int $post_id Post ID.
	 */
	public function post_source( int $post_id ): ?int;

	/**
	 * Registers a string for translation (labels, instructions, choices).
	 *
	 * @param string $context Group of strings, e.g. the field group title.
	 * @param string $name    Unique name inside the context.
	 * @param string $value   Original text.
	 */
	public function register_string( string $context, string $name, string $value ): void;

	/**
	 * Translation of a registered string in the current language.
	 *
	 * @param string $context Group of strings.
	 * @param string $name    Name inside the context.
	 * @param string $value   Original text.
	 */
	public function translate_string( string $context, string $name, string $value ): string;
}
