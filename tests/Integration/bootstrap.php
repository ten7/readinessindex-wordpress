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
 * Yoast SEO and Rank Math are loaded the same way when they are installed in the test site
 * (`bin/wp-env-variant yoast`, `rankmath`), and the tests that need them skip when they are not.
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
		// An SEO plugin is loaded only when the test is asked to: a file is chosen by the
		// environment variable, never discovered, so a plain run has none.
		$seo = getenv( 'READINESS_INDEX_TEST_SEO' );
		$map = array(
			'yoast'     => WP_PLUGIN_DIR . '/wordpress-seo/wp-seo.php',
			'rank_math' => WP_PLUGIN_DIR . '/seo-by-rank-math/rank-math.php',
		);
		if ( $seo && isset( $map[ $seo ] ) && is_readable( $map[ $seo ] ) ) {
			if ( 'rank_math' === $seo ) {
				// Rank Math serves no front end until its connection step is done or skipped, and
				// decides that while it loads, so the answer has to be there before it does.
				add_filter( 'pre_option_rank_math_registration_skip', '__return_true' );
				// Its schema and sitemap modules are switched on by its installer, which a test
				// site that never activated it has not run.
				add_filter(
					'pre_option_rank_math_modules',
					static function () {
						return array( 'rich-snippet', 'sitemap' );
					}
				);
			}
			require $map[ $seo ];
		}
	}
);

require $readiness_index_tests_dir . '/includes/bootstrap.php';
