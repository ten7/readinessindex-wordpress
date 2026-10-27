<?php
namespace ReadinessIndex\Tests;

use ReadinessIndex\Strings;
use ReadinessIndex\Tests\Support\Fixtures;
use ReadinessIndex\Tests\Support\SchemaValidator;
use ReadinessIndex\Tests\Support\WpTestCase;

/**
 * Holds the copied contract to its pin and to itself.
 *
 * The fixtures and strings are written in the web repository. These tests fail when a
 * copied file was edited by hand (its hash is not the pinned one), when the pin is stale,
 * and when a fixture no longer satisfies the OpenAPI document it was generated with.
 */
final class ContractTest extends WpTestCase {

	private function root(): string {
		return dirname( __DIR__, 2 );
	}

	public function test_every_copied_file_matches_the_pinned_hash(): void {
		$pin   = file( $this->root() . '/docs/contract/PIN', FILE_IGNORE_NEW_LINES );
		$count = 0;
		foreach ( $pin as $line ) {
			if ( 0 !== strpos( $line, 'sha256 ' ) ) {
				continue;
			}
			list( , $hash, $path ) = preg_split( '/\s+/', $line, 3 );
			$this->assertFileExists( $this->root() . '/' . $path );
			$this->assertSame( $hash, hash_file( 'sha256', $this->root() . '/' . $path ), $path . ' differs from the pin. Run bin/sync-contract; never edit a copied file.' );
			++$count;
		}
		$this->assertGreaterThan( 30, $count );
	}

	public function test_the_pin_names_a_web_commit(): void {
		$pin = (string) file_get_contents( $this->root() . '/docs/contract/PIN' );
		$this->assertMatchesRegularExpression( '/^web_commit [0-9a-f]{40}$/m', $pin );
	}

	public function test_every_fixture_in_the_index_exists_and_no_fixture_is_unlisted(): void {
		$listed = array();
		foreach ( Fixtures::index() as $entry ) {
			$listed[] = $entry['path'];
			$this->assertFileExists( Fixtures::dir() . '/' . $entry['path'] );
		}
		$found = array();
		$it    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( Fixtures::dir(), \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			$relative = substr( $file->getPathname(), strlen( Fixtures::dir() ) + 1 );
			if ( ! in_array( $relative, array( 'index.json', 'openapi.json' ), true ) ) {
				$found[] = $relative;
			}
		}
		sort( $listed );
		sort( $found );
		$this->assertSame( $listed, $found );
	}

	public function test_every_fixture_response_satisfies_the_openapi_contract_strictly(): void {
		$doc       = json_decode( (string) file_get_contents( Fixtures::dir() . '/openapi.json' ), true );
		$validator = new SchemaValidator( $doc );
		foreach ( Fixtures::index() as $entry ) {
			if ( 'none' === $entry['shape'] ) {
				continue;
			}
			$fixture = Fixtures::load( preg_replace( '/\.json$/', '', $entry['path'] ) );
			$this->assertSame( array(), $validator->validate( $fixture['response']['body'], $entry['shape'] ), $entry['path'] );
		}
	}

	public function test_the_shared_strings_hold_every_key_the_screens_cite_and_no_price(): void {
		$strings = Strings::all();
		$this->assertNotEmpty( $strings );
		foreach ( array( 'app.name', 'menu.title', 'connect.button', 'score.value', 'state.quota.title', 'upgrade.monitoring_basic.label', 'upgrade.upgrade_basic.body', 'upgrade.pack' ) as $key ) {
			$this->assertArrayHasKey( $key, $strings );
		}
		foreach ( $strings as $key => $text ) {
			$this->assertDoesNotMatchRegularExpression( '/[$€£]|\d\s*\/\s*(mo|month|year)/iu', $text, $key );
		}
	}
}
