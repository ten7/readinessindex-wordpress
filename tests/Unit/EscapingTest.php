<?php
namespace ReadinessIndex\Tests;

use Brain\Monkey\Functions;
use ReadinessIndex\Admin\Flash;
use ReadinessIndex\Admin\Screen;
use ReadinessIndex\Admin\ScoreView;
use ReadinessIndex\Api\Client;
use ReadinessIndex\Settings;
use ReadinessIndex\Tests\Support\Fixtures;
use ReadinessIndex\Tests\Support\WpTestCase;

/**
 * Pushes hostile strings through every string field of every fixture the screens read and
 * proves none comes out as markup, a script, an event handler or a javascript: address.
 *
 * Everything in a response about a site was written by that site or by whoever reached the
 * server, so each field is treated as the attack surface, not just the ones that look free-form.
 */
final class EscapingTest extends WpTestCase {

	const KEY = 'air_0a1b2c3d_abcdefghijklmnopqrstuvwxyz012345';

	const PAYLOADS = array(
		'<script>alert(1)</script>',
		'javascript:alert(1)',
		'x" onmouseover="alert(1)',
		'</p><img src=x onerror=alert(1)>',
	);

	protected function set_up() {
		parent::set_up();
		Functions\when( 'is_user_logged_in' )->justReturn( true );
	}

	/**
	 * Every path to a string value in a decoded document.
	 *
	 * @param mixed             $node  Node.
	 * @param array<int,string> $trail Path so far.
	 * @return array<int,array<int,string>>
	 */
	private function string_paths( $node, array $trail = array() ): array {
		if ( is_string( $node ) ) {
			return array( $trail );
		}
		$out = array();
		if ( is_array( $node ) ) {
			foreach ( $node as $key => $child ) {
				$out = array_merge( $out, $this->string_paths( $child, array_merge( $trail, array( (string) $key ) ) ) );
			}
		}
		return $out;
	}

	private function with( array $body, array $path, string $value ): array {
		$ref = &$body;
		foreach ( $path as $step ) {
			$ref = &$ref[ $step ];
		}
		$ref = $value;
		return $body;
	}

	private function render(): string {
		$model = Screen::build( 7, array(), new Client( 'https://readinessindex.io', self::KEY ), false );
		ob_start();
		ScoreView::render( $model );
		return (string) ob_get_clean();
	}

	private function assert_safe( string $html, string $where ): void {
		$this->assertStringNotContainsString( '<script', $html, $where );
		$this->assertStringNotContainsString( '<img', $html, $where );
		$this->assertDoesNotMatchRegularExpression( '/\son(mouseover|error|load|click)\s*=\s*"/i', $html, $where );
		$this->assertDoesNotMatchRegularExpression( '/(href|src|action)\s*=\s*"\s*javascript:/i', $html, $where );
	}

	public function test_every_string_of_every_site_fixture_is_escaped_wherever_it_lands(): void {
		$rendered = 0;
		foreach ( Fixtures::index() as $entry ) {
			if ( 'SiteResponse' !== $entry['shape'] ) {
				continue;
			}
			$name = preg_replace( '/\.json$/', '', $entry['path'] );
			$body = Fixtures::load( $name )['response']['body'];
			foreach ( $this->string_paths( $body ) as $path ) {
				foreach ( self::PAYLOADS as $payload ) {
					$this->options    = array( Settings::OPTION_KEY => self::KEY );
					$this->transients = array();
					$this->respond(
						array(
							'response' => array( 'code' => 200 ),
							'headers'  => array(),
							'body'     => json_encode( $this->with( $body, $path, $payload ) ),
						)
					);
					$this->assert_safe( $this->render(), $name . ' ' . implode( '.', $path ) . ' <- ' . $payload );
					++$rendered;
				}
			}
		}
		$this->assertGreaterThan( 400, $rendered );
	}

	public function test_every_string_of_every_error_fixture_is_escaped(): void {
		foreach ( Fixtures::index() as $entry ) {
			if ( 'ErrorBody' !== $entry['shape'] ) {
				continue;
			}
			$name = preg_replace( '/\.json$/', '', $entry['path'] );
			$body = Fixtures::load( $name )['response']['body'];
			foreach ( $this->string_paths( $body ) as $path ) {
				foreach ( self::PAYLOADS as $payload ) {
					$this->options    = array( Settings::OPTION_KEY => self::KEY );
					$this->transients = array();
					$response         = Fixtures::response( $name );
					$response['body'] = json_encode( $this->with( $body, $path, $payload ) );

					// As the answer to the screen's own call...
					$this->respond( $response );
					$this->assert_safe( $this->render(), $name . ' ' . implode( '.', $path ) );

					// ...and as the refusal of a scan, carried to the next screen by the flash.
					$this->respond( Fixtures::response( 'site/never-scanned' ) );
					Flash::put(
						7,
						'scan',
						(string) $body['error'],
						array(
							'message' => $payload,
							'reason'  => $payload,
						)
					);
					$this->assert_safe( $this->render(), $name . ' flash ' . implode( '.', $path ) );
				}
			}
		}
	}

	public function test_a_hostile_address_in_the_next_step_or_pack_is_never_linked(): void {
		$cases = array(
			array( 'site/done-first-scan', array( 'allowance', 'next_step', 'url' ) ),
			array( 'site/no-rescans-left', array( 'allowance', 'next_step', 'url' ) ),
			array( 'site/basic-plan', array( 'allowance', 'pack_url' ) ),
		);
		foreach ( $cases as $case ) {
			foreach ( array( 'javascript:alert(1)', 'data:text/html,<script>alert(1)</script>', 'vbscript:x', 'http://plain.example/', '//evil.example/' ) as $address ) {
				$this->options    = array( Settings::OPTION_KEY => self::KEY );
				$this->transients = array();
				$body             = $this->with( Fixtures::load( $case[0] )['response']['body'], $case[1], $address );
				$this->respond(
					array(
						'response' => array( 'code' => 200 ),
						'headers'  => array(),
						'body'     => json_encode( $body ),
					)
				);
				$html = $this->render();
				$this->assertStringNotContainsString( $address, $html, $case[0] . ' ' . $address );
				$this->assert_safe( $html, $case[0] );
			}
		}
	}
}
