<?php
/**
 * Database storage for options pages created in the admin.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Options;

use FreeformFields\Registry\GroupStore;

defined( 'ABSPATH' ) || exit;

/**
 * Sanitizes options pages and saves them as `wpff-options-page` posts.
 *
 * Post layout: post_name = menu slug, post_title = page title, post_status = publish (active)
 * or wpff-disabled, post_content = JSON of the other settings.
 */
final class OptionsPageStore {

	/**
	 * Parent menus offered in the builder besides WP Freeform Fields' own pages.
	 */
	public const CORE_PARENTS = array(
		'index.php',
		'edit.php',
		'upload.php',
		'edit.php?post_type=page',
		'themes.php',
		'plugins.php',
		'users.php',
		'tools.php',
		'options-general.php',
	);

	public function __construct( private OptionsPages $pages ) {}

	/**
	 * Menus of other post types (registered in code or in the admin), as parent choices.
	 *
	 * @return array<string, string> Parent slug => menu label.
	 */
	public static function post_type_parents(): array {
		$parents = array();
		foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $type ) {
			if ( in_array( $type->name, array( 'post', 'page', 'attachment', 'wp_block', 'wp_navigation' ), true ) || true !== $type->show_in_menu || \FreeformFields\Plugin::GROUP_POST_TYPE === $type->name ) {
				continue;
			}
			$parents[ 'edit.php?post_type=' . $type->name ] = (string) $type->labels->menu_name;
		}
		return $parents;
	}

	/**
	 * Cleans a page submitted from the builder.
	 *
	 * @param array<string, mixed> $input Raw page.
	 * @return array<string, mixed>|\WP_Error Clean page, or an error listing `errors` as {path, message}.
	 */
	public function sanitize( array $input ) {
		$errors = array();
		$title  = sanitize_text_field( (string) ( $input['page_title'] ?? '' ) );
		if ( '' === $title ) {
			$errors[] = array(
				'path'    => 'page_title',
				'message' => __( 'Page title is required.', 'freeform-fields' ),
			);
		}
		$menu_title = sanitize_text_field( (string) ( $input['menu_title'] ?? '' ) );
		$slug       = sanitize_title( (string) ( $input['menu_slug'] ?? '' ) );
		if ( '' === $slug ) {
			$slug = sanitize_title( '' !== $menu_title ? $menu_title : $title );
		}
		if ( '' === $slug ) {
			$errors[] = array(
				'path'    => 'menu_slug',
				'message' => __( 'Menu slug is required (latin letters, digits and dashes).', 'freeform-fields' ),
			);
		}

		$parent = (string) ( $input['parent_slug'] ?? '' );
		if ( '' !== $parent && ! in_array( $parent, self::CORE_PARENTS, true ) && ! preg_match( '/^edit\.php\?post_type=[a-z0-9_-]+$/', $parent ) ) {
			$parent = sanitize_title( $parent );
			if ( $parent === $slug ) {
				$errors[] = array(
					'path'    => 'parent_slug',
					'message' => __( 'A page cannot be its own parent.', 'freeform-fields' ),
				);
			}
		}

		$post_id = trim( (string) ( $input['post_id'] ?? 'options' ) );
		if ( '' === $post_id ) {
			$post_id = 'options';
		}
		if ( ! preg_match( '/^[a-z0-9_]+$/', $post_id ) ) {
			$errors[] = array(
				'path'    => 'post_id',
				'message' => __( 'Storage key may contain only a-z, 0-9 and _ (e.g. options, site_settings, term_5).', 'freeform-fields' ),
			);
		}

		$capability = sanitize_key( (string) ( $input['capability'] ?? 'edit_posts' ) );

		if ( array() !== $errors ) {
			return new \WP_Error(
				'wpff_invalid_options_page',
				$errors[0]['message'],
				array(
					'status' => 400,
					'errors' => $errors,
				)
			);
		}

		return array(
			'page_title'      => $title,
			'menu_title'      => $menu_title,
			'menu_slug'       => $slug,
			'capability'      => '' !== $capability ? $capability : 'edit_posts',
			'position'        => is_numeric( $input['position'] ?? null ) ? (float) $input['position'] : null,
			'parent_slug'     => $parent,
			'icon_url'        => self::sanitize_icon( (string) ( $input['icon_url'] ?? '' ) ),
			'redirect'        => ! empty( $input['redirect'] ),
			'post_id'         => $post_id,
			'autoload'        => ! empty( $input['autoload'] ),
			'update_button'   => sanitize_text_field( (string) ( $input['update_button'] ?? '' ) ),
			'updated_message' => sanitize_text_field( (string) ( $input['updated_message'] ?? '' ) ),
			'description'     => sanitize_textarea_field( (string) ( $input['description'] ?? '' ) ),
			'active'          => ! isset( $input['active'] ) || ! empty( $input['active'] ),
		);
	}

	/**
	 * Creates or updates a page. `$original_slug` identifies an existing page whose slug may change.
	 *
	 * @param array<string, mixed> $input         Raw page.
	 * @param string               $original_slug Slug of the page being edited, '' for a new page.
	 * @return array<string, mixed>|\WP_Error Normalized saved page.
	 */
	public function save( array $input, string $original_slug = '' ) {
		$page = $this->sanitize( $input );
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		if ( $this->pages->is_local( $page['menu_slug'] ) || ( '' !== $original_slug && $this->pages->is_local( $original_slug ) ) ) {
			return new \WP_Error( 'wpff_local_page', __( 'This options page is registered in code and cannot be edited here.', 'freeform-fields' ), array( 'status' => 409 ) );
		}
		$existing = '' !== $original_slug ? $this->find_post( $original_slug ) : null;
		$taken    = $this->find_post( $page['menu_slug'] );
		if ( $taken && ( ! $existing || $taken->ID !== $existing->ID ) ) {
			return new \WP_Error(
				'wpff_invalid_options_page',
				__( 'Another options page already uses this menu slug.', 'freeform-fields' ),
				array(
					'status' => 400,
					'errors' => array(
						array(
							'path'    => 'menu_slug',
							'message' => __( 'Another options page already uses this menu slug.', 'freeform-fields' ),
						),
					),
				)
			);
		}

		$content = $page;
		unset( $content['menu_slug'], $content['page_title'], $content['active'] );
		$postarr = array(
			'post_type'    => OptionsPages::POST_TYPE,
			'post_status'  => $page['active'] ? 'publish' : GroupStore::DISABLED_STATUS,
			'post_title'   => $page['page_title'],
			'post_name'    => $page['menu_slug'],
			'post_content' => (string) wp_json_encode( $content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
		);
		if ( $existing ) {
			$postarr['ID'] = $existing->ID;
		}

		$kses = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		if ( $kses ) {
			kses_remove_filters();
		}
		$id = wp_insert_post( wp_slash( $postarr ), true );
		if ( $kses ) {
			kses_init_filters();
		}
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$this->pages->flush();
		$slug = get_post_field( 'post_name', $id );
		return $this->pages->get( $slug ) ?? $page;
	}

	/**
	 * Deletes a page permanently. Stored option values are kept.
	 *
	 * @param string $slug Menu slug.
	 */
	public function delete( string $slug ): bool {
		$post = $this->find_post( $slug );
		if ( ! $post ) {
			return false;
		}
		$ok = (bool) wp_delete_post( $post->ID, true );
		$this->pages->flush();
		return $ok;
	}

	/**
	 * The post storing a page.
	 *
	 * @param string $slug Menu slug.
	 */
	public function find_post( string $slug ): ?\WP_Post {
		$posts = get_posts(
			array(
				'post_type'      => OptionsPages::POST_TYPE,
				'post_status'    => array( 'publish', GroupStore::DISABLED_STATUS ),
				'name'           => $slug,
				'posts_per_page' => 1,
			)
		);
		return $posts[0] ?? null;
	}

	/**
	 * Allows a dashicons class, a URL or an SVG data URI.
	 *
	 * @param string $icon Raw value.
	 */
	private static function sanitize_icon( string $icon ): string {
		$icon = trim( $icon );
		if ( preg_match( '/^dashicons-[a-z0-9\-]+$/', $icon ) ) {
			return $icon;
		}
		if ( str_starts_with( $icon, 'data:image/svg+xml;base64,' ) ) {
			return preg_match( '#^data:image/svg\+xml;base64,[A-Za-z0-9+/=]+$#', $icon ) ? $icon : '';
		}
		return esc_url_raw( $icon );
	}
}
