<?php
/**
 * Load benchmark: 100 fields plus a 1000-row repeater on one post.
 *
 * Run against the throwaway test database (after `composer test` created it):
 *
 *     WPFF_DB_FILE=wpff-tests.sqlite wp --path=.dev/wp eval-file bin/benchmark.php
 *
 * @package FreeformFields
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.DB.DirectDatabaseQuery

use FreeformFields\Admin\FieldRenderer;
use FreeformFields\Plugin;
use FreeformFields\Storage\ObjectRef;
use FreeformFields\Validation\Validator;

wp_set_current_user( 1 );
$plugin = Plugin::instance();
$rows_n = (int) ( getenv( 'WPFF_BENCH_ROWS' ) ?: 1000 );

$fields = array();
for ( $i = 1; $i <= 100; $i++ ) {
	$fields[] = array(
		'key'  => "field_bench_{$i}",
		'name' => "bench_{$i}",
		'type' => 0 === $i % 10 ? 'select' : 'text',
		'choices' => array( 'a' => 'A', 'b' => 'B' ),
	);
}
$fields[] = array(
	'key'        => 'field_bench_rows',
	'name'       => 'bench_rows',
	'type'       => 'repeater',
	'sub_fields' => array(
		array( 'key' => 'field_bench_r_title', 'name' => 'title' ),
		array( 'key' => 'field_bench_r_number', 'name' => 'number', 'type' => 'number' ),
		array( 'key' => 'field_bench_r_flag', 'name' => 'flag', 'type' => 'true_false' ),
	),
);
wpff_add_local_field_group(
	array(
		'key'      => 'group_bench',
		'title'    => 'Benchmark',
		'fields'   => $fields,
		'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ) ),
	)
);

global $wpdb;
$measure = static function ( string $label, callable $fn ) use ( $wpdb ) {
	$q0 = $wpdb->num_queries;
	$t0 = microtime( true );
	$r  = $fn();
	printf( "%-44s %8.1f ms %7d queries\n", $label, ( microtime( true ) - $t0 ) * 1000, $wpdb->num_queries - $q0 );
	return $r;
};

$post = wp_insert_post( array( 'post_title' => 'Benchmark', 'post_status' => 'publish' ) );
$ref  = new ObjectRef( 'post', $post );

$values = array();
for ( $i = 1; $i <= 100; $i++ ) {
	$values[ "bench_{$i}" ] = 0 === $i % 10 ? 'a' : "Value {$i}";
}
$rows = array();
for ( $r = 0; $r < $rows_n; $r++ ) {
	$rows[] = array( 'title' => "Row {$r}", 'number' => (string) $r, 'flag' => $r % 2 );
}

echo "Rows: {$rows_n}\n";
$measure(
	'save 100 fields',
	static function () use ( $values, $post ) {
		foreach ( $values as $name => $value ) {
			wpff_update_field( $name, $value, $post );
		}
	}
);
$measure( 'save repeater (new post)', static fn() => wpff_update_field( 'bench_rows', $rows, $post ) );
$measure( 'save repeater again, unchanged', static fn() => wpff_update_field( 'bench_rows', $rows, $post ) );
$changed            = $rows;
$changed[10]['title'] = 'Changed';
$changed[500]['flag'] = 1 - $changed[500]['flag'];
$measure( 'save repeater, 2 values changed', static fn() => wpff_update_field( 'bench_rows', $changed, $post ) );
$measure( 'save repeater, 100 rows removed', static fn() => wpff_update_field( 'bench_rows', array_slice( $changed, 0, count( $changed ) - 100 ), $post ) );

wp_cache_flush();
$all = $measure( 'get_fields() cold (all values, formatted)', static fn() => get_fields( $post ) );
$measure( 'get_fields() warm', static fn() => get_fields( $post ) );

wp_cache_flush();
$measure(
	'have_rows() loop + 3 get_sub_field() per row',
	static function () use ( $post ) {
		$n = 0;
		while ( have_rows( 'bench_rows', $post ) ) {
			the_row();
			$n += strlen( (string) get_sub_field( 'title' ) ) + (int) get_sub_field( 'number' ) + (int) get_sub_field( 'flag' );
		}
		return $n;
	}
);

$submitted = array( 'field_bench_rows' => array( '__present' => '1' ) );
foreach ( $rows as $r => $row ) {
	$submitted['field_bench_rows'][ "r{$r}" ] = array(
		'field_bench_r_title'  => $row['title'],
		'field_bench_r_number' => $row['number'],
		'field_bench_r_flag'   => (string) $row['flag'],
	);
}
$measure(
	'validate form with all rows',
	static fn() => ( new Validator( $plugin->types() ) )->validate( $submitted, array( 'field_bench_rows' => $plugin->fields()->get_field( 'field_bench_rows' ) ) )
);

wp_cache_flush();
$html = $measure(
	'render meta box HTML',
	static function () use ( $plugin, $ref ) {
		ob_start();
		( new FieldRenderer( $plugin->types(), $plugin->values() ) )->render_group( $plugin->fields()->get_group( 'group_bench' ), $ref );
		return ob_get_clean();
	}
);
printf( "%-44s %8.1f KB\n", 'meta box HTML size', strlen( $html ) / 1024 );
printf( "%-44s %8d\n", 'meta rows on the post', (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d", $post ) ) );
printf( "%-44s %8.1f MB\n", 'peak memory', memory_get_peak_usage() / 1048576 );
printf( "%-44s %8d\n", 'rows read back', count( $all['bench_rows'] ) );

wp_delete_post( $post, true );
