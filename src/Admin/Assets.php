<?php
/**
 * Admin script and style registration.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Admin;

use FreeformFields\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the shared admin assets; screens enqueue them when they render fields.
 */
final class Assets {

	public const HANDLE = 'wpff-admin';

	/**
	 * Whether the form settings were already printed on this page (meta boxes and blocks both ask).
	 *
	 * @var bool
	 */
	private static bool $localized = false;

	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'register_assets' ), 1 );
	}

	public function register_assets(): void {
		wp_register_style( self::HANDLE, WPFF_URL . 'assets/css/admin.css', array( 'dashicons' ), WPFF_VERSION );
		wp_register_script(
			self::HANDLE,
			WPFF_URL . 'assets/js/admin.js',
			// wp-data (and with it React) is only needed by the `wpff/fields` store in the admin;
			// front-end forms work without it.
			is_admin() ? array( 'jquery', 'wp-i18n', 'wp-data' ) : array( 'jquery', 'wp-i18n' ),
			WPFF_VERSION,
			array( 'in_footer' => true )
		);
		wp_set_script_translations( self::HANDLE, 'freeform-fields', WPFF_PATH . 'languages' );
	}

	/**
	 * Enqueues field assets for a form.
	 *
	 * @param Plugin               $plugin  Plugin.
	 * @param string[]             $types   Field types on the screen.
	 * @param array<string, mixed> $context object (validation id), formSelector, postId, blockEditor.
	 */
	public static function enqueue_form( Plugin $plugin, array $types, array $context ): void {
		if ( ! wp_script_is( self::HANDLE, 'registered' ) ) {
			( new self() )->register_assets();
		}
		wp_enqueue_style( self::HANDLE );
		wp_enqueue_script( self::HANDLE );
		if ( ! self::$localized ) {
			self::$localized = true;
			wp_localize_script(
				self::HANDLE,
				'wpffAdmin',
				$context + array(
					'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
					'nonce'        => wp_create_nonce( FormSupport::NONCE_ACTION ),
					'restUrl'      => esc_url_raw( rest_url() ),
					'restNonce'    => wp_create_nonce( 'wp_rest' ),
					'object'       => '',
					'formSelector' => '#post',
					'postId'       => 0,
					'blockEditor'  => false,
				)
			);
		}
		foreach ( $types as $type ) {
			$handler = $plugin->types()->get( $type );
			if ( $handler ) {
				$handler->enqueue_assets();
			}
		}
		if ( ! did_action( 'wpff/input/admin_enqueue_scripts' ) ) {
			// Field scripts are loaded: themes add their own here (ACF: acf/input/admin_enqueue_scripts).
			\FreeformFields\Hooks::action( 'input/admin_enqueue_scripts' );
		}
	}
}
