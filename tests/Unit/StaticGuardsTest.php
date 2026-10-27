<?php
namespace ReadinessIndex\Tests;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use ReadinessIndex\Plugin;
use ReadinessIndex\Strings;
use ReadinessIndex\Tests\Support\WpTestCase;

/**
 * Rules from CLAUDE.md that a reviewer would otherwise have to remember, written as tests
 * over the source so a later change cannot quietly break them.
 */
final class StaticGuardsTest extends WpTestCase {

	/**
	 * Every PHP and JavaScript file the plugin ships, by path relative to the plugin root.
	 *
	 * @return array<string,string> path => contents
	 */
	private function shipped(): array {
		$root  = dirname( __DIR__, 2 );
		$files = array();
		foreach ( array( 'src', 'assets' ) as $dir ) {
			$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/' . $dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $file ) {
				if ( preg_match( '/\.(php|js|css)$/', $file->getFilename() ) ) {
					$files[ substr( $file->getPathname(), strlen( $root ) + 1 ) ] = (string) file_get_contents( $file->getPathname() );
				}
			}
		}
		foreach ( array( 'readiness-index.php', 'uninstall.php' ) as $name ) {
			$files[ $name ] = (string) file_get_contents( $root . '/' . $name );
		}
		return $files;
	}

	public function test_only_the_api_client_makes_an_outbound_call(): void {
		foreach ( $this->shipped() as $path => $code ) {
			if ( 'src/Api/Client.php' === $path ) {
				$this->assertStringContainsString( 'wp_remote_request', $code );
				continue;
			}
			$this->assertDoesNotMatchRegularExpression( '/\b(wp_remote_|curl_|wp_safe_remote_|fsockopen|stream_socket_client|file_get_contents\(\s*[\'"]https?)/', $code, $path );
			$this->assertDoesNotMatchRegularExpression( '/\bfetch\(/', str_replace( 'window.fetch( config.ajaxUrl', '', $code ), $path . ' (the only fetch is the same-origin admin-ajax poll)' );
		}
	}

	public function test_the_client_never_turns_tls_verification_off_or_follows_redirects(): void {
		$code = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Api/Client.php' );
		$this->assertStringNotContainsString( "'sslverify'   => false", $code );
		$this->assertMatchesRegularExpression( "/'sslverify'\s*=>\s*true/", $code );
		$this->assertMatchesRegularExpression( "/'redirection'\s*=>\s*0/", $code );
	}

	public function test_nothing_is_logged_dumped_or_evaluated(): void {
		foreach ( $this->shipped() as $path => $code ) {
			$this->assertDoesNotMatchRegularExpression( '/\b(error_log|var_dump|print_r|var_export|debug_print_backtrace|trigger_error|eval|create_function|assert|call_user_func(_array)?|extract|unserialize|serialize)\s*\(/', $code, $path );
			// The autoloader requires a file it has just proved is under src/; nothing else may.
			if ( 'src/Autoloader.php' !== $path ) {
				$this->assertDoesNotMatchRegularExpression( '/\b(include|require)(_once)?\s*\(?\s*\$/', $code, $path . ' includes a variable path' );
			}
			$this->assertDoesNotMatchRegularExpression( '/\bconsole\.(log|debug)/', $code, $path );
		}
	}

	public function test_no_price_currency_or_per_period_figure_appears_anywhere_in_the_code_or_the_strings(): void {
		$sources                    = $this->shipped();
		$sources['strings/en.json'] = (string) file_get_contents( dirname( __DIR__, 2 ) . '/strings/en.json' );
		foreach ( $sources as $path => $code ) {
			$this->assertDoesNotMatchRegularExpression( '/[€£¥]|(?<![\w$\\\\])\$\s?\d|\d\s*\/\s*(mo|month|yr|year)\b|per (month|year)\b|\bUSD\b/i', $code, $path );
		}
	}

	public function test_every_sentence_key_the_code_asks_for_exists_in_the_shared_strings(): void {
		$strings = Strings::all();
		$found   = 0;
		foreach ( $this->shipped() as $path => $code ) {
			if ( ! preg_match_all( "/Strings::(?:get|has)\(\s*'([a-z_.]*[a-z_])'/", $code, $m ) ) {
				continue;
			}
			foreach ( $m[1] as $key ) {
				$this->assertArrayHasKey( $key, $strings, $path . ' asks for ' . $key );
				++$found;
			}
		}
		$this->assertGreaterThan( 60, $found );
	}

	public function test_every_sentence_that_ships_is_one_the_code_asks_for(): void {
		// Keys the code builds from a server value: an error's key, a next step's key, an
		// unreachable reason, a verify result. A key outside these must appear as a literal.
		$dynamic = array( 'error.', 'upgrade.', 'unreachable.', 'verify.' );
		$code    = implode( "\n", $this->shipped() );
		$unused  = array();
		foreach ( array_keys( Strings::all() ) as $key ) {
			$built = false;
			foreach ( $dynamic as $prefix ) {
				$built = $built || 0 === strpos( $key, $prefix );
			}
			if ( ! $built && false === strpos( $code, "'" . $key . "'" ) ) {
				$unused[] = $key;
			}
		}
		$this->assertSame( array(), $unused, 'These sentences ship and nothing prints them. Add them to bin/filter-strings, or delete them from strings/plugin.en.json.' );
	}

	public function test_the_code_writes_no_english_sentence_of_its_own(): void {
		foreach ( $this->shipped() as $path => $code ) {
			// A literal handed to an escaping or output function that reads like a sentence.
			$this->assertDoesNotMatchRegularExpression( "/(esc_html|esc_attr|echo)\(?\s*'[A-Z][a-z]+ [a-z]+ [a-z]+/", $code, $path );
			$this->assertDoesNotMatchRegularExpression( '/\b(esc_html_e|esc_html__|esc_attr__|__|_e|_x|_n)\s*\(\s*\'/', $code, $path );
		}
	}

	public function test_every_class_file_loads_through_the_plugins_own_autoloader(): void {
		$root = dirname( __DIR__, 2 ) . '/src';
		$it   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
		$seen = 0;
		foreach ( $it as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$relative = substr( $file->getPathname(), strlen( $root ) + 1, -4 );
			$class    = 'ReadinessIndex\\' . str_replace( '/', '\\', $relative );
			$this->assertTrue( class_exists( $class ) || interface_exists( $class ), $class . ' is not where the autoloader looks.' );
			++$seen;
		}
		$this->assertGreaterThan( 30, $seen );
	}

	public function test_a_front_end_request_registers_no_admin_code(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'register_deactivation_hook' )->justReturn( null );
		Actions\expectAdded( 'admin_menu' )->never();
		Actions\expectAdded( 'admin_init' )->never();
		Actions\expectAdded( 'admin_enqueue_scripts' )->never();
		Actions\expectAdded( 'admin_post_readiness_index_scan' )->never();
		Actions\expectAdded( 'init' )->once();
		Plugin::boot();
		$this->addToAssertionCount( 1 );
	}

	public function test_the_admin_registers_its_menu_and_actions(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'register_deactivation_hook' )->justReturn( null );
		Actions\expectAdded( 'admin_menu' )->once();
		Actions\expectAdded( 'admin_post_readiness_index_scan' )->once();
		Actions\expectAdded( 'admin_post_readiness_index_connect' )->once();
		Actions\expectAdded( 'admin_post_readiness_index_callback' )->once();
		Actions\expectAdded( 'admin_post_nopriv_readiness_index_callback' )->once();
		Actions\expectAdded( 'wp_ajax_readiness_index_progress' )->once();
		Actions\expectAdded( 'wp_ajax_nopriv_readiness_index_progress' )->never();
		Plugin::boot();
		$this->addToAssertionCount( 1 );
	}

	public function test_every_option_the_plugin_writes_is_not_autoloaded(): void {
		$writes = 0;
		foreach ( $this->shipped() as $path => $code ) {
			if ( preg_match_all( '/\b(update_option|add_option)\(([^;]*?)\);/s', $code, $m ) ) {
				foreach ( $m[2] as $arguments ) {
					$this->assertMatchesRegularExpression( '/false\s*(,\s*)?\)?\s*$/', trim( $arguments ) . ')', $path . ': ' . $arguments );
					++$writes;
				}
			}
		}
		$this->assertGreaterThan( 3, $writes );
	}

	public function test_nothing_of_the_fixer_side_ships(): void {
		// The fixers live on their own branch. A hook that rewrites what a site serves, a switch
		// for one, or a table of changes coming back into this tree is a different plugin, and
		// the one submitted to wordpress.org reads and reports only.
		foreach ( $this->shipped() as $path => $code ) {
			$this->assertDoesNotMatchRegularExpression( '/Fixers|READINESS_INDEX_FIXERS|readiness_index_fixers/', $code, $path );
			$this->assertDoesNotMatchRegularExpression( "/add_(filter|action)\(\s*'(robots_txt|wp_robots|wp_head|wp_sitemaps_[a-z_]+|the_content|template_redirect)'/", $code, $path );
			$this->assertDoesNotMatchRegularExpression( '/\bdbDelta\b|\\$wpdb->(insert|update|replace)\(|add_rewrite_rule|flush_rewrite_rules/', $code, $path );
		}
	}

	public function test_every_phpcs_ignore_in_the_code_says_why(): void {
		foreach ( $this->shipped() as $path => $code ) {
			if ( preg_match_all( '/phpcs:ignore[^\n]*/', $code, $m ) ) {
				foreach ( $m[0] as $line ) {
					$this->assertStringContainsString( ' -- ', $line, $path . ': an ignore without a reason is a silenced warning nobody can judge' );
				}
			}
		}
	}
}
