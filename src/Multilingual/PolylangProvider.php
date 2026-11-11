<?php
/**
 * Polylang adapter.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Multilingual;

defined( 'ABSPATH' ) || exit;

/**
 * Talks to Polylang through its public `pll_*` functions.
 */
final class PolylangProvider implements Provider {

	public function name(): string {
		return 'polylang';
	}

	public function current_language(): ?string {
		$lang = function_exists( 'pll_current_language' ) ? pll_current_language( 'slug' ) : false;
		return is_string( $lang ) && '' !== $lang ? $lang : null;
	}

	public function default_language(): ?string {
		$lang = function_exists( 'pll_default_language' ) ? pll_default_language( 'slug' ) : false;
		return is_string( $lang ) && '' !== $lang ? $lang : null;
	}

	public function languages(): array {
		if ( ! function_exists( 'pll_languages_list' ) ) {
			return array();
		}
		$slugs = (array) pll_languages_list( array( 'fields' => 'slug' ) );
		$names = (array) pll_languages_list( array( 'fields' => 'name' ) );
		$list  = array();
		foreach ( $slugs as $i => $slug ) {
			$list[ (string) $slug ] = (string) ( $names[ $i ] ?? $slug );
		}
		return $list;
	}

	public function post_language( int $post_id ): ?string {
		$lang = function_exists( 'pll_get_post_language' ) ? pll_get_post_language( $post_id, 'slug' ) : false;
		return is_string( $lang ) && '' !== $lang ? $lang : null;
	}

	public function term_language( int $term_id ): ?string {
		$lang = function_exists( 'pll_get_term_language' ) ? pll_get_term_language( $term_id, 'slug' ) : false;
		return is_string( $lang ) && '' !== $lang ? $lang : null;
	}

	public function post_translations( int $post_id ): array {
		if ( ! function_exists( 'pll_get_post_translations' ) || ! $this->post_language( $post_id ) ) {
			return array();
		}
		return array_map( 'intval', array_filter( (array) pll_get_post_translations( $post_id ) ) );
	}

	public function term_translations( int $term_id ): array {
		if ( ! function_exists( 'pll_get_term_translations' ) || ! $this->term_language( $term_id ) ) {
			return array();
		}
		return array_map( 'intval', array_filter( (array) pll_get_term_translations( $term_id ) ) );
	}

	/**
	 * Polylang has no "original": every post of a translation group is equal.
	 *
	 * @param int $post_id Post ID.
	 */
	public function post_source( int $post_id ): ?int {
		return null;
	}

	public function register_string( string $context, string $name, string $value ): void {
		if ( function_exists( 'pll_register_string' ) && '' !== trim( $value ) ) {
			pll_register_string( $name, $value, $context, str_contains( $value, "\n" ) );
		}
	}

	public function translate_string( string $context, string $name, string $value ): string {
		$lang = $this->current_language();
		if ( '' === trim( $value ) || null === $lang || ! function_exists( 'pll_translate_string' ) ) {
			return $value;
		}
		return (string) pll_translate_string( $value, $lang );
	}
}
