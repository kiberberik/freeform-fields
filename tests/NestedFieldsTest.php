<?php
/**
 * Group (sub fields), layout fields and conditional logic.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Tests;

use FreeformFields\Admin\PostScreen;

final class NestedFieldsTest extends TestCase {

	private function hero_group(): void {
		$this->make_group(
			array(
				array(
					'key'        => 'field_hero',
					'name'       => 'hero',
					'type'       => 'group',
					'sub_fields' => array(
						array(
							'key'      => 'field_hero_title',
							'name'     => 'title',
							'label'    => 'Title',
							'required' => 1,
						),
						array(
							'key'           => 'field_hero_size',
							'name'          => 'size',
							'type'          => 'select',
							'choices'       => array(
								's' => 'Small',
								'l' => 'Large',
							),
							'return_format' => 'label',
						),
					),
				),
				array(
					'key'   => 'field_tab',
					'type'  => 'tab',
					'label' => 'Details',
				),
				array(
					'key'     => 'field_note',
					'type'    => 'message',
					'label'   => 'Note',
					'message' => 'Hello',
				),
			)
		);
	}

	public function test_group_values_use_acf_layout(): void {
		$this->hero_group();
		$post = $this->make_post();

		$this->assertTrue(
			wpff_update_field(
				'hero',
				array(
					'title' => 'Welcome',
					'size'  => 'l',
				),
				$post
			)
		);

		$this->assertSame( '', get_post_meta( $post, 'hero', true ) );
		$this->assertSame( 'field_hero', get_post_meta( $post, '_hero', true ) );
		$this->assertSame( 'Welcome', get_post_meta( $post, 'hero_title', true ) );
		$this->assertSame( 'field_hero_title', get_post_meta( $post, '_hero_title', true ) );

		$this->assertSame(
			array(
				'title' => 'Welcome',
				'size'  => 'Large',
			),
			wpff_get_field( 'hero', $post )
		);
		$this->assertSame( 'Large', wpff_get_field( 'hero_size', $post ), 'A sub field is readable by its full name.' );
		$this->assertSame( array( 'hero' ), array_keys( wpff_get_fields( $post ) ), 'get_fields() returns only top-level fields.' );

		wpff_delete_field( 'hero', $post );
		$this->assertFalse( metadata_exists( 'post', $post, 'hero_title' ) );
		$this->assertFalse( metadata_exists( 'post', $post, '_hero' ) );
	}

	public function test_layout_fields_have_no_name_and_store_nothing(): void {
		$this->hero_group();
		$group = $this->plugin()->fields()->get_group( 'group_test_' . $this->last_group_number() );
		$this->assertSame( array( 'hero', '', '' ), array_column( $group['fields'], 'name' ) );

		$post = $this->make_post();
		$this->submit(
			$post,
			array(
				'field_tab'  => 'x',
				'field_note' => 'y',
				'field_hero' => array( 'field_hero_title' => 'T' ),
			)
		);

		$meta = array_keys( get_post_meta( $post ) );
		$this->assertContains( 'hero_title', $meta );
		$this->assertNotContains( 'tab', $meta );
		$this->assertNotContains( '', $meta, 'Layout fields are not stored.' );
		$this->assertSame( 'T', wpff_get_field( 'hero', $post )['title'] );
	}

	public function test_form_submits_nested_values_and_reports_sub_errors(): void {
		$this->hero_group();
		$post = $this->make_post();

		$this->submit(
			$post,
			array(
				'field_hero' => array(
					'field_hero_title' => '',
					'field_hero_size'  => 's',
				),
			)
		);

		$errors = get_transient( 'wpff_errors_1_' . $post );
		$this->assertArrayHasKey( 'field_hero.field_hero_title', $errors, 'Errors are keyed by the field path.' );
		$this->assertSame( 'Small', wpff_get_field( 'hero', $post )['size'], 'Valid siblings are still saved.' );

		ob_start();
		$screen = new PostScreen( $this->plugin() );
		$groups = $screen->groups_for_post( get_post( $post ) );
		$screen->render_meta_box( get_post( $post ), array( 'args' => array( 'group' => reset( $groups ) ) ) );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'name="wpff[field_hero][field_hero_size]"', $html );
		$this->assertStringContainsString( 'class="wpff-tab-marker" data-label="Details"', $html );
		$this->assertStringContainsString( '<p>Hello</p>', $html );
	}

	public function test_conditions_render_as_data_attribute(): void {
		$this->make_group(
			array(
				array(
					'key'  => 'field_kind',
					'name' => 'kind',
				),
				array(
					'key'               => 'field_extra',
					'name'              => 'extra',
					'conditional_logic' => array(
						array(
							array(
								'field'    => 'field_kind',
								'operator' => '==',
								'value'    => 'x',
							),
						),
					),
				),
			)
		);
		$post   = $this->make_post();
		$screen = new PostScreen( $this->plugin() );
		$groups = $screen->groups_for_post( get_post( $post ) );
		ob_start();
		$screen->render_meta_box( get_post( $post ), array( 'args' => array( 'group' => reset( $groups ) ) ) );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'data-conditions="[[{&quot;field&quot;:&quot;field_kind&quot;', $html );
	}

	public function test_builder_sanitizes_sub_fields_and_conditions(): void {
		$input = array(
			'title'  => 'Nested',
			'fields' => array(
				array(
					'key'   => 'field_a',
					'label' => 'A',
					'name'  => 'a',
					'type'  => 'select',
				),
				array(
					'key'               => 'field_b',
					'label'             => 'B',
					'name'              => 'b',
					'conditional_logic' => array(
						array(
							array(
								'field'    => 'field_a',
								'operator' => '==',
								'value'    => 'x',
							),
							array(
								'field'    => 'field_b',
								'operator' => '==',
								'value'    => 'self',
							),
							array(
								'field'    => 'field_missing',
								'operator' => '==',
								'value'    => '1',
							),
							array(
								'field'    => 'field_a',
								'operator' => 'LIKE',
								'value'    => '1',
							),
						),
					),
				),
				array(
					'key'        => 'field_g',
					'label'      => 'G',
					'name'       => 'g',
					'type'       => 'group',
					'sub_fields' => array(
						array(
							'label' => 'One',
							'name'  => 'one',
						),
						array(
							'label' => 'Two',
							'name'  => 'one',
						),
					),
				),
				array(
					'label' => 'Tab without name',
					'type'  => 'tab',
				),
			),
		);

		$error = $this->plugin()->groups()->sanitize( $input );
		$this->assertWPError( $error );
		$this->assertSame( array( 'fields.2.sub_fields.1.name' ), array_column( $error->get_error_data()['errors'], 'path' ) );

		$input['fields'][2]['sub_fields'][1]['name'] = 'two';
		$group                                       = $this->plugin()->groups()->sanitize( $input );
		$this->assertIsArray( $group );
		$this->assertSame(
			array(
				array(
					array(
						'field'    => 'field_a',
						'operator' => '==',
						'value'    => 'x',
					),
				),
			),
			$group['fields'][1]['conditional_logic'],
			'Self references, unknown fields and bad operators are dropped.'
		);
		$this->assertSame( array( 'one', 'two' ), array_column( $group['fields'][2]['sub_fields'], 'name' ) );
		$this->assertSame( '', $group['fields'][3]['name'] );

		$saved = $this->plugin()->groups()->save( $input );
		$copy  = $this->plugin()->groups()->duplicate( $saved['key'] );
		$this->assertSame( $copy['fields'][0]['key'], $copy['fields'][1]['conditional_logic'][0][0]['field'], 'Duplicated conditions point at the new keys.' );
		$this->assertNotSame( $saved['fields'][2]['sub_fields'][0]['key'], $copy['fields'][2]['sub_fields'][0]['key'] );
	}

	private function submit( int $post_id, array $values ): void {
		$_POST = array(
			'post_ID'              => $post_id,
			PostScreen::NONCE_NAME => wp_create_nonce( PostScreen::NONCE_ACTION ),
			'wpff'                 => wp_slash( $values ),
		);
		( new PostScreen( $this->plugin() ) )->save_post( $post_id, get_post( $post_id ) );
	}

	private function last_group_number(): int {
		$keys = array_filter( array_keys( $this->plugin()->fields()->get_groups() ), static fn( $k ) => str_starts_with( $k, 'group_test_' ) );
		return max( array_map( static fn( $k ) => (int) substr( $k, 11 ), $keys ) );
	}

	private function assertWPError( $value ): void {
		$this->assertInstanceOf( \WP_Error::class, $value );
	}
}
