<?php
/**
 * Options page screens.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Admin;

use FreeformFields\Plugin;
use FreeformFields\Storage\ObjectRef;

defined( 'ABSPATH' ) || exit;

/**
 * Adds menu entries for options pages, renders their field groups and saves values.
 *
 * Location rule: `options_page` == {menu slug}. Values are stored for the page's `post_id`
 * (`options` by default, i.e. `options_{name}` in wp_options, like ACF).
 */
final class OptionsScreen {

	/**
	 * Page slug by admin hook suffix.
	 *
	 * @var array<string, string>
	 */
	private array $hooks = array();

	private FormSupport $form;

	public function __construct( private Plugin $plugin ) {
		$this->form = new FormSupport( $plugin );
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menus' ), 99 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Location screen for a page.
	 *
	 * @param string $slug Menu slug.
	 * @return array<string, string>
	 */
	public static function screen( string $slug ): array {
		return array( 'options_page' => $slug );
	}

	public function add_menus(): void {
		$pages = $this->plugin->options_pages()->all();
		// While ACF is active, pages it registers itself (e.g. right after migration) stay ACF's.
		if ( Plugin::acf_active() && function_exists( 'acf_get_options_page' ) ) {
			$pages = array_filter( $pages, static fn( $page ) => ! acf_get_options_page( $page['menu_slug'] ) );
		}

		// Top-level pages first, so sub pages can attach to them.
		uasort( $pages, static fn( $a, $b ) => ( '' === $a['parent_slug'] ? 0 : 1 ) <=> ( '' === $b['parent_slug'] ? 0 : 1 ) );

		$menu_slug_for = array();
		foreach ( $pages as $slug => $page ) {
			if ( '' !== $page['parent_slug'] ) {
				continue;
			}
			$children = $this->plugin->options_pages()->children( $slug );
			// With `redirect`, the top-level entry opens the first sub page, like ACF.
			$target = $page;
			if ( $page['redirect'] && array() !== $children ) {
				$target = reset( $children );
			}
			$menu_slug_for[ $slug ] = $target['menu_slug'];

			$hook = add_menu_page(
				$target['page_title'],
				$page['menu_title'],
				$target['capability'],
				$target['menu_slug'],
				fn() => $this->render( $target['menu_slug'] ),
				'' !== $page['icon_url'] ? $page['icon_url'] : 'dashicons-admin-generic',
				$page['position']
			);
			$this->remember( $hook, $target['menu_slug'] );
		}

		foreach ( $pages as $slug => $page ) {
			if ( '' === $page['parent_slug'] ) {
				continue;
			}
			$parent = $menu_slug_for[ $page['parent_slug'] ] ?? $page['parent_slug'];
			// The sub page the top-level entry opens shares its hook: it is already rendered and handled.
			$is_target = $parent === $slug;
			$hook      = add_submenu_page(
				$parent,
				$page['page_title'],
				$page['menu_title'],
				$page['capability'],
				$slug,
				$is_target ? '' : fn() => $this->render( $slug ),
				null === $page['position'] ? null : (int) $page['position']
			);
			if ( ! $is_target ) {
				$this->remember( $hook, $slug );
			}
		}
	}

	/**
	 * @param string|false $hook Hook suffix.
	 * @param string       $slug Page slug.
	 */
	private function remember( $hook, string $slug ): void {
		if ( ! $hook ) {
			return;
		}
		$this->hooks[ $hook ] = $slug;
		add_action( "load-{$hook}", fn() => $this->handle_post( $slug ) );
	}

	/**
	 * Saves submitted values, then redirects back (post/redirect/get).
	 *
	 * @param string $slug Page slug.
	 */
	public function handle_post( string $slug ): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return;
		}
		$page = $this->plugin->options_pages()->get( $slug );
		if ( ! $page || ! current_user_can( $page['capability'] ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to edit these options.', 'freeform-fields' ), 403 );
		}
		$ref = ObjectRef::parse( $page['post_id'] );
		if ( ! $ref ) {
			return;
		}
		$errors = $this->form->save_request( self::screen( $slug ), $ref );
		if ( null === $errors ) {
			// Missing or invalid nonce.
			wp_die( esc_html__( 'The link you followed has expired. Please reload the page and try again.', 'freeform-fields' ), 403 );
		}
		if ( array() !== $errors ) {
			set_transient( FormSupport::errors_transient( 'options_' . $slug ), $errors, MINUTE_IN_SECONDS * 5 );
		}
		wp_safe_redirect( add_query_arg( 'wpff-updated', array() === $errors ? '1' : '0', menu_page_url( $slug, false ) ) );
		exit;
	}

