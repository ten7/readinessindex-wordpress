<?php
/**
 * Tests for the hand-written PSR-4 autoloader.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Tests\Unit;

use ReadinessIndex\Autoloader;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Covers mapping, nested namespaces and the cases that must stay silent.
 */
final class AutoloaderTest extends TestCase {

	/**
	 * A class under the prefix loads from the mapped directory, nested namespaces included.
	 */
	public function test_loads_a_class_from_a_nested_namespace(): void {
		$loader = Autoloader::register( 'Fixture\\', __DIR__ . '/fixtures/src/' );

		$this->assertTrue( class_exists( 'Fixture\\Sub\\Example' ) );

		spl_autoload_unregister( $loader );
	}

	/**
	 * A name outside the prefix is not ours; loading must leave it to the next autoloader.
	 */
	public function test_ignores_a_class_outside_its_prefix(): void {
		Autoloader::load( 'Other\\Thing', 'Fixture\\', __DIR__ . '/fixtures/src/' );

		$this->assertFalse( class_exists( 'Other\\Thing', false ) );
	}

	/**
	 * A class under the prefix with no file must not fatal; another loader may own it.
	 */
	public function test_a_missing_file_is_not_an_error(): void {
		Autoloader::load( 'Fixture\\Nope', 'Fixture\\', __DIR__ . '/fixtures/src/' );

		$this->assertFalse( class_exists( 'Fixture\\Nope', false ) );
	}

	/**
	 * The plugin's own prefix resolves to the real src directory.
	 */
	public function test_the_plugin_autoloader_class_is_loadable(): void {
		$this->assertTrue( class_exists( 'ReadinessIndex\\Autoloader' ) );
	}
}
