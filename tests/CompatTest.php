<?php
/**
 * ACF function-name compatibility and printing.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Tests;

final class CompatTest extends TestCase {

	public function test_acf_functions_are_defined(): void {
		foreach ( array( 'get_field', 'the_field', 'get_field_object', 'get_fields', 'update_field', 'delete_field', 'acf_add_local_field_group' ) as $fn ) {
			$this->assertTrue( function_exists( $fn ), $fn );
		}
	}

	public function test_theme_code_written_for_acf_works(): void {
		acf_add_local_field_group(
			array(
				'key'      => 'group_acf_style',
				'title'    => 'ACF style',
				'fields'   => array(
					array(
						'key'   => 'field_acf_style_hero',
						'label' => 'Hero',
						'name'  => 'hero',
						'type'  => 'text',
					),
				),
				'location' => array(
					array(
						array(
							'param'    => 'post_type',
							'operator' => '==',
							'value'    => 'post',
						),
					),
				),
			)
		);
		$post = $this->make_post();
		update_field( 'hero', 'Big <em>title</em><script>bad()</script>', $post );

		$this->assertSame( 'Big <em>title</em><script>bad()</script>', get_field( 'hero', $post ) );
		$this->assertSame( 'hero', get_field_object( 'hero', $post )['name'] );

		ob_start();
		the_field( 'hero', $post );
		$this->assertSame( 'Big <em>title</em>bad()', ob_get_clean(), 'the_field() escapes like ACF 6.2.5+.' );

		wpff_remove_local_field_group( 'group_acf_style' );
	}

	public function test_value_to_string(): void {
		$this->assertSame( 'a, b', wpff_value_to_string( array( 'a', 'b' ) ) );
		$this->assertSame(
			'Red',
			wpff_value_to_string(
				array(
					'value' => 'r',
					'label' => 'Red',
				)
			)
		);
		$this->assertSame( '', wpff_value_to_string( false ) );
		$this->assertSame( 'https://x.test/a.png', wpff_value_to_string( array( 'url' => 'https://x.test/a.png' ) ) );
	}
}
