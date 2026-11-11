<?php
/**
 * Plugin service container and bootstrap.
 *
 * @package FreeformFields
 */

namespace FreeformFields;

use FreeformFields\Admin\Ajax;
use FreeformFields\Admin\FieldGroupsPage;
use FreeformFields\Admin\Assets;
use FreeformFields\Admin\OptionsScreen;
use FreeformFields\Admin\PostScreen;
use FreeformFields\Admin\TermScreen;
use FreeformFields\Admin\UserScreen;
use FreeformFields\Blocks\BlockEditor;
use FreeformFields\Content\ContentTypes;
use FreeformFields\Blocks\BlockRegistry;
use FreeformFields\Fields\FieldTypeRegistry;
use FreeformFields\Location\LocationRules;
use FreeformFields\Options\OptionsPages;
use FreeformFields\Options\OptionsPageStore;
use FreeformFields\Registry\FieldRegistry;
use FreeformFields\Registry\GroupStore;
use FreeformFields\Registry\LocalJson;
use FreeformFields\Rest\BlocksController;
use FreeformFields\Rest\ContentTypesController;
use FreeformFields\Rest\FieldChoicesController;
use FreeformFields\Rest\FieldGroupsController;
use FreeformFields\Rest\OptionsPagesController;
use FreeformFields\Rest\RestFields;
use FreeformFields\Rest\ToolsController;
use FreeformFields\Storage\ValueManager;

defined( 'ABSPATH' ) || exit;

/**
 * Holds the shared services and wires WordPress hooks.
 */
final class Plugin {

	/**
	 * Post type that stores field groups created in the admin.
	 */
	public const GROUP_POST_TYPE = 'wpff-field-group';

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	private FieldTypeRegistry $types;
	private FieldRegistry $fields;
	private LocationRules $locations;
	private ValueManager $values;
	private GroupStore $groups;
	private OptionsPages $options_pages;
	private OptionsPageStore $options_store;
	private BlockRegistry $blocks;
	private LocalJson $local_json;
	private ContentTypes $content_types;
	private \FreeformFields\Multilingual\Multilingual $multilingual;
	private bool $booted = false;

