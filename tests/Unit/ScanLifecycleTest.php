<?php
namespace ReadinessIndex\Tests;

use Brain\Monkey\Functions;
use ReadinessIndex\Admin\Actions;
use ReadinessIndex\Admin\Ajax;
use ReadinessIndex\Admin\Flash;
use ReadinessIndex\Api\Client;
use ReadinessIndex\Api\SiteCache;
use ReadinessIndex\Connect\Connector;
use ReadinessIndex\Connect\Verifier;
use ReadinessIndex\Plugin;
use ReadinessIndex\Scan\Poller;
use ReadinessIndex\Scan\Starter;
use ReadinessIndex\Settings;
use ReadinessIndex\Tests\Support\Fixtures;
use ReadinessIndex\Tests\Support\WpTestCase;

final class ScanLifecycleTest extends WpTestCase {

	const KEY = 'air_0a1b2c3d_abcdefghijklmnopqrstuvwxyz012345';

	protected function set_up() {
		parent::set_up();
		$this->options[ Settings::OPTION_KEY ] = self::KEY;
	}

	private function client(): Client {
		return new Client( 'https://api.test', self::KEY );
	}

	public function test_starting_a_scan_marks_it_active_clears_the_cache_and_schedules_the_fallback(): void {
		SiteCache::write( '"old"', array( 'stale' => 1 ) );
		$this->respond( Fixtures::response( 'scan/started' ) );

		$started = Starter::start( $this->client() );

		$this->assertTrue( $started->started );
		$this->assertNull( SiteCache::read() );
		$this->assertSame( 'scan-token-4', Starter::active()['token'] );
		$this->assertFalse( $this->autoload[ Settings::OPTION_ACTIVE ] );
		$this->assertArrayHasKey( Poller::HOOK, $this->events );
		$this->assertArrayNotHasKey( Starter::LOCK_OPTION, $this->options, 'The lock does not outlive the call.' );
	}

	public function test_a_double_click_reaches_the_server_once(): void {
		$this->respond( Fixtures::response( 'scan/started' ), Fixtures::response( 'scan/started' ) );
		$this->assertNotNull( Starter::start( $this->client() ) );
		$this->assertNull( Starter::start( $this->client() ), 'A scan is already being watched.' );
		$this->assertCount( 1, $this->requests );
	}

	public function test_a_request_that_arrives_while_another_holds_the_lock_makes_no_call(): void {
		$this->options[ Starter::LOCK_OPTION ] = time();
		$this->respond( Fixtures::response( 'scan/started' ) );
		$this->assertNull( Starter::start( $this->client() ) );
		$this->assertCount( 0, $this->requests );
	}

	public function test_a_lock_left_by_a_dead_request_is_cleared(): void {
		$this->options[ Starter::LOCK_OPTION ] = time() - 3600;
		$this->respond( Fixtures::response( 'scan/started' ) );
		$this->assertNotNull( Starter::start( $this->client() ) );
		$this->assertCount( 1, $this->requests );
	}

	public function test_a_forgotten_active_marker_does_not_block_a_new_scan(): void {
		$this->options[ Settings::OPTION_ACTIVE ] = array(
			'token' => 't',
			'since' => time() - 7200,
		);
		$this->respond( Fixtures::response( 'scan/started' ) );
		$this->assertNotNull( Starter::start( $this->client() ) );
	}

	public function test_a_refused_scan_leaves_no_marker_no_event_and_no_lock(): void {
		$this->respond( Fixtures::response( 'errors/quota' ) );
		Actions::do_scan( 7, $this->client() );

		$this->assertNull( Starter::active() );
		$this->assertArrayNotHasKey( Poller::HOOK, $this->events );
		$this->assertArrayNotHasKey( Starter::LOCK_OPTION, $this->options );
		$flash = Flash::take( 7 );
		$this->assertSame( 'quota', $flash['code'] );
		$this->assertStringContainsString( 'A free account includes 5 rescans', $flash['message'] );
	}

	public function test_unreachable_is_recorded_with_its_reason_and_spends_nothing(): void {
		$this->respond( Fixtures::response( 'errors/unreachable-staging-password' ) );
		Actions::do_scan( 7, $this->client() );
		$flash = Flash::take( 7 );
		$this->assertSame( 'unreachable', $flash['code'] );
		$this->assertSame( 'staging_password', $flash['reason'] );
		$this->assertNull( Starter::active() );
	}

