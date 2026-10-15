<?php
/**
 * Plugin Name:       Readiness Index
 * Plugin URI:        https://readinessindex.io/
 * Description:       Your site's AI readiness score inside wp-admin: connect, scan, and see how your site scores.
 * Version:           0.6.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            TEN7
 * Author URI:        https://ten7.com/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       readiness-index
 * Domain Path:       /languages
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex;

// A direct request to this file would run it outside WordPress, with no escaping
// helpers and no capability checks to lean on.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'READINESS_INDEX_VERSION', '0.6.0' );
define( 'READINESS_INDEX_FILE', __FILE__ );
define( 'READINESS_INDEX_DIR', plugin_dir_path( __FILE__ ) );

// Hand-written on purpose: wordpress.org builds do not run Composer, so nothing
// the plugin needs at runtime may live in vendor/.
require_once READINESS_INDEX_DIR . 'src/Autoloader.php';

Autoloader::register( 'ReadinessIndex\\', READINESS_INDEX_DIR . 'src/' );

Plugin::boot();
