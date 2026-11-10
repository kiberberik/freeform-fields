<?php
/**
 * Plugin updates from the project's GitHub releases.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Updates;

defined( 'ABSPATH' ) || exit;

/**
 * Offers new versions on the Plugins and Updates screens.
 *
 * The plugin header has `Update URI: https://wpff.kiberberik.link`, so WordPress does not ask
 * wordpress.org about it and runs the `update_plugins_wpff.kiberberik.link` filter instead.
 * The filter reads `update.json`, a manifest that the release workflow attaches to every
 * GitHub release, and points WordPress at that release's zip. WordPress compares the versions
 * and installs the update itself, as for any other plugin.
 */
final class Updater {

	public const SLUG = 'freeform-fields';

	public const HOST = 'wpff.kiberberik.link';

	public const MANIFEST_URL = 'https://github.com/kiberberik/freeform-fields/releases/latest/download/update.json';

	/** Packages are only accepted from this project's releases. */
	public const PACKAGE_PREFIX = 'https://github.com/kiberberik/freeform-fields/releases/download/';

	public const CACHE_KEY = 'wpff_update_manifest';

	public function register(): void {
		add_filter( 'update_plugins_' . self::HOST, array( $this, 'check' ), 10, 3 );
		add_filter( 'plugins_api', array( $this, 'info' ), 20, 3 );
		// "Check again" on the Updates screen and plugin installs clear WordPress's own cache; clear ours with it.
		add_action( 'delete_site_transient_update_plugins', array( $this, 'flush' ) );
	}

	/**
	 * Whether update checks are on. Filter: `wpff/updates/enabled`.
	 */
	public static function enabled(): bool {
		/**
		 * Filters whether WP Freeform Fields checks its GitHub releases for updates.
		 *
		 * @param bool $enabled Default true.
		 */
		return (bool) apply_filters( 'wpff/updates/enabled', true );
	}

	/**
	 * `update_plugins_{$hostname}` filter.
	 *
	 * @param array|false $update      Update data from an earlier filter, or false.
	 * @param array       $plugin_data Plugin headers.
	 * @param string      $plugin_file Plugin basename.
	 * @return array|false
	 */
	public function check( $update, $plugin_data, $plugin_file ) {
		if ( plugin_basename( WPFF_FILE ) !== $plugin_file || ! self::enabled() ) {
			return $update;
		}
		$manifest = $this->manifest();
		if ( ! $manifest ) {
			return $update;
		}
		// WordPress compares `version` with the installed one and lists the plugin only when it is newer.
		return array(
			'id'           => 'https://' . self::HOST,
			'slug'         => self::SLUG,
			'plugin'       => $plugin_file,
			'version'      => $manifest['version'],
			'url'          => $manifest['homepage'],
			'package'      => $manifest['download_url'],
			'requires'     => $manifest['requires'],
			'requires_php' => $manifest['requires_php'],
			'tested'       => $manifest['tested'],
			'icons'        => array(),
			'banners'      => array(),
		);
	}

