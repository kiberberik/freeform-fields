<?php
/**
 * Plugin Name:       WP Freeform Fields
 * Description:       Free custom fields for WordPress: field groups, repeaters, flexible content, options pages and PHP blocks. Compatible with ACF data and template functions.
 * Version:           1.2.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            kiberberik
 * Author URI:        https://kiberberik.link
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       freeform-fields
 * Domain Path:       /languages
 *
 * @package FreeformFields
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'WPFF_VERSION' ) ) {
	return;
}

define( 'WPFF_VERSION', '1.2.0' );
define( 'WPFF_FILE', __FILE__ );
define( 'WPFF_PATH', plugin_dir_path( __FILE__ ) );
define( 'WPFF_URL', plugin_dir_url( __FILE__ ) );

require_once WPFF_PATH . 'src/autoload.php';
require_once WPFF_PATH . 'src/Api/functions.php';

FreeformFields\Plugin::instance()->boot();
