<?php
/**
 * Security fixes from the stage 9 audit, write batching and accessibility markup.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Tests;

use FreeformFields\Admin\PostScreen;

final class HardeningTest extends TestCase {

	/** @var int[] */
	private array $users = array();

	protected function tearDown(): void {
		foreach ( $this->users as $id ) {
			wp_delete_user( $id );
		}
		parent::tearDown();
	}

	private function user( string $role ): int {
		$id            = wp_insert_user(
			array(
				'user_login'   => $role . wp_generate_password( 6, false ),
				'user_pass'    => wp_generate_password(),
				'user_email'   => $role . wp_generate_password( 4, false ) . '@example.test',
				'role'         => $role,
				'display_name' => ucfirst( $role ),
			)
		);
		$this->users[] = $id;
		return $id;
	}

	private function read_acf( int $post_id ): array {
		$request = new \WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id );
		$request->set_query_params( array( 'acf_format' => 'standard' ) );
		return (array) rest_get_server()->dispatch( $request )->get_data()['acf'];
	}

	public function test_block_data_is_filtered_for_users_without_unfiltered_html(): void {
		$name = 'acf/hard-' . strtolower( wp_generate_password( 4, false, false ) );
		wpff_register_block_type(
			array(
				'name'  => $name,
				'title' => 'Hard',
			)
		);
		// Serialized like the editor does: "<" inside attributes is encoded as \u003c.
		$content = serialize_block(
			array(
				'blockName'    => $name,
				'attrs'        => array(
					'data' => array(
						'title'  => 'Hi <script>alert(1)</script><b>ok</b>',
						'_title' => 'field_x',
					),
				),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);

		wp_set_current_user( $this->user( 'author' ) );
		$author_post = $this->make_post( array( 'post_content' => wp_slash( $content ) ) );
		wp_set_current_user( 1 );
		$admin_post = $this->make_post( array( 'post_content' => wp_slash( $content ) ) );

		$stored = parse_blocks( get_post( $author_post )->post_content )[0]['attrs']['data']['title'];
		$this->assertStringNotContainsString( '<script>', $stored );
		$this->assertStringContainsString( '<b>ok</b>', $stored );
		$this->assertStringContainsString( '<script>', parse_blocks( get_post( $admin_post )->post_content )[0]['attrs']['data']['title'], 'Administrators keep unfiltered HTML, as in post content.' );
		unregister_block_type( $name );
	}

	public function test_rest_standard_format_hides_what_visitors_cannot_read(): void {
		$this->make_group(
			array(
				array(
					'key'  => 'field_h_rel',
					'name' => 'rel',
					'type' => 'relationship',
				),
				array(
					'key'           => 'field_h_owner',
					'name'          => 'owner',
					'type'          => 'user',
					'return_format' => 'object',
				),
				array(
					'key'  => 'field_h_note',
					'name' => 'note',
				),
			),
			array( 'show_in_rest' => 1 )
		);
		$public = $this->make_post( array( 'post_title' => 'Public' ) );
		$draft  = $this->make_post(
			array(
				'post_title'  => 'Secret draft',
				'post_status' => 'draft',
			)
		);
		$editor = $this->user( 'editor' );
		$post   = $this->make_post();
		wpff_update_field( 'rel', array( $public, $draft ), $post );
		wpff_update_field( 'owner', $editor, $post );
		wpff_update_field( 'note', 'Hidden note', $post );

		wp_set_current_user( 0 );
		$acf = $this->read_acf( $post );
		$this->assertSame( array( $public ), array_column( $acf['rel'], 'ID' ), 'Drafts are not exposed.' );
		$this->assertArrayNotHasKey( 'post_content', $acf['rel'][0], 'No raw post objects.' );
		$this->assertSame( array( 'ID', 'display_name' ), array_keys( $acf['owner'] ), 'No email or password hash.' );

		wp_set_current_user( 1 );
		$this->assertArrayHasKey( 'user_email', $this->read_acf( $post )['owner'], 'Users who may list users see the email.' );
		$this->assertCount( 2, $this->read_acf( $post )['rel'], 'Editors see drafts.' );

		// Password-protected posts hide their field values without the password.
		wp_update_post(
			array(
				'ID'            => $post,
				'post_password' => 'pw',
			)
		);
		wp_set_current_user( 0 );
		$request = new \WP_REST_Request( 'GET', '/wp/v2/posts/' . $post );
		$this->assertSame( array(), (array) rest_get_server()->dispatch( $request )->get_data()['acf'] );
		wp_set_current_user( 1 );
	}

	public function test_contributors_do_not_see_others_drafts_in_choices(): void {
		$this->make_group(
			array(
				array(
					'key'  => 'field_h_po',
					'name' => 'po',
					'type' => 'post_object',
				),
			)
		);
		$draft = $this->make_post(
			array(
				'post_title'  => 'Unique draft zq',
				'post_status' => 'draft',
			)
		);
		$pub   = $this->make_post( array( 'post_title' => 'Unique published zq' ) );
		$query = fn() => array_column( $this->plugin()->types()->get( 'post_object' )->query( $this->plugin()->fields()->get_field( 'field_h_po' ), 'zq', 1 )['results'], 'id' );

		$this->assertEqualsCanonicalizing( array( $draft, $pub ), $query() );
		wp_set_current_user( $this->user( 'contributor' ) );
		$this->assertSame( array( $pub ), $query() );
		wp_set_current_user( 1 );
	}

	public function test_bidirectional_respects_permissions_and_single_values(): void {
		$this->make_group(
			array(
				array(
					'key'                  => 'field_h_a',
					'name'                 => 'a_links',
					'type'                 => 'relationship',
					'bidirectional'        => 1,
					'bidirectional_target' => array( 'field_h_b' ),
				),
			)
		);
		$this->make_group(
			array(
				array(
					'key'  => 'field_h_b',
					'name' => 'b_link',
					'type' => 'post_object',
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
		$admin_page = $this->make_post( array( 'post_type' => 'page' ) );
		$taken_page = $this->make_post( array( 'post_type' => 'page' ) );
		update_post_meta( $taken_page, 'b_link', '999' );

		$author = $this->user( 'author' );
		wp_set_current_user( $author );
		$own = $this->make_post( array( 'post_author' => $author ) );
		wpff_update_field( 'field_h_a', array( $admin_page ), $own );
		$this->assertSame( '', get_post_meta( $admin_page, 'b_link', true ), 'An author cannot write to a page they cannot edit.' );

		wp_set_current_user( 1 );
		wpff_update_field( 'field_h_a', array(), $own );
		wpff_update_field( 'field_h_a', array( $taken_page, $admin_page ), $own );
		$this->assertSame( '999', get_post_meta( $taken_page, 'b_link', true ), 'A single-value link is not replaced.' );
		$this->assertSame( (string) $own, (string) get_post_meta( $admin_page, 'b_link', true ) );
	}

	public function test_unchanged_values_cost_no_queries(): void {
		global $wpdb;
		$this->make_group(
			array(
				array(
					'key'        => 'field_h_rows',
					'name'       => 'h_rows',
					'type'       => 'repeater',
					'sub_fields' => array(
						array(
							'key'  => 'field_h_row_t',
							'name' => 't',
						),
					),
				),
			)
		);
		$post = $this->make_post();
		$rows = array_map( static fn( $i ) => array( 't' => "row {$i}" ), range( 1, 50 ) );
		wpff_update_field( 'h_rows', $rows, $post );

		$before = $wpdb->num_queries;
		wpff_update_field( 'h_rows', $rows, $post );
		$this->assertLessThanOrEqual( 1, $wpdb->num_queries - $before, 'Re-saving the same rows only loads meta.' );
		$this->assertSame( 'row 50', wpff_get_field( 'h_rows', $post )[49]['t'] );

		$rows[3]['t'] = 'changed';
		wpff_update_field( 'h_rows', $rows, $post );
		$this->assertSame( 'changed', get_post_meta( $post, 'h_rows_3_t', true ) );
	}

	public function test_multi_control_fields_are_labelled_groups(): void {
		$this->make_group(
			array(
				array(
					'key'     => 'field_h_check',
					'name'    => 'checks',
					'label'   => 'Checks',
					'type'    => 'checkbox',
					'choices' => array( 'a' => 'A' ),
				),
				array(
					'key'  => 'field_h_text',
					'name' => 'plain',
				),
			)
		);
		$post   = $this->make_post();
		$screen = new PostScreen( $this->plugin() );
		$groups = $screen->groups_for_post( get_post( $post ) );
		ob_start();
		$screen->render_meta_box( get_post( $post ), array( 'args' => array( 'group' => reset( $groups ) ) ) );
		$html = ob_get_clean();
		$this->assertStringContainsString( '<label id="wpff-field_h_check-label" for="wpff-field_h_check">', $html );
		$this->assertStringContainsString( '<div class="wpff-input" role="group" aria-labelledby="wpff-field_h_check-label">', $html );
		$this->assertStringNotContainsString( 'aria-labelledby="wpff-field_h_text-label"', $html, 'Single inputs use the plain label.' );
	}
}
