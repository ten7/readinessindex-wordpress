<?php
namespace ReadinessIndex\Tests;

use Brain\Monkey\Functions;
use ReadinessIndex\Plugin;
use ReadinessIndex\Settings;
use ReadinessIndex\Tests\Support\WpTestCase;
use ReadinessIndex\Uninstall;

/**
 * What deactivation leaves and what deleting the plugin removes: every option, user meta and
 * transient the plugin ever stores, on every site of a network.
 */
final class UninstallTest extends WpTestCase {

	/**
	 * User meta deleted, as key.
	 *
	 * @var string[]
	 */
	private $deleted_meta = array();

	protected function set_up() {
		parent::set_up();
		$this->deleted_meta = array();
		Functions\when( 'delete_metadata' )->alias(
			function ( $type, $id, $key, $value, $all ) {
				$this->assertSame( 'user', $type );
				$this->assertTrue( $all, 'every user, not one' );
				$this->deleted_meta[] = $key;
				return true;
			}
		);
		// A $wpdb that records the one statement the uninstall makes.
		$GLOBALS['wpdb'] = new class() { // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A stand-in for the database.
			/**
			 * The options table's name.
			 *
			 * @var string
			 */
			public $options = 'wp_options';
			/**
			 * Statements run.
			 *
			 * @var string[]
			 */
			public $queries = array();

			public function esc_like( string $text ): string {
				return addcslashes( $text, '_%\\' );
			}

			public function prepare( string $query, ...$args ): string {
				return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
			}

			public function query( string $sql ) {
				$this->queries[] = $sql;
				return 1;
			}
		};
	}

	protected function tear_down() {
		unset( $GLOBALS['wpdb'] );
		parent::tear_down();
	}

	public function test_uninstall_removes_every_option_the_plugin_stores(): void {
		foreach ( Uninstall::OPTIONS as $option ) {
			$this->options[ $option ] = 'x';
		}
		$this->options['unrelated'] = 'kept';
		Uninstall::run();
		$this->assertSame( array( 'unrelated' => 'kept' ), $this->options );
	}

	public function test_every_option_the_code_writes_is_one_uninstall_removes(): void {
		// A new option that is not in Uninstall::OPTIONS would outlive the plugin on every site.
		$written = array();
		$it      = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( dirname( __DIR__, 2 ) . '/src', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			$code = (string) file_get_contents( $file->getPathname() );
			if ( preg_match_all( '/\b(?:update_option|add_option)\(\s*([\w:]+)/', $code, $m ) ) {
				foreach ( $m[1] as $name ) {
					// `self::` is the class of the file it is written in.
					$written[] = str_replace( 'self::', $file->getBasename( '.php' ) . '::', $name );
				}
			}
		}
		$this->assertNotEmpty( $written );
		$removed = array(
			'Settings::OPTION_KEY',
			'Settings::OPTION_CONNECTION',
			'Settings::OPTION_SITE_CACHE',
			'Settings::OPTION_ACTIVE',
			'Starter::LOCK_OPTION',
		);
		foreach ( array_unique( $written ) as $name ) {
			$this->assertContains( $name, $removed, $name . ' is written and not removed on uninstall' );
		}
		$this->assertCount( count( $removed ), Uninstall::OPTIONS );
	}

	public function test_uninstall_removes_both_users_choices_and_the_transients_by_prefix(): void {
		$this->transients['ri_backoff'] = 1;
		Uninstall::run();
		$this->assertEqualsCanonicalizing( array( 'readiness_index_dismissed_monitoring', 'readiness_index_dismissed_network' ), $this->deleted_meta );
		$this->assertArrayNotHasKey( 'ri_backoff', $this->transients );
		$queries = $GLOBALS['wpdb']->queries;
		$this->assertCount( 1, $queries );
		$this->assertStringContainsString( 'DELETE FROM wp_options', $queries[0] );
		$this->assertStringContainsString( '\\_transient\\_ri\\_%', $queries[0] );
		$this->assertStringContainsString( '\\_transient\\_timeout\\_ri\\_%', $queries[0] );
	}

	public function test_every_transient_the_code_sets_starts_with_the_prefix_uninstall_deletes(): void {
		$names = array();
		$it    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( dirname( __DIR__, 2 ) . '/src', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			$code = (string) file_get_contents( $file->getPathname() );
			if ( preg_match_all( "/return '(ri_[a-z_]*)'/", $code, $m ) ) {
				$names = array_merge( $names, $m[1] );
			}
			if ( preg_match_all( "/BACKOFF_TRANSIENT\s*=\s*'([^']+)'/", $code, $m ) ) {
				$names = array_merge( $names, $m[1] );
			}
		}
		$this->assertGreaterThanOrEqual( 3, count( $names ), 'the state, waiting and flash names, and the back-off' );
		foreach ( $names as $name ) {
			$this->assertStringStartsWith( 'ri_', $name );
		}
		$this->assertSame( 0, preg_match( '/set_site_transient|set_transient\(\s*[\'"](?!ri_)/', $this->allSource() ), 'a transient outside the ri_ prefix would survive an uninstall' );
	}

	public function test_uninstall_clears_the_scheduled_events(): void {
		$this->events['readiness_index_poll_scan']   = 1;
		$this->events['readiness_index_verify_soon'] = 1;
		Uninstall::run();
		$this->assertSame( array(), $this->events );
	}

	public function test_deactivation_clears_the_schedules_and_keeps_the_connection(): void {
		$this->options[ Settings::OPTION_KEY ]     = 'air_0a1b2c3d_abcdefghijklmnopqrstuvwxyz012345';
		$this->events['readiness_index_poll_scan'] = 1;
		Plugin::deactivate();
		$this->assertArrayNotHasKey( 'readiness_index_poll_scan', $this->events );
		$this->assertArrayHasKey( Settings::OPTION_KEY, $this->options, 'reactivating picks the connection up again' );
	}

	public function test_a_network_uninstall_visits_every_site_and_asks_for_all_of_them(): void {
		// get_sites() returns at most one hundred by default, and a site past the hundredth would
		// keep its connection key.
		$ids   = range( 1, 250 );
		$asked = array();
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_sites' )->alias(
			function ( $args ) use ( &$asked, $ids ) {
				$asked = $args;
				return isset( $args['number'] ) && 0 === $args['number'] ? $ids : array_slice( $ids, 0, 100 );
			}
		);
		$visited = array();
		Functions\when( 'switch_to_blog' )->alias(
			function ( $id ) use ( &$visited ) {
				$visited[] = $id;
				return true;
			}
		);
		$restored = 0;
		Functions\when( 'restore_current_blog' )->alias(
			function () use ( &$restored ) {
				++$restored;
				return true;
			}
		);
		Functions\when( 'plugin_dir_path' )->justReturn( dirname( __DIR__, 2 ) . '/' );
		define( 'WP_UNINSTALL_PLUGIN', 'readiness-index/readiness-index.php' ); // phpcs:ignore -- The constant WordPress defines for uninstall.php.

		require dirname( __DIR__, 2 ) . '/uninstall.php';

		$this->assertSame( 0, $asked['number'] );
		$this->assertSame( 'ids', $asked['fields'] );
		$this->assertSame( $ids, $visited );
		$this->assertSame( 250, $restored );
	}

	/**
	 * Every shipped PHP file under src, joined.
	 */
	private function allSource(): string {
		$out = '';
		$it  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( dirname( __DIR__, 2 ) . '/src', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			$out .= (string) file_get_contents( $file->getPathname() );
		}
		return $out;
	}
}