	public function test_the_cron_event_reschedules_itself_while_the_scan_runs(): void {
		$this->respond( Fixtures::response( 'scan/started' ), Fixtures::response( 'scan-status/running' ) );
		Starter::start( $this->client() );
		unset( $this->events[ Poller::HOOK ] ); // WordPress removes a single event as it fires.

		Poller::run( $this->client() );

		$this->assertArrayHasKey( Poller::HOOK, $this->events );
		$this->assertNotNull( Starter::active() );
	}

	public function test_the_cron_event_removes_itself_when_the_scan_is_done(): void {
		$this->respond( Fixtures::response( 'scan/started' ), Fixtures::response( 'scan-status/done' ) );
		Starter::start( $this->client() );
		SiteCache::write( '"x"', array( 'a' => 1 ) );
		unset( $this->events[ Poller::HOOK ] );

		Poller::run( $this->client() );

		$this->assertArrayNotHasKey( Poller::HOOK, $this->events );
		$this->assertNull( Starter::active() );
		$this->assertNull( SiteCache::read(), 'The finished score is fetched fresh next.' );
	}

	public function test_the_cron_event_removes_itself_when_the_scan_failed(): void {
		$this->respond( Fixtures::response( 'scan/started' ), Fixtures::response( 'scan-status/failed' ) );
		Starter::start( $this->client() );
		unset( $this->events[ Poller::HOOK ] );
		Poller::run( $this->client() );
		$this->assertArrayNotHasKey( Poller::HOOK, $this->events );
		$this->assertNull( Starter::active() );
	}

	public function test_the_cron_event_stops_when_the_connection_is_gone(): void {
		$this->respond( Fixtures::response( 'scan/started' ), Fixtures::response( 'errors/unauthenticated' ) );
		Starter::start( $this->client() );
		unset( $this->events[ Poller::HOOK ] );
		Poller::run( $this->client() );
		$this->assertArrayNotHasKey( Poller::HOOK, $this->events );
		$this->assertNull( Starter::active() );
	}

	public function test_the_cron_event_keeps_going_through_a_rate_limit_and_an_outage(): void {
		$this->respond( Fixtures::response( 'scan/started' ), Fixtures::response( 'errors/rate-limited' ), Fixtures::response( 'errors/internal-error' ), Fixtures::response( 'errors/internal-error' ) );
		Starter::start( $this->client() );
		unset( $this->events[ Poller::HOOK ] );
		Poller::run( $this->client() );
		$this->assertArrayHasKey( Poller::HOOK, $this->events );
		$this->assertNotNull( Starter::active() );
	}

	public function test_the_cron_event_with_nothing_to_watch_clears_itself(): void {
		$this->events[ Poller::HOOK ] = time() + 60;
		Poller::run( $this->client() );
		$this->assertArrayNotHasKey( Poller::HOOK, $this->events );
		$this->assertCount( 0, $this->requests );
	}

	public function test_disconnect_forgets_everything_and_removes_every_event(): void {
		$this->respond( Fixtures::response( 'scan/started' ) );
		Starter::start( $this->client() );
		Settings::store_connection( 'Full report', '0123456789abcdef0123456789abcdef' );
		SiteCache::write( '"x"', array( 'a' => 1 ) );
		$this->events[ Connector::VERIFY_HOOK ] = time() + 5;

		Actions::do_disconnect( 7 );

		$this->assertSame( '', Settings::key() );
		$this->assertSame( '', Settings::connection()['verify_token'] );
		$this->assertNull( SiteCache::read() );
		$this->assertNull( Starter::active() );
		$this->assertSame( array(), $this->events );
		$this->assertSame( 'disconnect', Flash::take( 7 )['kind'] );
	}

	public function test_deactivation_removes_every_event(): void {
		$this->events[ Poller::HOOK ]           = time() + 60;
		$this->events[ Connector::VERIFY_HOOK ] = time() + 5;
		Plugin::deactivate();
		$this->assertSame( array(), $this->events );
	}

