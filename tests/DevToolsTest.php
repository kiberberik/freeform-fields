<?php
/**
 * Local JSON, import/export, ACF migration and REST fields.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Tests;

use FreeformFields\Registry\LocalJson;
use FreeformFields\Tools\ImportExport;

final class DevToolsTest extends TestCase {

	private string $dir = '';

	/** @var callable[] */
	private array $filters = array();

	protected function setUp(): void {
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/wpff-json-' . wp_generate_password( 6, false );
		wp_mkdir_p( $this->dir );
		$this->filters = array(
			'wpff/settings/save_json' => fn() => $this->dir,
			'wpff/settings/load_json' => fn() => array( $this->dir ),
		);
		foreach ( $this->filters as $hook => $cb ) {
			add_filter( $hook, $cb, 20 );
		}
		$this->plugin()->local_json()->flush();
	}

	protected function tearDown(): void {
		foreach ( $this->filters as $hook => $cb ) {
			remove_filter( $hook, $cb, 20 );
		}
		array_map( 'unlink', glob( $this->dir . '/*' ) ?: array() );
		@rmdir( $this->dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$this->plugin()->local_json()->flush();
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type IN ('acf-field-group','acf-field')" );
		parent::tearDown();
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function group( string $title = 'Book' ): array {
		return array(
			'title'    => $title,
			'fields'   => array(
				array(
					'key'   => 'field_book_isbn',
					'label' => 'ISBN',
					'name'  => 'isbn',
				),
			),
			'location' => array(
				array(
					array(
						'param'    => 'post_type',
						'operator' => '==',
						'value'    => 'post',
					),
				),
			),
		);
	}

	public function test_local_json_write_load_sync_and_delete(): void {
		$saved = $this->plugin()->groups()->save( self::group() );
		$file  = $this->dir . '/' . $saved['key'] . '.json';
		$this->assertFileExists( $file );

		$json = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->assertSame( 'Book', $json['title'] );
		$this->assertSame( 'isbn', $json['fields'][0]['name'] );
		$this->assertArrayNotHasKey( 'parent', $json['fields'][0], 'Runtime props are not written.' );
		$this->assertArrayHasKey( 'modified', $json );

		$this->plugin()->fields()->flush();
		$group = $this->plugin()->fields()->get_group( $saved['key'] );
		$this->assertSame( 'json', $group['local'] );
		$this->assertFalse( LocalJson::needs_sync( $group ) );

		// The file changes (e.g. after git pull): it wins at runtime and offers a sync.
		$json['title']    = 'Book (from git)';
		$json['modified'] = time() + 100;
		file_put_contents( $file, (string) wp_json_encode( $json ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->plugin()->local_json()->flush();
		$this->plugin()->fields()->flush();
		$group = $this->plugin()->fields()->get_group( $saved['key'] );
		$this->assertSame( 'Book (from git)', $group['title'] );
		$this->assertTrue( LocalJson::needs_sync( $group ) );

		$synced = $this->plugin()->groups()->sync( $saved['key'] );
		$this->assertSame( 'Book (from git)', get_post( $this->plugin()->groups()->find_post( $saved['key'] )->ID )->post_title );
		$this->assertFalse( LocalJson::needs_sync( $synced ) );

		$this->plugin()->groups()->trash( $saved['key'] );
		$this->assertFileDoesNotExist( $file );
	}

	public function test_acf_json_file_and_php_precedence(): void {
		// An acf-json file as ACF writes it: layouts keyed by layout key.
		$acf = array(
			'key'      => 'group_from_acf_json',
			'title'    => 'From acf-json',
			'fields'   => array(
				array(
					'key'     => 'field_fc_acf',
					'label'   => 'Sections',
					'name'    => 'sections',
					'type'    => 'flexible_content',
					'layouts' => array(
						'layout_abc' => array(
							'key'        => 'layout_abc',
							'name'       => 'text',
							'label'      => 'Text',
							'display'    => 'block',
							'sub_fields' => array(
								array(
									'key'  => 'field_fc_text',
									'name' => 'text',
									'type' => 'text',
								),
							),
						),
					),
				),
			),
			'location' => array(),
			'modified' => 1700000000,
		);
		file_put_contents( $this->dir . '/group_from_acf_json.json', (string) wp_json_encode( $acf ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->plugin()->local_json()->flush();
		$this->plugin()->fields()->flush();

		$group = $this->plugin()->fields()->get_group( 'group_from_acf_json' );
		$this->assertSame( 'text', $group['fields'][0]['layouts'][0]['name'] );
		$this->assertNotNull( $this->plugin()->fields()->get_field( 'field_fc_text' ) );

		$this->make_group(
			array( array( 'name' => 'x' ) ),
			array(
				'key'   => 'group_from_acf_json',
				'title' => 'PHP wins',
			)
		);
		$this->assertSame( 'PHP wins', $this->plugin()->fields()->get_group( 'group_from_acf_json' )['title'] );
	}

	public function test_export_and_import(): void {
		$this->make_group(
			self::group( 'Exported' )['fields'],
			array(
				'key'   => 'group_exported',
				'title' => 'Exported',
			)
		);
		$tools = new ImportExport( $this->plugin() );

		$export = $tools->export( array( 'group_exported' ) );
		$this->assertCount( 1, $export );
		$this->assertSame( 'group_exported', $export[0]['key'] );

		$php = $tools->export_php( array( 'group_exported' ) );
		$this->assertStringContainsString( "wpff_add_local_field_group( array(\n", $php );
		$this->assertStringContainsString( "'key' => 'field_book_isbn'", $php );
		$file = $this->dir . '/export.php';
		file_put_contents( $file, "<?php\n" . $php ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exec( 'php -l ' . escapeshellarg( $file ), $out, $code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		$this->assertSame( 0, $code, 'Generated PHP is valid.' );

		$import             = $export[0];
		$import['key']      = 'group_imported';
		$import['fields'][] = array(
			'key'   => 'field_unknown',
			'label' => 'Unknown',
			'name'  => 'unknown',
			'type'  => 'some_addon_field',
		);
		$result             = $tools->import( array( $import, array( 'nope' => 1 ) ) );
		$this->assertSame( array( 'group_imported' ), array_column( $result['imported'], 'key' ) );
		$this->assertCount( 1, $result['warnings'], 'Unsupported field types are skipped with a warning.' );
		$this->assertCount( 1, $result['errors'] );
		$this->assertSame( array( 'isbn' ), array_column( $this->plugin()->fields()->get_group( 'group_imported' )['fields'], 'name' ) );
	}

	public function test_import_from_acf_database(): void {
		// Field groups as ACF stores them: settings serialized in post_content, fields as child posts.
		$group_id = wp_insert_post(
			array(
				'post_type'    => 'acf-field-group',
				'post_status'  => 'publish',
				'post_title'   => 'ACF Product',
				'post_name'    => 'group_acf_product',
				'post_content' => serialize( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
					array(
						'location' => array(
							array(
								array(
									'param'    => 'post_type',
									'operator' => '==',
									'value'    => 'post',
								),
							),
						),
						'position' => 'side',
					)
				),
			)
		);
		$field    = static fn( int $parent, string $key, string $label, string $name, array $settings, int $order = 0 ) => wp_insert_post(
			array(
				'post_type'    => 'acf-field',
				'post_status'  => 'publish',
				'post_parent'  => $parent,
				'post_name'    => $key,
				'post_title'   => $label,
				'post_excerpt' => $name,
				'menu_order'   => $order,
				'post_content' => serialize( $settings ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			)
		);
		$field( $group_id, 'field_acf_price', 'Price', 'Price_EUR', array( 'type' => 'number' ) );
		$rep      = $field( $group_id, 'field_acf_specs', 'Specs', 'specs', array( 'type' => 'repeater' ), 1 );
		$field( $rep, 'field_acf_spec', 'Spec', 'spec', array( 'type' => 'text' ) );
		$fc = $field(
			$group_id,
			'field_acf_fc',
			'Blocks',
			'blocks',
			array(
				'type'    => 'flexible_content',
				'layouts' => array(
					'layout_q' => array(
						'key'   => 'layout_q',
						'name'  => 'quote',
						'label' => 'Quote',
					),
				),
			),
			2
		);
		$field(
			$fc,
			'field_acf_quote',
			'Quote',
			'quote',
			array(
				'type'          => 'textarea',
				'parent_layout' => 'layout_q',
			)
		);

		$tools  = new ImportExport( $this->plugin() );
		$groups = $tools->acf_db_groups();
		$this->assertSame( 'group_acf_product', $groups[0]['key'] );
		$this->assertSame( array( 'Price_EUR', 'specs', 'blocks' ), array_column( $groups[0]['fields'], 'name' ) );

		$result = $tools->import_acf();
		$this->assertSame( array( 'group_acf_product' ), array_column( $result['imported'], 'key' ) );

		$group = $this->plugin()->fields()->get_group( 'group_acf_product' );
		$this->assertSame( 'side', $group['position'] );
		$this->assertSame( 'Price_EUR', $group['fields'][0]['name'], 'Field name case is kept.' );
		$this->assertSame( 'spec', $group['fields'][1]['sub_fields'][0]['name'] );
		$this->assertSame( 'quote', $group['fields'][2]['layouts'][0]['sub_fields'][0]['name'] );

		// Values ACF saved are readable right away.
		$post = $this->make_post();
		foreach (
			array(
				'Price_EUR'     => '10',
				'_Price_EUR'    => 'field_acf_price',
				'specs'         => 1,
				'_specs'        => 'field_acf_specs',
				'specs_0_spec'  => 'Steel',
				'_specs_0_spec' => 'field_acf_spec',
			) as $k => $v
		) {
			add_post_meta( $post, $k, $v );
		}
		$this->assertSame( '10', get_field( 'Price_EUR', $post ) );
		$this->assertSame( array( array( 'spec' => 'Steel' ) ), get_field( 'specs', $post ) );
	}

	public function test_rest_fields_read_and_write(): void {
		$this->make_group(
			array(
				array(
					'key'  => 'field_rest_rating',
					'name' => 'rating',
					'type' => 'number',
					'max'  => 5,
				),
				array(
					'key'           => 'field_rest_kind',
					'name'          => 'kind',
					'type'          => 'select',
					'choices'       => array( 'a' => 'Alpha' ),
					'return_format' => 'label',
				),
				array(
					'key'        => 'field_rest_steps',
					'name'       => 'steps',
					'type'       => 'repeater',
					'sub_fields' => array(
						array(
							'key'  => 'field_rest_step',
							'name' => 'step',
						),
					),
				),
			),
			array( 'show_in_rest' => 1 )
		);
		$this->make_group(
			array(
				array(
					'key'  => 'field_rest_hidden',
					'name' => 'secret',
				),
			)
		);
		$post = $this->make_post();
		wpff_update_field( 'kind', 'a', $post );
		wpff_update_field( 'secret', 'x', $post );

		$read = static function ( int $id, array $query = array() ) {
			$request = new \WP_REST_Request( 'GET', '/wp/v2/posts/' . $id );
			$request->set_query_params( $query );
			return (array) rest_get_server()->dispatch( $request )->get_data()['acf'];
		};

		$acf = $read( $post );
		$this->assertSame( 'a', $acf['kind'] );
		$this->assertArrayNotHasKey( 'secret', $acf, 'Only groups with show_in_rest are exposed.' );
		$this->assertSame( 'Alpha', $read( $post, array( 'acf_format' => 'standard' ) )['kind'] );

		$update = static function ( int $id, array $acf ) {
			$request = new \WP_REST_Request( 'POST', '/wp/v2/posts/' . $id );
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( array( 'acf' => $acf ) ) );
			return rest_get_server()->dispatch( $request );
		};
		$ok     = $update(
			$post,
			array(
				'rating' => 4,
				'steps'  => array( array( 'step' => 'One' ), array( 'step' => 'Two' ) ),
			)
		);
		$this->assertSame( 200, $ok->get_status(), (string) wp_json_encode( $ok->get_data() ) );
		$this->assertSame( '4', get_field( 'rating', $post ) );
		$this->assertSame( array( 'One', 'Two' ), array_column( get_field( 'steps', $post ), 'step' ) );

		$this->assertSame( 400, $update( $post, array( 'rating' => 9 ) )->get_status(), 'Values are validated.' );
		$this->assertSame( 400, $update( $post, array( 'secret' => 'y' ) )->get_status(), 'Hidden fields cannot be written.' );
		$this->assertSame( 'x', get_post_meta( $post, 'secret', true ) );
	}
}
