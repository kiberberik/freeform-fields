<?php
/**
 * Hook helpers: fire `wpff/…` hooks and, in ACF compatibility mode, the matching `acf/…` hooks.
 *
 * @package FreeformFields
 */

namespace FreeformFields;

defined( 'ABSPATH' ) || exit;

/**
 * Themes written for ACF customize it with `acf/…` filters (`acf/load_field/name=color`,
 * `acf/format_value`, `acf/save_post`…). While ACF itself is not active, every hook below
 * runs twice: first as `wpff/{suffix}`, then as `acf/{suffix}`, with the same arguments.
 * When ACF is active, only `wpff/…` runs, so ACF's own callbacks never see our fields.
 */
final class Hooks {

	/**
	 * Cached prefixes once ACF's presence is known (after after_setup_theme).
	 *
	 * @var string[]|null
	 */
	private static ?array $prefixes = null;

	/**
	 * @return string[]
	 */
	public static function prefixes(): array {
		if ( null !== self::$prefixes ) {
			return self::$prefixes;
		}
		$prefixes = Plugin::compat_enabled() ? array( 'wpff', 'acf' ) : array( 'wpff' );
		// A theme may still load its own ACF until after_setup_theme.
		if ( did_action( 'after_setup_theme' ) ) {
			self::$prefixes = $prefixes;
		}
		return $prefixes;
	}

	/**
	 * Forgets the cached prefixes (tests switch compatibility on and off).
	 */
	public static function reset(): void {
		self::$prefixes = null;
	}

	/**
	 * apply_filters() for `wpff/{suffix}` and, in compatibility mode, `acf/{suffix}`.
	 *
	 * @param string $suffix Hook name without the prefix, e.g. `settings/save_json`.
	 * @param mixed  $value  Value to filter.
	 * @param mixed  ...$args Extra arguments.
	 * @return mixed
	 */
	public static function filter( string $suffix, $value, ...$args ) {
		foreach ( self::prefixes() as $prefix ) {
			$value = apply_filters( "{$prefix}/{$suffix}", $value, ...$args ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- wpff/ or acf/.
		}
		return $value;
	}

	/**
	 * do_action() for `wpff/{suffix}` and, in compatibility mode, `acf/{suffix}`.
	 *
	 * @param string $suffix Hook name without the prefix.
	 * @param mixed  ...$args Arguments.
	 */
	public static function action( string $suffix, ...$args ): void {
		foreach ( self::prefixes() as $prefix ) {
			do_action( "{$prefix}/{$suffix}", ...$args ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- wpff/ or acf/.
		}
	}

	/**
	 * A field filter with ACF's variants: `{prefix}/{suffix}`, then `/type=`, `/name=`, `/key=`.
	 *
	 * @param string               $suffix Hook name without the prefix, e.g. `format_value`.
	 * @param mixed                $value  Value to filter (the field itself for load_field / prepare_field).
	 * @param array<string, mixed> $field  Field whose type, name and key pick the variants.
	 * @param mixed                ...$args Extra arguments after the value.
	 * @return mixed
	 */
	public static function field_filter( string $suffix, $value, array $field, ...$args ) {
		$is_field = in_array( $suffix, array( 'load_field', 'prepare_field' ), true );
		foreach ( self::prefixes() as $prefix ) {
			$value = apply_filters( "{$prefix}/{$suffix}", $value, ...$args ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
			foreach ( array( 'type', 'name', 'key' ) as $prop ) {
				// A field filter may return false (prepare_field hides the field).
				$source = $is_field ? $value : $field;
				if ( ! is_array( $source ) || ! isset( $source[ $prop ] ) ) {
					return $value;
				}
				$value = apply_filters( "{$prefix}/{$suffix}/{$prop}={$source[ $prop ]}", $value, ...$args ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
			}
		}
		return $value;
	}

	/**
	 * Runs `load_field` filters (ACF's way to fill choices or change settings at runtime).
	 *
	 * @param array<string, mixed> $field Field.
	 * @return array<string, mixed>
	 */
	public static function load_field( array $field ): array {
		$loaded = self::field_filter( 'load_field', $field, $field );
		return is_array( $loaded ) ? $loaded : $field;
	}
}
