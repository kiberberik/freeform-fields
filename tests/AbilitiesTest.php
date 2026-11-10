<?php
/**
 * Abilities API integration.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Tests;

final class AbilitiesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'The Abilities API needs WordPress 6.9.' );
		}
	}

	public function test_abilities_are_registered_and_check_permissions(): void {
		$this->make_group( array( array( 'name' => 'isbn' ) ), array( 'key' => 'group_books' ) );
		$post = $this->make_post();
		wpff_update_field( 'isbn', '978-3', $post );

		$groups = wp_get_ability( 'wpff/list-field-groups' );
		$this->assertNotNull( $groups );
		$keys = array_column( $groups->execute(), 'key' );
		$this->assertContains( 'group_books', $keys );

		$values = wp_get_ability( 'wpff/get-field-values' );
		$this->assertSame( '978-3', $values->execute( array( 'object_id' => $post ) )['isbn'] );

		$subscriber = wp_insert_user(
			array(
				'user_login' => 'ab' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password(),
				'role'       => 'subscriber',
			)
		);
		wp_set_current_user( $subscriber );
		$this->assertInstanceOf( \WP_Error::class, $values->execute( array( 'object_id' => $post ) ) );
		$this->assertInstanceOf( \WP_Error::class, $groups->execute() );
		wp_set_current_user( 1 );
		wp_delete_user( $subscriber );
	}
}
