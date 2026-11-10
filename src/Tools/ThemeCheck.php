<?php
/**
 * Pre-migration check of theme code for ACF APIs WP Freeform Fields does not provide.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Scans the active theme's PHP files for ACF functions and `acf/…` hooks and reports the ones
 * that would stop working after ACF is deactivated: missing functions cause fatal errors,
 * unsupported hooks are silently ignored.
 */
final class ThemeCheck {

	/**
	 * `acf/…` hooks fired in compatibility mode (variants /type=, /name=, /key= included).
	 */
	public const SUPPORTED_HOOKS = array(
		'init',
		'include_fields',
		'load_value',
		'format_value',
		'update_value',
		'validate_value',
		'prepare_field',
		'load_field',
		'load_field_groups',
		'save_post',
		'pre_save_post',
		'submit_form',
		'settings/save_json',
		'settings/load_json',
		'settings/google_api_key',
		'fields/post_object/query',
		'fields/relationship/query',
		'fields/page_link/query',
		'fields/taxonomy/query',
		'fields/user/query',
		'fields/google_map/api',
		'input/admin_enqueue_scripts',
	);

	private const MAX_FILES     = 2000;
	private const MAX_LOCATIONS = 3;

	/**
	 * @return array{functions: array<string, string[]>, hooks: array<string, string[]>, files: int}
	 *         Unsupported names with up to three `file:line` locations each.
	 */
	public function run(): array {
		$supported = self::supported_functions();
		$report    = array(
			'functions' => array(),
			'hooks'     => array(),
			'files'     => 0,
		);
		$root      = trailingslashit( get_theme_root() );
		foreach ( $this->files() as $file ) {
			++$report['files'];
			$code = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local theme file.
			if ( ! str_contains( $code, 'acf' ) && ! str_contains( $code, 'sub_field' ) && ! str_contains( $code, 'field_object' ) ) {
				continue;
			}
			$defined = array();
			preg_match_all( '/function\s+&?\s*([a-z_][a-z0-9_]*)\s*\(/i', $code, $m );
			foreach ( $m[1] as $name ) {
				$defined[ strtolower( $name ) ] = true;
			}
			$where = static fn( int $offset ): string => str_replace( $root, '', $file ) . ':' . ( substr_count( $code, "\n", 0, $offset ) + 1 );

			// Function calls (not method calls, not definitions, not function_exists strings).
			preg_match_all( '/(?<![\w>$:\'"])(acf_[a-z0-9_]+|get_field_objects|update_sub_field|delete_sub_field|get_sub_field_object|have_settings|the_setting|get_setting)\s*\(/i', $code, $calls, PREG_OFFSET_CAPTURE );
			foreach ( $calls[1] as [ $name, $offset ] ) {
				$name   = strtolower( $name );
				$before = substr( $code, max( 0, $offset - 20 ), min( 20, $offset ) );
				if ( isset( $supported[ $name ] ) || isset( $defined[ $name ] ) || preg_match( '/function\s+&?\s*$/i', $before ) ) {
					continue;
				}
				$report['functions'][ $name ] ??= array();
				if ( count( $report['functions'][ $name ] ) < self::MAX_LOCATIONS ) {
					$report['functions'][ $name ][] = $where( $offset );
				}
			}

			preg_match_all( '/add_(?:filter|action)\s*\(\s*[\'"]acf\/([a-z0-9_\/]+)/i', $code, $hooks, PREG_OFFSET_CAPTURE );
			foreach ( $hooks[1] as [ $hook, $offset ] ) {
				$base = strtolower( rtrim( (string) preg_replace( '#/(type|name|key|parent)$#', '', $hook ), '/' ) );
				if ( in_array( $base, self::SUPPORTED_HOOKS, true ) ) {
					continue;
				}
				$label                       = 'acf/' . $base;
				$report['hooks'][ $label ] ??= array();
				if ( count( $report['hooks'][ $label ] ) < self::MAX_LOCATIONS ) {
					$report['hooks'][ $label ][] = $where( $offset );
				}
			}
		}
		ksort( $report['functions'] );
		ksort( $report['hooks'] );
		return $report;
	}

	/**
	 * ACF-named functions this plugin defines (read from the compatibility file).
	 *
	 * @return array<string, true>
	 */
	public static function supported_functions(): array {
		$code = (string) file_get_contents( WPFF_PATH . 'src/Api/compat-acf.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- own file.
		preg_match_all( "/function_exists\\( '([a-z_]+)' \\)/", $code, $m );
		return array_fill_keys( $m[1], true );
	}

	/**
	 * PHP files of the child and parent theme, without bundled libraries.
	 *
	 * @return string[]
	 */
	private function files(): array {
		$files = array();
		foreach ( array_unique( array( get_stylesheet_directory(), get_template_directory() ) ) as $dir ) {
			$this->collect( $dir, $files );
		}
		return $files;
	}

	/**
	 * @param string   $dir   Folder.
	 * @param string[] $files Found files.
	 */
	private function collect( string $dir, array &$files ): void {
		if ( count( $files ) >= self::MAX_FILES ) {
			return;
		}
		// A bundled copy of ACF is ACF's own code, not the theme's.
		if ( is_readable( $dir . '/acf.php' ) && str_contains( (string) file_get_contents( $dir . '/acf.php', false, null, 0, 2048 ), 'Custom Fields' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			return;
		}
		foreach ( (array) glob( $dir . '/*.php' ) as $file ) {
			$files[] = (string) $file;
		}
		foreach ( (array) glob( $dir . '/*', GLOB_ONLYDIR ) as $sub ) {
			if ( ! in_array( basename( (string) $sub ), array( 'node_modules', 'vendor', '.git' ), true ) ) {
				$this->collect( (string) $sub, $files );
			}
		}
	}
}
