<?php
/**
 * Registry of options pages.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Options pages registered from PHP (`wpff_add_options_page()`) and created in the admin
 * (posts of type `wpff-options-page`, settings as JSON in post_content).
 *
 * Settings use ACF's names: page_title, menu_title, menu_slug, capability, position,
 * parent_slug, icon_url, redirect, post_id, autoload, update_button, updated_message, description.
 */
final class OptionsPages {

	public const POST_TYPE = 'wpff-options-page';

	/**
	 * Pages registered from PHP, keyed by menu slug.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $local = array();

	/**
	 * Normalized pages, keyed by menu slug. Null until built.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $pages = null;

	/**
	 * Registers a page from PHP.
	 *
	 * @param array<string, mixed>|string $page Settings, or just a page title.
	 * @return array<string, mixed> Normalized page.
	 */
	public function add_local( $page ): array {
		$page                              = self::normalize( is_string( $page ) ? array( 'page_title' => $page ) : (array) $page );
		$page['source']                    = 'php';
		$this->local[ $page['menu_slug'] ] = $page;
		$this->pages                       = null;
		return $page;
	}

	/**
	 * Registers a sub page. Without `parent_slug` it goes under the last top-level page added from PHP,
	 * like ACF.
	 *
	 * @param array<string, mixed>|string $page Settings, or just a page title.
	 * @return array<string, mixed>
	 */
	public function add_local_sub( $page ): array {
		$page = is_string( $page ) ? array( 'page_title' => $page ) : (array) $page;
		if ( empty( $page['parent_slug'] ) ) {
			$parents             = array_filter( $this->local, static fn( $p ) => '' === $p['parent_slug'] );
			$page['parent_slug'] = array() !== $parents ? (string) array_key_last( $parents ) : 'options-general.php';
		}
		return $this->add_local( $page );
	}

	/**
	 * Removes a page registered from PHP.
	 *
	 * @param string $slug Menu slug.
	 */
	public function remove_local( string $slug ): void {
		unset( $this->local[ $slug ] );
		$this->pages = null;
	}

	/**
	 * Whether a page is registered from PHP (read-only in the admin).
	 *
	 * @param string $slug Menu slug.
	 */
	public function is_local( string $slug ): bool {
		return isset( $this->local[ $slug ] );
	}

	public function flush(): void {
		$this->pages = null;
	}

	/**
	 * All active pages, keyed by menu slug.
	 *
	 * @param bool $include_inactive Also return pages switched off in the admin.
	 * @return array<string, array<string, mixed>>
	 */
	public function all( bool $include_inactive = false ): array {
		if ( null === $this->pages ) {
			$this->pages = $this->load_db();
			foreach ( $this->local as $slug => $page ) {
				$this->pages[ $slug ] = $page;
			}
			// Not migrated yet: ACF's admin-created pages keep working while ACF is inactive.
			foreach ( self::acf_fallback_pages() as $slug => $page ) {
				if ( ! isset( $this->pages[ $slug ] ) ) {
					$this->pages[ $slug ] = $page;
				}
			}
		}
		return $include_inactive ? $this->pages : array_filter( $this->pages, static fn( $p ) => $p['active'] );
	}

	/**
	 * One page by menu slug.
	 *
	 * @param string $slug Menu slug.
	 * @return array<string, mixed>|null
	 */
	public function get( string $slug ): ?array {
		return $this->all( true )[ $slug ] ?? null;
	}

	/**
	 * Sub pages of an WP Freeform Fields page, in registration order.
	 *
	 * @param string $slug Parent menu slug.
	 * @return array<string, array<string, mixed>>
	 */
	public function children( string $slug ): array {
		return array_filter( $this->all(), static fn( $p ) => $p['parent_slug'] === $slug );
	}

	/**
	 * Fills defaults.
	 *
	 * @param array<string, mixed> $page Raw settings.
	 * @return array<string, mixed>
	 */
	public static function normalize( array $page ): array {
		$page               = wp_parse_args(
			$page,
			array(
				'page_title'      => __( 'Options', 'freeform-fields' ),
				'menu_title'      => '',
				'menu_slug'       => '',
				'capability'      => 'edit_posts',
				'position'        => null,
				'parent_slug'     => '',
				'icon_url'        => '',
				'redirect'        => true,
				'post_id'         => 'options',
				'autoload'        => false,
				'update_button'   => __( 'Update', 'freeform-fields' ),
				'updated_message' => __( 'Options Updated', 'freeform-fields' ),
				'description'     => '',
				'active'          => true,
				'source'          => 'db',
			)
		);
		$page['page_title'] = (string) $page['page_title'];
		$page['menu_title'] = '' !== (string) $page['menu_title'] ? (string) $page['menu_title'] : $page['page_title'];
		$page['menu_slug']  = '' !== (string) $page['menu_slug'] ? sanitize_title( (string) $page['menu_slug'] ) : 'acf-options-' . sanitize_title( $page['menu_title'] );
		$page['post_id']    = '' !== (string) $page['post_id'] ? $page['post_id'] : 'options';
		$page['redirect']   = (bool) $page['redirect'];
		$page['autoload']   = (bool) $page['autoload'];
		$page['active']     = (bool) $page['active'];
		$page['position']   = is_numeric( $page['position'] ) ? (float) $page['position'] : null;
		return $page;
	}

	/**
	 * Pages created in the admin.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	/**
	 * Options pages created in ACF's admin, read-only, while ACF is inactive and they are not migrated.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function acf_fallback_pages(): array {
		if ( ! did_action( 'init' ) || \FreeformFields\Plugin::acf_active() || ! apply_filters( 'wpff/read_acf_groups', true ) ) {
			return array();
		}
		$hash = \FreeformFields\Registry\AcfStamp::get( 'pages' );
		if ( '' === $hash ) {
			return array();
		}
		$cache_key = 'wpff_acf_pages_' . $hash;
		$pages     = get_transient( $cache_key );
		if ( is_array( $pages ) ) {
			return $pages;
		}
		$pages = array();
		foreach ( ( new \FreeformFields\Tools\ImportExport( \FreeformFields\Plugin::instance() ) )->acf_db_options_pages() as $data ) {
			$page = self::normalize( array( 'source' => 'acf' ) + $data );
			if ( '' !== $page['menu_slug'] ) {
				$pages[ $page['menu_slug'] ] = $page;
			}
		}
		set_transient( $cache_key, $pages, DAY_IN_SECONDS );
		return $pages;
	}

	private function load_db(): array {
		if ( ! did_action( 'init' ) || ! post_type_exists( self::POST_TYPE ) ) {
			return array();
		}
		$posts = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => array( 'publish', 'wpff-disabled' ),
				'posts_per_page'   => -1,
				'orderby'          => 'menu_order title',
				'order'            => 'ASC',
				'suppress_filters' => false,
				// Never limited to one language (Polylang).
				'lang'             => '',
			)
		);
		$pages = array();
		foreach ( $posts as $post ) {
			$data = json_decode( $post->post_content, true );
			if ( ! is_array( $data ) ) {
				continue;
			}
			$data['menu_slug']  = $post->post_name;
			$data['page_title'] = $post->post_title;
			$data['active']     = 'publish' === $post->post_status;
			$data['source']     = 'db';
			$page               = self::normalize( $data );
			// The post slug may have been made unique by WordPress; it is the page's identity.
			$page['menu_slug']         = $post->post_name;
			$pages[ $post->post_name ] = $page;
		}
		return $pages;
	}
}
