<?php
/**
 * Integration test bootstrap: a real WordPress, from wp-env's test site.
 *
 * Run inside wp-env, from the plugin directory:
 *
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/readiness-index \
 *     vendor/bin/phpunit -c phpunit-integration.xml.dist
 *
 * The plugin is loaded as a must-use plugin so it is there before WordPress's own tests set up.
 *
 * @package ReadinessIndex
 */

$readiness_index_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $readiness_index_tests_dir ) {
	$readiness_index_tests_dir = '/wordpress-phpunit';
}
if ( ! is_readable( $readiness_index_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress's test library was not found at {$readiness_index_tests_dir}. Run this inside wp-env.\n" ); // phpcs:ignore WordPress.Security.EscapeOutput
	exit( 1 );
}

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills' );
require_once $readiness_index_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__, 2 ) . '/readiness-index.php';
		// The plugin's own autoloader loads the tests' support classes too, as it does in the unit suite.
		\ReadinessIndex\Autoloader::register( 'ReadinessIndex\\Tests\\Integration\\', __DIR__ . '/' );
		// The golden fixtures' reader is shared with the unit suite.
		\ReadinessIndex\Autoloader::register( 'ReadinessIndex\\Tests\\Support\\', dirname( __DIR__ ) . '/Unit/Support/' );
	}
);

require $readiness_index_tests_dir . '/includes/bootstrap.php';
