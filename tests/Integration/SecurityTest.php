<?php
namespace ReadinessIndex\Tests\Integration;

use ReadinessIndex\Admin\Actions;
use ReadinessIndex\Admin\Ajax;
use ReadinessIndex\Admin\Menu;
use ReadinessIndex\Admin\Notices;
use ReadinessIndex\Connect\Connector;
use ReadinessIndex\Settings;
use ReadinessIndex\Tests\Integration\Support\IntegrationCase;

/**
 * Capability and nonce on every action that changes something, in a real WordPress, and what a
 * visitor who is not signed in can and cannot reach.
 */
final class SecurityTest extends IntegrationCase {

	/**
	 * Makes wp_die throw, so a refused request can be observed.
	 */
	public function set_up() {
		parent::set_up();
		add_filter(
			'wp_die_handler',
			static function () {
				return static function ( $message, $title = '', $args = array() ) {
					throw new \RuntimeException( 'wp_die:' . ( $args['response'] ?? 0 ) ); // phpcs:ignore WordPress.Security.EscapeOutput
				};
			}
		);
		$_SERVER['REQUEST_METHOD'] = 'POST';
	}

	public function tear_down() {
		unset( $_SERVER['REQUEST_METHOD'], $_POST, $_REQUEST );
		parent::tear_down();
	}

	/**
	 * Every admin-post handler a signed-in administrator uses.
	 *
	 * @return array<string,array{0:array{0:string,1:string}}>
	 */
	public function handlers(): array {
		return array(
			'connect'         => array( array( Actions::class, 'handle_connect' ) ),
			'disconnect'      => array( array( Actions::class, 'handle_disconnect' ) ),
			'scan'            => array( array( Actions::class, 'handle_scan' ) ),
			'verify'          => array( array( Actions::class, 'handle_verify' ) ),
			'refresh'         => array( array( Actions::class, 'handle_refresh' ) ),
			'dismiss'         => array( array( Actions::class, 'handle_dismiss' ) ),
			'dismiss network' => array( array( Notices::class, 'handle_dismiss' ) ),
		);
	}

	/**
	 * A user who cannot manage options is stopped before the nonce is looked at.
	 *
	 * @dataProvider handlers
	 */
	public function test_a_user_without_the_capability_is_refused_with_a_403(): void {
		$handler = func_get_args()[0];
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		try {
			$handler();
			$this->fail( 'An editor was allowed.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'wp_die:403', $e->getMessage() );
		}
		$this->assertSame( array(), $this->requests );
	}

	/**
	 * An administrator without a valid nonce is refused, and nothing is requested.
	 *
	 * @dataProvider handlers
	 */
	public function test_an_administrator_without_a_nonce_is_refused(): void {
		$handler = func_get_args()[0];
		$this->connect();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_REQUEST['_wpnonce'] = 'not-a-nonce';
		try {
			$handler();
			$this->fail( 'A request with no valid nonce was allowed.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringStartsWith( 'wp_die:', $e->getMessage() );
		}
		$this->assertSame( array(), $this->requests );
		$this->assertSame( self::KEY, Settings::key(), 'a refused disconnect forgets nothing' );
	}

	public function test_the_progress_poll_is_for_administrators_with_a_nonce_only(): void {
		$this->expectException( \Exception::class );
		$this->setOutputCallback( static function () {} ); // The JSON refusal WordPress prints before it dies.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter(
			'wp_die_ajax_handler',
			static function () {
				return static function () {
					throw new \RuntimeException( 'ajax refused' );
				};
			}
		);
		Ajax::handle();
	}

	public function test_no_action_is_registered_for_a_visitor_who_is_not_signed_in_except_the_connect_return(): void {
		Menu::register();
		foreach ( array( 'readiness_index_connect', 'readiness_index_disconnect', 'readiness_index_scan', 'readiness_index_verify', 'readiness_index_refresh', 'readiness_index_dismiss', 'readiness_index_dismiss_network', Ajax::ACTION ) as $action ) {
			$this->assertFalse( has_action( 'admin_post_nopriv_' . $action ), $action );
			$this->assertFalse( has_action( 'wp_ajax_nopriv_' . $action ), $action );
		}
		$this->assertNotFalse( has_action( 'admin_post_nopriv_' . Connector::ACTION_CALLBACK ) );
	}

	public function test_the_screen_is_for_people_who_can_manage_options_only(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		ob_start();
		Menu::render();
		$this->assertSame( '', ob_get_clean(), 'the screen printed something for an editor' );
		$this->assertSame( array(), $this->requests );
	}
}
