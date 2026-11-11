<?php
/**
 * Per-request value cache, cached field groups and batched writes.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Tests;

final class CachingTest extends TestCase {

	private function text_group(): void {
		$this->make_group(
			array(
				array(
					'key'  => 'field_cache_title',
					'name' => 'cache_title',
				),
				array(
					'key'        => 'field_cache_rows',
					'name'       => 'cache_rows',
					'type'       => 'repeater',
					'sub_fields' => array(
						array(
							'key'  => 'field_cache_row_text',
							'name' => 'text',
						),
					),
				),
			)
		);
	}

	public function test_second_read_runs_no_load_or_format_filters(): void {
		$this->text_group();
		$post = $this->make_post();
		update_field( 'cache_title', 'Hello', $post );

		$calls   = 0;
		$counter = static function ( $value ) use ( &$calls ) {
			++$calls;
			return $value;
		};
		add_filter( 'wpff/load_value', $counter );
		add_filter( 'wpff/format_value', $counter );

		$this->assertSame( 'Hello', get_field( 'cache_title', $post ) );
		$first = $calls;
		$this->assertGreaterThan( 0, $first );
		$this->assertSame( 'Hello', get_field( 'cache_title', $post ) );
		$this->assertSame( $first, $calls, 'The second read comes from the cache.' );

		remove_filter( 'wpff/load_value', $counter );
		remove_filter( 'wpff/format_value', $counter );
	}

	public function test_writes_clear_the_value_cache(): void {
		$this->text_group();
		$post = $this->make_post();

		update_field( 'cache_title', 'One', $post );
		$this->assertSame( 'One', get_field( 'cache_title', $post ) );

		update_field( 'cache_title', 'Two', $post );
		$this->assertSame( 'Two', get_field( 'cache_title', $post ) );
		$this->assertSame( array( 'cache_title' => 'Two' ), get_fields( $post ) );

		// Written around the plugin: WordPress's meta hooks still clear the cache.
		update_post_meta( $post, 'cache_title', 'Three' );
		$this->assertSame( 'Three', get_field( 'cache_title', $post ) );

		delete_field( 'cache_title', $post );
		$this->assertNull( get_field( 'cache_title', $post ) );

		update_field( 'cache_title', 'Hello', 'options' );
		$this->assertSame( 'Hello', get_field( 'cache_title', 'options' ) );
		update_option( 'options_cache_title', 'Changed' );
		$this->assertSame( 'Changed', get_field( 'cache_title', 'options' ) );
		delete_field( 'cache_title', 'options' );
	}

	public function test_value_cache_can_be_turned_off(): void {
		$this->text_group();
		$post = $this->make_post();
		update_field( 'cache_title', 'Hello', $post );
		$this->assertSame( 'Hello', get_field( 'cache_title', $post ) );

		$upper = static fn( $value ) => strtoupper( (string) $value );
		add_filter( 'wpff/cache_values', '__return_false' );
		add_filter( 'wpff/format_value/name=cache_title', $upper );
		$this->assertSame( 'HELLO', get_field( 'cache_title', $post ) );
		remove_filter( 'wpff/format_value/name=cache_title', $upper );
		remove_filter( 'wpff/cache_values', '__return_false' );
	}

	public function test_saving_a_group_invalidates_the_cached_groups(): void {
		$groups = $this->plugin()->groups();
		$saved  = $groups->save(
			array(
				'title'    => 'Cached',
				'fields'   => array(
					array(
						'key'   => 'field_cached_one',
						'label' => 'One',
						'name'  => 'cached_one',
					),
				),
				'location' => array(),
			)
		);
		$this->assertIsArray( $saved );

		// A new request with a warm object cache: no query for the groups.
		global $wpdb;
		$this->plugin()->fields()->flush();
		$this->plugin()->fields()->get_group( $saved['key'] );
		$this->plugin()->fields()->flush();
		$before = $wpdb->num_queries;
		$this->assertNotNull( $this->plugin()->fields()->get_field( 'field_cached_one' ) );
		$this->assertSame( $before, $wpdb->num_queries );

		$saved['fields'][] = array(
			'key'   => 'field_cached_two',
			'label' => 'Two',
			'name'  => 'cached_two',
		);
		$groups->save( $saved );
		$this->plugin()->fields()->flush();
		$this->assertNotNull( $this->plugin()->fields()->get_field( 'field_cached_two' ), 'Saving a group starts the cache over.' );

		$groups->trash( $saved['key'] );
		$this->plugin()->fields()->flush();
		$this->assertNull( $this->plugin()->fields()->get_field( 'field_cached_two' ), 'Trashing a group starts the cache over.' );
	}

	public function test_removing_rows_deletes_them_in_one_query(): void {
		$this->text_group();
		$post = $this->make_post();
		$rows = array();
		for ( $i = 0; $i < 20; $i++ ) {
			$rows[] = array( 'text' => "Row {$i}" );
		}
		update_field( 'cache_rows', $rows, $post );
		$this->assertSame( 'Row 19', get_post_meta( $post, 'cache_rows_19_text', true ) );

		$deleted = array();
		$watch   = static function ( $ids, $object_id, $key ) use ( &$deleted ) {
			$deleted[] = $key;
		};
		add_action( 'deleted_post_meta', $watch, 10, 3 );

		global $wpdb;
		$before = $wpdb->num_queries;
		update_field( 'cache_rows', array_slice( $rows, 0, 5 ), $post );
		$queries = $wpdb->num_queries - $before;
		remove_action( 'deleted_post_meta', $watch, 10 );

		$this->assertLessThanOrEqual( 4, $queries, 'Select, delete and the new row count.' );
		$this->assertContains( 'cache_rows_5_text', $deleted, 'Meta hooks still run for each key.' );
		$this->assertContains( '_cache_rows_19_text', $deleted );
		$this->assertSame( '', get_post_meta( $post, 'cache_rows_5_text', true ) );
		$this->assertFalse( metadata_exists( 'post', $post, '_cache_rows_19_text' ) );
		$this->assertSame( 'Row 4', get_post_meta( $post, 'cache_rows_4_text', true ) );
		$this->assertSame( '5', get_post_meta( $post, 'cache_rows', true ) );
		$this->assertCount( 5, get_field( 'cache_rows', $post ) );

		delete_field( 'cache_rows', $post );
		$this->assertFalse( metadata_exists( 'post', $post, 'cache_rows_0_text' ) );
		$this->assertFalse( metadata_exists( 'post', $post, 'cache_rows' ) );
	}

	public function test_updating_many_fields_reuses_the_meta_cache(): void {
		$this->text_group();
		$post = $this->make_post();
		update_field( 'cache_title', 'One', $post );

		global $wpdb;
		$before = $wpdb->num_queries;
		update_field( 'cache_title', 'Two', $post );
		// update_metadata()'s own SELECT and UPDATE: the reference is unchanged and meta is not read again.
		$this->assertSame( 2, $wpdb->num_queries - $before );
		$this->assertSame( 'Two', get_post_meta( $post, 'cache_title', true ) );
		$this->assertSame( 'field_cache_title', get_post_meta( $post, '_cache_title', true ) );

		// Changed around the plugin: the next write sees it.
		delete_post_meta( $post, '_cache_title' );
		update_field( 'cache_title', 'Two', $post );
		$this->assertSame( 'field_cache_title', get_post_meta( $post, '_cache_title', true ) );
	}
}
