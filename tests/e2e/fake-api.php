<?php
/**
 * A stand-in for readinessindex.io's CMS API, for looking at the Fixes tab in a local wp-env run.
 *
 * It serves the golden fixtures from tests/fixtures/cms/ and nothing else: `GET /cms/v1/site` is
 * the finished-scan fixture with a list of outstanding tests that covers every kind of row, and
 * `GET /cms/v1/fixes/{check}` is the fix-prompt fixture for that check. It checks no key and stores
 * nothing, and it is not a test double the suite depends on.
 *
 *   php -S 0.0.0.0:8099 tests/e2e/fake-api.php
 *
 * and point wp-env at it with `"config": { "READINESS_INDEX_API_ORIGIN": "http://host.docker.internal:8099" }`
 * in .wp-env.override.json. Store a key of the right shape with
 * `ReadinessIndex\Settings::store_key( 'air_0a1b2c3d_abcdefghijklmnopqrstuvwxyz012345' )`.
 *
 * @package ReadinessIndex
 */

$root = dirname( __DIR__ ) . '/fixtures/cms';
$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ); // phpcs:ignore

header( 'Content-Type: application/json' );

if ( '/cms/v1/site' === $path ) {
	$fixture = json_decode( file_get_contents( $root . '/site/done-with-change.json' ), true ); // phpcs:ignore
	$body          = $fixture['response']['body'];
	$rows          = array(
		array( 'AIR-1.1', 'No blanket robots.txt blocks on AI crawler user-agents', 5 ),
		array( 'AIR-1.10', 'An llms.txt exists, is valid and tracks the sitemap', 2 ),
		array( 'AIR-1.11', 'One linked entity graph', 2 ),
		array( 'AIR-1.6', 'No stray noarchive, nosnippet or max-snippet:0', 1 ),
		array( 'AIR-4.8', 'Sitemap lastmod reflects real changes', 2 ),
		array( 'AIR-2.5', 'Images have alt text', 2 ),
		array( 'AIR-1.2', 'AI crawlers are not turned away at the edge', 3 ),
		array( 'AIR-2.1', 'Primary content is present without JavaScript', 3 ),
		array( 'AIR-4.1', 'RSL licensing document published and referenced', 1 ),
	);
	$base          = $body['fixes'][0];
	$body['fixes'] = array();
	foreach ( $rows as $i => $row ) {
		$fix             = $base;
		$fix['rank']     = $i + 1;
		$fix['check']    = $row[0];
		$fix['title']    = $row[1];
		$fix['points']   = $row[2];
		$body['fixes'][] = $fix;
	}
	echo json_encode( $body );
	exit;
}

if ( preg_match( '#^/cms/v1/fixes/(AIR-\d+\.\d+)$#', $path, $m ) ) {
	$fixture                                         = json_decode( file_get_contents( $root . '/fix-prompt/outstanding-check.json' ), true ); // phpcs:ignore
	$body                                  = $fixture['response']['body'];
	$body['check']                         = $m[1];
	$body['untrusted_site_text']['prompt'] = '### ' . $m[1] . "\n\nAn example prompt for " . $m[1] . ". <b>Not markup</b>.\n";
	echo json_encode( $body );
	exit;
}

http_response_code( 404 );
echo json_encode(
	array(
		'error'   => 'not_found',
		'message' => 'Not in the stand-in.',
	)
);
