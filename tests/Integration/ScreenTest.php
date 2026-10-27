<?php
namespace ReadinessIndex\Tests\Integration;

use ReadinessIndex\Admin\Actions;
use ReadinessIndex\Admin\Menu;
use ReadinessIndex\Admin\Notices;
use ReadinessIndex\Admin\Privacy;
use ReadinessIndex\Connect\OwnershipFile;
use ReadinessIndex\Settings;
use ReadinessIndex\Tests\Integration\Support\IntegrationCase;
use ReadinessIndex\Tests\Support\Fixtures;

/**
 * (The service's origin is read from `Settings::origin()`, not written here, so a test site whose
 * wp-config.php still defines `READINESS_INDEX_API_ORIGIN` from a stand-in run does not fail the suite.)
 *
 * The Score screen, printed by a real WordPress for every state the golden fixtures describe.
 * PHPUnit turns a PHP notice, warning or deprecation into a failure, and the suite runs with
 * WP_DEBUG on, so a screen that triggers one in any state fails here. It also counts the requests
 * the plugin makes, which must be none before Connect.
 */
final class ScreenTest extends IntegrationCase {

	/**
	 * Prints the screen as an administrator.
	 */
	private function screen(): string {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		}
		set_current_screen( 'toplevel_page_readiness-index' );
		ob_start();
		Menu::render();
		return (string) ob_get_clean();
	}

	public function test_an_unconnected_site_makes_no_request_at_all(): void {
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'toplevel_page_readiness-index' );
		// Every callback the plugin hooks to a request an administrator makes, run by hand: firing
		// the core actions themselves sends headers, which a test runner has already passed.
		OwnershipFile::maybe_serve();
		Menu::add_menu();
		// WordPress only accepts the policy text during admin_init; firing that action sends headers.
		$GLOBALS['wp_current_filter'][] = 'admin_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Placing the call inside the action it expects.
		Privacy::add_policy_content();
		array_pop( $GLOBALS['wp_current_filter'] );
		Notices::network();
		$html = $this->screen();
		Menu::enqueue( 'toplevel_page_readiness-index' );
		$this->assertStringContainsString( 'Connect to Readiness Index', $html );
		$this->assertSame( array(), $this->requests, 'nothing reaches the service before an administrator clicks Connect' );
	}

	public function test_connect_sends_the_browser_and_makes_no_request_itself(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_REQUEST['_wpnonce']      = wp_create_nonce( \ReadinessIndex\Connect\Connector::ACTION_START );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new \RuntimeException( 'redirect:' . $location ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
		);
		try {
			Actions::handle_connect();
			$this->fail( 'Connect did not redirect.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringStartsWith( 'redirect:' . Settings::origin() . '/connect/cms?', $e->getMessage() );
			$this->assertStringContainsString( 'platform=wordpress', $e->getMessage() ); // phpcs:ignore WordPress.WP.CapitalPDangit -- The value on the wire is lowercase.
			$this->assertStringNotContainsString( 'air_', $e->getMessage(), 'no key is in the address' );
		}
		unset( $_REQUEST['_wpnonce'], $_SERVER['REQUEST_METHOD'] );
		$this->assertSame( array(), $this->requests );
	}

	/**
	 * Every site fixture is one state of the screen.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function site_fixtures(): array {
		$out = array();
		foreach ( Fixtures::index() as $entry ) {
			if ( 'SiteResponse' === $entry['shape'] ) {
				$name         = preg_replace( '/\.json$/', '', $entry['path'] );
				$out[ $name ] = array( $name );
			}
		}
		return $out;
	}

	/**
	 * A connected site makes the one `GET /cms/v1/site` call and draws without a notice.
	 *
	 * @dataProvider site_fixtures
	 */
	public function test_every_site_state_draws_with_one_request(): void {
		$fixture = func_get_args()[0];
		$this->connect();
		$this->answers['/cms/v1/site'] = $fixture;
		$html                          = $this->screen();
		$this->assertCount( 1, $this->requests, $fixture );
		$this->assertSame( 'GET', $this->requests[0]['method'] );
		$this->assertStringStartsWith( Settings::origin() . '/cms/v1/site', $this->requests[0]['url'] );
		$this->assertStringContainsString( '<h1>Readiness Index</h1>', $html );
		$this->assertSame( 1, substr_count( $html, '<h1>' ), $fixture );
	}

	public function test_a_done_scan_shows_the_four_categories_and_the_full_report_link(): void {
		$this->connect();
		$this->answers['/cms/v1/site'] = 'site/done-first-scan';
		$html                          = $this->screen();
		foreach ( array( 'Reach', 'Read', 'Understand', 'Act' ) as $category ) {
			$this->assertStringContainsString( 'aria-label="' . $category . '"', $html );
		}
		$this->assertSame( 4, substr_count( $html, '<meter ' ) );
		$this->assertStringContainsString( 'href="' . Settings::origin() . '/report/', $html );
		$this->assertStringContainsString( 'rel="noopener"', $html );
	}

	public function test_the_upgrade_link_carries_this_sites_own_screen_as_its_return_target(): void {
		$this->connect();
		$this->answers['/cms/v1/site'] = 'site/done-first-scan';
		$html                          = $this->screen();
		$this->assertSame( 1, preg_match( '#href="(https://readinessindex\.io/billing/[^"]+)"#', $html, $m ), $html );
		$query = array();
		parse_str( (string) wp_parse_url( html_entity_decode( $m[1] ), PHP_URL_QUERY ), $query );
		$this->assertSame( admin_url( 'admin.php?page=readiness-index' ), $query['return'] );
		$this->assertCount( 1, $this->requests, 'following the link is the browser\'s request, not the plugin\'s' );
	}

	public function test_the_disconnect_confirmation_opens_only_with_its_nonce(): void {
		$this->connect();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) ); // The nonce is this user's.
		$this->answers['/cms/v1/site'] = 'site/done-first-scan';
		$_GET['ri_confirm']            = 'disconnect';
		$this->assertStringNotContainsString( 'Disconnect</button> <a', $this->screen(), 'a link without a nonce shows the ordinary screen' );
		$_GET['_wpnonce'] = 'not-a-nonce';
		$this->assertStringNotContainsString( 'Disconnect</button> <a', $this->screen() );
		$_GET['_wpnonce'] = wp_create_nonce( Menu::CONFIRM_ACTION );
		$html             = $this->screen();
		unset( $_GET['ri_confirm'], $_GET['_wpnonce'] );
		$this->assertStringContainsString( 'name="action" value="readiness_index_disconnect"', $html );
		$this->assertStringContainsString( 'Cancel', $html );
	}

	public function test_the_service_being_down_draws_a_state_and_not_a_notice(): void {
		$this->connect();
		// No answer is configured, so the request fails the way an offline host does.
		$html = $this->screen();
		$this->assertStringContainsString( 'Try again', $html );
		$this->assertCount( 2, $this->requests, 'one try and the one retry the client makes for a GET' );
	}
}
