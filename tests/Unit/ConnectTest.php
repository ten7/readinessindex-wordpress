<?php
namespace ReadinessIndex\Tests;

use ReadinessIndex\Admin\Actions;
use ReadinessIndex\Api\SiteCache;
use ReadinessIndex\Connect\Connector;
use ReadinessIndex\Settings;
use ReadinessIndex\Tests\Support\WpTestCase;
use Brain\Monkey\Functions;

final class ConnectTest extends WpTestCase {

	const KEY   = 'air_0a1b2c3d_abcdefghijklmnopqrstuvwxyz012345';
	const TOKEN = '0123456789abcdef0123456789abcdef';

	protected function set_up() {
		parent::set_up();
		Functions\when( 'time' )->alias( fn() => $this->now );
	}

	/**
	 * Fake clock for the state's expiry.
	 *
	 * @var int
	 */
	private $now = 1000000;

	private function state_from_url( string $url ): string {
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
		return $query['state'];
	}

	private function fields( string $state, array $over = array() ): array {
		return array_merge(
			array(
				'key'          => self::KEY,
				'state'        => $state,
				'plan'         => 'Full report',
				'verify_token' => self::TOKEN,
			),
			$over
		);
	}

	public function test_the_start_url_carries_platform_site_callback_and_a_fresh_state(): void {
		$url = Connector::start_url( 7 );
		$this->assertStringStartsWith( 'https://readinessindex.io/connect/cms?', $url );
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
		$this->assertSame( 'wordpress', $query['platform'] ); // phpcs:ignore WordPress.WP.CapitalPDangit -- The API's platform key is lowercase.
		$this->assertSame( 'https://example.com', $query['site'] );
		$this->assertSame( 'https://example.com/wp-admin/admin-post.php?action=readiness_index_callback', $query['callback'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{40}$/', $query['state'] );
		$this->assertNotSame( $query['state'], $this->state_from_url( Connector::start_url( 7 ) ), 'Each press makes a new state.' );
	}

	public function test_the_state_is_stored_only_as_a_hash(): void {
		$state = $this->state_from_url( Connector::start_url( 7 ) );
		$this->assertStringNotContainsString( $state, json_encode( $this->transients ) );
	}

	public function test_a_good_return_stores_the_key_outside_autoload_and_the_token(): void {
		$state = $this->state_from_url( Connector::start_url( 7 ) );
		$this->assertSame( Connector::CONNECTED, Connector::receive( $this->fields( $state ), 7 ) );
		$this->assertSame( self::KEY, Settings::key() );
		$this->assertFalse( $this->autoload[ Settings::OPTION_KEY ] );
		$this->assertFalse( $this->autoload[ Settings::OPTION_CONNECTION ] );
		$this->assertSame(
			array(
				'plan'         => 'Full report',
				'verify_token' => self::TOKEN,
			),
			Settings::connection()
		);
		$this->assertArrayHasKey( Connector::VERIFY_HOOK, $this->events );
	}

	public function test_a_return_clears_the_cached_answer_of_the_old_connection(): void {
		SiteCache::write( '"x"', array( 'old' => true ) );
		$state = $this->state_from_url( Connector::start_url( 7 ) );
		Connector::receive( $this->fields( $state ), 7 );
		$this->assertNull( SiteCache::read() );
	}

	public function test_a_wrong_state_stores_nothing(): void {
		Connector::start_url( 7 );
		$this->assertSame( Connector::STATE_MISMATCH, Connector::receive( $this->fields( str_repeat( 'a', 40 ) ), 7 ) );
		$this->assertSame( '', Settings::key() );
	}

	public function test_a_missing_state_stores_nothing(): void {
		Connector::start_url( 7 );
		$fields = $this->fields( '' );
		unset( $fields['state'] );
		$this->assertSame( Connector::STATE_MISMATCH, Connector::receive( $fields, 7 ) );
		$this->assertSame( '', Settings::key() );
	}

	public function test_a_state_is_single_use(): void {
		$state = $this->state_from_url( Connector::start_url( 7 ) );
		$this->assertSame( Connector::CONNECTED, Connector::receive( $this->fields( $state ), 7 ) );
		Settings::forget_connection();
		$this->assertSame( Connector::STATE_MISMATCH, Connector::receive( $this->fields( $state ), 7 ), 'A replayed callback finds nothing.' );
		$this->assertSame( '', Settings::key() );
	}

	public function test_a_malformed_key_still_spends_the_state(): void {
		$state = $this->state_from_url( Connector::start_url( 7 ) );
		$this->assertSame( Connector::INVALID, Connector::receive( $this->fields( $state, array( 'key' => 'not-a-key' ) ), 7 ) );
		$this->assertSame( '', Settings::key() );
		$this->assertSame( Connector::STATE_MISMATCH, Connector::receive( $this->fields( $state ), 7 ) );
	}

	public function test_malformed_keys_and_tokens_are_refused(): void {
		foreach ( array(
			array( 'key', '' ),
			array( 'key', 'air_ZZZZZZZZ_abcdefghijklmnopqrstuvwxyz012345' ),
			array( 'key', 'air_0a1b2c3d_short' ),
			array( 'key', self::KEY . "\n" ),
			array( 'key', '<script>' ),
			array( 'verify_token', 'abc' ),
			array( 'verify_token', strtoupper( self::TOKEN ) ),
			array( 'verify_token', '' ),
		) as $case ) {
			$state = $this->state_from_url( Connector::start_url( 7 ) );
			$this->assertSame( Connector::INVALID, Connector::receive( $this->fields( $state, array( $case[0] => $case[1] ) ), 7 ), $case[0] . '=' . $case[1] );
			$this->assertSame( '', Settings::key() );
		}
	}

	public function test_an_expired_state_is_refused(): void {
		$state      = $this->state_from_url( Connector::start_url( 7 ) );
		$this->now += Connector::STATE_TTL + 1;
		$this->assertSame( Connector::STATE_MISMATCH, Connector::receive( $this->fields( $state ), 7 ) );
		$this->assertSame( '', Settings::key() );
	}

	public function test_a_state_belongs_to_the_user_who_pressed_connect(): void {
		$state = $this->state_from_url( Connector::start_url( 7 ) );
		$this->assertSame( Connector::STATE_MISMATCH, Connector::receive( $this->fields( $state ), 8 ) );
		$this->assertSame( '', Settings::key() );
		$this->assertSame( Connector::CONNECTED, Connector::receive( $this->fields( $state ), 7 ), 'Another user\'s attempt does not spend it.' );
	}

	public function test_access_denied_is_understood_and_stores_nothing(): void {
		$state = $this->state_from_url( Connector::start_url( 7 ) );
		$this->assertSame(
			Connector::DENIED,
			Connector::receive(
				array(
					'error' => 'access_denied',
					'state' => $state,
				),
				7
			)
		);
		$this->assertSame( '', Settings::key() );
	}

	public function test_access_denied_with_a_wrong_state_is_a_mismatch(): void {
		Connector::start_url( 7 );
		$this->assertSame(
			Connector::STATE_MISMATCH,
			Connector::receive(
				array(
					'error' => 'access_denied',
					'state' => 'nope',
				),
				7
			)
		);
	}

	public function test_a_state_with_control_characters_or_excess_length_is_refused_unread(): void {
		$this->assertFalse( Connector::state_is_pending( "ab\ncd" ) );
		$this->assertFalse( Connector::state_is_pending( str_repeat( 'a', 201 ) ) );
		$this->assertFalse( Connector::state_is_pending( '' ) );
	}

	public function test_waiting_is_remembered_until_the_return(): void {
		$this->assertFalse( Connector::is_waiting( 7 ) );
		$state = $this->state_from_url( Connector::start_url( 7 ) );
		$this->assertTrue( Connector::is_waiting( 7 ) );
		Connector::receive( $this->fields( $state ), 7 );
		$this->assertFalse( Connector::is_waiting( 7 ) );
	}

	public function test_a_constant_key_wins_over_the_stored_one(): void {
		$this->options[ Settings::OPTION_KEY ] = 'air_ffffffff_stored-key-stored-key-stored';
		$this->assertSame( 'air_ffffffff_stored-key-stored-key-stored', Settings::key() );
		$this->assertFalse( Settings::key_is_constant() );
	}

	public function test_the_origin_defaults_to_readinessindex_and_has_no_trailing_slash(): void {
		$this->assertSame( 'https://readinessindex.io', Settings::origin() );
	}

	public function test_a_return_without_a_session_for_an_unknown_state_is_sent_to_login_and_never_echoed(): void {
		Connector::start_url( 7 );
		$this->assertSame(
			'login',
			Actions::callback_plan(
				array(
					'key'   => self::KEY,
					'state' => 'forged',
				),
				false,
				false
			)
		);
		$this->assertSame( 'login', Actions::callback_plan( array( 'key' => self::KEY ), false, false ) );
	}

	public function test_a_return_without_a_cookie_for_a_pending_state_is_relayed_once(): void {
		$state = $this->state_from_url( Connector::start_url( 7 ) );
		$this->assertSame( 'relay', Actions::callback_plan( array( 'state' => $state ), false, false ) );
		$this->assertSame( 'login', Actions::callback_plan( array( 'state' => $state ), false, true ), 'A second hop with no session must not loop.' );
		$this->assertSame( 'process', Actions::callback_plan( array( 'state' => $state ), true, false ) );
		$this->assertTrue( Connector::state_is_pending( $state ), 'Relaying does not spend the state.' );
	}
}
