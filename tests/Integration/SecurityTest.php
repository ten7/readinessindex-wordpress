<?php
namespace ReadinessIndex\Tests\Integration;

use ReadinessIndex\Admin\Actions;
use ReadinessIndex\Admin\Menu;
use ReadinessIndex\Changes\Log;
use ReadinessIndex\Fixers\Hooks;
use ReadinessIndex\Fixers\Runner;
use ReadinessIndex\Fixers\State;
use ReadinessIndex\Tests\Integration\Support\IntegrationCase;

/**
 * Capability, nonce and method on every action that changes a site, in a real WordPress, and the
 * data lifecycle: what deactivation leaves, what uninstall removes.
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
	 * The two actions that change a site, and the other that makes requests.
	 *
	 * @return array<string,array{0:callable}>
	 */
	public function handlers(): array {
		return array(
			'apply'    => array( array( Runner::class, 'handle_apply' ) ),
			'undo'     => array( array( Runner::class, 'handle_undo' ) ),
			'settings' => array( array( Actions::class, 'handle_save_settings' ) ),
			'diagnose' => array( array( Actions::class, 'handle_diagnose' ) ),
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
	}

	/**
	 * An administrator without a valid nonce is refused.
	 *
	 * @dataProvider handlers
	 */
	public function test_an_administrator_without_a_nonce_is_refused(): void {
		$handler = func_get_args()[0];
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_REQUEST['_wpnonce'] = 'not-a-nonce';
		try {
			$handler();
			$this->fail( 'A request with no valid nonce was allowed.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringStartsWith( 'wp_die:', $e->getMessage() );
		}
		$this->assertNull( State::get( 'robots-txt' ) );
		$this->assertSame( 0, Log::count() );
	}

	public function test_a_get_request_cannot_apply_even_with_a_valid_nonce(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_REQUEST['_wpnonce']      = wp_create_nonce( Runner::APPLY_ACTION );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$this->expectExceptionMessage( 'wp_die:405' );
		Runner::handle_apply();
	}

	public function test_no_action_is_registered_for_a_visitor_who_is_not_signed_in(): void {
		Menu::register();
		foreach ( array( 'readiness_index_apply', 'readiness_index_undo', 'readiness_index_save_settings', 'readiness_index_diagnose', 'readiness_index_fix_prompt' ) as $action ) {
			$this->assertFalse( has_action( 'admin_post_nopriv_' . $action ), $action );
			$this->assertFalse( has_action( 'wp_ajax_nopriv_' . $action ), $action );
		}
	}

	public function test_the_screens_are_for_people_who_can_manage_options_only(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		foreach ( array( 'render', 'render_fixes', 'render_changes', 'render_preview' ) as $method ) {
			ob_start();
			Menu::$method();
			$this->assertSame( '', ob_get_clean(), $method . ' printed something for an editor' );
		}
	}

	public function test_nothing_is_hooked_on_a_front_end_request_when_nothing_has_been_applied(): void {
		global $wp_filter;
		$before = array_keys( $wp_filter );
		$this->next_request();
		Hooks::register();
		$this->assertSame( $before, array_keys( $wp_filter ), 'no hook name was added' );
	}
}
