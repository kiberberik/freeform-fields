<?php
/**
 * Loads a real WordPress (from bin/setup-dev.sh) on a fresh throwaway SQLite database.
 *
 * @package FreeformFields
 */

$root   = dirname( __DIR__ );
$wp_dir = $root . '/.dev/wp';

if ( ! file_exists( $wp_dir . '/wp-config.php' ) ) {
	fwrite( STDERR, "WordPress not found. Run bin/setup-dev.sh first.\n" );
	exit( 1 );
}

$db_file = 'wpff-tests.sqlite';
putenv( 'WPFF_DB_FILE=' . $db_file );
@unlink( $wp_dir . '/wp-content/database/' . $db_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

$wp       = 'wp --path=' . escapeshellarg( $wp_dir ) . ' ';
$commands = array(
	'core install --url=http://localhost --title=WPFF-Tests --admin_user=admin --admin_password=' . bin2hex( random_bytes( 8 ) ) . ' --admin_email=admin@example.test --skip-email',
	'plugin activate freeform-fields',
);
foreach ( $commands as $command ) {
	exec( $wp . $command . ' 2>&1', $output, $code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	if ( 0 !== $code ) {
		fwrite( STDERR, "Failed: wp {$command}\n" . implode( "\n", $output ) . "\n" );
		exit( 1 );
	}
}

$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';
define( 'WP_ADMIN', true );

require $wp_dir . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

wp_set_current_user( 1 );

// Keep tests independent of the dev site's theme folders: Local JSON is off unless a test turns it on.
add_filter( 'wpff/settings/save_json', '__return_empty_string' );
add_filter( 'wpff/settings/load_json', '__return_empty_array' );
FreeformFields\Plugin::instance()->local_json()->flush();
FreeformFields\Plugin::instance()->fields()->flush();

require __DIR__ . '/TestCase.php';
