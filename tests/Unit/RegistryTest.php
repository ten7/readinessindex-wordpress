<?php
namespace ReadinessIndex\Tests;

use ReadinessIndex\Fixers\Fixer;
use ReadinessIndex\Fixers\Registry;
use ReadinessIndex\Tests\Support\FakeFixer;
use ReadinessIndex\Tests\Support\WpTestCase;

/**
 * The structural rules that keep a fixer from being a way around the runner.
 */
final class RegistryTest extends WpTestCase {

	protected function tear_down() {
		Registry::use_fixers( null );
		parent::tear_down();
	}

	/**
	 * Every source file under src/, by path relative to the plugin root.
	 *
	 * @return array<string,string>
	 */
	private function sources(): array {
		$root = dirname( __DIR__, 2 );
		$out  = array();
		$it   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/src', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$out[ substr( $file->getPathname(), strlen( $root ) + 1 ) ] = (string) file_get_contents( $file->getPathname() );
			}
		}
		return $out;
	}

	public function test_every_class_that_implements_fixer_is_registered(): void {
		$registered = array_map( 'ltrim', Registry::CLASSES, array_fill( 0, count( Registry::CLASSES ), '\\' ) );
		$found      = array();
		foreach ( $this->sources() as $path => $code ) {
			if ( ! preg_match( '/^namespace\s+([\w\\\\]+);.*?\bclass\s+(\w+)[^{]*\bimplements\b[^{]*\bFixer\b/s', $code, $m ) && ! preg_match( '/^namespace\s+([\w\\\\]+);.*?\babstract class\s+(\w+)\s+implements\s+Fixer\b/s', $code, $m ) ) {
				continue;
			}
			if ( false !== strpos( $code, 'abstract class' ) ) {
				continue;
			}
			$found[] = $m[1] . '\\' . $m[2];
		}
		sort( $found );
		sort( $registered );
		$this->assertSame( $found, $registered, 'A Fixer that is not in Registry::CLASSES does not exist for the Fixes screen, the runner or the uninstall.' );
	}

	public function test_every_registered_class_is_a_fixer_with_checks_and_sentences(): void {
		foreach ( Registry::CLASSES as $class ) {
			$fixer = new $class();
			$this->assertInstanceOf( Fixer::class, $fixer, $class );
			$this->assertNotEmpty( $fixer->checks(), $class );
			foreach ( $fixer->checks() as $check ) {
				$this->assertMatchesRegularExpression( '/^AIR-\d+\.\d+$/D', $check, $class );
			}
			$this->assertNotSame( $fixer->title(), 'fixer.' . str_replace( '-', '_', $fixer->id() ) . '.title', $class . ' has no title string' );
			$key = str_replace( '-', '_', $fixer->id() );
			foreach ( array( 'affects', 'wont', 'title', 'summary' ) as $part ) {
				$this->assertTrue( \ReadinessIndex\Strings::has( 'fixer.' . $key . '.' . $part ), $class . ' lacks fixer.' . $key . '.' . $part );
			}
		}
		$this->assertCount( count( Registry::CLASSES ), Registry::all() );
	}

	public function test_an_id_is_only_ever_looked_up_never_turned_into_a_class(): void {
		Registry::use_fixers( array() );
		$this->assertNull( Registry::get( 'ReadinessIndex\\Fixers\\Runner' ) );
		$this->assertNull( Registry::get( '../../etc/passwd' ) );
		$this->assertNull( Registry::for_check( 'AIR-1.1' ) );
	}

	public function test_for_check_finds_the_fixer_that_moves_a_test(): void {
		$vector           = \ReadinessIndex\Tests\Support\Vectors::all()['llms-txt/pages-and-posts'][0];
		$fixer            = new FakeFixer( 'llms-txt', \ReadinessIndex\Fixers\Logic\LlmsTxt::class, $vector['given'] );
		$fixer->check_ids = array( 'AIR-1.10' );
		Registry::use_fixers( array( $fixer ) );
		$this->assertSame( $fixer, Registry::for_check( 'AIR-1.10' ) );
		$this->assertNull( Registry::for_check( 'AIR-1.11' ) );
	}

	public function test_nothing_but_the_runner_can_apply_undo_or_clean_up(): void {
		foreach ( $this->sources() as $path => $code ) {
			if ( 'src/Fixers/Runner.php' === $path ) {
				continue;
			}
			$this->assertDoesNotMatchRegularExpression( '/->(apply|undo|cleanup)\s*\(/', $code, $path . ' calls a fixer outside the runner' );
			$this->assertDoesNotMatchRegularExpression( '/::(apply|undo|cleanup)\s*\(/', preg_replace( '/self::|static::|parent::/', '', $code ), $path . ' calls a fixer statically' );
			$this->assertDoesNotMatchRegularExpression( '/new\s+Permit\b/', $code, $path . ' issues a permit' );
		}
	}

	public function test_apply_undo_and_cleanup_take_a_permit_in_every_fixer(): void {
		foreach ( array( 'apply', 'undo', 'cleanup' ) as $method ) {
			$parameters = ( new \ReflectionMethod( Fixer::class, $method ) )->getParameters();
			$this->assertSame( 'ReadinessIndex\\Fixers\\Permit', (string) $parameters[0]->getType(), $method );
		}
	}
}
