<?php
namespace ReadinessIndex\Tests;

use Brain\Monkey\Functions;
use ReadinessIndex\Admin\Notices;
use ReadinessIndex\Tests\Support\WpTestCase;

/**
 * The multisite notice: on the plugin's own screen only, for an administrator on a network
 * activation, until that user closes it.
 */
final class NoticesTest extends WpTestCase {

	/**
	 * Per-user meta, by key.
	 *
	 * @var array<string,string>
	 */
	private $meta = array();

	protected function set_up() {
		parent::set_up();
		$this->meta = array();
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'plugin_basename' )->justReturn( 'readiness-index/readiness-index.php' );
		Functions\when( 'is_plugin_active_for_network' )->justReturn( true );
		Functions\when( 'get_user_meta' )->alias( fn( $user, $key ) => $this->meta[ $key ] ?? '' );
		Functions\when( 'get_current_screen' )->justReturn( (object) array( 'id' => 'toplevel_page_readiness-index' ) );
		Functions\when( 'admin_url' )->alias( fn( $path = '' ) => 'https://example.com/wp-admin/' . $path );
	}

	private function printed(): string {
		ob_start();
		Notices::network();
		return (string) ob_get_clean();
	}

	public function test_it_shows_on_the_plugins_own_screen_with_a_dismiss_button(): void {
		$html = $this->printed();
		$this->assertStringContainsString( 'network activated', $html );
		$this->assertStringContainsString( 'name="action" value="readiness_index_dismiss_network"', $html );
		$this->assertStringContainsString( 'nonce-readiness_index_dismiss_network', $html );
	}

	public function test_it_does_not_show_on_the_plugins_screen_or_the_dashboard(): void {
		foreach ( array( 'plugins', 'plugins-network', 'dashboard' ) as $id ) {
			Functions\when( 'get_current_screen' )->justReturn( (object) array( 'id' => $id ) );
			$this->assertSame( '', $this->printed(), $id );
		}
	}

	public function test_it_stays_closed_for_the_user_who_closed_it_and_only_for_them(): void {
		$this->meta[ Notices::DISMISS_META ] = '1';
		$this->assertSame( '', $this->printed() );
		$this->assertTrue( Notices::should_show( true, true, 'toplevel_page_readiness-index', false ), 'another user still sees it' );
	}

	public function test_it_needs_a_network_activation_and_the_capability(): void {
		$this->assertFalse( Notices::should_show( false, true, 'toplevel_page_readiness-index', false ) );
		$this->assertFalse( Notices::should_show( true, false, 'toplevel_page_readiness-index', false ), 'per-site activation needs no notice' );
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->assertSame( '', $this->printed() );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'is_multisite' )->justReturn( false );
		$this->assertSame( '', $this->printed() );
	}
}
