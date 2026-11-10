<?php
/**
 * Storage layout and value API.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Tests;

use FreeformFields\Storage\ObjectRef;

final class StorageTest extends TestCase {

	public function test_object_ref_parses_acf_identifiers(): void {
		$this->assertEquals( new ObjectRef( 'post', 12 ), ObjectRef::parse( 12 ) );
		$this->assertEquals( new ObjectRef( 'post', 12 ), ObjectRef::parse( '12' ) );
		$this->assertEquals( new ObjectRef( 'term', 5 ), ObjectRef::parse( 'term_5' ) );
		$this->assertEquals( new ObjectRef( 'term', 5 ), ObjectRef::parse( 'category_5' ) );
		$this->assertEquals( new ObjectRef( 'user', 3 ), ObjectRef::parse( 'user_3' ) );
		$this->assertEquals( new ObjectRef( 'comment', 7 ), ObjectRef::parse( 'comment_7' ) );
		$this->assertEquals( new ObjectRef( 'option', 'options' ), ObjectRef::parse( 'option' ) );
		$this->assertEquals( new ObjectRef( 'option', 'options' ), ObjectRef::parse( 'options' ) );
		$this->assertEquals( new ObjectRef( 'option', 'site_settings' ), ObjectRef::parse( 'site_settings' ) );
		$this->assertNull( ObjectRef::parse( false ), 'No current object outside the loop.' );
	}

	public function test_post_values_use_acf_meta_layout(): void {
		$this->make_group(
			array(
				array(
					'key'  => 'field_price',
					'name' => 'price',
					'type' => 'number',
				),
			)
		);
		$post = $this->make_post();

		$this->assertTrue( wpff_update_field( 'price', '1500', $post ) );

		$this->assertSame( '1500', get_post_meta( $post, 'price', true ) );
		$this->assertSame( 'field_price', get_post_meta( $post, '_price', true ) );
		$this->assertSame( '1500', wpff_get_field( 'price', $post ) );
		$this->assertSame( '1500', wpff_get_field( 'field_price', $post ) );
	}

	public function test_term_user_and_option_layout(): void {
		$this->make_group(
			array(
				array(
					'key'  => 'field_color',
					'name' => 'color',
				),
			)
		);
		$term = wp_insert_term( 'Red ' . wp_generate_password( 4, false ), 'category' );
		$user = wp_insert_user(
			array(
				'user_login' => 'u' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password(),
			)
		);

		wpff_update_field( 'field_color', 'red', 'term_' . $term['term_id'] );
		wpff_update_field( 'field_color', 'blue', 'user_' . $user );
		wpff_update_field( 'field_color', 'green', 'option' );

		$this->assertSame( 'red', get_term_meta( $term['term_id'], 'color', true ) );
		$this->assertSame( 'field_color', get_term_meta( $term['term_id'], '_color', true ) );
		$this->assertSame( 'blue', get_user_meta( $user, 'color', true ) );
		$this->assertSame( 'green', get_option( 'options_color' ) );
		$this->assertSame( 'field_color', get_option( '_options_color' ) );

		$this->assertSame( 'red', wpff_get_field( 'color', get_term( $term['term_id'] ) ) );
		$this->assertSame( 'blue', wpff_get_field( 'color', get_user_by( 'id', $user ) ) );
		$this->assertSame( 'green', wpff_get_field( 'color', 'options' ) );

		$this->assertTrue( wpff_delete_field( 'color', 'option' ) );
		$this->assertFalse( get_option( 'options_color' ) );
		$this->assertFalse( get_option( '_options_color' ) );

		wp_delete_term( $term['term_id'], 'category' );
		wp_delete_user( $user );
	}

	public function test_reads_data_written_by_acf(): void {
		// What ACF itself stores for a checkbox and a true/false field.
		$this->make_group(
			array(
				array(
					'key'     => 'field_6512ab0c1d2e3',
					'name'    => 'features',
					'type'    => 'checkbox',
					'choices' => array(
						'wifi'    => 'Wi-Fi',
						'parking' => 'Parking',
					),
				),
				array(
					'key'  => 'field_6512ab0c1d2e4',
					'name' => 'featured',
					'type' => 'true_false',
				),
			)
		);
		$post = $this->make_post();
		add_post_meta( $post, 'features', array( 'wifi', 'parking' ) );
		add_post_meta( $post, '_features', 'field_6512ab0c1d2e3' );
		add_post_meta( $post, 'featured', '1' );
		add_post_meta( $post, '_featured', 'field_6512ab0c1d2e4' );

		$this->assertSame( array( 'wifi', 'parking' ), wpff_get_field( 'features', $post ) );
		$this->assertTrue( wpff_get_field( 'featured', $post ) );
		$this->assertSame(
			array(
				'features' => array( 'wifi', 'parking' ),
				'featured' => true,
			),
			wpff_get_fields( $post )
		);
	}

	public function test_stored_reference_wins_over_name_lookup(): void {
		// Two groups use the same name; the stored `_name` reference decides which field formats the value.
		$this->make_group(
			array(
				array(
					'key'           => 'field_kind_a',
					'name'          => 'kind',
					'type'          => 'select',
					'choices'       => array( 'x' => 'From A' ),
					'return_format' => 'label',
				),
			)
		);
		$this->make_group(
			array(
				array(
					'key'           => 'field_kind_b',
					'name'          => 'kind',
					'type'          => 'select',
					'choices'       => array( 'x' => 'From B' ),
					'return_format' => 'label',
				),
			)
		);
		$post = $this->make_post();
		wpff_update_field( 'field_kind_b', 'x', $post );

		$this->assertSame( 'From B', wpff_get_field( 'kind', $post ) );
	}

	public function test_default_value_and_unknown_fields(): void {
		$this->make_group(
			array(
				array(
					'name'          => 'subtitle',
					'default_value' => 'Hello',
				),
			)
		);
		$post = $this->make_post();

		$this->assertSame( 'Hello', wpff_get_field( 'subtitle', $post ) );

		update_post_meta( $post, 'plain_meta', 'raw' );
		$this->assertSame( 'raw', wpff_get_field( 'plain_meta', $post ), 'Unregistered names fall back to plain meta.' );
		$this->assertNull( wpff_get_field( 'missing', $post ) );
	}

	public function test_omitted_field_key_is_stable(): void {
		$this->make_group( array( array( 'name' => 'tagline' ) ), array( 'key' => 'group_stable' ) );
		$field = wpff_get_field_object( 'tagline' );
		$this->assertSame( 'field_' . substr( md5( 'group_stable/tagline' ), 0, 13 ), $field['key'] );
	}

	public function test_slashes_and_html_survive_round_trip(): void {
		$this->make_group( array( array( 'name' => 'path' ) ) );
		$post = $this->make_post();
		wpff_update_field( 'path', 'C:\\dir\\file "quoted"', $post );
		$this->assertSame( 'C:\\dir\\file "quoted"', wpff_get_field( 'path', $post ) );
	}

	public function test_filters_run_with_variants(): void {
		$this->make_group(
			array(
				array(
					'key'  => 'field_f',
					'name' => 'filtered',
				),
			)
		);
		$post  = $this->make_post();
		$calls = array();
		$spy   = function ( $suffix ) use ( &$calls ) {
			return function ( $value ) use ( $suffix, &$calls ) {
				$calls[] = $suffix;
				return $value . '|' . $suffix;
			};
		};
		add_filter( 'wpff/format_value', $f1 = $spy( 'all' ) );
		add_filter( 'wpff/format_value/type=text', $f2 = $spy( 'type' ) );
		add_filter( 'wpff/format_value/name=filtered', $f3 = $spy( 'name' ) );
		add_filter( 'wpff/format_value/key=field_f', $f4 = $spy( 'key' ) );

		wpff_update_field( 'filtered', 'v', $post );
		$this->assertSame( 'v|all|type|name|key', wpff_get_field( 'filtered', $post ) );
		$this->assertSame( 'v', wpff_get_field( 'filtered', $post, false ) );

		remove_filter( 'wpff/format_value', $f1 );
		remove_filter( 'wpff/format_value/type=text', $f2 );
		remove_filter( 'wpff/format_value/name=filtered', $f3 );
		remove_filter( 'wpff/format_value/key=field_f', $f4 );
	}
}
