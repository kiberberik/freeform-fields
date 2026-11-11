<?php
/**
 * Post types and taxonomies created in the admin.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Content;

use FreeformFields\Registry\GroupStore;

defined( 'ABSPATH' ) || exit;

/**
 * Stores custom post types (`wpff-post-type` posts) and taxonomies (`wpff-taxonomy` posts),
 * settings as JSON in post_content, and registers them on every request.
 *
 * Kinds: `post_type` and `taxonomy`. Each item has a `key` (the post type / taxonomy name),
 * singular and plural labels and the usual registration options.
 */
final class ContentTypes {

	public const POST_TYPES = array(
		'post_type' => 'wpff-post-type',
		'taxonomy'  => 'wpff-taxonomy',
	);

	/**
	 * Names WordPress reserves for its own use.
	 */
	private const RESERVED = array(
		'post',
		'page',
		'attachment',
		'revision',
		'nav_menu_item',
		'custom_css',
		'customize_changeset',
		'oembed_cache',
		'user_request',
		'wp_block',
		'wp_template',
		'wp_template_part',
		'wp_global_styles',
		'wp_navigation',
		'wp_font_family',
		'wp_font_face',
		'action',
		'author',
		'order',
		'theme',
		'type',
		'category',
		'post_tag',
		'nav_menu',
		'link_category',
		'post_format',
		'wp_theme',
		'wp_pattern_category',
		'term',
		'taxonomy',
		'name',
		'page_id',
		'year',
		'month',
		'day',
		'hour',
		'minute',
		'second',
		's',
		'search',
	);

