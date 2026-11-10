<?php
/**
 * Stage 3 field types: dates, color, link, file, range, wysiwyg and relational fields.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Tests;

use FreeformFields\Fields\Types\ColorPicker;
use FreeformFields\Validation\Validator;

final class MoreFieldTypesTest extends TestCase {

	/**
	 * Sanitize + validate one value for a one-field group.
	 *
	 * @param array<string, mixed> $settings Field settings.
	 * @param mixed                $value    Submitted value.
	 * @return array{values: array<string, mixed>, errors: array<string, string>}
	 */
	private function check( array $settings, $value ): array {
		$this->make_group(
			array(
				$settings + array(
					'key'   => 'field_x',
					'name'  => 'x',
					'label' => 'X',
				),
			)
		);
		$field = $this->plugin()->fields()->get_field( 'field_x' );
		return ( new Validator( $this->plugin()->types() ) )->validate( array( 'field_x' => $value ), array( 'field_x' => $field ) );
	}

	public function test_dates_are_stored_like_acf_and_formatted(): void {
		$this->assertSame( '20261109', $this->check( array( 'type' => 'date_picker' ), '2026-11-09' )['values']['field_x'] );
		$this->assertSame( '2026-11-09 14:30:00', $this->check( array( 'type' => 'date_time_picker' ), '2026-11-09T14:30' )['values']['field_x'] );
		$this->assertSame( '07:05:00', $this->check( array( 'type' => 'time_picker' ), '07:05' )['values']['field_x'] );
		$this->assertArrayHasKey( 'field_x', $this->check( array( 'type' => 'date_picker' ), '2026-02-30' )['errors'] );

		$this->make_group(
			array(
				array(
					'name' => 'd',
					'type' => 'date_picker',
				),
				array(
					'name'          => 'dt',
					'type'          => 'date_time_picker',
					'return_format' => 'Y-m-d H:i',
				),
			)
		);
		$post = $this->make_post();
		wpff_update_field( 'd', '20261109', $post );
		wpff_update_field( 'dt', '2026-11-09 23:59:00', $post );
		$this->assertSame( '09/11/2026', wpff_get_field( 'd', $post ) );
		$this->assertSame( '2026-11-09 23:59', wpff_get_field( 'dt', $post ), 'No time zone shift.' );
		$this->assertSame( '20261109', wpff_get_field( 'd', $post, false ) );
	}

	public function test_color(): void {
		$this->assertSame(
			array(
				'red'   => 255,
				'green' => 0,
				'blue'  => 0,
				'alpha' => 1.0,
			),
			ColorPicker::parse( '#f00' )
		);
		$this->assertSame( 0.5, ColorPicker::parse( 'rgba(1, 2, 3, .5)' )['alpha'] );
		$this->assertNull( ColorPicker::parse( 'rgb(300,0,0)' ) );
		$this->assertArrayHasKey( 'field_x', $this->check( array( 'type' => 'color_picker' ), 'red' )['errors'] );
		$this->assertSame( '#00ff00', $this->check( array( 'type' => 'color_picker' ), ' #00FF00 ' )['values']['field_x'] );
	}

	public function test_link(): void {
		$value = array(
			'url'    => 'https://example.com',
			'title'  => 'Ex <b>ample</b>',
			'target' => '_blank',
		);
		$this->assertSame(
			array(
				'title'  => 'Ex ample',
				'url'    => 'https://example.com',
				'target' => '_blank',
			),
			$this->check( array( 'type' => 'link' ), $value )['values']['field_x']
		);
		$this->assertArrayHasKey( 'field_x', $this->check( array( 'type' => 'link' ), array( 'url' => 'javascript:alert(1)' ) )['errors'] );
		$this->assertSame( '', $this->check( array( 'type' => 'link' ), array( 'url' => '' ) )['values']['field_x'] );

		$this->make_group(
			array(
				array(
					'name'          => 'l',
					'type'          => 'link',
					'return_format' => 'url',
				),
			)
		);
		$post = $this->make_post();
		wpff_update_field( 'l', $value, $post );
		$this->assertSame( 'https://example.com', wpff_get_field( 'l', $post ) );
	}

	public function test_range_file_and_wysiwyg(): void {
		$this->assertArrayHasKey(
			'field_x',
			$this->check(
				array(
					'type' => 'range',
					'max'  => 10,
				),
				'11'
			)['errors']
		);

		$image = $this->make_image();
		$this->assertSame( array(), $this->check( array( 'type' => 'file' ), (string) $image )['errors'] );
		$this->assertArrayHasKey(
			'field_x',
			$this->check(
				array(
					'type'       => 'file',
					'mime_types' => 'pdf, docx',
				),
				(string) $image
			)['errors']
		);

		$this->make_group(
			array(
				array(
					'name' => 'f',
					'type' => 'file',
				),
				array(
					'name' => 'w',
					'type' => 'wysiwyg',
				),
			)
		);
		$post = $this->make_post();
		wpff_update_field( 'f', $image, $post );
		wpff_update_field( 'w', "Line one\n\nLine two", $post );
		$file = wpff_get_field( 'f', $post );
		$this->assertSame( $image, $file['ID'] );
		$this->assertArrayHasKey( 'filesize', $file );
		$this->assertSame( "<p>Line one</p>\n<p>Line two</p>\n", wpff_get_field( 'w', $post ) );
	}

	public function test_post_object_page_link_and_relationship(): void {
		$page_a = $this->make_post(
			array(
				'post_type'  => 'page',
				'post_title' => 'Alpha',
			)
		);
		$page_b = $this->make_post(
			array(
				'post_type'  => 'page',
				'post_title' => 'Beta',
			)
		);
		$post   = $this->make_post( array( 'post_title' => 'A post' ) );

		$this->assertSame(
			$page_a,
			$this->check(
				array(
					'type'      => 'post_object',
					'post_type' => array( 'page' ),
				),
				(string) $page_a
			)['values']['field_x']
		);
		$this->assertArrayHasKey(
			'field_x',
			$this->check(
				array(
					'type'      => 'post_object',
					'post_type' => array( 'page' ),
				),
				(string) $post
			)['errors'],
			'Wrong post type is rejected.'
		);
		$this->assertArrayHasKey(
			'field_x',
			$this->check(
				array(
					'type' => 'relationship',
					'min'  => 2,
				),
				array( (string) $page_a )
			)['errors']
		);
		$this->assertArrayHasKey(
			'field_x',
			$this->check(
				array(
					'type' => 'relationship',
					'max'  => 1,
				),
				array( (string) $page_a, (string) $page_b )
			)['errors']
		);

		$this->make_group(
			array(
				array(
					'name' => 'po',
					'type' => 'post_object',
				),
				array(
					'name'     => 'pl',
					'type'     => 'page_link',
					'multiple' => 1,
				),
				array(
					'name'          => 'rel',
					'type'          => 'relationship',
					'return_format' => 'id',
				),
			)
		);
		wpff_update_field( 'po', $page_a, $post );
		wpff_update_field( 'pl', array( $page_a, $page_b ), $post );
		wpff_update_field( 'rel', array( $page_b, $page_a ), $post );

		$this->assertInstanceOf( \WP_Post::class, wpff_get_field( 'po', $post ) );
		$this->assertSame( array( get_permalink( $page_a ), get_permalink( $page_b ) ), wpff_get_field( 'pl', $post ) );
		$this->assertSame( array( $page_b, $page_a ), wpff_get_field( 'rel', $post ), 'Order is kept.' );

		$results = $this->plugin()->types()->get( 'post_object' )->query(
			$this->plugin()->fields()->find_field_by_name( 'po' ) + array( 'post_type' => array( 'page' ) ),
			'Alph',
			1
		);
		$this->assertContains( $page_a, array_column( $results['results'], 'id' ) );
		$this->assertNotContains( $page_b, array_column( $results['results'], 'id' ) );
	}

	public function test_user_field(): void {
		$editor = wp_insert_user(
			array(
				'user_login'   => 'ed' . wp_generate_password( 6, false ),
				'user_pass'    => wp_generate_password(),
				'role'         => 'editor',
				'display_name' => 'Ed Itor',
			)
		);
		$this->assertArrayHasKey(
			'field_x',
			$this->check(
				array(
					'type' => 'user',
					'role' => array( 'author' ),
				),
				(string) $editor
			)['errors'],
			'Role filter applies.'
		);

		$this->make_group(
			array(
				array(
					'name' => 'owner',
					'type' => 'user',
				),
			)
		);
		$post = $this->make_post();
		wpff_update_field( 'owner', $editor, $post );
		$user = wpff_get_field( 'owner', $post );
		$this->assertSame( $editor, $user['ID'] );
		$this->assertSame( 'Ed Itor', $user['display_name'] );
		wp_delete_user( $editor );
	}

	public function test_taxonomy_save_and_load_terms(): void {
		$a = wp_insert_term( 'Tax A ' . wp_generate_password( 4, false ), 'category' );
		$b = wp_insert_term( 'Tax B ' . wp_generate_password( 4, false ), 'category' );
		$this->make_group(
			array(
				array(
					'name'          => 'cats',
					'type'          => 'taxonomy',
					'taxonomy'      => 'category',
					'save_terms'    => 1,
					'load_terms'    => 1,
					'return_format' => 'object',
				),
			)
		);
		$post = $this->make_post();
		wpff_update_field( 'cats', array( $a['term_id'], $b['term_id'] ), $post );

		$this->assertEqualsCanonicalizing( array( $a['term_id'], $b['term_id'] ), wp_get_post_categories( $post ) );
		wp_set_post_categories( $post, array( $b['term_id'] ) );
		$terms = wpff_get_field( 'cats', $post );
		$this->assertSame( array( $b['term_id'] ), array_column( $terms, 'term_id' ), 'load_terms reads the post terms.' );

		wp_delete_term( $a['term_id'], 'category' );
		wp_delete_term( $b['term_id'], 'category' );
	}

	public function test_choices_endpoint_permissions(): void {
		$this->make_group(
			array(
				array(
					'key'  => 'field_po',
					'name' => 'po',
					'type' => 'post_object',
				),
				array(
					'key'  => 'field_us',
					'name' => 'us',
					'type' => 'user',
				),
				array(
					'key'  => 'field_tx',
					'name' => 'tx',
					'type' => 'text',
				),
			)
		);
		$get = static fn( string $key ) => rest_get_server()->dispatch( new \WP_REST_Request( 'GET', "/wpff/v1/fields/{$key}/choices" ) );

		$this->assertSame( 200, $get( 'field_po' )->get_status() );
		$this->assertSame( 200, $get( 'field_us' )->get_status() );
		$this->assertSame( 404, $get( 'field_tx' )->get_status(), 'Non-relational fields have no choices.' );

		$author = wp_insert_user(
			array(
				'user_login' => 'au' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password(),
				'role'       => 'author',
			)
		);
		wp_set_current_user( $author );
		$this->assertSame( 200, $get( 'field_po' )->get_status() );
		$this->assertSame( 403, $get( 'field_us' )->get_status(), 'Listing users needs list_users.' );
		wp_set_current_user( 1 );
		wp_delete_user( $author );
	}
}
