<?php
namespace ReadinessIndex\Tests\Integration;

use ReadinessIndex\Admin\Notices;
use ReadinessIndex\Admin\Screen;
use ReadinessIndex\Plugin;
use ReadinessIndex\Scan\Poller;
use ReadinessIndex\Scan\Starter;
use ReadinessIndex\Settings;
use ReadinessIndex\Tests\Integration\Support\IntegrationCase;
use ReadinessIndex\Uninstall;

/**
 * Deactivation and uninstall in a real WordPress, with a real options table, real transients,
 * real user meta and a real cron table.
 */
final class UninstallTest extends IntegrationCase {

	/**
	 * Stores everything the plugin ever stores, the way the plugin stores it.
	 *
	 * @return int The administrator who has dismissed both notices.
	 */
	private function everything(): int {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->connect();
		update_option(
			Settings::OPTION_SITE_CACHE,
			array(
				'etag' => '"x"',
				'body' => array(),
			),
			false
		);
		update_option(
			Settings::OPTION_ACTIVE,
			array(
				'token' => 'scan-1',
				'since' => time(),
			),
			false
		);
		add_option( Starter::LOCK_OPTION, time(), '', false );
		set_transient( Screen::BACKOFF_TRANSIENT, 1, 60 );
		set_transient(
			'ri_flash_' . $user,
			array(
				'kind' => 'scan',
				'code' => 'x',
			),
			300
		);
		set_transient( 'ri_waiting_' . $user, 1, 1200 );
		set_transient( 'ri_state_' . str_repeat( 'a', 40 ), array( 'user' => $user ), 1200 );
		update_user_meta( $user, Screen::DISMISS_META, '1' );
		update_user_meta( $user, Notices::DISMISS_META, '1' );
		wp_schedule_single_event( time() + 60, Poller::HOOK );
		wp_schedule_single_event( time() + 60, 'readiness_index_verify_soon' );
		return $user;
	}

	/**
	 * Every row of the options table whose name has one of the plugin's prefixes.
	 *
	 * @return string[]
	 */
	private function leftovers(): array {
		global $wpdb;
		return $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'readiness\\_index\\_%' OR option_name LIKE '\\_transient\\_ri\\_%' OR option_name LIKE '\\_transient\\_timeout\\_ri\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function test_uninstall_leaves_nothing_the_plugin_stored(): void {
		$user = $this->everything();
		$this->assertNotEmpty( $this->leftovers(), 'the fixture stored something' );

		Uninstall::run();

		$this->assertSame( array(), $this->leftovers() );
		$this->assertSame( '', get_user_meta( $user, Screen::DISMISS_META, true ) );
		$this->assertSame( '', get_user_meta( $user, Notices::DISMISS_META, true ) );
		$this->assertFalse( wp_next_scheduled( Poller::HOOK ) );
		$this->assertFalse( wp_next_scheduled( 'readiness_index_verify_soon' ) );
	}

	public function test_uninstall_does_not_touch_what_is_not_the_plugins(): void {
		update_option( 'blogname', 'Kept' );
		update_option( 'ri_unrelated_option', 'kept' );
		set_transient( 'other_plugin_cache', 'kept', 60 );
		update_user_meta( 1, 'some_other_meta', 'kept' );
		Uninstall::run();
		$this->assertSame( 'Kept', get_option( 'blogname' ) );
		$this->assertSame( 'kept', get_option( 'ri_unrelated_option' ) );
		$this->assertSame( 'kept', get_transient( 'other_plugin_cache' ) );
		$this->assertSame( 'kept', get_user_meta( 1, 'some_other_meta', true ) );
	}

	public function test_deactivation_stops_the_events_and_keeps_the_connection(): void {
		$this->everything();
		Plugin::deactivate();
		$this->assertFalse( wp_next_scheduled( Poller::HOOK ) );
		$this->assertFalse( wp_next_scheduled( 'readiness_index_verify_soon' ) );
		$this->assertSame( self::KEY, Settings::key() );
	}

	public function test_every_option_the_plugin_stores_is_not_autoloaded(): void {
		global $wpdb;
		$this->everything();
		$rows = $wpdb->get_results( "SELECT option_name, autoload FROM {$wpdb->options} WHERE option_name LIKE 'readiness\\_index\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertNotEmpty( $rows );
		foreach ( $rows as $row ) {
			$this->assertContains( $row->autoload, array( 'no', 'off' ), $row->option_name . ' would be read into memory on every request' );
		}
	}
}