	private const SUPPORTS = array( 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields', 'comments', 'revisions', 'author', 'page-attributes' );

	/**
	 * Items by kind and key. Null until loaded.
	 *
	 * @var array<string, array<string, array<string, mixed>>>|null
	 */
	private ?array $items = null;

	public function register(): void {
		add_action( 'init', array( $this, 'register_storage' ), 4 );
		add_action( 'init', array( $this, 'register_all' ), 6 );
	}

	public function register_storage(): void {
		foreach ( self::POST_TYPES as $kind => $type ) {
			register_post_type(
				$type,
				array(
					'labels'          => array( 'name' => 'post_type' === $kind ? __( 'Post Types', 'freeform-fields' ) : __( 'Taxonomies', 'freeform-fields' ) ),
					'public'          => false,
					'show_ui'         => false,
					'rewrite'         => false,
					'query_var'       => false,
					'supports'        => array( 'title' ),
					'capability_type' => array( 'wpff_config', 'wpff_configs' ),
					'map_meta_cap'    => true,
					'capabilities'    => \FreeformFields\Plugin::config_capabilities(),
				)
			);
		}
	}

	/**
	 * Registers every active item (taxonomies first, so post types can attach them).
	 */
	public function register_all(): void {
		foreach ( $this->all( 'taxonomy' ) as $tax ) {
			if ( $tax['active'] && ! taxonomy_exists( $tax['key'] ) ) {
				register_taxonomy( $tax['key'], $tax['object_type'], self::taxonomy_args( $tax ) );
			}
		}
		foreach ( $this->all( 'post_type' ) as $type ) {
			if ( $type['active'] && ! post_type_exists( $type['key'] ) ) {
				register_post_type( $type['key'], self::post_type_args( $type ) );
			}
		}
		// New or changed slugs need fresh rewrite rules once.
		if ( get_option( 'wpff_flush_rewrite_rules' ) ) {
			update_option( 'wpff_flush_rewrite_rules', 0, true ); // Kept (autoloaded) so later requests read it from memory.
			flush_rewrite_rules( false );
		}
	}

	/**
	 * Items of a kind, keyed by key.
	 *
	 * @param string $kind `post_type` or `taxonomy`.
	 * @return array<string, array<string, mixed>>
	 */
	public function all( string $kind ): array {
		if ( null === $this->items ) {
			$this->items = array(
				'post_type' => array(),
				'taxonomy'  => array(),
			);
			if ( post_type_exists( self::POST_TYPES['post_type'] ) ) {
				foreach ( self::POST_TYPES as $k => $type ) {
					$posts = get_posts(
						array(
							'post_type'      => $type,
							'post_status'    => array( 'publish', GroupStore::DISABLED_STATUS ),
							'posts_per_page' => -1,
							'orderby'        => 'title',
							'order'          => 'ASC',
						)
					);
					foreach ( $posts as $post ) {
						$data = json_decode( $post->post_content, true );
						if ( ! is_array( $data ) ) {
							continue;
						}
						$data['key']    = $post->post_name;
						$data['active'] = 'publish' === $post->post_status;
						$item           = self::normalize( $k, $data );
						// The stored slug is the identity, even if WordPress made it unique.
						$item['key']                           = $post->post_name;
						$this->items[ $k ][ $post->post_name ] = $item;
					}
				}
			}
		}
		return $this->items[ $kind ] ?? array();
	}

	/**
	 * @param string $kind Kind.
	 * @param string $key  Key.
	 * @return array<string, mixed>|null
	 */
	public function get( string $kind, string $key ): ?array {
		return $this->all( $kind )[ $key ] ?? null;
	}

	public function flush(): void {
		$this->items = null;
	}

	/**
	 * Fills defaults.
	 *
	 * @param string               $kind Kind.
	 * @param array<string, mixed> $item Raw settings.
	 * @return array<string, mixed>
	 */
	public static function normalize( string $kind, array $item ): array {
		$common = array(
			'key'          => '',
			'singular'     => '',
			'plural'       => '',
			'description'  => '',
			'public'       => true,
			'hierarchical' => false,
			'show_in_rest' => true,
			'rewrite_slug' => '',
			'active'       => true,
		);
		$extra  = 'post_type' === $kind
			? array(
				'menu_icon'           => 'dashicons-admin-post',
				'menu_position'       => null,
				'supports'            => array( 'title', 'editor', 'thumbnail' ),
				'taxonomies'          => array(),
				'has_archive'         => false,
				'exclude_from_search' => false,
			)
			: array(
				'object_type'       => array( 'post' ),
				'show_admin_column' => true,
			);
		$item   = wp_parse_args( $item, $common + $extra );
		foreach ( array( 'public', 'hierarchical', 'show_in_rest', 'active', 'has_archive', 'exclude_from_search', 'show_admin_column' ) as $flag ) {
			if ( array_key_exists( $flag, $item ) ) {
				$item[ $flag ] = (bool) $item[ $flag ];
			}
		}
		$item['singular'] = '' !== (string) $item['singular'] ? (string) $item['singular'] : (string) $item['key'];
		$item['plural']   = '' !== (string) $item['plural'] ? (string) $item['plural'] : $item['singular'];
		return $item;
	}

	/**
	 * Cleans an item from the builder.
	 *
	 * @param string               $kind     Kind.
	 * @param array<string, mixed> $input    Raw item.
	 * @param string               $original Key of the item being edited ('' for new).
	 * @return array<string, mixed>|\WP_Error
	 */
	public function sanitize( string $kind, array $input, string $original = '' ) {
		$errors = array();
		$max    = 'post_type' === $kind ? 20 : 32;
		$key    = sanitize_key( (string) ( $input['key'] ?? '' ) );
		if ( '' === $key || strlen( $key ) > $max ) {
			$errors[] = array(
				'path'    => 'key',
				/* translators: %d: maximum length. */
				'message' => sprintf( __( 'Key is required: up to %d lowercase letters, digits, _ or -.', 'freeform-fields' ), $max ),
			);
		} elseif ( in_array( $key, self::RESERVED, true ) ) {
			$errors[] = array(
				'path'    => 'key',
				'message' => __( 'This key is reserved by WordPress.', 'freeform-fields' ),
			);
		} elseif ( $key !== $original && ( null !== $this->get( 'post_type', $key ) || null !== $this->get( 'taxonomy', $key ) || ( 'post_type' === $kind ? post_type_exists( $key ) : taxonomy_exists( $key ) ) ) ) {
			$errors[] = array(
				'path'    => 'key',
				'message' => __( 'This key is already used.', 'freeform-fields' ),
			);
		}
		foreach ( array( 'singular', 'plural' ) as $label ) {
			if ( '' === trim( (string) ( $input[ $label ] ?? '' ) ) ) {
				$errors[] = array(
					'path'    => $label,
					'message' => __( 'Label is required.', 'freeform-fields' ),
				);
			}
		}
		if ( array() !== $errors ) {
			return new \WP_Error(
				'wpff_invalid_content_type',
				$errors[0]['message'],
				array(
					'status' => 400,
					'errors' => $errors,
				)
			);
		}

		$item = array(
			'key'          => $key,
			'singular'     => sanitize_text_field( (string) $input['singular'] ),
			'plural'       => sanitize_text_field( (string) $input['plural'] ),
			'description'  => sanitize_textarea_field( (string) ( $input['description'] ?? '' ) ),
			'public'       => ! empty( $input['public'] ),
			'hierarchical' => ! empty( $input['hierarchical'] ),
			'show_in_rest' => ! empty( $input['show_in_rest'] ),
			'rewrite_slug' => sanitize_title( (string) ( $input['rewrite_slug'] ?? '' ) ),
			'active'       => ! isset( $input['active'] ) || ! empty( $input['active'] ),
		);
		if ( 'post_type' === $kind ) {
			$icon  = (string) ( $input['menu_icon'] ?? '' );
			$item += array(
				'menu_icon'           => preg_match( '/^dashicons-[a-z0-9\-]+$/', $icon ) ? $icon : 'dashicons-admin-post',
				'menu_position'       => is_numeric( $input['menu_position'] ?? null ) ? (int) $input['menu_position'] : null,
				'supports'            => array_values( array_intersect( (array) ( $input['supports'] ?? array() ), self::SUPPORTS ) ),
				'taxonomies'          => array_values( array_filter( array_map( 'sanitize_key', (array) ( $input['taxonomies'] ?? array() ) ) ) ),
				'has_archive'         => ! empty( $input['has_archive'] ),
				'exclude_from_search' => ! empty( $input['exclude_from_search'] ),
			);
		} else {
			$item += array(
				'object_type'       => array_values( array_filter( array_map( 'sanitize_key', (array) ( $input['object_type'] ?? array() ) ) ) ),
				'show_admin_column' => ! empty( $input['show_admin_column'] ),
			);
		}
		return $item;
	}

	/**
	 * Creates or updates an item.
	 *
	 * @param string               $kind     Kind.
	 * @param array<string, mixed> $input    Raw item.
	 * @param string               $original Key being edited ('' for new).
	 * @return array<string, mixed>|\WP_Error
	 */
	public function save( string $kind, array $input, string $original = '' ) {
		if ( ! isset( self::POST_TYPES[ $kind ] ) ) {
			return new \WP_Error( 'wpff_invalid_kind', 'Unknown kind.', array( 'status' => 400 ) );
		}
		$item = $this->sanitize( $kind, $input, $original );
		if ( is_wp_error( $item ) ) {
			return $item;
		}
		$post    = '' !== $original ? $this->find_post( $kind, $original ) : null;
		$content = $item;
		unset( $content['key'], $content['active'] );
		$postarr = array(
			'post_type'    => self::POST_TYPES[ $kind ],
			'post_status'  => $item['active'] ? 'publish' : GroupStore::DISABLED_STATUS,
			'post_title'   => $item['plural'],
			'post_name'    => $item['key'],
			'post_content' => (string) wp_json_encode( $content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
		);
		if ( $post ) {
			$postarr['ID'] = $post->ID;
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
		update_option( 'wpff_flush_rewrite_rules', 1, true );
		$this->flush();
		return $this->get( $kind, (string) get_post_field( 'post_name', $id ) ) ?? $item;
	}

	/**
	 * Deletes an item. Content of that type stays in the database.
	 *
	 * @param string $kind Kind.
	 * @param string $key  Key.
	 */
	public function delete( string $kind, string $key ): bool {
		$post = $this->find_post( $kind, $key );
		if ( ! $post ) {
			return false;
		}
		wp_delete_post( $post->ID, true );
		update_option( 'wpff_flush_rewrite_rules', 1, true );
		$this->flush();
		return true;
	}

	/**
	 * @param string $kind Kind.
	 * @param string $key  Key.
	 */
	public function find_post( string $kind, string $key ): ?\WP_Post {
		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPES[ $kind ] ?? '',
				'post_status'    => array( 'publish', GroupStore::DISABLED_STATUS ),
				'name'           => $key,
				'posts_per_page' => 1,
			)
		);
		return $posts[0] ?? null;
	}

	/**
	 * register_post_type() arguments.
	 *
	 * @param array<string, mixed> $type Item.
	 * @return array<string, mixed>
	 */
	public static function post_type_args( array $type ): array {
		$s = $type['singular'];
		$p = $type['plural'];
		return array(
			'labels'              => array(
				'name'               => $p,
				'singular_name'      => $s,
				'menu_name'          => $p,
				'all_items'          => $p,
				/* translators: %s: singular label. */
				'add_new_item'       => sprintf( __( 'Add New %s', 'freeform-fields' ), $s ),
				/* translators: %s: singular label. */
				'edit_item'          => sprintf( __( 'Edit %s', 'freeform-fields' ), $s ),
				/* translators: %s: singular label. */
				'new_item'           => sprintf( __( 'New %s', 'freeform-fields' ), $s ),
				/* translators: %s: singular label. */
				'view_item'          => sprintf( __( 'View %s', 'freeform-fields' ), $s ),
				/* translators: %s: plural label. */
				'search_items'       => sprintf( __( 'Search %s', 'freeform-fields' ), $p ),
				/* translators: %s: plural label. */
				'not_found'          => sprintf( __( 'No %s found', 'freeform-fields' ), $p ),
				/* translators: %s: plural label. */
				'not_found_in_trash' => sprintf( __( 'No %s found in Trash', 'freeform-fields' ), $p ),
			),
			'description'         => $type['description'],
			'public'              => $type['public'],
			'hierarchical'        => $type['hierarchical'],
			'show_in_rest'        => $type['show_in_rest'],
			'menu_icon'           => $type['menu_icon'],
			'menu_position'       => $type['menu_position'],
			'supports'            => array() !== $type['supports'] ? $type['supports'] : false,
			'taxonomies'          => $type['taxonomies'],
			'has_archive'         => $type['has_archive'],
			'exclude_from_search' => $type['exclude_from_search'],
			'rewrite'             => '' !== $type['rewrite_slug'] ? array( 'slug' => $type['rewrite_slug'] ) : true,
		);
	}

	/**
	 * register_taxonomy() arguments.
	 *
	 * @param array<string, mixed> $tax Item.
	 * @return array<string, mixed>
	 */
	public static function taxonomy_args( array $tax ): array {
		$s = $tax['singular'];
		$p = $tax['plural'];
		return array(
			'labels'            => array(
				'name'          => $p,
				'singular_name' => $s,
				'menu_name'     => $p,
				'all_items'     => $p,
				/* translators: %s: singular label. */
				'add_new_item'  => sprintf( __( 'Add New %s', 'freeform-fields' ), $s ),
				/* translators: %s: singular label. */
				'edit_item'     => sprintf( __( 'Edit %s', 'freeform-fields' ), $s ),
				/* translators: %s: plural label. */
				'search_items'  => sprintf( __( 'Search %s', 'freeform-fields' ), $p ),
				/* translators: %s: plural label. */
				'not_found'     => sprintf( __( 'No %s found', 'freeform-fields' ), $p ),
			),
			'description'       => $tax['description'],
			'public'            => $tax['public'],
			'hierarchical'      => $tax['hierarchical'],
			'show_in_rest'      => $tax['show_in_rest'],
			'show_admin_column' => $tax['show_admin_column'],
			'rewrite'           => '' !== $tax['rewrite_slug'] ? array( 'slug' => $tax['rewrite_slug'] ) : true,
		);
	}
}
