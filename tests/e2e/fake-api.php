<?php
/**
 * A stand-in for readinessindex.io's CMS API, for driving every screen state of the Score in a
 * local wp-env run, and for counting what the plugin sends.
 *
 * It serves the golden fixtures from tests/fixtures/cms/ and nothing else, checks no key and stores
 * nothing but which fixture to answer each route with. It is not a test double the suite depends on.
 *
 *   php -S 0.0.0.0:8099 tests/e2e/fake-api.php
 *
 * and point wp-env at it with `"config": { "READINESS_INDEX_API_ORIGIN": "http://host.docker.internal:8099" }`
 * in .wp-env.override.json.
 *
 * Choosing what a route answers with (any fixture can answer any route, so an error fixture can
 * stand in for the site call):
 *
 *   GET /__state?site=site/running&scan=scan/started&status=scan-status/done&verify=verify/verified
 *   GET /__state                    shows the current choices
 *   GET /__log                      every request received, as JSON lines
 *   GET /__reset                    clears the log and restores the defaults
 *
 * The Connect page, `GET /connect/cms`, answers as the real consent page would after approval: a
 * form that posts the key, the state, the plan label and the ownership token back to the callback.
 * The key is a made-up one of the right shape.
 *
 * @package ReadinessIndex
 */

$root  = dirname( __DIR__ ) . '/fixtures/cms';
$store = sys_get_temp_dir() . '/readiness-index-fake-api.json';
$log   = sys_get_temp_dir() . '/readiness-index-fake-api.log';
$path  = (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$query = array();
parse_str( (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_QUERY ), $query );

$defaults = array(
	'site'   => 'site/done-first-scan',
	'scan'   => 'scan/started',
	'status' => 'scan-status/running',
	'verify' => 'verify/verified',
);
$state    = is_readable( $store ) ? json_decode( (string) file_get_contents( $store ), true ) : $defaults;
$state    = is_array( $state ) ? $state + $defaults : $defaults;

if ( '/__reset' === $path ) {
	@unlink( $log );
	file_put_contents( $store, json_encode( $defaults ) );
	header( 'Content-Type: application/json' );
	echo json_encode( $defaults );
	exit;
}
if ( '/__state' === $path ) {
	foreach ( array_keys( $defaults ) as $name ) {
		if ( isset( $query[ $name ] ) && 1 === preg_match( '#^[a-z0-9/-]+$#', (string) $query[ $name ] ) && is_readable( $root . '/' . $query[ $name ] . '.json' ) ) {
			$state[ $name ] = (string) $query[ $name ];
		}
	}
	file_put_contents( $store, json_encode( $state ) );
	header( 'Content-Type: application/json' );
	echo json_encode( $state );
	exit;
}
if ( '/__log' === $path ) {
	header( 'Content-Type: text/plain' );
	echo is_readable( $log ) ? file_get_contents( $log ) : '';
	exit;
}

// What is logged is the method, the path and whether a bearer key was sent: never the key.
file_put_contents(
	$log,
	json_encode(
		array(
			'method' => $_SERVER['REQUEST_METHOD'],
			'path'   => $path,
			'bearer' => isset( $_SERVER['HTTP_AUTHORIZATION'] ) && 0 === strpos( $_SERVER['HTTP_AUTHORIZATION'], 'Bearer ' ),
			'agent'  => $_SERVER['HTTP_USER_AGENT'] ?? '',
		)
	) . "\n",
	FILE_APPEND
);

if ( '/connect/cms' === $path ) {
	$callback = (string) ( $query['callback'] ?? '' );
	header( 'Content-Type: text/html; charset=utf-8' );
	echo '<!doctype html><meta charset="utf-8"><title>Stand-in consent page</title>';
	echo '<form id="f" method="post" action="' . htmlspecialchars( $callback, ENT_QUOTES ) . '">';
	echo '<input type="hidden" name="key" value="air_0a1b2c3d_abcdefghijklmnopqrstuvwxyz012345">';
	echo '<input type="hidden" name="state" value="' . htmlspecialchars( (string) ( $query['state'] ?? '' ), ENT_QUOTES ) . '">';
	echo '<input type="hidden" name="plan" value="Free">';
	echo '<input type="hidden" name="verify_token" value="0123456789abcdef0123456789abcdef">';
	echo '<button>Approve (stand-in)</button></form>';
	exit;
}

$fixture = null;
if ( '/cms/v1/site' === $path && 'GET' === $_SERVER['REQUEST_METHOD'] ) {
	$fixture = $state['site'];
} elseif ( '/cms/v1/scan' === $path && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
	$fixture = $state['scan'];
} elseif ( 1 === preg_match( '#^/cms/v1/scan/[A-Za-z0-9._-]+$#', $path ) ) {
	$fixture = $state['status'];
} elseif ( '/cms/v1/verify' === $path && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
	$fixture = $state['verify'];
}

if ( null !== $fixture ) {
	$data = json_decode( (string) file_get_contents( $root . '/' . $fixture . '.json' ), true );
	http_response_code( (int) $data['response']['status'] );
	header( 'Content-Type: application/json' );
	foreach ( (array) $data['response']['headers'] as $name => $value ) {
		if ( 'content-type' !== strtolower( (string) $name ) && 'content-length' !== strtolower( (string) $name ) ) {
			header( $name . ': ' . $value );
		}
	}
	echo json_encode( $data['response']['body'] );
	exit;
}

http_response_code( 404 );
header( 'Content-Type: application/json' );
echo json_encode(
	array(
		'error'   => 'not_found',
		'message' => 'Not in the stand-in.',
	)
);
