<?php
/**
 * The internal config post types (field groups, options pages, post type/taxonomy
 * definitions) are writable only with Plugin::admin_capability(), by any core path.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Tests;

use FreeformFields\Plugin;

final class ConfigCapabilitiesTest extends TestCase {

	/** @var int[] */
	private array $users = array();

	/** @var int[] */
	private array $config_posts = array();

	protected function tearDown(): void {
		foreach ( $this->config_posts as $id ) {
			wp_delete_post( $id, true );
		}
		foreach ( $this->users as $id ) {
			wp_delete_user( $id );
		}
		wp_set_current_user( 1 );
		parent::tearDown();
	}

	private function user( string $role, string $pass ): int {
		$id = wp_insert_user(
			array(
				'user_login' => $role . wp_generate_password( 6, false ),
				'user_pass'  => $pass,
				'user_email' => $role . wp_generate_password( 4, false ) . '@example.test',
				'role'       => $role,
			)
		);
		$this->assertIsInt( $id );
		$this->users[] = $id;
		return $id;
	}

	/**
	 * One published admin-created post per config post type.
	 *
	 * @return array<string, int>
	 */
	private function config_posts(): array {
		wp_set_current_user( 1 );
		$posts = array();
		foreach ( Plugin::config_post_types() as $type ) {
			$this->assertTrue( post_type_exists( $type ), $type );
			$id = wp_insert_post(
				array(
					'post_type'    => $type,
					'post_status'  => 'publish',
					'post_title'   => 'Config ' . $type,
					'post_name'    => 'cfg_' . strtolower( wp_generate_password( 6, false, false ) ),
					'post_content' => '{}',
				),
				true
			);
			$this->assertIsInt( $id );
			$this->config_posts[] = $id;
			$posts[ $type ]       = $id;
		}
		return $posts;
	}

	public function test_editor_cannot_read_edit_or_delete_config_posts(): void {
		$posts = $this->config_posts();
		wp_set_current_user( $this->user( 'editor', wp_generate_password() ) );
		$this->assertTrue( current_user_can( 'edit_others_posts' ) );
		foreach ( $posts as $type => $id ) {
			$this->assertFalse( current_user_can( 'edit_post', $id ), "edit_post on $type" );
			$this->assertFalse( current_user_can( 'delete_post', $id ), "delete_post on $type" );
			$this->assertFalse( current_user_can( 'read_post', $id ), "read_post on $type" );
			$this->assertFalse( current_user_can( get_post_type_object( $type )->cap->create_posts ), "create on $type" );
		}
	}

	public function test_admin_keeps_full_access(): void {
		$posts = $this->config_posts();
		wp_set_current_user( 1 );
		foreach ( $posts as $type => $id ) {
			$this->assertTrue( current_user_can( 'edit_post', $id ), "edit_post on $type" );
			$this->assertTrue( current_user_can( 'delete_post', $id ), "delete_post on $type" );
		}
		// The override must not turn manage_options itself into a post meta capability.
		$this->assertTrue( current_user_can( 'manage_options' ) );
	}

	public function test_xmlrpc_cannot_rewrite_or_delete_config_posts(): void {
		$posts = $this->config_posts();
		$pass  = wp_generate_password( 24, false );
		$id    = $this->user( 'editor', $pass );
		$login = get_userdata( $id )->user_login;

		require_once ABSPATH . WPINC . '/class-IXR.php';
		require_once ABSPATH . WPINC . '/class-wp-xmlrpc-server.php';
		$server = new \wp_xmlrpc_server();

		foreach ( $posts as $type => $post_id ) {
			$edit = $server->wp_editPost( array( 0, $login, $pass, $post_id, array( 'post_content' => '{"post_id":"users_can","capability":"read"}' ) ) );
			$this->assertInstanceOf( \IXR_Error::class, $edit, "wp.editPost on $type" );
			$del = $server->wp_deletePost( array( 0, $login, $pass, $post_id ) );
			$this->assertInstanceOf( \IXR_Error::class, $del, "wp.deletePost on $type" );
			$this->assertSame( '{}', get_post( $post_id )->post_content );
		}
	}

	public function test_xmlrpc_write_filter_rejects_config_types_only(): void {
		foreach ( Plugin::config_post_types() as $type ) {
			$this->assertFalse( Plugin::block_xmlrpc_config_writes( true, get_post_type_object( $type ) ), $type );
		}
		$this->assertTrue( Plugin::block_xmlrpc_config_writes( true, get_post_type_object( 'post' ) ) );
	}
}
