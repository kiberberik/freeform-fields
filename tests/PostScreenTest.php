<?php
/**
 * Saving and rendering on the post edit screen.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Tests;

use FreeformFields\Admin\PostScreen;

final class PostScreenTest extends TestCase {

	private function setup_fields(): void {
		$this->make_group(
			array(
				array(
					'key'      => 'field_title2',
					'name'     => 'title2',
					'label'    => 'Second title',
					'required' => 1,
				),
				array(
					'key'  => 'field_count',
					'name' => 'count',
					'type' => 'number',
					'max'  => 10,
				),
				array(
					'key'     => 'field_tags',
					'name'    => 'tags',
					'type'    => 'checkbox',
					'choices' => array(
						'a' => 'A',
						'b' => 'B',
					),
				),
			)
		);
		// A group for pages only: its field must not be writable through a post form.
		$this->make_group(
			array(
				array(
					'key'  => 'field_page_only',
					'name' => 'page_only',
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
	}

	private function submit( int $post_id, array $values, ?string $nonce = null ): void {
		$_POST = array(
			'post_ID'              => $post_id,
			PostScreen::NONCE_NAME => $nonce ?? wp_create_nonce( PostScreen::NONCE_ACTION ),
			'wpff'                 => wp_slash( $values ),
		);
		( new PostScreen( $this->plugin() ) )->save_post( $post_id, get_post( $post_id ) );
	}

	public function test_saves_valid_values(): void {
		$this->setup_fields();
		$post = $this->make_post();

		$this->submit(
			$post,
			array(
				'field_title2' => 'It\'s "quoted"',
				'field_count'  => '7',
				'field_tags'   => array( 'b' ),
			)
		);

		$this->assertSame( 'It\'s "quoted"', wpff_get_field( 'title2', $post ) );
		$this->assertSame( '7', wpff_get_field( 'count', $post ) );
		$this->assertSame( array( 'b' ), wpff_get_field( 'tags', $post ) );
	}

	public function test_unchecking_all_checkboxes_saves_empty(): void {
		$this->setup_fields();
		$post = $this->make_post();
		wpff_update_field( 'field_tags', array( 'a' ), $post );

		// The form sends the hidden empty input when nothing is checked.
		$this->submit(
			$post,
			array(
				'field_title2' => 'x',
				'field_tags'   => '',
			)
		);

		$this->assertSame( array(), wpff_get_field( 'tags', $post ) );
	}

	public function test_rejects_invalid_values_and_reports_them(): void {
		$this->setup_fields();
		$post = $this->make_post();
		wpff_update_field( 'field_count', '3', $post );

		$this->submit(
			$post,
			array(
				'field_title2' => '',
				'field_count'  => '99',
			)
		);

		$this->assertSame( '3', wpff_get_field( 'count', $post ), 'Invalid value keeps the old one.' );
		$errors = get_transient( 'wpff_errors_1_' . $post );
		$this->assertArrayHasKey( 'field_title2', $errors );
		$this->assertArrayHasKey( 'field_count', $errors );
	}

	public function test_ignores_bad_nonce_foreign_fields_and_other_posts(): void {
		$this->setup_fields();
		$post  = $this->make_post();
		$other = $this->make_post();

		$this->submit( $post, array( 'field_title2' => 'x' ), 'bad-nonce' );
		$this->assertNull( wpff_get_field( 'title2', $post ) );

		$this->submit(
			$post,
			array(
				'field_title2'    => 'x',
				'field_page_only' => 'hack',
			)
		);
		$this->assertSame( '', get_post_meta( $post, 'page_only', true ) );

		$_POST['post_ID'] = $post;
		( new PostScreen( $this->plugin() ) )->save_post( $other, get_post( $other ) );
		$this->assertSame( '', get_post_meta( $other, 'title2', true ) );
	}

	public function test_requires_edit_capability(): void {
		$this->setup_fields();
		$post       = $this->make_post();
		$subscriber = wp_insert_user(
			array(
				'user_login' => 's' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password(),
				'role'       => 'subscriber',
			)
		);
		wp_set_current_user( $subscriber );
		$this->submit( $post, array( 'field_title2' => 'x' ) );
		wp_set_current_user( 1 );

		$this->assertNull( wpff_get_field( 'title2', $post ) );
		wp_delete_user( $subscriber );
	}

	public function test_renders_meta_box_with_values(): void {
		$this->setup_fields();
		$post = $this->make_post();
		wpff_update_field( 'field_title2', 'Hello <world>', $post );
		wpff_update_field( 'field_tags', array( 'a' ), $post );

		$screen = new PostScreen( $this->plugin() );
		$groups = $screen->groups_for_post( get_post( $post ) );
		$this->assertCount( 1, $groups, 'Only the post group matches.' );

		// The nonce is printed once per page; reset that for this render.
		( new \ReflectionProperty( \FreeformFields\Admin\FormSupport::class, 'nonce_printed' ) )->setValue( null, false );

		ob_start();
		$screen->render_meta_box( get_post( $post ), array( 'args' => array( 'group' => reset( $groups ) ) ) );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'name="wpff_nonce"', $html );
		$this->assertStringContainsString( 'name="wpff[field_title2]" value="Hello &lt;world&gt;"', $html );
		$this->assertStringContainsString( 'value="a" checked=\'checked\'', $html );
		$this->assertStringContainsString( 'data-required="1"', $html );
		$this->assertStringContainsString( 'max="10"', $html );
	}
}
