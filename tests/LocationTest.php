<?php
/**
 * Location rules.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Tests;

final class LocationTest extends TestCase {

	private static function rule( string $param, string $value, string $operator = '==' ): array {
		return array(
			'param'    => $param,
			'operator' => $operator,
			'value'    => $value,
		);
	}

	private function shows( array $location, int $post_id ): bool {
		return $this->plugin()->locations()->matches( $location, array( 'post_id' => $post_id ) );
	}

	public function test_or_of_and_groups(): void {
		$page = $this->make_post( array( 'post_type' => 'page' ) );
		$post = $this->make_post();

		$location = array(
			array( self::rule( 'post_type', 'page' ), self::rule( 'post_status', 'draft' ) ),
			array( self::rule( 'post_type', 'post' ) ),
		);
		$this->assertFalse( $this->shows( $location, $page ), 'First AND group fails on status, second on type.' );
		$this->assertTrue( $this->shows( $location, $post ) );
		$this->assertFalse( $this->shows( array(), $post ), 'No rules means hidden.' );
	}

	public function test_not_equal_and_unknown_param(): void {
		$post = $this->make_post();
		$this->assertTrue( $this->shows( array( array( self::rule( 'post_type', 'page', '!=' ) ) ), $post ) );
		$this->assertFalse( $this->shows( array( array( self::rule( 'no_such_param', 'x' ) ) ), $post ) );
	}

	public function test_template_format_taxonomy_and_parent(): void {
		$parent = $this->make_post( array( 'post_type' => 'page' ) );
		$child  = $this->make_post(
			array(
				'post_type'   => 'page',
				'post_parent' => $parent,
			)
		);
		update_post_meta( $child, '_wp_page_template', 'templates/landing.php' );

		$term = wp_insert_term( 'News ' . wp_generate_password( 4, false ), 'category' );
		$slug = get_term( $term['term_id'] )->slug;
		$post = $this->make_post( array( 'post_category' => array( $term['term_id'] ) ) );
		set_post_format( $post, 'video' );

		$this->assertTrue( $this->shows( array( array( self::rule( 'page_template', 'templates/landing.php' ) ) ), $child ) );
		$this->assertTrue( $this->shows( array( array( self::rule( 'page_template', 'default' ) ) ), $parent ) );
		$this->assertTrue( $this->shows( array( array( self::rule( 'page_parent', (string) $parent ) ) ), $child ) );
		$this->assertTrue( $this->shows( array( array( self::rule( 'page_type', 'child' ) ) ), $child ) );
		$this->assertTrue( $this->shows( array( array( self::rule( 'page_type', 'parent' ) ) ), $parent ) );
		$this->assertTrue( $this->shows( array( array( self::rule( 'page_type', 'top_level' ) ) ), $parent ) );
		$this->assertTrue( $this->shows( array( array( self::rule( 'post_format', 'video' ) ) ), $post ) );
		$this->assertTrue( $this->shows( array( array( self::rule( 'post_category', 'category:' . $slug ) ) ), $post ) );
		$this->assertTrue( $this->shows( array( array( self::rule( 'post_taxonomy', 'category:' . $slug ) ) ), $post ) );
		$this->assertFalse( $this->shows( array( array( self::rule( 'post_category', 'category:' . $slug ) ) ), $child ) );

		wp_delete_term( $term['term_id'], 'category' );
	}

	public function test_post_rules_do_not_match_non_post_screens(): void {
		$this->assertFalse( $this->plugin()->locations()->matches( array( array( self::rule( 'post_type', 'post', '!=' ) ) ), array() ) );
	}

	public function test_user_rules(): void {
		$post = $this->make_post();
		$this->assertTrue( $this->shows( array( array( self::rule( 'current_user_role', 'administrator' ) ) ), $post ) );
		$this->assertTrue( $this->shows( array( array( self::rule( 'current_user', 'logged_in' ) ) ), $post ) );
		$this->assertTrue( $this->shows( array( array( self::rule( 'current_user', 'viewing_back' ) ) ), $post ) );
	}

	public function test_custom_rule(): void {
		$post = $this->make_post();
		$this->plugin()->locations()->register( 'post_title', static fn( $rule, $screen ) => get_the_title( $screen['post_id'] ) === $rule['value'] );
		$this->assertTrue( $this->shows( array( array( self::rule( 'post_title', 'Test' ) ) ), $post ) );
	}
}
