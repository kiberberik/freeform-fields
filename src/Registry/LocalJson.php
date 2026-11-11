<?php
/**
 * Field groups as JSON files in the theme (Local JSON).
 *
 * @package FreeformFields
 */

namespace FreeformFields\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps one `{group_key}.json` file per field group so groups can live in version control.
 *
 *  - Saving a group in the admin writes its file to the save folder (`{theme}/wpff-json` when
 *    that folder exists; filter `wpff/settings/save_json`).
 *  - Groups are read from every load folder (`wpff-json` and ACF's `acf-json` in the child and
 *    parent theme; filter `wpff/settings/load_json`) and take precedence over the database copy,
 *    like ACF. PHP-registered groups still win over both.
 *  - A file newer than the database copy (or without one) is "available for sync": syncing
 *    imports it into the database.
 *
 * The file format is ACF's export format, so ACF's acf-json files load directly.
 */
final class LocalJson {

	/**
	 * Files found in load folders: group key => [ 'file' => path, 'group' => data ].
	 *
	 * @var array<string, array{file: string, group: array<string, mixed>}>|null
	 */
	private ?array $files = null;

	public function register(): void {
		add_filter( 'wpff/load_field_groups', array( $this, 'merge' ), 5 );
		add_action( 'wpff/save_field_group', array( $this, 'write' ) );
		add_action( 'wpff/delete_field_group', array( $this, 'remove' ) );
	}

	/**
	 * Folder new files are written to, or '' when Local JSON saving is off.
	 */
	public function save_path(): string {
		$default = get_stylesheet_directory() . '/wpff-json';
		/**
		 * Filters the Local JSON save folder. Return '' to stop writing files.
		 *
		 * @param string $path Folder; used only if it exists and is writable.
		 */
		$path = (string) \FreeformFields\Hooks::filter( 'settings/save_json', is_dir( $default ) ? $default : '' );
		return '' !== $path && is_dir( $path ) && wp_is_writable( $path ) ? untrailingslashit( $path ) : '';
	}

	/**
	 * Folders groups are read from.
	 *
	 * @return string[]
	 */
	public function load_paths(): array {
		$paths = array_unique(
			array(
				get_stylesheet_directory() . '/wpff-json',
				get_template_directory() . '/wpff-json',
			)
		);
		// While ACF is active it loads acf-json itself; reading it too would show every group twice.
		if ( ! \FreeformFields\Plugin::acf_active() ) {
			$paths[] = get_stylesheet_directory() . '/acf-json';
			$paths[] = get_template_directory() . '/acf-json';
			$paths   = array_values( array_unique( $paths ) );
		}
		/**
		 * Filters the folders Local JSON groups are loaded from.
		 *
		 * @param string[] $paths Folders.
		 */
		$paths = (array) \FreeformFields\Hooks::filter( 'settings/load_json', $paths );
		return array_values( array_filter( array_map( 'untrailingslashit', $paths ), 'is_dir' ) );
	}

	/**
	 * Groups found in JSON files, keyed by group key. The first folder wins for a key.
	 *
	 * @return array<string, array{file: string, group: array<string, mixed>}>
	 */
	public function files(): array {
		if ( null !== $this->files ) {
			return $this->files;
		}
		$this->files = array();
		$found       = array();
		clearstatcache();
		foreach ( $this->load_paths() as $path ) {
			foreach ( glob( $path . '/*.json' ) ?: array() as $file ) {
				$found[ $file ] = (int) filemtime( $file ) . ':' . (int) filesize( $file );
			}
		}
		if ( array() === $found ) {
			return $this->files;
		}
		// Reading and decoding every file is the slow part; the list of names and times is cheap.
		$cache_key = 'json_files:' . md5( wp_cache_get_last_changed( \FreeformFields\Registry\FieldRegistry::CACHE_GROUP ) . (string) wp_json_encode( $found ) );
		$cached    = wp_cache_get( $cache_key, \FreeformFields\Registry\FieldRegistry::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			$this->files = $cached;
			return $this->files;
		}
		foreach ( array_keys( $found ) as $file ) {
			$group = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			// ACF also allows a file holding a list of groups (an export); take each.
			$groups = isset( $group['key'] ) ? array( $group ) : ( is_array( $group ) ? $group : array() );
			foreach ( $groups as $item ) {
				if ( ! is_array( $item ) || empty( $item['key'] ) || ! str_starts_with( (string) $item['key'], 'group_' ) ) {
					continue;
				}
				if ( ! isset( $this->files[ $item['key'] ] ) ) {
					$this->files[ $item['key'] ] = array(
						'file'  => $file,
						'group' => $item,
					);
				}
			}
		}
		wp_cache_set( $cache_key, $this->files, \FreeformFields\Registry\FieldRegistry::CACHE_GROUP, DAY_IN_SECONDS );
		return $this->files;
	}

