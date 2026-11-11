<?php
/**
 * REST and block-form output never reveals what the requester could not read:
 * nested Password sub-fields, password-protected linked posts, unreadable media.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Tests;

final class RestExposureTest extends TestCase {

	/** @var int[] */
	private array $users = array();

	protected function tearDown(): void {
		global $wp_rest_server;
		remove_filter( 'wpff/emulate_acf_to_rest_api', '__return_true' );
		$wp_rest_server = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.GlobalVariablesOverride -- routes go back to normal.
		wp_set_current_user( 1 );
		foreach ( $this->users as $id ) {
			wp_delete_user( $id );
		}
		parent::tearDown();
	}

	private function user( string $role ): int {
		$id = wp_insert_user(
			array(
				'user_login' => $role . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password(),
				'user_email' => $role . wp_generate_password( 4, false ) . '@example.test',
				'role'       => $role,
			)
		);
		$this->assertIsInt( $id );
		$this->users[] = $id;
		return $id;
	}

	private function get( string $path, array $query = array() ): array {
		$request = new \WP_REST_Request( 'GET', $path );
		$request->set_query_params( $query );
		$response = rest_get_server()->dispatch( $request );
		return array( $response->get_status(), $response->get_data() );
	}

	public function test_nested_password_sub_fields_are_never_returned(): void {
		$this->make_group(
			array(
				array(
					'key'  => 'field_rx_top',
					'name' => 'rx_top',
					'type' => 'password',
				),
				array(
					'key'        => 'field_rx_grp',
					'name'       => 'rx_grp',
					'type'       => 'group',
					'sub_fields' => array(
						array(
							'key'  => 'field_rx_gpw',
							'name' => 'gpw',
							'type' => 'password',
						),
						array(
							'key'  => 'field_rx_gtx',
							'name' => 'gtx',
							'type' => 'text',
						),
					),
				),
				array(
					'key'        => 'field_rx_rep',
					'name'       => 'rx_rep',
					'type'       => 'repeater',
					'sub_fields' => array(
						array(
							'key'  => 'field_rx_rpw',
							'name' => 'rpw',
							'type' => 'password',
						),
					),
				),
				array(
					'key'     => 'field_rx_fc',
					'name'    => 'rx_fc',
					'type'    => 'flexible_content',
					'layouts' => array(
						array(
							'key'        => 'layout_rx',
							'name'       => 'lay',
							'label'      => 'Lay',
							'sub_fields' => array(
								array(
									'key'  => 'field_rx_fpw',
									'name' => 'fpw',
									'type' => 'password',
								),
							),
						),
					),
				),
			),
			array( 'show_in_rest' => 1 )
		);
		$post = $this->make_post();
		wpff_update_field( 'rx_top', 'TOP-secret', $post );
		wpff_update_field(
			'rx_grp',
			array(
				'gpw' => 'GRP-secret',
				'gtx' => 'visible',
			),
			$post
		);
		wpff_update_field( 'rx_rep', array( array( 'rpw' => 'REP-secret' ) ), $post );
		wpff_update_field(
			'rx_fc',
			array(
				array(
					'acf_fc_layout' => 'lay',
					'fpw'           => 'FC-secret',
				),
			),
			$post
		);

		wp_set_current_user( 0 );
		foreach ( array( array(), array( 'acf_format' => 'standard' ) ) as $query ) {
			[ $status, $data ] = $this->get( '/wp/v2/posts/' . $post, $query );
			$this->assertSame( 200, $status );
			$json = (string) wp_json_encode( $data['acf'] );
			foreach ( array( 'TOP-secret', 'GRP-secret', 'REP-secret', 'FC-secret' ) as $secret ) {
				$this->assertStringNotContainsString( $secret, $json, wp_json_encode( $query ) );
			}
			$this->assertSame( 'visible', $data['acf']['rx_grp']['gtx'] );
		}
	}

	public function test_acf_v3_does_not_serialize_protected_linked_posts(): void {
		$this->make_group(
			array(
				array(
					'key'           => 'field_rx_rel',
					'name'          => 'rx_rel',
					'type'          => 'relationship',
					'return_format' => 'object',
				),
			),
			array( 'show_in_rest' => 1 )
		);
		$protected = $this->make_post(
			array(
				'post_content'  => 'RX-PROTECTED-BODY',
				'post_password' => 'rx-pass-42',
			)
		);
		$host      = $this->make_post();
		wpff_update_field( 'rx_rel', array( $protected ), $host );

		global $wp_rest_server;
		add_filter( 'wpff/emulate_acf_to_rest_api', '__return_true' );
		$wp_rest_server = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.GlobalVariablesOverride -- rebuild routes with the filter on.

		wp_set_current_user( $this->user( 'subscriber' ) );
		foreach ( array( '/acf/v3/posts/' . $host, '/acf/v3/posts/' . $host . '/rx_rel', '/wp/v2/posts/' . $host ) as $path ) {
			[ $status, $data ] = $this->get( $path );
			$this->assertSame( 200, $status, $path );
			$json = (string) wp_json_encode( $data );
			$this->assertStringNotContainsString( 'RX-PROTECTED-BODY', $json, $path );
			$this->assertStringNotContainsString( 'rx-pass-42', $json, $path );
		}

		// Someone who may edit the protected post still gets it.
		wp_set_current_user( 1 );
		[ , $data ] = $this->get( '/acf/v3/posts/' . $host );
		$this->assertStringContainsString( 'RX-PROTECTED-BODY', (string) wp_json_encode( $data ) );
	}

	public function test_block_form_does_not_print_unreadable_media(): void {
		wp_set_current_user( 1 );
		$name = 'acf/rx-' . strtolower( wp_generate_password( 4, false, false ) );
		wpff_register_block_type(
			array(
				'name'  => $name,
				'title' => 'RX',
			)
		);
		$this->make_group(
			array(
				array(
					'key'  => 'field_rx_doc',
					'name' => 'doc',
					'type' => 'file',
				),
				array(
					'key'  => 'field_rx_pic',
					'name' => 'pic',
					'type' => 'image',
				),
				array(
					'key'  => 'field_rx_gal',
					'name' => 'gal',
					'type' => 'gallery',
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
		$private = $this->make_post( array( 'post_status' => 'private' ) );
		$hidden  = $this->make_image();
		wp_update_post(
			array(
				'ID'          => $hidden,
				'post_parent' => $private,
			)
		);
		clean_post_cache( $hidden );
		$public      = $this->make_image();
		$hidden_file = wp_basename( (string) wp_get_attachment_url( $hidden ) );
		$public_file = wp_basename( (string) wp_get_attachment_url( $public ) );

		wp_set_current_user( $this->user( 'contributor' ) );
		$this->assertFalse( current_user_can( 'read_post', $hidden ) );
		$form = static function ( string $field, $value ) use ( $name ): string {
			$request = new \WP_REST_Request( 'POST', '/wpff/v1/blocks/form' );
			$request->set_body_params(
				array(
					'name' => $name,
					'data' => array(
						$field       => $value,
						'_' . $field => 'field_rx_' . $field,
					),
				)
			);
			return (string) ( rest_get_server()->dispatch( $request )->get_data()['html'] ?? '' );
		};
		foreach ( array( 'doc', 'pic', 'gal' ) as $field ) {
			$hidden_value = 'gal' === $field ? array( (string) $hidden ) : (string) $hidden;
			$public_value = 'gal' === $field ? array( (string) $public ) : (string) $public;
			$this->assertStringNotContainsString( $hidden_file, $form( $field, $hidden_value ), $field );
			$this->assertStringContainsString( $public_file, $form( $field, $public_value ), $field );
		}
	}
}
