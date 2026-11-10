<?php
/**
 * Field group storage and REST API.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Tests;

use FreeformFields\Admin\PostScreen;
use FreeformFields\Plugin;
use FreeformFields\Registry\GroupStore;

final class FieldGroupsApiTest extends TestCase {

	/**
	 * @param array<string, mixed>|null $body JSON body.
	 */
	private function request( string $method, string $route, ?array $body = null ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, '/wpff/v1' . $route );
		if ( null !== $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function group_input( array $extra = array() ): array {
		return $extra + array(
			'title'    => 'Product',
			'fields'   => array(
				array(
					'key'   => 'field_price_x',
					'label' => 'Price',
					'name'  => 'price',
					'type'  => 'number',
					'min'   => '0',
				),
				array(
					'label'         => 'Color',
					'name'          => 'Co lor!',
					'type'          => 'select',
					'choices'       => "red : Red\nblue : Blue",
					'return_format' => 'bogus',
					'instructions'  => 'Pick <b>one</b><script>x()</script>',
					'unknown_prop'  => 'dropped',
					'wrapper'       => array(
						'width' => '150',
						'class' => 'a b"c',
					),
				),
			),
			'location' => array(
				array(
					array(
						'param'    => 'post_type',
						'operator' => '==',
						'value'    => 'post',
					),
					array(
						'param'    => 'not_a_rule',
						'operator' => '==',
						'value'    => 'x',
					),
				),
				array(),
			),
			'position' => 'nowhere',
		);
	}

	public function test_create_sanitizes_and_stores_group(): void {
		$response = $this->request( 'POST', '/field-groups', self::group_input() );
		$this->assertSame( 201, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );

		$group = $response->get_data();
		$this->assertMatchesRegularExpression( '/^group_[a-z0-9]{13}$/', $group['key'] );
		$this->assertSame( 'db', $group['source'] );
		$this->assertSame( 'normal', $group['position'], 'Unknown position falls back.' );
		$this->assertSame(
			array(
				array(
					array(
						'param'    => 'post_type',
						'operator' => '==',
						'value'    => 'post',
					),
				),
			),
			$group['location'],
			'Unknown params and empty rule groups are dropped.'
		);

		[ $price, $color ] = $group['fields'];
		$this->assertSame( 'field_price_x', $price['key'] );
		$this->assertSame( '0', $price['min'] );
		$this->assertSame( 'Color', $color['name'], 'Invalid characters are removed; case is kept.' );
		$this->assertStringStartsWith( 'field_', $color['key'] );
		$this->assertSame(
			array(
				'red'  => 'Red',
				'blue' => 'Blue',
			),
			$color['choices']
		);
		$this->assertSame( 'value', $color['return_format'], 'Invalid option falls back to the default.' );
		$this->assertSame( 'Pick <b>one</b>x()', $color['instructions'] );
		$this->assertArrayNotHasKey( 'unknown_prop', $color );
		$this->assertSame( 100, $color['wrapper']['width'] );
		$this->assertSame( 'a bc', $color['wrapper']['class'] );

		// Stored as one post whose JSON round-trips, and immediately usable on edit screens.
		$post = $this->plugin()->groups()->find_post( $group['key'] );
		$this->assertSame( 'Product', $post->post_title );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertIsArray( json_decode( $post->post_content, true ) );

		$screen = new PostScreen( $this->plugin() );
		$this->assertArrayHasKey( $group['key'], $screen->groups_for_post( get_post( $this->make_post() ) ) );
	}

	public function test_non_latin_text_and_slashes_survive(): void {
		$input                         = self::group_input( array( 'title' => 'Карточка "товара" \\ 1' ) );
		$input['fields'][1]['choices'] = array( 'red' => 'Красный' );
		$group                         = $this->request( 'POST', '/field-groups', $input )->get_data();

		$loaded = $this->request( 'GET', '/field-groups/' . $group['key'] )->get_data();
		$this->assertSame( 'Карточка "товара" \\ 1', $loaded['title'] );
		$this->assertSame( array( 'red' => 'Красный' ), $loaded['fields'][1]['choices'] );
	}

	public function test_validation_errors_have_paths(): void {
		$input                      = self::group_input( array( 'title' => '  ' ) );
		$input['fields'][1]['name'] = 'price';
		$input['fields'][]          = array(
			'label' => '',
			'name'  => 'x',
			'type'  => 'nope',
		);

		$response = $this->request( 'POST', '/field-groups', $input );
		$this->assertSame( 400, $response->get_status() );
		$paths = array_column( $response->get_data()['data']['errors'], 'path' );
		$this->assertEqualsCanonicalizing( array( 'title', 'fields.1.name', 'fields.2.type', 'fields.2.label' ), $paths );
	}

	public function test_update_deactivate_trash_restore(): void {
		$group = $this->request( 'POST', '/field-groups', self::group_input() )->get_data();
		$key   = $group['key'];

		$group['title']  = 'Renamed';
		$group['active'] = false;
		$response        = $this->request( 'PUT', '/field-groups/' . $key, $group );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Renamed', $response->get_data()['title'] );
		$this->assertSame( GroupStore::DISABLED_STATUS, $this->plugin()->groups()->find_post( $key )->post_status );
		$this->assertArrayNotHasKey( $key, $this->plugin()->fields()->get_groups(), 'Inactive groups are not shown.' );
		$this->assertArrayHasKey( $key, $this->plugin()->fields()->get_all_groups() );

		$this->assertSame( 200, $this->request( 'DELETE', '/field-groups/' . $key )->get_status() );
		$this->assertSame( 404, $this->request( 'GET', '/field-groups/' . $key )->get_status() );

		$restored = $this->request( 'POST', '/field-groups/' . $key . '/restore' );
		$this->assertSame( 200, $restored->get_status() );
		$this->assertFalse( $restored->get_data()['active'], 'Restore keeps the previous (inactive) status.' );
	}

	public function test_trash_list_and_permanent_delete(): void {
		$key = $this->request( 'POST', '/field-groups', self::group_input( array( 'title' => 'Old' ) ) )->get_data()['key'];
		$this->assertSame( array(), $this->request( 'GET', '/field-groups/trash' )->get_data() );
		$this->assertSame( 404, $this->request( 'DELETE', '/field-groups/' . $key . '?force=1' )->get_status(), 'Only trashed groups can be deleted for good.' );

		$this->request( 'DELETE', '/field-groups/' . $key );
		$trash = $this->request( 'GET', '/field-groups/trash' )->get_data();
		$this->assertCount( 1, $trash );
		$this->assertSame( $key, $trash[0]['key'], 'The key comes without the __trashed suffix.' );
		$this->assertSame( 'Old', $trash[0]['title'] );

		$request = new \WP_REST_Request( 'DELETE', '/wpff/v1/field-groups/' . $key );
		$request->set_param( 'force', '1' );
		$this->assertTrue( rest_get_server()->dispatch( $request )->get_data()['deleted'] );
		$this->assertSame( array(), $this->request( 'GET', '/field-groups/trash' )->get_data() );
		$this->assertNull( $this->plugin()->groups()->find_post( $key, true ) );
	}

	public function test_list_counts_nested_fields(): void {
		$input           = self::group_input();
		$input['fields'] = array(
			array(
				'label'      => 'Rows',
				'name'       => 'rows',
				'type'       => 'repeater',
				'sub_fields' => array(
					array(
						'label' => 'A',
						'name'  => 'a',
						'type'  => 'text',
					),
					array(
						'label'      => 'B',
						'name'       => 'b',
						'type'       => 'group',
						'sub_fields' => array(
							array(
								'label' => 'C',
								'name'  => 'c',
								'type'  => 'text',
							),
						),
					),
				),
			),
		);
		$key             = $this->request( 'POST', '/field-groups', $input )->get_data()['key'];
		$listed          = array_column( $this->request( 'GET', '/field-groups' )->get_data(), null, 'key' );
		$this->assertSame( 4, $listed[ $key ]['field_count'], 'Counts sub fields, as the builder does.' );
	}

	public function test_create_ignores_taken_key(): void {
		$first  = $this->request( 'POST', '/field-groups', self::group_input() )->get_data();
		$second = $this->request( 'POST', '/field-groups', self::group_input( array( 'key' => $first['key'] ) ) )->get_data();
		$this->assertNotSame( $first['key'], $second['key'] );
	}

	public function test_duplicate_gives_new_keys(): void {
		$group = $this->request( 'POST', '/field-groups', self::group_input() )->get_data();
		$copy  = $this->request( 'POST', '/field-groups/' . $group['key'] . '/duplicate' );

		$this->assertSame( 201, $copy->get_status() );
		$copy = $copy->get_data();
		$this->assertNotSame( $group['key'], $copy['key'] );
		$this->assertSame( 'Product (copy)', $copy['title'] );
		$this->assertSame( array( 'price', 'Color' ), array_column( $copy['fields'], 'name' ) );
		$this->assertEmpty( array_intersect( array_column( $group['fields'], 'key' ), array_column( $copy['fields'], 'key' ) ) );
	}

	public function test_local_groups_are_read_only_but_copyable(): void {
		$key = $this->make_group( array( array( 'name' => 'from_code' ) ) );

		$this->assertSame( 'php', $this->request( 'GET', '/field-groups/' . $key )->get_data()['source'] );
		$this->assertSame( 409, $this->request( 'PUT', '/field-groups/' . $key, array( 'title' => 'X' ) )->get_status() );
		$this->assertSame( 409, $this->request( 'DELETE', '/field-groups/' . $key )->get_status() );

		$copy = $this->request( 'POST', '/field-groups/' . $key . '/duplicate' );
		$this->assertSame( 201, $copy->get_status() );
		$this->assertSame( 'db', $copy->get_data()['source'] );
	}

	public function test_requires_capability(): void {
		$editor = wp_insert_user(
			array(
				'user_login' => 'e' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password(),
				'role'       => 'editor',
			)
		);
		wp_set_current_user( $editor );

		$this->assertSame( 403, $this->request( 'GET', '/field-groups' )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/field-groups', self::group_input() )->get_status() );

		wp_set_current_user( 1 );
		wp_delete_user( $editor );
	}

	public function test_list_and_builder_data(): void {
		$this->make_group( array( array( 'name' => 'a' ) ) );
		$this->request( 'POST', '/field-groups', self::group_input() );

		$list = $this->request( 'GET', '/field-groups' )->get_data();
		$this->assertEqualsCanonicalizing( array( 'php', 'db' ), array_column( $list, 'source' ) );

		$data  = $this->request( 'GET', '/builder' )->get_data();
		$types = array_column( $data['fieldTypes'], null, 'name' );
		$this->assertCount( 35, $types );
		$this->assertContains( 'choices', array_column( $types['select']['settings'], 'name' ) );

		$params = array_column( $data['location'], null, 'name' );
		$this->assertArrayHasKey( 'post', (array) $params['post_type']['values'] );
		$this->assertArrayHasKey( 'administrator', (array) $params['current_user_role']['values'] );
	}

	public function test_type_settings_are_consistent(): void {
		// Every setting the builder shows must have a default, so new fields start valid.
		foreach ( Plugin::instance()->types()->all() as $type ) {
			$defaults = $type->defaults() + array( 'default_value' => '' );
			foreach ( $type->settings() as $setting ) {
				$this->assertArrayHasKey( $setting['name'], $defaults, "{$type->name}.{$setting['name']}" );
				if ( 'select' === $setting['control'] && isset( $type->defaults()[ $setting['name'] ] ) ) {
					$this->assertArrayHasKey( $type->defaults()[ $setting['name'] ], $setting['options'], "{$type->name}.{$setting['name']} default is an option" );
				}
			}
		}
	}
}
