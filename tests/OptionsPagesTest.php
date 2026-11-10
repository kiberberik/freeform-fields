<?php
/**
 * Options pages: registration, screens, storage and REST.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Tests;

use FreeformFields\Admin\FormSupport;
use FreeformFields\Admin\OptionsScreen;
use FreeformFields\Options\OptionsPages;
use FreeformFields\Storage\ObjectRef;

final class OptionsPagesTest extends TestCase {

	/** @var string[] */
	private array $local_pages = array();

	protected function tearDown(): void {
		foreach ( $this->local_pages as $slug ) {
			$this->plugin()->options_pages()->remove_local( $slug );
		}
		foreach ( get_posts(
			array(
				'post_type'      => OptionsPages::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		) as $id ) {
			wp_delete_post( $id, true );
		}
		$this->plugin()->options_pages()->flush();
		parent::tearDown();
	}

	private function page( array|string $args ): array {
		$page                = acf_add_options_page( $args );
		$this->local_pages[] = $page['menu_slug'];
		return $page;
	}

	private function rule( string $slug ): array {
		return array(
			array(
				array(
					'param'    => 'options_page',
					'operator' => '==',
					'value'    => $slug,
				),
			),
		);
	}

	private function rest( string $method, string $route, ?array $body = null ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, '/wpff/v1' . $route );
		if ( null !== $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	public function test_registration_defaults_like_acf(): void {
		$parent = $this->page( 'Theme Settings' );
		$this->assertSame( 'acf-options-theme-settings', $parent['menu_slug'] );
		$this->assertSame( 'edit_posts', $parent['capability'] );
		$this->assertSame( 'options', $parent['post_id'] );
		$this->assertTrue( $parent['redirect'] );
		$this->assertFalse( $parent['autoload'] );
		$this->assertSame( 'php', $parent['source'], 'Pages from code are marked read-only.' );

		$sub                 = acf_add_options_sub_page( 'Footer' );
		$this->local_pages[] = $sub['menu_slug'];
		$this->assertSame( 'acf-options-theme-settings', $sub['parent_slug'], 'A sub page attaches to the last parent.' );
		$this->assertSame( array( 'acf-options-footer' ), array_keys( $this->plugin()->options_pages()->children( 'acf-options-theme-settings' ) ) );
	}

	public function test_values_render_and_save_for_page_post_id(): void {
		$this->page(
			array(
				'page_title'    => 'Site',
				'menu_slug'     => 'site-settings',
				'post_id'       => 'site_settings',
				'update_button' => 'Save settings',
			)
		);
		$this->make_group(
			array(
				array(
					'key'      => 'field_phone',
					'name'     => 'phone',
					'required' => 1,
				),
			),
			array( 'location' => $this->rule( 'site-settings' ) )
		);

		$form = new FormSupport( $this->plugin() );
		$this->assertCount( 1, $form->groups_for( OptionsScreen::screen( 'site-settings' ) ) );
		$this->assertCount( 0, $form->groups_for( array( 'post_id' => $this->make_post() ) ), 'Options rules never match posts.' );

		$_POST  = array(
			FormSupport::NONCE_NAME => wp_create_nonce( FormSupport::NONCE_ACTION ),
			'wpff'                  => array( 'field_phone' => '+7 700' ),
		);
		$errors = $form->save_request( OptionsScreen::screen( 'site-settings' ), ObjectRef::parse( 'site_settings' ) );
		$this->assertSame( array(), $errors );
		$this->assertSame( '+7 700', get_option( 'site_settings_phone' ) );
		$this->assertSame( '+7 700', get_field( 'phone', 'site_settings' ) );

		( new \ReflectionProperty( FormSupport::class, 'nonce_printed' ) )->setValue( null, false );
		ob_start();
		( new OptionsScreen( $this->plugin() ) )->render( 'site-settings' );
		$html = ob_get_clean();
		$this->assertStringContainsString( '<form id="wpff-options-form" method="post"', $html );
		$this->assertStringContainsString( 'name="wpff_nonce"', $html );
		$this->assertStringContainsString( 'value="+7 700"', $html );
		$this->assertStringContainsString( 'value="Save settings"', $html );

		delete_option( 'site_settings_phone' );
		delete_option( '_site_settings_phone' );
	}

	public function test_autoload_follows_page_setting(): void {
		global $wpdb;
		$this->page(
			array(
				'page_title' => 'Fast',
				'post_id'    => 'fast',
				'autoload'   => true,
			)
		);
		$this->make_group( array( array( 'name' => 'banner' ) ), array( 'location' => $this->rule( 'acf-options-fast' ) ) );

		wpff_update_field( 'banner', 'on', 'fast' );
		wpff_update_field( 'banner', 'off', 'options' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$autoload = $wpdb->get_col( "SELECT autoload FROM {$wpdb->options} WHERE option_name IN ('fast_banner','options_banner') ORDER BY option_name" );
		$this->assertTrue( wp_autoload_values_to_autoload() !== array() );
		$this->assertContains( $autoload[0], wp_autoload_values_to_autoload(), 'fast_banner is autoloaded.' );
		$this->assertNotContains( $autoload[1], wp_autoload_values_to_autoload(), 'options_banner is not.' );

		foreach ( array( 'fast_banner', '_fast_banner', 'options_banner', '_options_banner' ) as $option ) {
			delete_option( $option );
		}
	}

	public function test_menu_redirect_to_first_sub_page(): void {
		global $menu, $submenu;
		$menu    = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		$submenu = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		$this->page(
			array(
				'page_title' => 'Shop',
				'menu_slug'  => 'shop',
			)
		);
		$child               = acf_add_options_sub_page(
			array(
				'page_title' => 'Shipping',
				'menu_slug'  => 'shop-shipping',
			)
		);
		$this->local_pages[] = $child['menu_slug'];

		( new OptionsScreen( $this->plugin() ) )->add_menus();
		$top = array_values( array_filter( $menu, static fn( $item ) => 'Shop' === $item[0] ) );
		$this->assertSame( 'shop-shipping', $top[0][2], 'The top-level entry opens the first sub page.' );
		$this->assertSame( 'shop-shipping', $submenu['shop-shipping'][0][2] );
	}

	public function test_ajax_object_checks_capability(): void {
		$this->page(
			array(
				'page_title' => 'Admins only',
				'menu_slug'  => 'admins-only',
				'capability' => 'manage_options',
			)
		);
		$this->assertSame( array( 'options_page' => 'admins-only' ), FormSupport::screen_for_object( 'options_admins-only' )['screen'] );
		$this->assertNull( FormSupport::screen_for_object( 'options_missing' ) );

		$editor = wp_insert_user(
			array(
				'user_login' => 'ed' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password(),
				'role'       => 'editor',
			)
		);
		wp_set_current_user( $editor );
		$this->assertNull( FormSupport::screen_for_object( 'options_admins-only' ) );
		wp_set_current_user( 1 );
		wp_delete_user( $editor );
	}

	public function test_post_type_menu_as_parent(): void {
		register_post_type(
			'book',
			array(
				'show_ui' => true,
				'label'   => 'Books',
			)
		);
		$this->assertArrayHasKey( 'edit.php?post_type=book', \FreeformFields\Options\OptionsPageStore::post_type_parents() );

		$created = $this->rest(
			'POST',
			'/options-pages',
			array(
				'page_title'  => 'Catalog settings',
				'parent_slug' => 'edit.php?post_type=book',
			)
		);
		$this->assertSame( 201, $created->get_status() );
		$this->assertSame( 'edit.php?post_type=book', $created->get_data()['parent_slug'], 'A post type menu is kept as the parent.' );

		// The redirect after saving must keep `&page=` (menu_page_url() returns `&#038;`).
		global $_parent_pages;
		$_parent_pages['catalog-settings'] = 'edit.php?post_type=book'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- what add_submenu_page() sets.
		$url                               = \FreeformFields\Admin\OptionsScreen::page_url( 'catalog-settings' );
		$this->assertStringContainsString( 'edit.php?post_type=book&page=catalog-settings', $url );
		unset( $_parent_pages['catalog-settings'] );
		unregister_post_type( 'book' );
	}

	public function test_rest_crud(): void {
		$bad = $this->rest(
			'POST',
			'/options-pages',
			array(
				'page_title' => '',
				'post_id'    => 'bad id!',
			)
		);
		$this->assertSame( 400, $bad->get_status() );
		$this->assertEqualsCanonicalizing( array( 'page_title', 'post_id', 'menu_slug' ), array_column( $bad->get_data()['data']['errors'], 'path' ) );

		$created = $this->rest(
			'POST',
			'/options-pages',
			array(
				'page_title' => 'Contacts',
				'icon_url'   => 'javascript:alert(1)',
				'capability' => 'manage_options',
			)
		);
		$this->assertSame( 201, $created->get_status() );
		$page = $created->get_data();
		$this->assertSame( 'contacts', $page['menu_slug'] );
		$this->assertSame( '', $page['icon_url'], 'Unsafe icon URLs are dropped.' );
		$this->assertSame( 'db', $page['source'] );

		$this->assertSame( 400, $this->rest( 'POST', '/options-pages', array( 'page_title' => 'Contacts' ) )->get_status(), 'Slugs are unique.' );

		$page['menu_slug']  = 'contacts-page';
		$page['page_title'] = 'Contacts page';
		$updated            = $this->rest( 'PUT', '/options-pages/contacts', $page );
		$this->assertSame( 200, $updated->get_status() );
		$this->assertSame( 'contacts-page', $updated->get_data()['menu_slug'] );
		$this->assertNull( $this->plugin()->options_pages()->get( 'contacts' ) );

		$this->make_group( array( array( 'name' => 'email' ) ), array( 'location' => $this->rule( 'contacts-page' ) ) );
		$list = array_column( $this->rest( 'GET', '/options-pages' )->get_data(), null, 'menu_slug' );
		$this->assertSame( array( 'Test group ' . $this->last_group_number() ), $list['contacts-page']['field_groups'] );

		$this->assertSame( 200, $this->rest( 'DELETE', '/options-pages/contacts-page' )->get_status() );
		$this->assertSame( 404, $this->rest( 'GET', '/options-pages/contacts-page' )->get_status() );

		$this->page(
			array(
				'page_title' => 'From code',
				'menu_slug'  => 'from-code',
			)
		);
		$this->assertSame( 409, $this->rest( 'PUT', '/options-pages/from-code', array( 'page_title' => 'X' ) )->get_status() );
		$this->assertSame( 409, $this->rest( 'DELETE', '/options-pages/from-code' )->get_status() );
	}

	private function last_group_number(): int {
		$keys = array_filter( array_keys( $this->plugin()->fields()->get_groups() ), static fn( $k ) => str_starts_with( $k, 'group_test_' ) );
		return max( array_map( static fn( $k ) => (int) substr( $k, 11 ), $keys ) );
	}
}
