<?php
/**
 * Compatibility with themes and sites built for ACF.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Tests;

use FreeformFields\Admin\FormSupport;
use FreeformFields\Front\Forms;
use FreeformFields\Hooks;
use FreeformFields\Plugin;
use FreeformFields\Tools\ThemeCheck;
use FreeformFields\Validation\Validator;

final class AcfCompatTest extends TestCase {

	/** @var array<int, array{0: string, 1: callable, 2: int}> */
	private array $filters = array();

	private string $dir = '';

	protected function tearDown(): void {
		foreach ( $this->filters as [ $hook, $cb, $priority ] ) {
			remove_filter( $hook, $cb, $priority );
		}
		Hooks::reset();
		if ( '' !== $this->dir ) {
			( new \WP_Filesystem_Direct( null ) )->rmdir( $this->dir, true );
		}
		parent::tearDown();
	}

	private function on( string $hook, callable $cb, int $priority = 10, int $args = 1 ): void {
		add_filter( $hook, $cb, $priority, $args );
		$this->filters[] = array( $hook, $cb, $priority );
	}

	private function acf_active( bool $active ): void {
		$this->on( 'wpff/acf_active', static fn() => $active );
		Hooks::reset();
	}

	private function tmp_theme( array $files ): string {
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
		$this->dir = sys_get_temp_dir() . '/wpff-theme-' . wp_generate_password( 6, false );
		foreach ( $files as $path => $code ) {
			wp_mkdir_p( dirname( $this->dir . '/' . $path ) );
			file_put_contents( $this->dir . '/' . $path, $code ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		$dir = $this->dir;
		$this->on( 'stylesheet_directory', static fn() => $dir );
		$this->on( 'template_directory', static fn() => $dir );
		return $dir;
	}

	public function test_acf_hooks_run_alongside_wpff_hooks(): void {
		$this->make_group(
			array(
				array(
					'key'  => 'field_cc_title',
					'name' => 'cc_title',
				),
				array(
					'key'           => 'field_cc_color',
					'name'          => 'cc_color',
					'type'          => 'select',
					'return_format' => 'label',
				),
			)
		);
		$post = $this->make_post();
		wpff_update_field( 'cc_title', 'hello', $post );

		$this->on( 'acf/format_value/name=cc_title', static fn( $v ) => strtoupper( $v ) );
		$this->assertSame( 'HELLO', wpff_get_field( 'cc_title', $post ) );

		// The classic ACF recipe: fill choices at runtime.
		$this->on(
			'acf/load_field/name=cc_color',
			static function ( $field ) {
				$field['choices'] = array( 'teal' => 'Teal' );
				return $field;
			}
		);
		$field  = $this->plugin()->fields()->get_field( 'field_cc_color' );
		$result = ( new Validator( $this->plugin()->types() ) )->validate( array( 'field_cc_color' => 'teal' ), array( 'field_cc_color' => $field ) );
		$this->assertSame( 'teal', $result['values']['field_cc_color'], 'Choices from acf/load_field are accepted.' );
		wpff_update_field( 'cc_color', 'teal', $post );
		$this->assertSame( 'Teal', wpff_get_field( 'cc_color', $post ) );
		$this->assertSame( array( 'teal' => 'Teal' ), acf_get_field( 'cc_color' )['choices'] );

		$this->on( 'acf/settings/google_api_key', static fn() => 'acf-key' );
		$this->assertSame( 'acf-key', wpff_get_setting( 'google_api_key' ) );

		$this->on( 'acf/fields/post_object/query', static fn( $args ) => array( 'post__in' => array( 1 ) ) + $args );
		$this->make_group(
			array(
				array(
					'key'  => 'field_cc_po',
					'name' => 'cc_po',
					'type' => 'post_object',
				),
			)
		);
		$found = $this->plugin()->types()->get( 'post_object' )->query( $this->plugin()->fields()->get_field( 'field_cc_po' ), '', 1 );
		$this->assertSame( array( 1 ), array_column( $found['results'], 'id' ) );
	}

	public function test_acf_save_post_sees_values_and_post_acf(): void {
		$this->make_group(
			array(
				array(
					'key'  => 'field_cc_note',
					'name' => 'cc_note',
				),
			)
		);
		$post = $this->make_post();
		$seen = null;
		$this->on(
			'acf/save_post',
			static function ( $id ) use ( &$seen ) {
				$seen = array( $id, $_POST['acf']['field_cc_note'] ?? null, get_post_meta( (int) $id, 'cc_note', true ) ); // phpcs:ignore WordPress.Security.NonceVerification
			},
			20
		);
		$_POST = array(
			FormSupport::NONCE_NAME => wp_create_nonce( FormSupport::NONCE_ACTION ),
			'wpff'                  => array( 'field_cc_note' => 'saved' ),
		);
		( new FormSupport( $this->plugin() ) )->save_request( array( 'post_id' => $post ), new \FreeformFields\Storage\ObjectRef( 'post', $post ) );
		$this->assertSame( array( (string) $post, 'saved', 'saved' ), $seen );
		$this->assertArrayNotHasKey( 'acf', $_POST, 'The copy is removed afterwards.' );
	}

	public function test_no_acf_hooks_while_acf_is_active(): void {
		$this->acf_active( true );
		$this->make_group( array( array( 'name' => 'cc_plain' ) ) );
		$post = $this->make_post();
		wpff_update_field( 'cc_plain', 'x', $post );
		$this->on( 'acf/format_value', static fn() => 'from ACF filter' );
		$this->assertSame( 'x', wpff_get_field( 'cc_plain', $post ), 'ACF callbacks belong to ACF.' );
		$this->assertSame( array( 'wpff' ), Hooks::prefixes() );
	}

	public function test_acf_json_and_acf_blocks_are_left_to_active_acf(): void {
		$dir = $this->tmp_theme( array( 'acf-json/group_x.json' => '{}' ) );
		// The test bootstrap turns Local JSON off; look at the folders themselves here.
		remove_filter( 'wpff/settings/load_json', '__return_empty_array' );
		$paths = $this->plugin()->local_json()->load_paths();
		add_filter( 'wpff/settings/load_json', '__return_empty_array' );
		$this->assertContains( $dir . '/acf-json', $paths );
		$this->acf_active( true );
		remove_filter( 'wpff/settings/load_json', '__return_empty_array' );
		$paths = $this->plugin()->local_json()->load_paths();
		add_filter( 'wpff/settings/load_json', '__return_empty_array' );
		$this->assertNotContains( $dir . '/acf-json', $paths );

		$registry = $this->plugin()->blocks();
		$settings = $registry->from_metadata(
			array( 'name' => 'acf/cc' ),
			array(
				'name' => 'acf/cc',
				'acf'  => array( 'renderTemplate' => 'x.php' ),
			)
		);
		$this->assertArrayNotHasKey( 'render_callback', $settings );
	}

	public function test_new_functions(): void {
		$group = $this->make_group(
			array(
				array(
					'key'  => 'field_cc_a',
					'name' => 'cc_a',
				),
				array(
					'key'        => 'field_cc_rows',
					'name'       => 'cc_rows',
					'type'       => 'repeater',
					'sub_fields' => array(
						array(
							'key'  => 'field_cc_rows_t',
							'name' => 't',
						),
					),
				),
			)
		);
		$post  = $this->make_post();
		wpff_update_field( 'cc_a', 'A', $post );
		wpff_update_field( 'cc_rows', array( array( 't' => 'one' ), array( 't' => 'two' ) ), $post );

		$objects = get_field_objects( $post );
		$this->assertSame( 'A', $objects['cc_a']['value'] );
		$this->assertSame( 'field_cc_a', $objects['cc_a']['key'] );
		$this->assertSame( array( 'cc_a', 'cc_rows' ), array_column( acf_get_fields( $group ), 'name' ) );
		$this->assertSame( array( 't' ), array_column( acf_get_fields( 'field_cc_rows' ), 'name' ) );
		$this->assertContains( $group, array_column( acf_get_field_groups( array( 'post_id' => $post ) ), 'key' ) );
		$this->assertNotContains( $group, array_column( acf_get_field_groups( array( 'post_type' => 'page' ) ), 'key' ) );
		$this->assertSame( $group, acf_get_field_group( $group )['key'] );

		while ( have_rows( 'cc_rows', $post ) ) {
			the_row();
			if ( 2 === get_row_index() ) {
				$this->assertTrue( update_sub_field( 't', 'TWO' ) );
				$this->assertSame( 'TWO', get_sub_field( 't' ) );
			}
		}
		$this->assertSame( 'TWO', get_post_meta( $post, 'cc_rows_1_t', true ) );
		$this->assertTrue( update_sub_field( array( 'cc_rows', 1, 't' ), 'ONE', $post ) );
		$this->assertSame( array( 'ONE', 'TWO' ), array_column( get_field( 'cc_rows', $post ), 't' ) );

		$this->assertTrue(
			acf_add_local_field(
				array(
					'parent' => $group,
					'key'    => 'field_cc_late',
					'name'   => 'cc_late',
				)
			)
		);
		$this->assertSame( 'cc_late', acf_get_field( 'field_cc_late' )['name'] );
		$this->assertSame( '<b>b</b>x', acf_esc_html( '<b>b</b><script>x</script>' ) );
	}

	public function test_theme_check_reports_missing_functions_and_hooks(): void {
		$this->tmp_theme(
			array(
				'functions.php'     => "<?php\nacf_form_head();\nacf_get_field_types();\n\$x->acf_method();\nfunction my_helper() {}\nmy_helper();\nadd_filter( 'acf/load_field/name=color', 'cb' );\nadd_filter( 'acf/fields/wysiwyg/toolbars', 'cb' );\n",
				'inc/theme-acf.php' => "<?php\nfunction acf_theme_own() {}\nacf_theme_own();\nupdate_sub_field( 'x', 1 );\nacf_render_field_wrap( \$f );\n",
				'inc/acf/acf.php'   => "<?php\n/* Plugin Name: Advanced Custom Fields PRO */\nacf_bundled_internal();\n",
			)
		);
		$report = ( new ThemeCheck() )->run();
		$this->assertSame( array( 'acf_get_field_types', 'acf_render_field_wrap' ), array_keys( $report['functions'] ) );
		$this->assertCount( 1, $report['functions']['acf_get_field_types'] );
		$this->assertStringEndsWith( '/functions.php:3', $report['functions']['acf_get_field_types'][0] );
		$this->assertSame( array( 'acf/fields/wysiwyg/toolbars' ), array_keys( $report['hooks'] ) );
		$this->assertTrue( $this->invoke_find_acf( $this->dir ), 'A bundled ACF is detected.' );
	}

	private function invoke_find_acf( string $dir ): bool {
		$method = new \ReflectionMethod( Plugin::class, 'find_acf' );
		return (bool) $method->invoke( null, $dir, 4 );
	}

	public function test_front_form_renders_and_signs_settings(): void {
		$this->make_group(
			array(
				array(
					'key'  => 'field_cc_f',
					'name' => 'cc_f',
				),
			)
		);
		$post = $this->make_post();
		ob_start();
		acf_form(
			array(
				'id'      => 'cc-form',
				'post_id' => $post,
				'fields'  => array( 'cc_f' ),
			)
		);
		$html = ob_get_clean();
		$this->assertStringContainsString( '<form id="cc-form"', $html );
		$this->assertStringContainsString( 'name="wpff[field_cc_f]"', $html );
		$this->assertStringContainsString( '<input type="submit"', $html );
		preg_match( '/name="_wpff_form" value="([^"]+)"/', $html, $m );

		$verify = new \ReflectionMethod( Forms::class, 'verify' );
		$args   = $verify->invoke( null, html_entity_decode( $m[1] ) );
		$this->assertSame( $post, $args['post_id'] );

		[ $payload, $mac ] = explode( '.', html_entity_decode( $m[1] ), 2 );
		$data              = json_decode( base64_decode( $payload ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		$data['post_id']   = 1;
		$this->assertNull( $verify->invoke( null, base64_encode( wp_json_encode( $data ) ) . '.' . $mac ), 'Changed settings are rejected.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	public function test_compat_functions_wait_while_acf_is_being_activated(): void {
		$dir = WP_PLUGIN_DIR . '/fake-acf-' . wp_generate_password( 4, false, false );
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/acf.php', "<?php\n/**\n * Plugin Name: Advanced Custom Fields PRO\n */\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$pagenow             = $GLOBALS['pagenow'] ?? null;
		$GLOBALS['pagenow']  = 'plugins.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		$_REQUEST['action']  = 'activate';
		$_REQUEST['plugin']  = basename( $dir ) . '/acf.php';
		try {
			$this->assertTrue( Plugin::acf_being_activated() );
			$_REQUEST['plugin'] = 'akismet/akismet.php';
			$this->assertFalse( Plugin::acf_being_activated() );
			$_REQUEST['plugin'] = '../../wp-config.php';
			$this->assertFalse( Plugin::acf_being_activated(), 'Paths outside the plugins folder are ignored.' );
		} finally {
			unset( $_REQUEST['action'], $_REQUEST['plugin'] );
			$GLOBALS['pagenow'] = $pagenow; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
			unlink( $dir . '/acf.php' );
			rmdir( $dir );
		}
	}

	public function test_tab_name_may_repeat_a_field_name(): void {
		// ACF keeps a name on tabs (from the label); a tab and a group named "about" coexist there.
		$tools  = new \FreeformFields\Tools\ImportExport( $this->plugin() );
		$result = $tools->import(
			array(
				array(
					'key'      => 'group_tab_names',
					'title'    => 'Tabs',
					'fields'   => array(
						array( 'key' => 'field_tab_about', 'name' => 'about', 'label' => 'About', 'type' => 'tab' ),
						array( 'key' => 'field_grp_about', 'name' => 'about', 'label' => 'About', 'type' => 'group', 'sub_fields' => array( array( 'key' => 'field_grp_about_t', 'name' => 'title', 'label' => 'Title', 'type' => 'text' ) ) ),
					),
					'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ) ),
				),
			)
		);
		$this->assertSame( array( 'group_tab_names' ), array_column( $result['imported'], 'key' ) );
		$this->assertSame( array(), $result['errors'] );
	}

	public function test_import_accepts_fields_without_label(): void {
		// ACF allows an empty label: tabs and messages keep it empty, value fields fall back to their name.
		$tools  = new \FreeformFields\Tools\ImportExport( $this->plugin() );
		$result = $tools->import(
			array(
				array(
					'key'      => 'group_no_labels',
					'title'    => 'No labels',
					'fields'   => array(
						array( 'key' => 'field_nl_tab', 'name' => '', 'label' => '', 'type' => 'tab' ),
						array( 'key' => 'field_nl_msg', 'name' => '', 'label' => '', 'type' => 'message', 'message' => 'Hello' ),
						array( 'key' => 'field_nl_text', 'name' => 'subtitle', 'label' => '', 'type' => 'text' ),
						array(
							'key'     => 'field_nl_flex',
							'name'    => 'sections',
							'label'   => 'Sections',
							'type'    => 'flexible_content',
							'layouts' => array(
								'layout_nl_hero' => array(
									'key'        => 'layout_nl_hero',
									'name'       => 'hero',
									'label'      => '',
									'sub_fields' => array(),
								),
							),
						),
					),
					'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ) ),
				),
			)
		);
		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( array( 'group_no_labels' ), array_column( $result['imported'], 'key' ) );
		$fields = $this->plugin()->fields()->get_group( 'group_no_labels' )['fields'];
		$this->assertSame( array( '', '', 'subtitle', 'Sections' ), array_column( $fields, 'label' ) );
		$this->assertSame( 'hero', $fields[3]['layouts'][0]['label'] );

		// The builder still requires a label on fields that store a value.
		$invalid = $this->plugin()->groups()->sanitize(
			array(
				'key'    => 'group_no_labels_builder',
				'title'  => 'Builder',
				'fields' => array( array( 'key' => 'field_nlb_text', 'name' => 'subtitle', 'label' => '', 'type' => 'text' ) ),
			)
		);
		$this->assertInstanceOf( \WP_Error::class, $invalid );
	}

		public function test_reads_acf_groups_before_migration(): void {
		$group = wp_insert_post(
			array(
				'post_type'    => 'acf-field-group',
				'post_status'  => 'publish',
				'post_title'   => 'From ACF',
				'post_name'    => 'group_from_acf',
				'post_content' => serialize( array( 'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ) ) ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			)
		);
		wp_insert_post(
			array(
				'post_type'    => 'acf-field',
				'post_status'  => 'publish',
				'post_parent'  => $group,
				'post_title'   => 'Rating',
				'post_name'    => 'field_from_acf_rating',
				'post_excerpt' => 'rating',
				'post_content' => serialize( array( 'type' => 'number' ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			)
		);
		$this->plugin()->fields()->flush();
		$post = $this->make_post();
		add_post_meta( $post, 'rating', '5' );
		add_post_meta( $post, '_rating', 'field_from_acf_rating' );

		try {
			$this->assertSame( 'acf', $this->plugin()->fields()->get_group( 'group_from_acf' )['local'] );
			$this->assertSame( array( 'rating' => '5' ), get_fields( $post ), 'Values work before migration.' );
			$this->plugin()->fields()->flush();
			$this->assertSame( 'acf', $this->plugin()->fields()->get_group( 'group_from_acf' )['local'] ?? null, 'Still read on the next request.' );

			// After migration the ACF copy is not read any more.
			wpff_add_local_field_group( array( 'key' => 'group_from_acf', 'title' => 'Migrated', 'fields' => array( array( 'key' => 'field_from_acf_rating', 'name' => 'rating', 'type' => 'number' ) ), 'location' => array() ) );
			$this->plugin()->fields()->flush();
			$this->plugin()->fields()->get_groups();
			$this->assertNotEmpty( get_option( 'wpff_acf_migrated' ) );
			$this->plugin()->fields()->remove_local_group( 'group_from_acf' );
			$this->plugin()->fields()->flush();
			$this->assertNull( $this->plugin()->fields()->get_group( 'group_from_acf' ), 'Skipped once migrated, until ACF groups change.' );

			$this->on( 'wpff/read_acf_groups', '__return_false' );
			$this->plugin()->fields()->flush();
			$this->assertNull( $this->plugin()->fields()->get_group( 'group_from_acf' ) );
		} finally {
			global $wpdb;
			$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type IN ('acf-field-group','acf-field')" );
			delete_option( 'wpff_acf_migrated' );
			$this->plugin()->fields()->remove_local_group( 'group_from_acf' );
			$this->plugin()->fields()->flush();
		}
	}

	public function test_value_attr_keeps_entities_on_round_trip(): void {
		$this->assertSame( 'a&amp;amp;b &quot;q&quot;', wpff_value_attr( 'a&amp;b "q"' ) );
		$this->make_group( array( array( 'key' => 'field_url_amp', 'name' => 'u', 'type' => 'text' ) ) );
		$field                = $this->plugin()->fields()->get_field( 'field_url_amp' );
		$field['value']       = 'x?a=1&amp;b=2';
		$field['input_id']    = 'u';
		$field['input_name']  = 'wpff[field_url_amp]';
		ob_start();
		$this->plugin()->types()->get( 'text' )->render_input( $field );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'value="x?a=1&amp;amp;b=2"', $html, 'The browser sends back the stored text unchanged.' );
	}

	public function test_rest_values_are_typed_like_acf(): void {
		$image = $this->make_image();
		$this->make_group(
			array(
				array( 'key' => 'field_rt_num', 'name' => 'num', 'type' => 'number' ),
				array( 'key' => 'field_rt_img', 'name' => 'img', 'type' => 'image' ),
				array( 'key' => 'field_rt_txt', 'name' => 'txt', 'type' => 'text' ),
				array( 'key' => 'field_rt_rows', 'name' => 'rows', 'type' => 'repeater', 'sub_fields' => array( array( 'key' => 'field_rt_rows_t', 'name' => 't', 'type' => 'text' ) ) ),
			),
			array( 'show_in_rest' => 1 )
		);
		$post = $this->make_post( array( 'post_status' => 'publish' ) );
		wpff_update_field( 'num', '37', $post );
		wpff_update_field( 'img', $image, $post );
		wpff_update_field( 'txt', '', $post );
		$acf = rest_get_server()->dispatch( new \WP_REST_Request( 'GET', '/wp/v2/posts/' . $post ) )->get_data()['acf'];
		$this->assertSame( 37, $acf['num'] );
		$this->assertSame( $image, $acf['img'] );
		$this->assertSame( '', $acf['txt'] );
		$this->assertNull( $acf['rows'] );
	}

	public function test_acf_to_rest_api_output_is_emulated(): void {
		$this->on( 'wpff/emulate_acf_to_rest_api', '__return_true' );
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride -- rebuild routes with the filter on.
		$this->make_group( array( array( 'key' => 'field_ar_title', 'name' => 'headline' ), array( 'key' => 'field_ar_other', 'name' => 'other' ) ) );
		$post = $this->make_post( array( 'post_status' => 'publish' ) );
		wpff_update_field( 'headline', 'Hello', $post );
		$server = rest_get_server();

		$this->assertSame( array( 'acf' => array( 'headline' => 'Hello' ) ), $server->dispatch( new \WP_REST_Request( 'GET', '/acf/v3/posts/' . $post ) )->get_data() );
		$this->assertSame( array( 'headline' => 'Hello' ), $server->dispatch( new \WP_REST_Request( 'GET', '/acf/v3/posts/' . $post . '/headline' ) )->get_data() );
		$this->assertSame( array( 'headline' => 'Hello' ), $server->dispatch( new \WP_REST_Request( 'GET', '/wp/v2/posts/' . $post ) )->get_data()['acf'] );

		// Without stored values: every field of the post's groups, empty (as the original plugin does).
		$empty = $this->make_post( array( 'post_status' => 'publish' ) );
		$this->assertSame( array( 'headline' => null, 'other' => null ), $server->dispatch( new \WP_REST_Request( 'GET', '/acf/v3/posts/' . $empty ) )->get_data()['acf'] );

		$draft = $this->make_post( array( 'post_status' => 'draft' ) );
		wp_set_current_user( 0 );
		$this->assertSame( 401, $server->dispatch( new \WP_REST_Request( 'GET', '/acf/v3/posts/' . $draft ) )->get_status(), 'Drafts stay private.' );
		wp_set_current_user( 1 );
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
	}

	public function test_acf_to_rest_api_emulation_exposes_only_allowed_fields(): void {
		$this->on( 'wpff/emulate_acf_to_rest_api', '__return_true' );
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride -- rebuild routes with the filter on.
		$this->make_group(
			array(
				array( 'key' => 'field_ars_title', 'name' => 'headline' ),
				array( 'key' => 'field_ars_pass', 'name' => 'secret', 'type' => 'password' ),
			)
		);
		$post = $this->make_post( array( 'post_status' => 'publish' ) );
		wpff_update_field( 'headline', 'Hi', $post );
		wpff_update_field( 'secret', 'p4ss', $post );
		update_post_meta( $post, '_private_key', 'hidden' );
		update_option( 'mail_pass', 'hunter2' );
		$page = acf_add_options_page( array( 'page_title' => 'Site', 'menu_slug' => 'site-opts', 'capability' => 'manage_options' ) );
		$this->make_group(
			array( array( 'key' => 'field_ars_phone', 'name' => 'phone' ) ),
			array( 'location' => array( array( array( 'param' => 'options_page', 'operator' => '==', 'value' => 'site-opts' ) ) ) )
		);
		wpff_update_field( 'phone', '123', 'options' );
		$server = rest_get_server();
		$get    = static fn( string $route ) => $server->dispatch( new \WP_REST_Request( 'GET', $route ) );

		$lurker = wp_insert_user( array( 'user_login' => 'ars_lurker', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
		wp_set_current_user( 0 );
		$this->assertSame( array( 'headline' => 'Hi' ), $get( '/acf/v3/posts/' . $post )->get_data()['acf'], 'Password fields are left out.' );
		$this->assertSame( 404, $get( '/acf/v3/posts/' . $post . '/_private_key' )->get_status(), 'No raw meta.' );
		$this->assertSame( 404, $get( '/acf/v3/posts/' . $post . '/secret' )->get_status() );
		$this->assertContains( $get( '/acf/v3/options/mail/pass' )->get_status(), array( 401, 403, 404 ), 'No arbitrary options.' );
		$this->assertContains( $get( '/acf/v3/options/user_1/session_tokens' )->get_status(), array( 401, 403, 404 ) );
		$this->assertSame( array( 'acf' => array() ), $get( '/acf/v3/options/options' )->get_data(), 'Options need the page capability.' );
		$this->assertSame( 404, $get( '/acf/v3/options/options/phone' )->get_status() );
		$this->assertContains( $get( '/acf/v3/users/' . $lurker )->get_status(), array( 401, 403 ), 'Users without public posts stay hidden.' );

		wp_set_current_user( 1 );
		$this->assertSame( array( 'phone' => '123' ), $get( '/acf/v3/options/options' )->get_data()['acf'] );
		$this->assertSame( 404, $get( '/acf/v3/options/options/mail_pass' )->get_status() );

		wp_delete_user( $lurker );
		$this->plugin()->options_pages()->remove_local( $page['menu_slug'] );
		$this->plugin()->options_pages()->flush();
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
	}
}
