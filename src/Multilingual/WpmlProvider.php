<?php
/**
 * WPML adapter.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Multilingual;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own hooks.

/**
 * Talks to WPML through its documented `wpml_*` hooks.
 */
final class WpmlProvider implements Provider {

	public function name(): string {
		return 'wpml';
	}

	public function current_language(): ?string {
		$lang = apply_filters( 'wpml_current_language', null );
		return is_string( $lang ) && '' !== $lang && 'all' !== $lang ? $lang : null;
	}

	public function default_language(): ?string {
		$lang = apply_filters( 'wpml_default_language', null );
		return is_string( $lang ) && '' !== $lang ? $lang : null;
	}

	/**
	 * Active languages, cached for the request.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $languages = null;

	public function languages(): array {
		if ( null !== $this->languages ) {
			return $this->languages;
		}
		$active = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
		$list   = array();
		foreach ( is_array( $active ) ? $active : array() as $code => $language ) {
			$language               = (array) $language;
			$list[ (string) $code ] = (string) ( $language['native_name'] ?? $language['translated_name'] ?? $code );
		}
		if ( did_action( 'wpml_loaded' ) && array() !== $list ) {
			$this->languages = $list;
		}
		return $list;
	}

	public function post_language( int $post_id ): ?string {
		$type = get_post_type( $post_id );
		if ( ! $type ) {
			return null;
		}
		$lang = apply_filters(
			'wpml_element_language_code',
			null,
			array(
				'element_id'   => $post_id,
				'element_type' => $type,
			)
		);
		return is_string( $lang ) && '' !== $lang ? $lang : null;
	}

	public function term_language( int $term_id ): ?string {
		$term = get_term( $term_id );
		if ( ! $term instanceof \WP_Term ) {
			return null;
		}
		$lang = apply_filters(
			'wpml_element_language_code',
			null,
			array(
				'element_id'   => $term->term_taxonomy_id,
				'element_type' => $term->taxonomy,
			)
		);
		return is_string( $lang ) && '' !== $lang ? $lang : null;
	}

	public function post_translations( int $post_id ): array {
		$type = get_post_type( $post_id );
		if ( ! $type || ! apply_filters( 'wpml_is_translated_post_type', false, $type ) ) {
			return array();
		}
		return $this->translations( $post_id, $type );
	}

	public function term_translations( int $term_id ): array {
		$term = get_term( $term_id );
		if ( ! $term instanceof \WP_Term || ! apply_filters( 'wpml_is_translated_taxonomy', false, $term->taxonomy ) ) {
			return array();
		}
		return $this->translations( $term_id, $term->taxonomy );
	}

	public function post_source( int $post_id ): ?int {
		$type = get_post_type( $post_id );
		if ( ! $type ) {
			return null;
		}
		$original = (int) apply_filters( 'wpml_original_element_id', null, $post_id, 'post_' . $type );
		return $original && $original !== $post_id ? $original : null;
	}

	public function register_string( string $context, string $name, string $value ): void {
		if ( '' !== trim( $value ) ) {
			do_action( 'wpml_register_single_string', $context, $name, $value );
		}
	}

	public function translate_string( string $context, string $name, string $value ): string {
		if ( '' === trim( $value ) ) {
			return $value;
		}
		return (string) apply_filters( 'wpml_translate_single_string', $value, $context, $name );
	}

	/**
	 * Language => ID of each translation of an object.
	 *
	 * @param int    $id   Post or term ID.
	 * @param string $type Post type or taxonomy.
	 * @return array<string, int>
	 */
	private function translations( int $id, string $type ): array {
		$list = array();
		foreach ( array_keys( $this->languages() ) as $lang ) {
			$tr = (int) apply_filters( 'wpml_object_id', $id, $type, false, $lang );
			if ( $tr ) {
				$list[ $lang ] = $tr;
			}
		}
		return $list;
	}
}