	/**
	 * `plugins_api` filter: the "View details" window for this plugin.
	 *
	 * @param false|object|array $result Result from an earlier filter.
	 * @param string             $action API action.
	 * @param object             $args   Request arguments.
	 * @return false|object|array
	 */
	public function info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || ( $args->slug ?? '' ) !== self::SLUG || ! self::enabled() ) {
			return $result;
		}
		$manifest = $this->manifest();
		if ( ! $manifest ) {
			return $result;
		}
		$changelog  = '' !== $manifest['changelog']
			? self::render_changelog( $manifest['changelog'] )
			: '';
		$changelog .= sprintf(
			'<p><a href="%s">%s</a></p>',
			esc_url( 'https://github.com/kiberberik/freeform-fields/blob/main/CHANGELOG.md' ),
			esc_html__( 'Full changelog', 'freeform-fields' )
		);

		return (object) array(
			'name'          => 'WP Freeform Fields',
			'slug'          => self::SLUG,
			'version'       => $manifest['version'],
			'author'        => '<a href="https://kiberberik.link">kiberberik</a>',
			'homepage'      => $manifest['homepage'],
			'requires'      => $manifest['requires'],
			'requires_php'  => $manifest['requires_php'],
			'tested'        => $manifest['tested'],
			'last_updated'  => $manifest['released'],
			'download_link' => $manifest['download_url'],
			'sections'      => array(
				'description' => '<p>' . esc_html__( 'Free custom fields for WordPress: field groups, repeaters, flexible content, options pages and PHP blocks. Compatible with ACF data and template functions.', 'freeform-fields' ) . '</p>'
					. sprintf( '<p><a href="%s">%s</a></p>', esc_url( $manifest['homepage'] ), esc_html__( 'Documentation', 'freeform-fields' ) ),
				'changelog'   => $changelog,
			),
		);
	}

	/**
	 * The latest release's manifest, cached for 12 hours (1 hour after a failed request).
	 *
	 * @return array|null Normalized manifest, or null when there is none.
	 */
	public function manifest(): ?array {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached['manifest'] ?? null;
		}

		$manifest = null;
		$response = wp_safe_remote_get(
			self::manifest_url(),
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);
		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$manifest = self::normalize( json_decode( wp_remote_retrieve_body( $response ), true ) );
		}

		set_site_transient( self::CACHE_KEY, array( 'manifest' => $manifest ), $manifest ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
		return $manifest;
	}

	public function flush(): void {
		delete_site_transient( self::CACHE_KEY );
	}

	/**
	 * Manifest URL. Filter: `wpff/updates/manifest_url`.
	 */
	public static function manifest_url(): string {
		/**
		 * Filters the URL of the update manifest.
		 *
		 * @param string $url Default: update.json of the latest GitHub release.
		 */
		return (string) apply_filters( 'wpff/updates/manifest_url', self::MANIFEST_URL );
	}

	/**
	 * Validates a decoded manifest and fills in defaults.
	 *
	 * @param mixed $data Decoded JSON.
	 * @return array|null
	 */
	public static function normalize( $data ): ?array {
		if ( ! is_array( $data ) ) {
			return null;
		}
		$version = is_string( $data['version'] ?? null ) ? $data['version'] : '';
		$package = is_string( $data['download_url'] ?? null ) ? $data['download_url'] : '';
		if ( ! preg_match( '/^\d+(\.\d+){1,3}$/', $version ) ) {
			return null;
		}
		// Only zips from this project's own releases, whatever the manifest says.
		if ( 0 !== strpos( $package, self::PACKAGE_PREFIX ) || ! str_ends_with( $package, '.zip' ) ) {
			return null;
		}
		$text = static fn( $key ) => is_string( $data[ $key ] ?? null ) ? $data[ $key ] : '';

		return array(
			'version'      => $version,
			'download_url' => $package,
			'homepage'     => 'https://' . self::HOST,
			'requires'     => $text( 'requires' ),
			'requires_php' => $text( 'requires_php' ),
			'tested'       => $text( 'tested' ),
			'released'     => $text( 'released' ),
			'changelog'    => $text( 'changelog' ),
		);
	}

	/**
	 * Turns the release's CHANGELOG.md section into simple escaped HTML:
	 * `### Heading` lines, `- item` lines and `code` spans.
	 *
	 * @param string $markdown Changelog section.
	 */
	public static function render_changelog( string $markdown ): string {
		$html    = '';
		$in_list = false;
		foreach ( preg_split( '/\R/', $markdown ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$is_item = str_starts_with( $line, '- ' ) || str_starts_with( $line, '* ' );
			if ( $in_list && ! $is_item ) {
				$html   .= '</ul>';
				$in_list = false;
			}
			if ( preg_match( '/^#{2,4}\s+(.*)$/', $line, $m ) ) {
				$html .= '<h4>' . self::inline( $m[1] ) . '</h4>';
			} elseif ( $is_item ) {
				if ( ! $in_list ) {
					$html   .= '<ul>';
					$in_list = true;
				}
				$html .= '<li>' . self::inline( substr( $line, 2 ) ) . '</li>';
			} else {
				$html .= '<p>' . self::inline( $line ) . '</p>';
			}
		}
		if ( $in_list ) {
			$html .= '</ul>';
		}
		return $html;
	}

	private static function inline( string $text ): string {
		$text = esc_html( $text );
		$text = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $text );
		return (string) preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text );
	}
}
