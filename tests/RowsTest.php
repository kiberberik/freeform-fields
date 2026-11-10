<?php
/**
 * Repeater, flexible content, row loops, gallery and clone.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Tests;

use FreeformFields\Admin\PostScreen;
use FreeformFields\Api\RowLoop;
use FreeformFields\Validation\Validator;

final class RowsTest extends TestCase {

	protected function tearDown(): void {
		RowLoop::reset_all();
		parent::tearDown();
	}

	private function repeater_group(): void {
		$this->make_group(
			array(
				array(
					'key'        => 'field_slides',
					'name'       => 'slides',
					'type'       => 'repeater',
					'max'        => 3,
					'sub_fields' => array(
						array(
							'key'      => 'field_slide_title',
							'name'     => 'title',
							'required' => 1,
						),
						array(
							'key'           => 'field_slide_kind',
							'name'          => 'kind',
							'type'          => 'select',
							'choices'       => array(
								'a' => 'Alpha',
								'b' => 'Beta',
							),
							'return_format' => 'label',
						),
						array(
							'key'        => 'field_slide_tags',
							'name'       => 'tags',
							'type'       => 'repeater',
							'sub_fields' => array(
								array(
									'key'  => 'field_tag_name',
									'name' => 'name',
								),
							),
						),
					),
				),
			)
		);
	}

	private function flexible_group(): void {
		$this->make_group(
			array(
				array(
					'key'     => 'field_sections',
					'name'    => 'sections',
					'type'    => 'flexible_content',
					'layouts' => array(
						array(
							'name'       => 'hero',
							'label'      => 'Hero',
							'max'        => 1,
							'sub_fields' => array(
								array(
									'key'  => 'field_hero_heading',
									'name' => 'heading',
								),
							),
						),
						array(
							'name'       => 'text',
							'label'      => 'Text',
							'sub_fields' => array(
								array(
									'key'       => 'field_text_body',
									'name'      => 'body',
									'type'      => 'textarea',
									'new_lines' => 'br',
								),
							),
						),
					),
				),
			)
		);
	}

	public function test_repeater_storage_matches_acf(): void {
		$this->repeater_group();
		$post = $this->make_post();

		wpff_update_field(
			'slides',
			array(
				array(
					'title' => 'One',
					'kind'  => 'a',
					'tags'  => array( array( 'name' => 'x' ), array( 'name' => 'y' ) ),
				),
				array(
					'title' => 'Two',
					'kind'  => 'b',
				),
				array( 'title' => 'Three' ),
			),
			$post
		);

		$this->assertSame( 3, (int) get_post_meta( $post, 'slides', true ) );
		$this->assertSame( 'field_slides', get_post_meta( $post, '_slides', true ) );
		$this->assertSame( 'Two', get_post_meta( $post, 'slides_1_title', true ) );
		$this->assertSame( 'field_slide_title', get_post_meta( $post, '_slides_1_title', true ) );
		$this->assertSame( 'y', get_post_meta( $post, 'slides_0_tags_1_name', true ) );
		$this->assertSame( 'field_tag_name', get_post_meta( $post, '_slides_0_tags_1_name', true ) );

		$rows = wpff_get_field( 'slides', $post );
		$this->assertSame( 'Alpha', $rows[0]['kind'] );
		$this->assertSame( array( array( 'name' => 'x' ), array( 'name' => 'y' ) ), $rows[0]['tags'] );
		$this->assertFalse( $rows[2]['tags'], 'An empty nested repeater formats to false, like ACF.' );

		// Shrinking removes the old rows' meta.
		wpff_update_field( 'slides', array( array( 'title' => 'Only' ) ), $post );
		$this->assertSame( 1, (int) get_post_meta( $post, 'slides', true ) );
		$this->assertFalse( metadata_exists( 'post', $post, 'slides_1_title' ) );
		$this->assertFalse( metadata_exists( 'post', $post, 'slides_2_title' ) );
		$this->assertFalse( metadata_exists( 'post', $post, '_slides_1_kind' ) );

		wpff_update_field( 'slides', array(), $post );
		$this->assertFalse( wpff_get_field( 'slides', $post ) );
	}

	public function test_reads_rows_written_by_acf(): void {
		$this->repeater_group();
		$this->flexible_group();
		$post = $this->make_post();
		// What ACF stores for a repeater with one row and a flexible field with two rows.
		foreach (
			array(
				'slides'              => 1,
				'_slides'             => 'field_slides',
				'slides_0_title'      => 'From ACF',
				'_slides_0_title'     => 'field_slide_title',
				'sections'            => array( 'text', 'hero' ),
				'_sections'           => 'field_sections',
				'sections_0_body'     => "a\nb",
				'_sections_0_body'    => 'field_text_body',
				'sections_1_heading'  => 'Big',
				'_sections_1_heading' => 'field_hero_heading',
			) as $key => $value
		) {
			add_post_meta( $post, $key, $value );
		}

		$this->assertSame( 'From ACF', wpff_get_field( 'slides', $post )[0]['title'] );
		$this->assertSame(
			array(
				array(
					'acf_fc_layout' => 'text',
					'body'          => "a<br />\nb",
				),
				array(
					'acf_fc_layout' => 'hero',
					'heading'       => 'Big',
				),
			),
			wpff_get_field( 'sections', $post )
		);
	}

	public function test_flexible_layout_change_removes_old_values(): void {
		$this->flexible_group();
		$post = $this->make_post();
		wpff_update_field(
			'sections',
			array(
				array(
					'acf_fc_layout' => 'hero',
					'heading'       => 'H',
				),
				array(
					'acf_fc_layout' => 'missing',
					'x'             => 1,
				),
			),
			$post
		);
		$this->assertSame( array( 'hero' ), get_post_meta( $post, 'sections', true ), 'Rows with unknown layouts are skipped.' );

		wpff_update_field(
			'sections',
			array(
				array(
					'acf_fc_layout' => 'text',
					'body'          => 'T',
				),
			),
			$post
		);
		$this->assertFalse( metadata_exists( 'post', $post, 'sections_0_heading' ) );
		$this->assertSame( 'T', get_post_meta( $post, 'sections_0_body', true ) );
	}

	public function test_row_loops(): void {
		$this->repeater_group();
		$this->flexible_group();
		$this->make_group(
			array(
				array(
					'key'        => 'field_box',
					'name'       => 'box',
					'type'       => 'group',
					'sub_fields' => array(
						array(
							'key'  => 'field_box_color',
							'name' => 'color',
						),
					),
				),
			)
		);
		$post = $this->make_post();
		wpff_update_field(
			'slides',
			array(
				array(
					'title' => 'One',
					'kind'  => 'a',
					'tags'  => array( array( 'name' => 'x' ), array( 'name' => 'y' ) ),
				),
				array(
					'title' => 'Two <b>bold</b><script>x</script>',
					'kind'  => 'b',
				),
			),
			$post
		);
		wpff_update_field(
			'sections',
			array(
				array(
					'acf_fc_layout' => 'text',
					'body'          => 'Body',
				),
				array(
					'acf_fc_layout' => 'hero',
					'heading'       => 'Head',
				),
			),
			$post
		);
		wpff_update_field( 'box', array( 'color' => 'red' ), $post );

		$out = array();
		while ( have_rows( 'slides', $post ) ) {
			the_row();
			$tags = array();
			while ( have_rows( 'tags' ) ) {
				the_row();
				$tags[] = get_sub_field( 'name' );
			}
			$out[] = get_row_index() . ':' . get_sub_field( 'kind' ) . ':' . implode( '|', $tags );
		}
		$this->assertSame( array( '1:Alpha:x|y', '2:Beta:' ), $out );

		// The same loop can run again once it has finished.
		$count = 0;
		while ( have_rows( 'slides', $post ) ) {
			the_row();
			++$count;
			if ( 2 === $count ) {
				ob_start();
				the_sub_field( 'title' );
				$this->assertSame( 'Two <b>bold</b>x', ob_get_clean(), 'the_sub_field() escapes.' );
			}
		}
		$this->assertSame( 2, $count );

		$layouts = array();
		while ( have_rows( 'sections', $post ) ) {
			the_row();
			$layouts[] = get_row_layout() . '=' . ( 'hero' === get_row_layout() ? get_sub_field( 'heading' ) : get_sub_field( 'body' ) );
		}
		$this->assertSame( array( 'text=Body', 'hero=Head' ), $layouts );

		$this->assertTrue( have_rows( 'box', $post ) );
		the_row();
		$this->assertSame( 'red', get_sub_field( 'color' ) );
		$this->assertFalse( have_rows( 'box', $post ) );

		$this->assertFalse( have_rows( 'not_a_field', $post ) );
		$this->assertNull( get_sub_field( 'title' ), 'Outside a loop there is no sub field.' );
	}

	public function test_add_update_delete_row(): void {
		$this->repeater_group();
		$post = $this->make_post();

		$this->assertSame( 1, add_row( 'slides', array( 'title' => 'A' ), $post ) );
		$this->assertSame( 2, add_row( 'field_slides', array( 'field_slide_title' => 'B' ), $post ) );
		$this->assertTrue( update_row( 'slides', 1, array( 'kind' => 'b' ), $post ) );
		$this->assertTrue( delete_row( 'slides', 2, $post ) );

		$rows = wpff_get_field( 'slides', $post );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'A', $rows[0]['title'] );
		$this->assertSame( 'Beta', $rows[0]['kind'] );
		$this->assertFalse( delete_row( 'slides', 5, $post ) );
	}

	public function test_validation_paths_and_limits(): void {
		$this->repeater_group();
		$this->flexible_group();
		$fields    = array_column( array( $this->plugin()->fields()->get_field( 'field_slides' ), $this->plugin()->fields()->get_field( 'field_sections' ) ), null, 'key' );
		$validator = new Validator( $this->plugin()->types() );

		$result = $validator->validate(
			array(
				'field_slides'   => array(
					'__present' => '1',
					'r2'        => array( 'field_slide_title' => 'B' ),
					'r1'        => array(
						'field_slide_title' => '',
						'field_slide_tags'  => array(
							'__present' => '1',
							't1'        => array( 'field_tag_name' => 'n' ),
						),
					),
				),
				'field_sections' => array(
					'__present' => '1',
					'a'         => array(
						'acf_fc_layout'      => 'hero',
						'field_hero_heading' => '1',
					),
					'b'         => array(
						'acf_fc_layout'      => 'hero',
						'field_hero_heading' => '2',
					),
				),
			),
			$fields
		);

		$this->assertArrayHasKey( 'field_slides.r1.field_slide_title', $result['errors'] );
		$this->assertArrayHasKey( 'field_sections', $result['errors'], 'Layout max is reported on the field.' );
		$this->assertArrayNotHasKey( 'field_sections', $result['values'], 'A field with a row-level error is not saved.' );
		$this->assertArrayNotHasKey( 'field_slides', $result['values'], 'Rows with an invalid sub field are not saved.' );

		$valid = $validator->validate(
			array(
				'field_slides' => array(
					'r2' => array( 'field_slide_title' => 'B' ),
					'r1' => array(
						'field_slide_title' => 'A',
						'field_slide_tags'  => array( 't1' => array( 'field_tag_name' => 'n' ) ),
					),
				),
			),
			$fields
		);
		$this->assertSame( array( 'B', 'A' ), array_column( $valid['values']['field_slides'], 'field_slide_title' ), 'Row order is kept.' );
		$this->assertSame( array( array( 'field_tag_name' => 'n' ) ), $valid['values']['field_slides'][1]['field_slide_tags'] );

		$too_many = array( '__present' => '1' );
		foreach ( range( 1, 4 ) as $i ) {
			$too_many[ "r{$i}" ] = array( 'field_slide_title' => "t{$i}" );
		}
		$this->assertArrayHasKey( 'field_slides', $validator->validate( array( 'field_slides' => $too_many ), $fields )['errors'] );
	}

	public function test_form_saves_rows_and_renders_template(): void {
		$this->repeater_group();
		$post  = $this->make_post();
		$_POST = array(
			'post_ID'              => $post,
			PostScreen::NONCE_NAME => wp_create_nonce( PostScreen::NONCE_ACTION ),
			'wpff'                 => wp_slash(
				array(
					'field_slides' => array(
						'__present' => '1',
						'rk9'       => array(
							'field_slide_title' => 'Saved',
							'field_slide_kind'  => 'b',
						),
					),
				)
			),
		);
		( new PostScreen( $this->plugin() ) )->save_post( $post, get_post( $post ) );
		$this->assertSame( 'Saved', wpff_get_field( 'slides', $post )[0]['title'] );

		// Only the marker: every row was removed.
		$_POST['wpff'] = array( 'field_slides' => array( '__present' => '1' ) );
		( new PostScreen( $this->plugin() ) )->save_post( $post, get_post( $post ) );
		$this->assertSame( 0, (int) get_post_meta( $post, 'slides', true ) );

		wpff_update_field( 'slides', array( array( 'title' => 'Row' ) ), $post );
		$screen = new PostScreen( $this->plugin() );
		$groups = $screen->groups_for_post( get_post( $post ) );
		ob_start();
		$screen->render_meta_box( get_post( $post ), array( 'args' => array( 'group' => reset( $groups ) ) ) );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'name="wpff[field_slides][row-0][field_slide_title]" value="Row"', $html );
		$this->assertStringContainsString( 'data-path="field_slides.row-0.field_slide_title"', $html );
		$this->assertStringContainsString( '<template class="wpff-row-template" data-placeholder="__ROWID_field_slides__">', $html );
		$this->assertStringContainsString( 'name="wpff[field_slides][__ROWID_field_slides__][field_slide_title]"', $html );
		$this->assertStringContainsString( 'name="wpff[field_slides][row-0][field_slide_tags][__present]"', $html );
	}

	public function test_gallery(): void {
		$a = $this->make_image();
		$b = $this->make_image();
		$this->make_group(
			array(
				array(
					'key'           => 'field_photos',
					'name'          => 'photos',
					'type'          => 'gallery',
					'max'           => 2,
					'return_format' => 'id',
				),
			)
		);
		$field     = $this->plugin()->fields()->get_field( 'field_photos' );
		$validator = new Validator( $this->plugin()->types() );

		$ok = $validator->validate( array( 'field_photos' => array( (string) $b, (string) $a, (string) $b, '' ) ), array( 'field_photos' => $field ) );
		$this->assertSame( array( $b, $a ), $ok['values']['field_photos'], 'Order kept, duplicates and blanks dropped.' );

		$page = $this->make_post( array( 'post_type' => 'page' ) );
		$bad  = $validator->validate( array( 'field_photos' => array( (string) $page ) ), array( 'field_photos' => $field ) );
		$this->assertArrayHasKey( 'field_photos', $bad['errors'] );

		$post = $this->make_post();
		wpff_update_field( 'photos', array( $b, $a ), $post );
		$this->assertSame( array( $b, $a ), wpff_get_field( 'photos', $post ) );
		wpff_update_field( 'photos', array(), $post );
		$this->assertFalse( wpff_get_field( 'photos', $post ) );
	}

	public function test_clone_seamless_and_group(): void {
		$this->make_group(
			array(
				array(
					'key'   => 'field_src_title',
					'name'  => 'title',
					'label' => 'Title',
				),
				array(
					'key'               => 'field_src_sub',
					'name'              => 'subtitle',
					'label'             => 'Subtitle',
					'conditional_logic' => array(
						array(
							array(
								'field'    => 'field_src_title',
								'operator' => '!=empty',
								'value'    => '',
							),
						),
					),
				),
			),
			array(
				'key'      => 'group_src',
				'location' => array(),
			)
		);
		$this->make_group(
			array(
				array(
					'key'         => 'field_seo',
					'name'        => 'seo',
					'label'       => 'SEO',
					'type'        => 'clone',
					'clone'       => array( 'group_src' ),
					'display'     => 'seamless',
					'prefix_name' => 1,
				),
				array(
					'key'     => 'field_card',
					'name'    => 'card',
					'label'   => 'Card',
					'type'    => 'clone',
					'clone'   => array( 'field_src_title' ),
					'display' => 'group',
				),
				array(
					'key'   => 'field_self',
					'name'  => 'self',
					'type'  => 'clone',
					'clone' => array( 'group_test_clone_target' ),
				),
			),
			array( 'key' => 'group_test_clone_target' )
		);

		$group = $this->plugin()->fields()->get_group( 'group_test_clone_target' );
		$names = array_column( $group['fields'], 'name' );
		$this->assertSame( array( 'seo_title', 'seo_subtitle', 'card' ), $names, 'Seamless clones are replaced by prefixed copies; a self-clone adds nothing.' );
		$this->assertSame( 'field_seo_field_src_title', $group['fields'][0]['key'] );
		$this->assertSame( 'field_seo_field_src_title', $group['fields'][1]['conditional_logic'][0][0]['field'], 'Conditions point at the copies.' );
		$this->assertSame( array( 'field_card_field_src_title' ), array_column( $group['fields'][2]['sub_fields'], 'key' ) );

		$post = $this->make_post();
		wpff_update_field( 'seo_title', 'SEO title', $post );
		wpff_update_field( 'card', array( 'title' => 'Card title' ), $post );
		$this->assertSame( 'field_seo_field_src_title', get_post_meta( $post, '_seo_title', true ) );
		$this->assertSame( 'Card title', get_post_meta( $post, 'card_title', true ) );
		$this->assertSame( array( 'title' => 'Card title' ), wpff_get_field( 'card', $post ) );
	}

	public function test_builder_sanitizes_layouts(): void {
		$input = array(
			'title'  => 'Flex',
			'fields' => array(
				array(
					'label'      => 'Rows',
					'name'       => 'rows',
					'type'       => 'repeater',
					'collapsed'  => 'field_not_a_sub',
					'sub_fields' => array(
						array(
							'key'   => 'field_r1',
							'label' => 'R1',
							'name'  => 'r1',
						),
					),
				),
				array(
					'label'   => 'Sections',
					'name'    => 'sections',
					'type'    => 'flexible_content',
					'layouts' => array(
						array(
							'label'      => 'Hero',
							'name'       => 'hero',
							'display'    => 'nope',
							'sub_fields' => array(
								array(
									'label' => 'Heading',
									'name'  => 'heading',
								),
							),
						),
						array(
							'label' => '',
							'name'  => 'hero',
						),
					),
				),
			),
		);
		$error = $this->plugin()->groups()->sanitize( $input );
		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertEqualsCanonicalizing( array( 'fields.1.layouts.1.label', 'fields.1.layouts.1.name' ), array_column( $error->get_error_data()['errors'], 'path' ) );

		unset( $input['fields'][1]['layouts'][1] );
		$input['fields'][0]['collapsed'] = 'field_r1';
		$saved                           = $this->plugin()->groups()->save( $input );
		$this->assertSame( 'field_r1', $saved['fields'][0]['collapsed'] );
		$this->assertSame( 'block', $saved['fields'][1]['layouts'][0]['display'] );
		$this->assertSame( 'heading', $saved['fields'][1]['layouts'][0]['sub_fields'][0]['name'] );

		$copy = $this->plugin()->groups()->duplicate( $saved['key'] );
		$this->assertNotSame( 'field_r1', $copy['fields'][0]['collapsed'] );
		$this->assertSame( $copy['fields'][0]['sub_fields'][0]['key'], $copy['fields'][0]['collapsed'], 'Collapsed follows the new key.' );
		$this->assertNotSame( $saved['fields'][1]['layouts'][0]['key'], $copy['fields'][1]['layouts'][0]['key'] );
	}
}
