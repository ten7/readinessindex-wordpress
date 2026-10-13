<?php
/**
 * Base class for tests that run in a real WordPress.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Tests\Integration\Support;

use ReadinessIndex\Fixers\Registry;
use ReadinessIndex\Fixers\SeoTools;
use ReadinessIndex\Fixers\State;
use ReadinessIndex\Plugin;
use WP_UnitTestCase;

/**
 * Gives every test the plugin's own table and an empty fixer state, no SEO plugin unless the
 * test names one, and no real HTTP: a request the plugin makes to its own site (the verify
 * step) is answered by a test double or, by default, fails, which the plugin reports as "could
 * not look" and never as a pass.
 */
abstract class IntegrationCase extends WP_UnitTestCase {

	/**
	 * Requests the plugin tried to make, as URLs.
	 *
	 * @var string[]
	 */
	protected $requests = array();

	/**
	 * The User-Agent of each request, in order.
	 *
	 * @var string[]
	 */
	protected $user_agents = array();

	/**
	 * Answers to give for requests, by URL substring: an array of status, headers and body, or a
	 * callable that is given the URL and the request arguments and returns one.
	 *
	 * @var array<string,array<string,mixed>|callable>
	 */
	protected $answers = array();

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		Plugin::upgrade();
		delete_option( State::OPTION );
		add_option( State::OPTION, array(), '', 'yes' );
		Registry::use_fixers( null );
		SeoTools::use_active( array() );
		$this->requests    = array();
		$this->answers     = array();
		$this->user_agents = array();
		add_filter( 'pre_http_request', array( $this, 'intercept' ), 10, 3 );
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );
		SeoTools::use_active( null );
		Registry::use_fixers( null );
		parent::tear_down();
	}

	/**
	 * Behaves as the next request would: every runtime hook of the plugin is removed and the
	 * ones the stored state calls for are added again. A fixer's hooks are registered when a
	 * request starts, so a test that applies and then looks at what is served needs this.
	 */
	protected function next_request(): void {
		remove_filter( 'robots_txt', array( \ReadinessIndex\Fixers\Runtime\RobotsTxt::class, 'filter' ), \ReadinessIndex\Fixers\Runtime\RobotsTxt::PRIORITY );
		remove_filter( 'wp_robots', array( \ReadinessIndex\Fixers\Runtime\Directives::class, 'filter' ), \ReadinessIndex\Fixers\Runtime\Directives::PRIORITY );
		remove_action( 'wp_head', array( \ReadinessIndex\Fixers\Runtime\EntityGraph::class, 'print_graph' ), 1 );
		remove_action( 'template_redirect', array( \ReadinessIndex\Fixers\Runtime\LlmsTxt::class, 'serve' ), 0 );
		remove_action( 'init', array( \ReadinessIndex\Fixers\Runtime\LlmsTxt::class, 'add_rule' ) );
		remove_filter( 'wp_sitemaps_posts_entry', array( \ReadinessIndex\Fixers\Runtime\Dates::class, 'core_sitemap_entry' ), 10 );
		remove_action( 'post_updated', array( \ReadinessIndex\Fixers\Runtime\Dates::class, 'record_change' ), 10 );
		\ReadinessIndex\Fixers\Hooks::register();
	}

	/**
	 * Answers a request from the table, or fails it.
	 *
	 * @param mixed  $pre  Short-circuit value.
	 * @param array  $args Request arguments.
	 * @param string $url  The URL.
	 * @return mixed
	 */
	public function intercept( $pre, $args, $url ) {
		$this->requests[]    = $url;
		$this->user_agents[] = $args['user-agent'] ?? '';
		foreach ( $this->answers as $needle => $answer ) {
			if ( false !== strpos( $url, $needle ) ) {
				if ( is_callable( $answer ) ) {
					$answer = $answer( $url, $args );
				}
				return array(
					'headers'  => $answer['headers'] ?? array(),
					'body'     => $answer['body'] ?? '',
					'response' => array(
						'code'    => $answer['status'] ?? 200,
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