	public function test_progress_payload_for_a_running_scan_has_the_servers_numbers_in_the_shared_sentence(): void {
		$this->respond( Fixtures::response( 'scan/started' ), Fixtures::response( 'scan-status/running' ) );
		Starter::start( $this->client() );
		$payload = Ajax::payload( $this->client() );
		$this->assertFalse( $payload['finished'] );
		$this->assertFalse( $payload['reload'] );
		$this->assertSame( '4 of 10 pages read.', $payload['body'] );
		$this->assertSame( 'Scanning your site', $payload['title'] );
	}

	public function test_progress_payload_for_a_finished_scan_asks_for_a_reload_and_for_nothing_after(): void {
		$this->respond( Fixtures::response( 'scan/started' ), Fixtures::response( 'scan-status/done' ) );
		Starter::start( $this->client() );
		$payload = Ajax::payload( $this->client() );
		$this->assertTrue( $payload['finished'] );
		$this->assertTrue( $payload['reload'] );
		$this->assertTrue( Ajax::payload( $this->client() )['finished'], 'With no scan to watch it says stop.' );
	}

	public function test_progress_payload_respects_a_rate_limit(): void {
		$this->respond( Fixtures::response( 'scan/started' ), Fixtures::response( 'errors/rate-limited' ) );
		Starter::start( $this->client() );
		$payload = Ajax::payload( $this->client() );
		$this->assertSame( 60, $payload['retry_after'] );
		$this->assertFalse( $payload['finished'] );
	}

	public function test_a_revoked_key_during_polling_stops_the_polling_and_reloads(): void {
		$this->respond( Fixtures::response( 'scan/started' ), Fixtures::response( 'errors/unauthenticated' ) );
		Starter::start( $this->client() );
		$payload = Ajax::payload( $this->client() );
		$this->assertTrue( $payload['reload'] );
	}

	public function test_check_ownership_clears_the_cache_on_success_and_names_the_state_on_failure(): void {
		SiteCache::write( '"x"', array( 'a' => 1 ) );
		$this->respond( Fixtures::response( 'verify/verified' ) );
		$this->assertTrue( Verifier::check( $this->client() )['verified'] );
		$this->assertNull( SiteCache::read() );

		$this->respond( Fixtures::response( 'errors/not-verified-not-found' ) );
		$this->assertSame( 'not_found', Verifier::check( $this->client() )['key'] );
		$this->respond( Fixtures::response( 'errors/not-verified-error' ) );
		$this->assertSame( 'error', Verifier::check( $this->client() )['key'] );
	}

	public function test_every_action_checks_the_capability_before_the_nonce_and_before_any_work(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'wp_die' )->alias(
			function () {
				throw new \RuntimeException( 'died' );
			}
		);
		Functions\expect( 'check_admin_referer' )->never();
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		$this->respond( Fixtures::response( 'scan/started' ) );

		foreach ( array( 'handle_connect', 'handle_disconnect', 'handle_scan', 'handle_verify', 'handle_refresh', 'handle_dismiss', 'handle_callback' ) as $handler ) {
			try {
				Actions::$handler();
				$this->fail( $handler . ' ran for a user without the capability.' );
			} catch ( \RuntimeException $e ) {
				$this->assertSame( 'died', $e->getMessage(), $handler );
			}
		}
		$this->assertCount( 0, $this->requests );
		$this->assertSame( self::KEY, Settings::key(), 'Nothing was disconnected.' );
	}

	public function test_a_failed_nonce_stops_the_action_before_it_does_anything(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_admin_referer' )->alias(
			function () {
				throw new \RuntimeException( 'bad nonce' );
			}
		);
		$this->respond( Fixtures::response( 'scan/started' ) );
		foreach ( array( 'handle_connect', 'handle_disconnect', 'handle_scan', 'handle_verify', 'handle_refresh', 'handle_dismiss' ) as $handler ) {
			try {
				Actions::$handler();
				$this->fail( $handler );
			} catch ( \RuntimeException $e ) {
				$this->assertSame( 'bad nonce', $e->getMessage(), $handler );
			}
		}
		$this->assertCount( 0, $this->requests );
		$this->assertSame( self::KEY, Settings::key() );
	}

	public function test_the_progress_handler_checks_the_capability_first(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'wp_send_json_error' )->alias(
			function () {
				throw new \RuntimeException( 'denied' );
			}
		);
		Functions\expect( 'check_ajax_referer' )->never();
		$this->expectExceptionMessage( 'denied' );
		Ajax::handle();
	}
}
