<?php
/**
 * Post types / taxonomies from the admin and bidirectional relationships.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Tests;

use FreeformFields\Content\ContentTypes;
use FreeformFields\Tools\ImportExport;

final class ContentAndRelationsTest extends TestCase {

	protected function tearDown(): void {
		foreach ( ContentTypes::POST_TYPES as $type ) {
			foreach ( get_posts(
				array(
					'post_type'      => $type,
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
				)
			) as $id ) {
				wp_delete_post( $id, true );
			}
		}
		foreach ( array( 'book', 'genre', 'acf_movie', 'acf_studio' ) as $name ) {
			if ( post_type_exists( $name ) ) {
				unregister_post_type( $name );
			}
			if ( taxonomy_exists( $name ) ) {
				unregister_taxonomy( $name );
			}
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type IN ('acf-post-type','acf-taxonomy','acf-ui-options-page')" );
		$this->plugin()->content_types()->flush();
		parent::tearDown();
	}

	private function rest( string $method, string $route, ?array $body = null ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, '/wpff/v1' . $route );
		if ( null !== $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	public function test_post_type_and_taxonomy_crud_and_registration(): void {
		$bad = $this->rest(
			'POST',
			'/content-types/post_type',
			array(
				'key'      => 'post',
				'singular' => '',
				'plural'   => 'X',
			)
		);
		$this->assertSame( 400, $bad->get_status() );
		$this->assertEqualsCanonicalizing( array( 'key', 'singular' ), array_column( $bad->get_data()['data']['errors'], 'path' ) );

		$this->assertSame(
			400,
			$this->rest(
				'POST',
				'/content-types/post_type',
				array(
					'key'      => str_repeat( 'a', 21 ),
					'singular' => 'A',
					'plural'   => 'As',
				)
			)->get_status(),
			'Post type keys are limited to 20 characters.'
		);

		$created = $this->rest(
			'POST',
			'/content-types/taxonomy',
			array(
				'key'          => 'genre',
				'singular'     => 'Жанр',
				'plural'       => 'Жанры',
				'hierarchical' => true,
				'object_type'  => array( 'book' ),
			)
		);
		$this->assertSame( 201, $created->get_status() );
		$book = $this->rest(
			'POST',
			'/content-types/post_type',
			array(
				'key'          => 'book',
				'singular'     => 'Книга',
				'plural'       => 'Книги',
				'supports'     => array( 'title', 'thumbnail', 'nonsense' ),
				'taxonomies'   => array( 'genre' ),
				'menu_icon'    => 'javascript:alert(1)',
				'has_archive'  => true,
				'rewrite_slug' => 'Книги',
			)
		)->get_data();
		$this->assertSame( array( 'title', 'thumbnail' ), $book['supports'] );
		$this->assertSame( 'dashicons-admin-post', $book['menu_icon'] );
		$this->assertSame( 'genre', $this->rest( 'GET', '/content-types/taxonomy' )->get_data()[0]['key'] );
		$this->assertSame(
			400,
			$this->rest(
				'POST',
				'/content-types/taxonomy',
				array(
					'key'      => 'book',
					'singular' => 'B',
					'plural'   => 'Bs',
				)
			)->get_status(),
			'Keys are unique across kinds.'
		);

		$this->plugin()->content_types()->register_all();
		$this->assertTrue( post_type_exists( 'book' ) );
		$this->assertTrue( taxonomy_exists( 'genre' ) );
		$this->assertSame( 'Книги', get_post_type_object( 'book' )->labels->name );
		$this->assertSame( 'Add New Книга', get_post_type_object( 'book' )->labels->add_new_item );
		$this->assertTrue( is_object_in_taxonomy( 'book', 'genre' ) );
		$this->assertTrue( get_taxonomy( 'genre' )->hierarchical );

		$book['plural'] = 'Книжки';
		$this->assertSame( 'Книжки', $this->rest( 'PUT', '/content-types/post_type/book', $book )->get_data()['plural'] );
		$this->assertSame( 200, $this->rest( 'DELETE', '/content-types/post_type/book' )->get_status() );
		$this->assertSame( 404, $this->rest( 'GET', '/content-types/post_type/book' )->get_status() );
	}

	public function test_import_post_types_from_acf(): void {
		wp_insert_post(
			array(
				'post_type'    => 'acf-post-type',
				'post_status'  => 'publish',
				'post_title'   => 'Movies',
				'post_content' => serialize( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
					array(
						'post_type'    => 'acf_movie',
						'labels'       => array(
							'name'          => 'Movies',
							'singular_name' => 'Movie',
						),
						'public'       => 1,
						'show_in_rest' => 1,
						'supports'     => array( 'title', 'editor' ),
						'menu_icon'    => array(
							'type'  => 'dashicons',
							'value' => 'dashicons-video-alt',
						),
					)
				),
			)
		);
		$tools = new ImportExport( $this->plugin() );
		$types = $tools->acf_db_content_types();
		$this->assertSame( 'acf_movie', $types['post_type'][0]['key'] );
		$result = $tools->import_acf_content_types();
		$this->assertSame( array( 'acf_movie' ), array_column( $result['imported'], 'key' ) );
		$this->assertSame( 'dashicons-video-alt', $this->plugin()->content_types()->get( 'post_type', 'acf_movie' )['menu_icon'] );
	}

	public function test_import_options_pages_from_acf(): void {
		wp_insert_post(
			array(
				'post_type'    => 'acf-ui-options-page',
				'post_status'  => 'publish',
				'post_title'   => 'Site settings',
				'post_content' => serialize( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
					array(
						'page_title'     => 'Site settings',
						'menu_slug'      => 'acf-site-settings',
						'parent_slug'    => 'none',
						'menu_icon'      => array(
							'type'  => 'dashicons',
							'value' => 'dashicons-admin-generic',
						),
						'data_storage'   => 'custom_storage',
						'custom_storage' => 'site_settings',
						'autoload'       => 1,
					)
				),
			)
		);
		$tools  = new ImportExport( $this->plugin() );
		$result = $tools->import_acf_all();
		$this->assertSame( array( 'acf-site-settings' ), array_column( $result['imported'], 'key' ) );
		$post = $this->plugin()->options_store()->find_post( 'acf-site-settings' );
		$this->assertNotNull( $post );

		$again = $tools->import_acf_options_pages();
		$this->assertSame( array(), $again['imported'], 'Existing pages are skipped.' );
		$this->assertCount( 1, $again['warnings'] );
		wp_delete_post( $post->ID, true );
	}

	public function test_import_acf_json_with_post_types_taxonomies_and_options_pages(): void {
		// An ACF 6.2 export lists post types, taxonomies and options pages next to field groups.
		$export = array(
			array(
				'key'      => 'group_acf_export_movie',
				'title'    => 'Movie details',
				'fields'   => array(
					array(
						'key'   => 'field_acf_export_year',
						'label' => 'Year',
						'name'  => 'year',
						'type'  => 'number',
					),
				),
				'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'acf_movie' ) ) ),
			),
			array(
				'key'          => 'post_type_65a1b2c3d4e5f',
				'title'        => 'Movies',
				'post_type'    => 'acf_movie',
				'labels'       => array(
					'name'          => 'Movies',
					'singular_name' => 'Movie',
				),
				'public'       => true,
				'show_in_rest' => true,
				'menu_icon'    => array(
					'type'  => 'dashicons',
					'value' => 'dashicons-video-alt',
				),
				'active'       => true,
			),
			array(
				'key'         => 'taxonomy_65a1b2c3d4e60',
				'title'       => 'Studios',
				'taxonomy'    => 'acf_studio',
				'object_type' => array( 'acf_movie' ),
				'labels'      => array(
					'name'          => 'Studios',
					'singular_name' => 'Studio',
				),
				'active'      => false,
			),
			array(
				'key'            => 'ui_options_page_65a1b2c3d4e61',
				'title'          => 'Site settings',
				'page_title'     => 'Site settings',
				'menu_slug'      => 'acf-json-site-settings',
				'parent_slug'    => 'none',
				'data_storage'   => 'custom_storage',
				'custom_storage' => 'site_settings',
				'active'         => true,
			),
		);
		$tools  = new ImportExport( $this->plugin() );
		$result = $tools->import( $export );
		$this->assertSame( array(), $result['errors'] );
		$this->assertSame(
			array( 'acf_movie', 'acf_studio', 'acf-json-site-settings', 'group_acf_export_movie' ),
			array_column( $result['imported'], 'key' ),
			'Post types, taxonomies and options pages come first, then field groups.'
		);
		$groups = get_posts(
			array(
				'post_type'      => \FreeformFields\Plugin::GROUP_POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
			)
		);
		$this->assertSame( array( 'group_acf_export_movie' ), wp_list_pluck( $groups, 'post_name' ), 'No empty groups for non-group items.' );

		$types = $this->plugin()->content_types();
		$this->assertSame( 'dashicons-video-alt', $types->get( 'post_type', 'acf_movie' )['menu_icon'] );
		$this->assertSame( array( 'acf_movie' ), $types->get( 'taxonomy', 'acf_studio' )['object_type'] );
		$this->assertFalse( $types->get( 'taxonomy', 'acf_studio' )['active'] );

		$post = $this->plugin()->options_store()->find_post( 'acf-json-site-settings' );
		$this->assertNotNull( $post );
		$this->assertSame( 'site_settings', json_decode( $post->post_content, true )['post_id'] );

		// Importing the same file again updates the items instead of failing.
		$again = $tools->import( $export );
		$this->assertSame( array(), $again['errors'] );
		$this->assertCount( 4, $again['imported'] );
		wp_delete_post( $post->ID, true );
	}

		public function test_bidirectional_relationship(): void {
		$this->make_group(
			array(
				array(
					'key'                  => 'field_post_related',
					'name'                 => 'related_pages',
					'type'                 => 'relationship',
					'bidirectional'        => 1,
					'bidirectional_target' => array( 'field_page_related' ),
				),
			)
		);
		$this->make_group(
			array(
				array(
					'key'                  => 'field_page_related',
					'name'                 => 'related_posts',
					'type'                 => 'relationship',
					'bidirectional'        => 1,
					'bidirectional_target' => array( 'field_post_related' ),
				),
			),
			array(
				'location' => array(
					array(
						array(
							'param'    => 'post_type',
							'operator' => '==',
							'value'    => 'page',
						),
					),
				),
			)
		);
		$post  = $this->make_post();
		$page1 = $this->make_post( array( 'post_type' => 'page' ) );
		$page2 = $this->make_post( array( 'post_type' => 'page' ) );

		wpff_update_field( 'field_post_related', array( $page1, $page2 ), $post );
		$this->assertSame( array( $post ), array_map( 'intval', get_post_meta( $page1, 'related_posts', true ) ) );
		$this->assertSame( 'field_page_related', get_post_meta( $page1, '_related_posts', true ) );
		$this->assertSame( array( $post ), array_map( 'intval', get_post_meta( $page2, 'related_posts', true ) ) );

		// Removing a page on one side removes the post on the other.
		wpff_update_field( 'field_post_related', array( $page2 ), $post );
		$this->assertSame( array(), (array) get_post_meta( $page1, 'related_posts', true ) );

		// Editing the other side updates this side, without looping.
		wpff_update_field( 'field_page_related', array(), $page2 );
		$this->assertSame( array(), (array) get_post_meta( $post, 'related_pages', true ) );
	}

	public function test_bidirectional_settings_are_offered(): void {
		$this->make_group(
			array(
				array(
					'key'  => 'field_rel_x',
					'name' => 'x',
					'type' => 'relationship',
				),
			),
			array( 'title' => 'Rel group' )
		);
		$settings = array_column( $this->plugin()->types()->get( 'user' )->settings(), null, 'name' );
		$this->assertArrayHasKey( 'bidirectional', $settings );
		$this->assertArrayHasKey( 'field_rel_x', $settings['bidirectional_target']['options'] );
	}
}
