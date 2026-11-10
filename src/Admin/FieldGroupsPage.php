<?php
/**
 * Admin screens for the field group builder.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Admin;

use FreeformFields\Options\OptionsPageStore;
use FreeformFields\Plugin;
use FreeformFields\Rest\FieldGroupsController;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the "Custom Fields" menu and mounts the React builder app.
 */
final class FieldGroupsPage {

	public const SLUG         = 'freeform-fields';
	public const NEW_SLUG     = 'wpff-new';
	public const OPTIONS_SLUG = 'wpff-options';
	public const TOOLS_SLUG   = 'wpff-tools';
	public const TYPES_SLUG   = 'wpff-post-types';
	public const TAX_SLUG     = 'wpff-taxonomies';
	private const HANDLE      = 'wpff-builder';

	/**
	 * Hook suffixes of our screens.
	 *
	 * @var string[]
	 */
	private array $hooks = array();

	public function __construct( private Plugin $plugin ) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'admin_body_class', array( $this, 'body_class' ) );
	}

	public function add_menu(): void {
		$cap = Plugin::admin_capability();

		$this->hooks[] = (string) add_menu_page(
			__( 'Field Groups', 'freeform-fields' ),
			__( 'Custom Fields', 'freeform-fields' ),
			$cap,
			self::SLUG,
			array( $this, 'render' ),
			self::menu_icon(),
			80
		);
		$this->hooks[] = (string) add_submenu_page( self::SLUG, __( 'Field Groups', 'freeform-fields' ), __( 'Field Groups', 'freeform-fields' ), $cap, self::SLUG, array( $this, 'render' ) );
		$this->hooks[] = (string) add_submenu_page( self::SLUG, __( 'Add New Field Group', 'freeform-fields' ), __( 'Add New', 'freeform-fields' ), $cap, self::NEW_SLUG, array( $this, 'render' ) );
		$this->hooks[] = (string) add_submenu_page( self::SLUG, __( 'Options Pages', 'freeform-fields' ), __( 'Options Pages', 'freeform-fields' ), $cap, self::OPTIONS_SLUG, array( $this, 'render' ) );
		$this->hooks[] = (string) add_submenu_page( self::SLUG, __( 'Post Types', 'freeform-fields' ), __( 'Post Types', 'freeform-fields' ), $cap, self::TYPES_SLUG, array( $this, 'render' ) );
		$this->hooks[] = (string) add_submenu_page( self::SLUG, __( 'Taxonomies', 'freeform-fields' ), __( 'Taxonomies', 'freeform-fields' ), $cap, self::TAX_SLUG, array( $this, 'render' ) );
		$this->hooks[] = (string) add_submenu_page( self::SLUG, __( 'Tools', 'freeform-fields' ), __( 'Tools', 'freeform-fields' ), $cap, self::TOOLS_SLUG, array( $this, 'render' ) );
	}

	/**
	 * Which screen of the app to show.
	 *
	 * @param string $page    Admin page slug.
	 * @param string $group   Group key from the URL.
	 * @param string $options Options page slug from the URL (`new` for a new page).
	 */
	private static function route( string $page, string $group, string $options ): string {
		if ( self::TOOLS_SLUG === $page ) {
			return 'tools';
		}
		if ( self::TYPES_SLUG === $page || self::TAX_SLUG === $page ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
			return isset( $_GET['item'] ) ? 'content-edit' : 'content';
		}
		if ( self::OPTIONS_SLUG === $page ) {
			return '' === $options ? 'options' : 'options-edit';
		}
		if ( self::NEW_SLUG === $page ) {
			return 'new';
		}
		return '' !== $group ? 'edit' : 'list';
	}

	/**
	 * Choices for the options page editor.
	 *
	 * @return array<string, mixed>
	 */
	private function options_builder_data(): array {
		$parents = array( '' => __( '— Top level menu —', 'freeform-fields' ) );
		foreach ( OptionsPageStore::CORE_PARENTS as $slug ) {
			$parents[ $slug ] = self::core_menu_title( $slug );
		}
		foreach ( OptionsPageStore::post_type_parents() as $slug => $label ) {
			$parents[ $slug ] = $label;
		}
		foreach ( $this->plugin->options_pages()->all( true ) as $slug => $page ) {
			if ( '' === $page['parent_slug'] ) {
				/* translators: %s: options page title. */
				$parents[ $slug ] = sprintf( __( 'Options page: %s', 'freeform-fields' ), $page['page_title'] );
			}
		}
		return array(
			'parents'      => $parents,
			'capabilities' => array(
				'edit_posts'         => __( 'Editors, authors and contributors (edit_posts)', 'freeform-fields' ),
				'publish_posts'      => __( 'Editors and authors (publish_posts)', 'freeform-fields' ),
				'edit_pages'         => __( 'Editors (edit_pages)', 'freeform-fields' ),
				'edit_theme_options' => __( 'Administrators: appearance (edit_theme_options)', 'freeform-fields' ),
				'manage_options'     => __( 'Administrators (manage_options)', 'freeform-fields' ),
			),
		);
	}

	/**
	 * Data for the post type / taxonomy screens.
	 *
	 * @param string $page Admin page slug.
	 * @return array<string, mixed>
	 */
	private function content_builder_data( string $page ): array {
		$post_types = array();
		foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $type ) {
			if ( ! str_starts_with( $type->name, 'wpff-' ) && ! in_array( $type->name, array( 'wp_block', 'wp_navigation', 'attachment' ), true ) ) {
				$post_types[ $type->name ] = $type->labels->singular_name;
			}
		}
		$taxonomies = array();
		foreach ( get_taxonomies( array( 'show_ui' => true ), 'objects' ) as $tax ) {
			if ( ! in_array( $tax->name, array( 'post_format', 'link_category', 'wp_pattern_category' ), true ) ) {
				$taxonomies[ $tax->name ] = $tax->labels->singular_name;
			}
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		$item = isset( $_GET['item'] ) ? sanitize_key( wp_unslash( $_GET['item'] ) ) : '';
		return array(
			'kind'       => self::TAX_SLUG === $page ? 'taxonomy' : 'post_type',
			'item'       => $item,
			'listUrl'    => admin_url( 'admin.php?page=' . ( self::TAX_SLUG === $page ? self::TAX_SLUG : self::TYPES_SLUG ) ),
			'postTypes'  => $post_types,
			'taxonomies' => $taxonomies,
			'supports'   => array(
				'title'           => __( 'Title', 'freeform-fields' ),
				'editor'          => __( 'Editor', 'freeform-fields' ),
				'thumbnail'       => __( 'Featured Image', 'freeform-fields' ),
				'excerpt'         => __( 'Excerpt', 'freeform-fields' ),
				'custom-fields'   => __( 'Custom Fields (core)', 'freeform-fields' ),
				'comments'        => __( 'Comments', 'freeform-fields' ),
				'revisions'       => __( 'Revisions', 'freeform-fields' ),
				'author'          => __( 'Author', 'freeform-fields' ),
				'page-attributes' => __( 'Page Attributes (order, parent)', 'freeform-fields' ),
			),
		);
	}

	/**
	 * Readable name of a core admin menu.
	 *
	 * @param string $slug Menu file.
	 */
	private static function core_menu_title( string $slug ): string {
		$titles = array(
			'index.php'               => __( 'Dashboard', 'freeform-fields' ),
			'edit.php'                => __( 'Posts', 'freeform-fields' ),
			'upload.php'              => __( 'Media', 'freeform-fields' ),
			'edit.php?post_type=page' => __( 'Pages', 'freeform-fields' ),
			'themes.php'              => __( 'Appearance', 'freeform-fields' ),
			'plugins.php'             => __( 'Plugins', 'freeform-fields' ),
			'users.php'               => __( 'Users', 'freeform-fields' ),
			'tools.php'               => __( 'Tools', 'freeform-fields' ),
			'options-general.php'     => __( 'Settings', 'freeform-fields' ),
		);
		return $titles[ $slug ] ?? $slug;
	}

	/**
	 * Marks the plugin's screens so their styles stay scoped.
	 *
	 * @param string $classes Body classes.
	 */
	public function body_class( $classes ): string {
		$screen = get_current_screen();
		return $screen && in_array( $screen->id, $this->hooks, true ) ? $classes . ' wpff-app' : (string) $classes;
	}

	public function render(): void {
		echo '<div class="wrap wpff-builder-wrap"><div id="wpff-builder"><p>' . esc_html__( 'Loading…', 'freeform-fields' ) . '</p></div></div>';
	}

	/**
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue( $hook ): void {
		if ( ! in_array( $hook, $this->hooks, true ) ) {
			return;
		}

		$asset_file = WPFF_PATH . 'build/builder.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'The field group builder is not built. Run "npm install && npm run build" in the plugin folder.', 'freeform-fields' ) . '</p></div>';
				}
			);
			return;
		}
		$asset = require $asset_file;

		wp_enqueue_script( self::HANDLE, WPFF_URL . 'build/builder.js', $asset['dependencies'], $asset['version'], array( 'in_footer' => true ) );
		wp_enqueue_style( self::HANDLE, WPFF_URL . 'build/style-builder.css', array( 'wp-components' ), $asset['version'] );
		wp_set_script_translations( self::HANDLE, 'freeform-fields', WPFF_PATH . 'languages' );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only routing.
		$page    = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : self::SLUG;
		$group   = isset( $_GET['group'] ) ? sanitize_key( wp_unslash( $_GET['group'] ) ) : '';
		$options = isset( $_GET['options_page'] ) ? sanitize_title( wp_unslash( $_GET['options_page'] ) ) : '';
		// phpcs:enable

		$data = new BuilderData( $this->plugin );
		$init = array(
			'route'      => self::route( $page, $group, $options ),
			'groupKey'   => $group,
			'optionsKey' => $options,
			'optionsUrl' => admin_url( 'admin.php?page=' . self::OPTIONS_SLUG ),
			'options'    => $this->options_builder_data(),
			'content'    => $this->content_builder_data( $page ),
			'localJson'  => array(
				'savePath'  => $this->plugin->local_json()->save_path(),
				'loadPaths' => $this->plugin->local_json()->load_paths(),
				'suggested' => get_stylesheet_directory() . '/wpff-json',
			),
			'listUrl'    => admin_url( 'admin.php?page=' . self::SLUG ),
			'newUrl'     => admin_url( 'admin.php?page=' . self::NEW_SLUG ),
			'restPath'   => '/' . FieldGroupsController::NAMESPACE,
			'fieldTypes' => $data->field_types(),
			'location'   => $data->location_params(),
		);
		wp_add_inline_script( self::HANDLE, 'window.wpffBuilder = ' . wp_json_encode( $init ) . ';', 'before' );
	}

	/**
	 * Menu icon as an SVG data URI; WordPress recolors it to match the admin color scheme.
	 */
	private static function menu_icon(): string {
		$svg = (string) file_get_contents( WPFF_PATH . 'assets/images/menu-icon.svg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- data URI.
	}
}