	/**
	 * Returns the shared instance.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->types     = new FieldTypeRegistry();
		$this->fields    = new FieldRegistry( $this->types );
		$this->locations = new LocationRules();
		$this->values    = new ValueManager( $this->fields, $this->types );
		$this->groups    = new GroupStore( $this->fields, $this->types, $this->locations );

		$this->options_pages = new OptionsPages();
		$this->options_store = new OptionsPageStore( $this->options_pages );
		$this->blocks        = new BlockRegistry( $this );
		$this->local_json    = new LocalJson();
		$this->content_types = new ContentTypes();
		$this->multilingual  = new \FreeformFields\Multilingual\Multilingual( $this );
	}

	/**
	 * Registers hooks. Safe to call more than once.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		// setup_theme runs before the theme's functions.php: guarded ACF calls there still find get_field() etc.,
		// and a theme that bundles its own ACF can be detected before it declares the same functions.
		add_action( 'setup_theme', array( $this, 'load_compat_layer' ), 1 );
		add_action( 'init', array( $this, 'init' ), 5 );
		add_filter( 'wpff/options_autoload', array( $this, 'options_autoload' ), 10, 2 );
		$this->blocks->register_hooks();
		\FreeformFields\Registry\AcfStamp::register();
		$this->local_json->register();
		$this->content_types->register();
		$this->multilingual->register();
		( new \FreeformFields\Fields\Bidirectional( $this ) )->register();
		( new RestFields( $this ) )->register();
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'wpff', \FreeformFields\Cli\Command::class );
		}
		( new BlockEditor( $this ) )->register();
		( new \FreeformFields\Blocks\Bindings( $this ) )->register();
		( new \FreeformFields\Integrations\Abilities( $this ) )->register();
		( new \FreeformFields\Integrations\AcfToRestApi( $this ) )->register();
		( new \FreeformFields\Updates\Updater() )->register();
		\FreeformFields\Front\Forms::instance()->register();
		add_filter( 'plugin_action_links_' . plugin_basename( WPFF_FILE ), array( $this, 'action_links' ) );
		add_action(
			'rest_api_init',
			function (): void {
				( new FieldGroupsController( $this ) )->register_routes();
				( new FieldChoicesController( $this ) )->register_routes();
				( new OptionsPagesController( $this ) )->register_routes();
				( new BlocksController( $this ) )->register_routes();
				( new ToolsController( $this ) )->register_routes();
				( new ContentTypesController( $this ) )->register_routes();
			}
		);

		if ( is_admin() ) {
			( new Assets() )->register();
			( new FieldGroupsPage( $this ) )->register();
			( new PostScreen( $this ) )->register();
			( new TermScreen( $this ) )->register();
			( new UserScreen( $this ) )->register();
			( new OptionsScreen( $this ) )->register();
			( new Ajax( $this ) )->register();
			( new \FreeformFields\Admin\AcfNotice() )->register();
		}
	}

	/**
	 * Runs on `init`.
	 */
	public function init(): void {
		load_plugin_textdomain( 'freeform-fields', false, dirname( plugin_basename( WPFF_FILE ) ) . '/languages' );

		register_post_type(
			self::GROUP_POST_TYPE,
			array(
				'labels'          => array(
					'name'          => __( 'Field Groups', 'freeform-fields' ),
					'singular_name' => __( 'Field Group', 'freeform-fields' ),
				),
				'public'          => false,
				'show_ui'         => false,
				'rewrite'         => false,
				'query_var'       => false,
				'supports'        => array( 'title' ),
				'capability_type' => 'post',
				'map_meta_cap'    => true,
				'capabilities'    => array(
					'create_posts' => 'manage_options',
				),
			)
		);

		register_post_type(
			OptionsPages::POST_TYPE,
			array(
				'labels'          => array(
					'name'          => __( 'Options Pages', 'freeform-fields' ),
					'singular_name' => __( 'Options Page', 'freeform-fields' ),
				),
				'public'          => false,
				'show_ui'         => false,
				'rewrite'         => false,
				'query_var'       => false,
				'supports'        => array( 'title' ),
				'capability_type' => 'post',
				'map_meta_cap'    => true,
				'capabilities'    => array(
					'create_posts' => 'manage_options',
				),
			)
		);

		register_post_status(
			GroupStore::DISABLED_STATUS,
			array(
				'label'                     => _x( 'Inactive', 'field group status', 'freeform-fields' ),
				'public'                    => false,
				'internal'                  => true,
				'exclude_from_search'       => true,
				'show_in_admin_all_list'    => false,
				'show_in_admin_status_list' => false,
			)
		);

		/**
		 * Fires when WP Freeform Fields is ready for field group registration.
		 */
		do_action( 'wpff/init' );
		// ACF 6.1+ PHP exports register groups on acf/include_fields.
		Hooks::action( 'include_fields' );

		if ( self::compat_enabled() ) {
			// Themes written for ACF register their groups on this hook.
			do_action( 'acf/init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- ACF compatibility.
		}
	}

	/**
	 * Defines ACF-named functions (get_field() etc.) when ACF itself is absent.
	 */
	public function load_compat_layer(): void {
		if ( self::compat_enabled() && ! self::theme_bundles_acf() && ! self::acf_being_activated() ) {
			require_once WPFF_PATH . 'src/Api/compat-acf.php';
		}
	}

	/**
	 * Whether this request activates ACF. WordPress includes the plugin's file during activation,
	 * after our functions would exist, and ACF would then fail with "Cannot redeclare".
	 */
	public static function acf_being_activated(): bool {
		$candidates = array();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only check; WordPress verifies the activation itself.
		if ( is_admin() && isset( $GLOBALS['pagenow'] ) && 'plugins.php' === $GLOBALS['pagenow'] && isset( $_REQUEST['action'] ) ) {
			$action = sanitize_key( wp_unslash( $_REQUEST['action'] ) );
			if ( 'activate' === $action && isset( $_REQUEST['plugin'] ) ) {
				$candidates[] = sanitize_text_field( wp_unslash( $_REQUEST['plugin'] ) );
			} elseif ( 'activate-selected' === $action && isset( $_REQUEST['checked'] ) ) {
				$candidates = array_map( 'sanitize_text_field', (array) wp_unslash( $_REQUEST['checked'] ) );
			}
		}
		// phpcs:enable
		if ( defined( 'WP_CLI' ) && WP_CLI && isset( $GLOBALS['argv'] ) && in_array( 'activate', (array) $GLOBALS['argv'], true ) ) {
			foreach ( (array) $GLOBALS['argv'] as $arg ) {
				$arg          = (string) $arg;
				$candidates[] = $arg;
				$candidates[] = $arg . '/' . $arg . '.php';
				$candidates[] = $arg . '/acf.php';
			}
		}
		foreach ( $candidates as $file ) {
			if ( '' === $file || 0 !== validate_file( $file ) || ! str_ends_with( $file, '.php' ) ) {
				continue;
			}
			$path = WP_PLUGIN_DIR . '/' . $file;
			if ( is_readable( $path ) && preg_match( '/Plugin Name:\s*(Advanced Custom Fields|Secure Custom Fields)/i', (string) file_get_contents( $path, false, null, 0, 8192 ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- plugin header.
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the active theme ships its own copy of ACF (it would declare get_field() too).
	 *
	 * The theme folders are scanned a few levels deep for ACF's main file; the answer is cached
	 * per theme version. Filter: `wpff/theme_bundles_acf`.
	 */
	public static function theme_bundles_acf(): bool {
		$dirs  = array_unique( array( get_stylesheet_directory(), get_template_directory() ) );
		$theme = wp_get_theme();
		$cache = 'wpff_theme_acf_' . md5( implode( '|', $dirs ) . $theme->get( 'Version' ) );
		$found = get_transient( $cache );
		if ( false === $found ) {
			$found = 'no';
			foreach ( $dirs as $dir ) {
				if ( self::find_acf( $dir, 4 ) ) {
					$found = 'yes';
					break;
				}
			}
			set_transient( $cache, $found, WEEK_IN_SECONDS );
		}
		/**
		 * Filters whether the active theme bundles ACF; when true, ACF-named functions are left to it.
		 *
		 * @param bool $bundles Detected value.
		 */
		return (bool) apply_filters( 'wpff/theme_bundles_acf', 'yes' === $found );
	}

	/**
	 * @param string $dir   Folder.
	 * @param int    $depth Levels left.
	 */
	private static function find_acf( string $dir, int $depth ): bool {
		if ( $depth < 0 || ! is_dir( $dir ) ) {
			return false;
		}
		$file = $dir . '/acf.php';
		if ( is_readable( $file ) ) {
			$head = (string) file_get_contents( $file, false, null, 0, 2048 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file header.
			if ( str_contains( $head, 'Advanced Custom Fields' ) || str_contains( $head, 'Secure Custom Fields' ) ) {
				return true;
			}
		}
		foreach ( (array) glob( $dir . '/*', GLOB_ONLYDIR ) as $sub ) {
			$name = basename( (string) $sub );
			if ( in_array( $name, array( 'node_modules', '.git', 'assets', 'images', 'fonts', 'css', 'js' ), true ) ) {
				continue;
			}
			if ( self::find_acf( (string) $sub, $depth - 1 ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether ACF, ACF PRO or a fork with the same API (Secure Custom Fields) is loaded.
	 */
	public static function acf_active(): bool {
		/**
		 * Filters whether ACF is considered active (WP Freeform Fields then leaves ACF's files, blocks and names alone).
		 *
		 * @param bool $active Detected value.
		 */
		return (bool) apply_filters( 'wpff/acf_active', class_exists( 'ACF' ) || defined( 'ACF_VERSION' ) );
	}

	/**
	 * Whether the ACF compatibility layer should be active.
	 */
	public static function compat_enabled(): bool {
		if ( self::acf_active() ) {
			return false;
		}
		if ( defined( 'WPFF_ACF_COMPAT' ) && ! WPFF_ACF_COMPAT ) {
			return false;
		}
		/**
		 * Filters whether WP Freeform Fields provides get_field(), the_field() and other ACF function names.
		 *
		 * @param bool $enabled Default true.
		 */
		return (bool) apply_filters( 'wpff/acf_compat', true );
	}

	public function types(): FieldTypeRegistry {
		return $this->types;
	}

	public function fields(): FieldRegistry {
		return $this->fields;
	}

	public function locations(): LocationRules {
		return $this->locations;
	}

	public function values(): ValueManager {
		return $this->values;
	}

	public function groups(): GroupStore {
		return $this->groups;
	}

	public function options_pages(): OptionsPages {
		return $this->options_pages;
	}

	public function options_store(): OptionsPageStore {
		return $this->options_store;
	}

	public function blocks(): BlockRegistry {
		return $this->blocks;
	}

	public function local_json(): LocalJson {
		return $this->local_json;
	}

	public function content_types(): ContentTypes {
		return $this->content_types;
	}

	public function multilingual(): \FreeformFields\Multilingual\Multilingual {
		return $this->multilingual;
	}

	/**
	 * Whether values stored under an options prefix are autoloaded (an options page's `autoload`).
	 *
	 * @param bool   $autoload Default false.
	 * @param string $prefix   Options prefix (the page's post_id), e.g. `options`.
	 */
	public function options_autoload( $autoload, $prefix ): bool {
		foreach ( $this->options_pages->all() as $page ) {
			$ref = \FreeformFields\Storage\ObjectRef::parse( $page['post_id'] );
			if ( $page['autoload'] && $ref && 'option' === $ref->type && (string) $ref->id === (string) $prefix ) {
				return true;
			}
		}
		return (bool) $autoload;
	}

	/**
	 * Capability required to manage field groups.
	 */
	public static function admin_capability(): string {
		/**
		 * Filters the capability required to manage field groups.
		 *
		 * @param string $capability Default manage_options.
		 */
		return (string) apply_filters( 'wpff/capability', 'manage_options' );
	}

	/**
	 * Adds a "Field Groups" link to the plugin's row on the Plugins screen.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public function action_links( $links ): array {
		if ( current_user_can( self::admin_capability() ) ) {
			array_unshift(
				$links,
				sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=' . \FreeformFields\Admin\FieldGroupsPage::SLUG ) ), esc_html__( 'Field Groups', 'freeform-fields' ) )
			);
		}
		return (array) $links;
	}
}