	public function flush(): void {
		$this->files = null;
	}

	/**
	 * Forgets the files cached between requests too (after this plugin writes or removes one).
	 */
	private function changed(): void {
		wp_cache_set_last_changed( \FreeformFields\Registry\FieldRegistry::CACHE_GROUP );
		$this->flush();
	}

	/**
	 * Adds JSON groups over database groups (filter on wpff/load_field_groups).
	 *
	 * @param array<string, array<string, mixed>> $raw Groups so far (database and PHP).
	 * @return array<string, array<string, mixed>>
	 */
	public function merge( $raw ): array {
		$raw = (array) $raw;
		foreach ( $this->files() as $key => $entry ) {
			$existing = $raw[ $key ] ?? null;
			if ( $existing && 'php' === ( $existing['local'] ?? '' ) ) {
				continue;
			}
			$group                  = $entry['group'];
			$group['local']         = 'json';
			$group['local_file']    = $entry['file'];
			$group['json_modified'] = (int) ( $group['modified'] ?? filemtime( $entry['file'] ) );
			$group['db_modified']   = $existing ? (int) ( $existing['modified'] ?? 0 ) : 0;
			$group['has_db']        = null !== $existing;
			if ( $existing && isset( $existing['ID'] ) ) {
				$group['ID'] = $existing['ID'];
			}
			$raw[ $key ] = $group;
		}
		return $raw;
	}

	/**
	 * Whether a group's JSON file should be imported into the database.
	 *
	 * @param array<string, mixed> $group Normalized group.
	 */
	public static function needs_sync( array $group ): bool {
		return 'json' === ( $group['local'] ?? '' ) && ( empty( $group['has_db'] ) || (int) $group['json_modified'] > (int) $group['db_modified'] );
	}

	/**
	 * Writes a group's file (after it is saved in the admin).
	 *
	 * @param array<string, mixed> $group Sanitized group (with `modified`).
	 * @return bool Whether a file was written.
	 */
	public function write( array $group ): bool {
		$path = $this->save_path();
		if ( '' === $path ) {
			return false;
		}
		$data = self::export_shape( $group );
		$ok   = false !== file_put_contents( $path . '/' . $group['key'] . '.json', wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->changed();
		return $ok;
	}

	/**
	 * Deletes a group's file from the save folder.
	 *
	 * @param string $key Group key.
	 */
	public function remove( string $key ): void {
		$path = $this->save_path();
		if ( '' !== $path && file_exists( $path . '/' . $key . '.json' ) ) {
			wp_delete_file( $path . '/' . $key . '.json' );
		}
		$this->changed();
	}

	/**
	 * A group in the export / JSON file format: settings and fields without runtime props.
	 *
	 * @param array<string, mixed> $group Group.
	 * @return array<string, mixed>
	 */
	public static function export_shape( array $group ): array {
		$clean_fields = static function ( array $fields ) use ( &$clean_fields ): array {
			$out = array();
			foreach ( $fields as $field ) {
				unset( $field['parent'], $field['menu_order'] );
				if ( ! empty( $field['sub_fields'] ) ) {
					$field['sub_fields'] = $clean_fields( $field['sub_fields'] );
				}
				foreach ( $field['layouts'] ?? array() as $i => $layout ) {
					$field['layouts'][ $i ]['sub_fields'] = $clean_fields( $layout['sub_fields'] ?? array() );
				}
				$out[] = $field;
			}
			return $out;
		};
		$out          = array(
			'key'                   => $group['key'],
			'title'                 => $group['title'],
			'fields'                => $clean_fields( $group['fields'] ?? array() ),
			'location'              => $group['location'] ?? array(),
			'menu_order'            => (int) ( $group['menu_order'] ?? 0 ),
			'position'              => $group['position'] ?? 'normal',
			'style'                 => $group['style'] ?? 'default',
			'label_placement'       => $group['label_placement'] ?? 'top',
			'instruction_placement' => $group['instruction_placement'] ?? 'label',
			'hide_on_screen'        => $group['hide_on_screen'] ?? array(),
			'active'                => (bool) ( $group['active'] ?? true ),
			'description'           => $group['description'] ?? '',
			'show_in_rest'          => (int) ! empty( $group['show_in_rest'] ),
			'modified'              => (int) ( $group['modified'] ?? time() ),
		);
		return $out;
	}
}
