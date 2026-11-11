<?php
/**
 * Regression tests for the security and performance audit fixes.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Tests;

use FreeformFields\Fields\Bidirectional;
use FreeformFields\Front\Forms;
use FreeformFields\Registry\AcfStamp;

final class AuditFixesTest extends TestCase {

	/** @var int[] */
	private array $users = array();

	protected function tearDown(): void {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( $this->users as $id ) {
			wp_delete_user( $id );
		}
		parent::tearDown();
	}

	private function user( string $role ): int {
		$id            = wp_insert_user(
			array(
				'user_login'   => $role . '_' . wp_generate_password( 6, false, false ),
				'user_pass'    => wp_generate_password(),
				'user_email'   => wp_generate_password( 6, false, false ) . '@example.test',
				'role'         => $role,
				'display_name' => ucfirst( $role ),
			)
		);
		$this->users[] = $id;
		return $id;
	}

	public function test_visitor_saves_do_not_sync_into_posts_they_cannot_edit(): void {
		$this->make_group(
			array(
				array(
					'key'                  => 'field_af_a',
					'name'                 => 'af_links',
					'type'                 => 'relationship',
					'bidirectional'        => 1,
					'bidirectional_target' => array( 'field_af_b' ),
				),
				array(
					'key'  => 'field_af_b',
					'name' => 'af_back',
					'type' => 'relationship',
				),
			)
		);
		$form_post = $this->make_post();
		$victim    = $this->make_post();

		wp_set_current_user( 0 );
		Bidirectional::as_visitor( static fn() => wpff_update_field( 'field_af_a', array( $victim ), $form_post ) );
		$this->assertSame( '', get_post_meta( $victim, 'af_back', true ), 'A visitor cannot write into another post.' );

		// Code running without a user (cron, CLI, theme code) still keeps both sides in step.
		wpff_update_field( 'field_af_a', array(), $form_post );
		wpff_update_field( 'field_af_a', array( $victim ), $form_post );
		$this->assertSame( array( (string) $form_post ), array_map( 'strval', (array) get_post_meta( $victim, 'af_back', true ) ) );
	}

	public function test_block_data_from_authors_is_validated_by_field_type(): void {
		$name = 'acf/audit-' . strtolower( wp_generate_password( 4, false, false ) );
		wpff_register_block_type(
			array(
				'name'  => $name,
				'title' => 'Audit',
			)
		);
		$this->make_group(
			array(
				array(
					'key'     => 'field_af_variant',
					'name'    => 'variant',
					'type'    => 'select',
					'choices' => array(
						'light' => 'Light',
						'dark'  => 'Dark',
					),
				),
				array(
					'key'  => 'field_af_pick',
					'name' => 'pick',
					'type' => 'post_object',
				),
			),
			array(
				'location' => array(
					array(
						array(
							'param'    => 'block',
							'operator' => '==',
							'value'    => $name,
						),
					),
				),
			)
		);
		$draft   = $this->make_post( array( 'post_status' => 'draft' ) );
		$content = static fn( array $data ) => serialize_block(
			array(
				'blockName'    => $name,
				'attrs'        => array( 'data' => $data ),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
		$stored  = static fn( int $id ) => parse_blocks( get_post( $id )->post_content )[0]['attrs']['data'];

		wp_set_current_user( $this->user( 'author' ) );
		$bad  = $this->make_post(
			array(
				'post_content' => wp_slash(
					$content(
						array(
							'variant'  => 'x" onmouseover="alert(1)',
							'_variant' => 'field_af_variant',
							'pick'     => (string) $draft,
							'_pick'    => 'field_af_pick',
							'extra'    => 'kept <b>bold</b>',
							'_extra'   => 'field_af_variant',
						)
					)
				),
			)
		);
		$good = $this->make_post(
			array(
				'post_content' => wp_slash(
					$content(
						array(
							'variant'  => 'dark',
							'_variant' => 'field_af_variant',
						)
					)
				),
			)
		);
		wp_set_current_user( 1 );

		$data = $stored( $bad );
		$this->assertSame( '', $data['variant'] ?? '', 'A value outside the choices is cleared.' );
		$this->assertEmpty( $data['pick'] ?? '', 'A draft the author cannot read is dropped.' );
		$this->assertSame( 'kept <b>bold</b>', $data['extra'], 'Keys no field owns stay.' );
		$this->assertArrayNotHasKey( '_extra', $data, 'They cannot borrow another field.' );
		$this->assertSame( 'dark', $stored( $good )['variant'] );
		unregister_block_type( $name );
	}

	public function test_acf_to_rest_api_output_hides_unreadable_posts_and_user_details(): void {
		$this->on( 'wpff/emulate_acf_to_rest_api', '__return_true' );
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride -- rebuild routes with the filter on.
		$this->make_group(
			array(
				array(
					'key'  => 'field_af_rel',
					'name' => 'rel',
					'type' => 'relationship',
				),
				array(
					'key'           => 'field_af_owner',
					'name'          => 'owner',
					'type'          => 'user',
					'return_format' => 'object',
				),
			)
		);
		$public = $this->make_post();
		$draft  = $this->make_post( array( 'post_status' => 'draft' ) );
		$post   = $this->make_post();
		wpff_update_field( 'rel', array( $public, $draft ), $post );
		wpff_update_field( 'owner', $this->user( 'editor' ), $post );

		wp_set_current_user( 0 );
		$acf = rest_get_server()->dispatch( new \WP_REST_Request( 'GET', '/acf/v3/posts/' . $post ) )->get_data()['acf'];
		$this->assertSame( array( $public ), array_map( static fn( $p ) => $p->ID, $acf['rel'] ), 'Drafts are left out.' );
		$this->assertIsArray( $acf['owner'], 'Never a WP_User: its data holds the password hash.' );
		$this->assertArrayNotHasKey( 'user_email', $acf['owner'] );
		$this->assertArrayHasKey( 'display_name', $acf['owner'] );
		wp_set_current_user( 1 );
	}

	public function test_rest_never_returns_password_fields(): void {
		$this->make_group(
			array(
				array(
					'key'  => 'field_af_secret',
					'name' => 'secret',
					'type' => 'password',
				),
			),
			array( 'show_in_rest' => 1 )
		);
		$post = $this->make_post();
		wpff_update_field( 'secret', 'hunter2', $post );
		wp_set_current_user( 0 );
		$acf = (array) rest_get_server()->dispatch( new \WP_REST_Request( 'GET', '/wp/v2/posts/' . $post ) )->get_data()['acf'];
		wp_set_current_user( 1 );
		$this->assertArrayNotHasKey( 'secret', $acf );
	}

	public function test_signed_form_settings_belong_to_one_user_and_expire(): void {
		$sign   = new \ReflectionMethod( Forms::class, 'sign' );
		$verify = new \ReflectionMethod( Forms::class, 'verify' );
		$args   = array(
			'id'      => 'f1',
			'post_id' => 42,
		);

		$signed = $sign->invoke( null, $args );
		$this->assertSame( 42, $verify->invoke( null, $signed )['post_id'] );
		$this->assertArrayNotHasKey( '_uid', $verify->invoke( null, $signed ) );

		wp_set_current_user( 0 );
		$this->assertNull( $verify->invoke( null, $signed ), 'Another visitor cannot replay it.' );
		wp_set_current_user( 1 );

		$this->on( 'wpff/form_lifetime', static fn() => -1 );
		$this->assertNull( $verify->invoke( null, $sign->invoke( null, $args ) ), 'Expired settings are refused.' );
	}

	public function test_acf_fingerprint_is_cached_and_cleared_on_change(): void {
		global $wpdb;
		AcfStamp::flush();
		$first  = AcfStamp::get( 'groups' );
		$before = $wpdb->num_queries;
		$this->assertSame( $first, AcfStamp::get( 'groups' ) );
		$this->assertSame( $before, $wpdb->num_queries, 'No query once cached.' );

		$id = wp_insert_post(
			array(
				'post_type'   => 'acf-field-group',
				'post_status' => 'publish',
				'post_title'  => 'Stamp',
			)
		);
		try {
			$this->assertNotSame( $first, AcfStamp::get( 'groups' ), 'Saving an ACF post clears it.' );
		} finally {
			wp_delete_post( $id, true );
		}
		$this->assertSame( $first, AcfStamp::get( 'groups' ) );
	}

	public function test_images_in_rows_are_loaded_in_one_go(): void {
		$this->make_group(
			array(
				array(
					'key'        => 'field_af_slides',
					'name'       => 'slides',
					'type'       => 'repeater',
					'sub_fields' => array(
						array(
							'key'  => 'field_af_slide_img',
							'name' => 'img',
							'type' => 'image',
						),
					),
				),
			)
		);
		$images = array( $this->make_image(), $this->make_image(), $this->make_image(), $this->make_image() );
		$post   = $this->make_post();
		wpff_update_field( 'slides', array_map( static fn( $id ) => array( 'img' => $id ), $images ), $post );
		get_field( 'slides', $post ); // Warm the post's own caches.

		global $wpdb;
		foreach ( $images as $id ) {
			clean_post_cache( $id );
		}
		$before = $wpdb->num_queries;
		$rows   = get_field( 'slides', $post );
		$this->assertSame( $images, array_column( array_column( $rows, 'img' ), 'ID' ) );
		$this->assertLessThanOrEqual( 2, $wpdb->num_queries - $before, 'Posts and meta of all images in one query each.' );
		foreach ( $images as $id ) {
			wp_delete_attachment( $id, true );
		}
	}

	/**
	 * Adds a filter for this test only.
	 *
	 * @param string   $hook     Hook.
	 * @param callable $callback Callback.
	 */
	private function on( string $hook, callable $callback ): void {
		add_filter( $hook, $callback );
		$this->cleanups[] = static fn() => remove_filter( $hook, $callback );
	}

	/** @var array<int, callable> */
	private array $cleanups = array();

	protected function assertPostConditions(): void {
		foreach ( $this->cleanups as $cleanup ) {
			$cleanup();
		}
		$this->cleanups = array();
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride -- routes go back to normal.
	}
}
