<?php
/**
 * Term and user screens, location rules for them, and AJAX object permissions.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Tests;

use FreeformFields\Admin\FormSupport;
use FreeformFields\Admin\TermScreen;
use FreeformFields\Admin\UserScreen;

final class ScreensTest extends TestCase {

	private static function rule( string $param, string $value ): array {
		return array(
			array(
				array(
					'param'    => $param,
					'operator' => '==',
					'value'    => $value,
				),
			),
		);
	}

	private function post_form( array $values ): void {
		$_POST = array(
			FormSupport::NONCE_NAME => wp_create_nonce( FormSupport::NONCE_ACTION ),
			'wpff'                  => wp_slash( $values ),
		);
	}

	public function test_location_rules_for_terms_and_users(): void {
		$rules = $this->plugin()->locations();
		$this->assertTrue( $rules->matches( self::rule( 'taxonomy', 'category' ), array( 'taxonomy' => 'category' ) ) );
		$this->assertTrue( $rules->matches( self::rule( 'taxonomy', 'all' ), array( 'taxonomy' => 'post_tag' ) ) );
		$this->assertFalse( $rules->matches( self::rule( 'taxonomy', 'all' ), array( 'post_id' => $this->make_post() ) ), 'Term rules never match posts.' );
		$this->assertFalse( $rules->matches( self::rule( 'post_type', 'post' ), array( 'taxonomy' => 'category' ) ), 'Post rules never match terms.' );

		$this->assertTrue( $rules->matches( self::rule( 'user_form', 'edit' ), UserScreen::screen( 1, 'edit' ) ) );
		$this->assertFalse( $rules->matches( self::rule( 'user_form', 'add' ), UserScreen::screen( 1, 'edit' ) ) );
		$this->assertTrue( $rules->matches( self::rule( 'user_role', 'administrator' ), UserScreen::screen( 1, 'edit' ) ) );
		$this->assertFalse( $rules->matches( self::rule( 'user_role', 'administrator' ), UserScreen::screen( 0, 'add' ) ) );
	}

	public function test_term_fields_render_and_save(): void {
		$this->make_group(
			array(
				array(
					'key'      => 'field_icon',
					'name'     => 'icon',
					'required' => 1,
				),
			),
			array( 'location' => self::rule( 'taxonomy', 'category' ) )
		);
		$term   = wp_insert_term( 'Screen ' . wp_generate_password( 4, false ), 'category' );
		$screen = new TermScreen( $this->plugin() );

		ob_start();
		$screen->render_edit( get_term( $term['term_id'] ), 'category' );
		$html = ob_get_clean();
		$this->assertStringContainsString( '<tr class="wpff-field', $html );
		$this->assertStringContainsString( 'name="wpff[field_icon]"', $html );

		$this->post_form( array( 'field_icon' => 'star' ) );
		$screen->save( $term['term_id'], $term['term_taxonomy_id'], 'category' );
		$this->assertSame( 'star', wpff_get_field( 'icon', 'term_' . $term['term_id'] ) );

		// A new term with an empty required field is rejected before it is created.
		$this->post_form( array( 'field_icon' => '' ) );
		$this->assertInstanceOf( \WP_Error::class, $screen->validate_new_term( 'New', 'category' ) );
		$this->post_form( array( 'field_icon' => 'ok' ) );
		$this->assertSame( 'New', $screen->validate_new_term( 'New', 'category' ) );

		wp_delete_term( $term['term_id'], 'category' );
	}

	public function test_user_fields_validate_and_save(): void {
		$this->make_group(
			array(
				array(
					'key'  => 'field_phone',
					'name' => 'phone',
					'type' => 'number',
				),
			),
			array( 'location' => self::rule( 'user_form', 'all' ) )
		);
		$screen = new UserScreen( $this->plugin() );

		$this->post_form( array( 'field_phone' => 'abc' ) );
		$errors = new \WP_Error();
		$screen->validate( $errors, true, (object) array( 'ID' => 1 ) );
		$this->assertContains( 'wpff_field_phone', $errors->get_error_codes(), 'Invalid values block the profile save.' );

		$this->post_form( array( 'field_phone' => '123' ) );
		$screen->save( 1 );
		$this->assertSame( '123', get_user_meta( 1, 'phone', true ) );

		ob_start();
		$screen->render_edit( get_userdata( 1 ) );
		$this->assertStringContainsString( 'value="123"', ob_get_clean() );
		delete_user_meta( 1, 'phone' );
		delete_user_meta( 1, '_phone' );
	}

	public function test_ajax_objects_check_permissions(): void {
		$post = $this->make_post();
		$this->assertSame( array( 'post_id' => $post ), FormSupport::screen_for_object( 'post_' . $post )['screen'] );
		$this->assertSame( 'category', FormSupport::screen_for_object( 'newterm_category' )['screen']['taxonomy'] );
		$this->assertNull( FormSupport::screen_for_object( 'newterm_nope' ) );
		$this->assertNull( FormSupport::screen_for_object( 'post_999999' ) );
		$this->assertNull( FormSupport::screen_for_object( 'something' ) );

		$subscriber = wp_insert_user(
			array(
				'user_login' => 'sb' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password(),
				'role'       => 'subscriber',
			)
		);
		wp_set_current_user( $subscriber );
		$this->assertNull( FormSupport::screen_for_object( 'post_' . $post ) );
		$this->assertNull( FormSupport::screen_for_object( 'user_1' ) );
		$this->assertNull( FormSupport::screen_for_object( 'newuser' ) );
		$this->assertNotNull( FormSupport::screen_for_object( 'user_' . $subscriber ), 'Users may edit their own profile.' );
		wp_set_current_user( 1 );
		wp_delete_user( $subscriber );
	}
}