	/**
	 * Prints the page.
	 *
	 * @param string $slug Page slug.
	 */
	public function render( string $slug ): void {
		$page = $this->plugin->options_pages()->get( $slug );
		if ( ! $page ) {
			return;
		}
		$groups = $this->form->groups_for( self::screen( $slug ) );
		$ref    = ObjectRef::parse( $page['post_id'] );

		echo '<div class="wrap wpff-options-wrap">';
		echo '<h1>' . esc_html( $page['page_title'] ) . '</h1>';
		if ( '' !== $page['description'] ) {
			echo '<p class="description">' . esc_html( $page['description'] ) . '</p>';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( isset( $_GET['wpff-updated'] ) && '1' === $_GET['wpff-updated'] ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( '' !== $page['updated_message'] ? $page['updated_message'] : __( 'Options Updated', 'freeform-fields' ) ) . '</p></div>';
		}
		PostScreen::print_saved_errors( 'options_' . $slug );

		if ( array() === $groups ) {
			printf(
				'<div class="notice notice-info inline"><p>%s</p></div></div>',
				esc_html__( 'No field groups are assigned to this options page yet. Create a field group with the location rule "Options Page is equal to" this page.', 'freeform-fields' )
			);
			return;
		}

		echo '<form id="wpff-options-form" method="post" novalidate>';
		FormSupport::nonce_field();
		echo '<div id="poststuff"><div id="post-body" class="metabox-holder columns-2">';

		echo '<div id="postbox-container-1" class="postbox-container">';
		echo '<div class="postbox wpff-options-publish"><div class="postbox-header"><h2>' . esc_html__( 'Publish', 'freeform-fields' ) . '</h2></div><div class="inside">';
		printf(
			'<div id="publishing-action"><span class="spinner"></span><input type="submit" id="publish" class="button button-primary button-large" value="%s" /></div><div class="clear"></div>',
			esc_attr( '' !== $page['update_button'] ? $page['update_button'] : __( 'Update', 'freeform-fields' ) )
		);
		echo '</div></div>';
		$this->render_boxes( $groups, $ref, 'side' );
		echo '</div>';

		echo '<div id="postbox-container-2" class="postbox-container">';
		$this->render_boxes( $groups, $ref, 'normal' );
		echo '</div>';

		echo '</div><br class="clear" /></div></form></div>';
	}

	/**
	 * Prints groups of one column as boxes.
	 *
	 * @param array<string, array<string, mixed>> $groups Groups.
	 * @param ObjectRef|null                      $ref    Where values are stored.
	 * @param string                              $column `side` or `normal`.
	 */
	private function render_boxes( array $groups, ?ObjectRef $ref, string $column ): void {
		$renderer = new FieldRenderer( $this->plugin->types(), $this->plugin->values() );
		foreach ( $groups as $group ) {
			$in_side = 'side' === $group['position'];
			if ( ( 'side' === $column ) !== $in_side ) {
				continue;
			}
			if ( 'seamless' === $group['style'] ) {
				echo '<div class="wpff-seamless-box">';
				$renderer->render_group( $group, $ref );
				echo '</div>';
				continue;
			}
			printf(
				'<div class="postbox" id="wpff-%s"><div class="postbox-header"><h2 class="hndle">%s</h2></div><div class="inside">',
				esc_attr( $group['key'] ),
				esc_html( $group['title'] )
			);
			$renderer->render_group( $group, $ref );
			echo '</div></div>';
		}
	}

	/**
	 * @param string $hook Admin page hook.
	 */
	public function enqueue( $hook ): void {
		$slug = $this->hooks[ $hook ] ?? null;
		if ( null === $slug ) {
			return;
		}
		$groups = $this->form->groups_for( self::screen( $slug ) );
		if ( array() === $groups ) {
			return;
		}
		Assets::enqueue_form(
			$this->plugin,
			FormSupport::types_of( $groups ),
			array(
				'object'       => 'options_' . $slug,
				'formSelector' => '#wpff-options-form',
			)
		);
	}
}
