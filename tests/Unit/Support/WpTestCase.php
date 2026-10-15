<?php
/**
 * Base class for tests that stand in for WordPress with Brain Monkey.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Tests\Support;

use Brain\Monkey;
use Brain\Monkey\Functions;
use ReadinessIndex\Strings;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Gives every test an in-memory options table, transients and the handful of WordPress
 * functions the plugin's logic calls, and records every outbound request.
 */
abstract class WpTestCase extends TestCase {

	/**
	 * Options table.
	 *
	 * @var array<string,mixed>
	 */
	protected $options = array();

	/**
	 * Autoload flags of the options written, by name.
	 *
	 * @var array<string,mixed>
	 */
	protected $autoload = array();

	/**
	 * Transients.
	 *
	 * @var array<string,mixed>
	 */
	protected $transients = array();

	/**
	 * Outbound requests, in order: url and args.
	 *
	 * @var array<int,array{url:string,args:array<string,mixed>}>
	 */
	protected $requests = array();

	/**
	 * Responses to give, in order; the last repeats.
	 *
	 * @var array<int,mixed>
	 */
	protected $responses = array();

	/**
	 * Scheduled single events: hook, then timestamp.
	 *
	 * @var array<string,int>
	 */
	protected $events = array();

	/**
	 * Set up Brain Monkey and the stand-ins.
	 */
	protected function set_up() {
		parent::set_up();
		Monkey\setUp();
		Strings::use_file( null );

		$this->options    = array();
		$this->autoload   = array();
		$this->transients = array();
		$this->requests   = array();
		$this->responses  = array();
		$this->events     = array();

		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value, $autoload = null ) {
				$this->options[ $name ]  = $value;
				$this->autoload[ $name ] = $autoload;
				return true;
			}
		);
		Functions\when( 'add_option' )->alias(
			function ( $name, $value = '', $deprecated = '', $autoload = 'yes' ) {
				if ( array_key_exists( $name, $this->options ) ) {
					return false;
				}
				$this->options[ $name ]  = $value;
				$this->autoload[ $name ] = $autoload;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $name ) {
				unset( $this->options[ $name ] );
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias(
			function ( $name ) {
				return array_key_exists( $name, $this->transients ) ? $this->transients[ $name ] : false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $name, $value ) {
				$this->transients[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $name ) {
				unset( $this->transients[ $name ] );
				return true;
			}
		);
		Functions\when( 'wp_next_scheduled' )->alias(
			function ( $hook ) {
				return $this->events[ $hook ] ?? false;
			}
		);
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $when, $hook ) {
				$this->events[ $hook ] = $when;
				return true;
			}
		);
		Functions\when( 'wp_clear_scheduled_hook' )->alias(
			function ( $hook ) {
				unset( $this->events[ $hook ] );
				return 1;
			}
		);

		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'get_bloginfo' )->justReturn( '6.6' );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'is_wp_error' )->alias(
			function ( $thing ) {
				return $thing instanceof \ReadinessIndex\Tests\WP_Error_Double;
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			function ( $response ) {
				return $response['response']['code'];
			}
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			function ( $response ) {
				return $response['body'];
			}
		);
		Functions\when( 'wp_remote_retrieve_header' )->alias(
			function ( $response, $header ) {
				return $response['headers'][ strtolower( $header ) ] ?? '';
			}
		);
		Functions\when( 'wp_remote_request' )->alias(
			function ( $url, $args = array() ) {
				$this->requests[] = array(
					'url'  => $url,
					'args' => $args,
				);
				$index            = min( count( $this->requests ), count( $this->responses ) ) - 1;
				if ( $index < 0 ) {
					throw new \LogicException( 'A request was made and no response was queued: ' . $url ); // phpcs:ignore WordPress.Security.EscapeOutput
				}
				return $this->responses[ $index ];
			}
		);
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'admin_url' )->alias( fn( $path = '' ) => 'https://example.com/wp-admin/' . $path );
		Functions\when( 'wp_nonce_field' )->alias(
			function ( $action ) {
				echo '<input type="hidden" name="_wpnonce" value="nonce-' . htmlspecialchars( (string) $action, ENT_QUOTES ) . '" />';
			}
		);
		Functions\when( 'wp_date' )->alias( fn( $format, $time ) => gmdate( $format, $time ) );
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'esc_url_raw' )->alias(
			function ( $url, $protocols = null ) {
				$url     = (string) $url;
				$allowed = $protocols ?? array( 'http', 'https' );
				$scheme  = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
				return in_array( $scheme, $allowed, true ) ? $url : '';
			}
		);
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias(
			function ( $value ) {
				return trim( wp_strip_double( (string) $value ) );
			}
		);
		Functions\when( 'esc_textarea' )->alias( fn( $text ) => htmlspecialchars( (string) $text, ENT_QUOTES ) );
		Functions\when( 'checked' )->alias(
			function ( $checked, $current = true, $display = true ) {
				$out = (string) $checked === (string) $current ? " checked='checked'" : '';
				if ( $display ) {
					echo $out;
				}
				return $out;
			}
		);
		Functions\when( 'selected' )->alias(
			function ( $selected, $current = true, $display = true ) {
				$out = (string) $selected === (string) $current ? " selected='selected'" : '';
				if ( $display ) {
					echo $out;
				}
				return $out;
			}
		);
		Functions\when( 'esc_sql' )->returnArg();
		Functions\when( 'absint' )->alias( fn( $n ) => abs( (int) $n ) );
		Functions\when( 'sanitize_key' )->alias( fn( $k ) => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ) );
		Functions\when( 'esc_html' )->alias( fn( $text ) => htmlspecialchars( (string) $text, ENT_QUOTES ) );
		Functions\when( 'esc_attr' )->alias( fn( $text ) => htmlspecialchars( (string) $text, ENT_QUOTES ) );
		Functions\when( 'esc_url' )->alias(
			function ( $url, $protocols = null ) {
				$url = (string) $url;
				if ( ! preg_match( '#^(https?)://#i', $url ) ) {
					return '';
				}
				if ( null !== $protocols && ! in_array( strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) ), $protocols, true ) ) {
					return '';
				}
				return htmlspecialchars( $url, ENT_QUOTES );
			}
		);
	}

	/**
	 * Switches the fixer side on for this test, the way a site would with the filter. The
	 * shipped default is off, so a test of a fixer, its screens or its hooks says so here and
	 * keeps that code verified while it is switched off in releases.
	 */
	protected function fixers_on(): void {
		Monkey\Filters\expectApplied( 'readiness_index_fixers' )->andReturn( true );
	}

	/**
	 * Tear Brain Monkey down.
	 */
	protected function tear_down() {
		Monkey\tearDown();
		parent::tear_down();
	}

	/**
	 * Queues the responses the next requests will receive.
	 *
	 * @param mixed ...$responses Responses, in order.
	 */
	protected function respond( ...$responses ): void {
		$this->responses = $responses;
	}
}

/**
 * Strips tags the way sanitize_text_field does, for the stand-in.
 *
 * @param string $value Input.
 */
function wp_strip_double( string $value ): string { // phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed,Generic.Files.OneObjectStructurePerFile.MultipleFound
	return strip_tags( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
}
