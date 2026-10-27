<?php
/**
 * Base class for tests that run in a real WordPress.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Tests\Integration\Support;

use ReadinessIndex\Admin\Screen;
use ReadinessIndex\Settings;
use WP_UnitTestCase;

/**
 * Gives every test a clean set of the plugin's own options and no real HTTP: every request
 * the plugin tries to make is recorded, and answered from a fixture of the golden API
 * contract, or refused. A test that expects no request asserts that the record is empty.
 */
abstract class IntegrationCase extends WP_UnitTestCase {

	const KEY = 'air_0a1b2c3d_abcdefghijklmnopqrstuvwxyz012345';

	/**
	 * Requests the plugin tried to make: method and URL.
	 *
	 * @var array<int,array{method:string,url:string,headers:array<string,string>}>
	 */
	protected $requests = array();

	/**
	 * The golden fixtures to answer with, by URL path suffix: `/cms/v1/site` => `site/done-first-scan`.
	 *
	 * @var array<string,string>
	 */
	protected $answers = array();

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		$this->clean();
		$this->requests = array();
		$this->answers  = array();
		add_filter( 'pre_http_request', array( $this, 'intercept' ), 10, 3 );
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );
		$this->clean();
		parent::tear_down();
	}

	/**
	 * Forgets everything the plugin stores, so one test cannot leak into the next.
	 */
	private function clean(): void {
		foreach ( \ReadinessIndex\Uninstall::OPTIONS as $option ) {
			delete_option( $option );
		}
		delete_transient( Screen::BACKOFF_TRANSIENT );
	}

	/**
	 * Stores a connection key the way the Connect return does.
	 */
	protected function connect(): void {
		Settings::store_key( self::KEY );
		Settings::store_connection( 'Free', str_repeat( 'a', 32 ) );
	}

	/**
	 * Answers a request from a golden fixture, or fails it.
	 *
	 * @param mixed  $pre  Short-circuit value.
	 * @param array  $args Request arguments.
	 * @param string $url  The URL.
	 * @return mixed
	 */
	public function intercept( $pre, $args, $url ) {
		$this->requests[] = array(
			'method'  => (string) ( $args['method'] ?? 'GET' ),
			'url'     => $url,
			'headers' => (array) ( $args['headers'] ?? array() ),
		);
		$path             = (string) wp_parse_url( $url, PHP_URL_PATH );
		foreach ( $this->answers as $suffix => $fixture ) {
			if ( substr( $path, -strlen( $suffix ) ) === $suffix ) {
				$data = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/cms/' . $fixture . '.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local test fixture.
				return array(
					'headers'  => array_change_key_case( (array) $data['response']['headers'], CASE_LOWER ),
					'body'     => wp_json_encode( $data['response']['body'] ),
					'response' => array(
						'code'    => (int) $data['response']['status'],
						'message' => '',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			}
		}
		return new \WP_Error( 'ri_test_no_network', 'No network in this test.' );
	}
}
