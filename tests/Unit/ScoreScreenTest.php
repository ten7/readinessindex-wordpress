<?php
namespace ReadinessIndex\Tests;

use Brain\Monkey\Functions;
use ReadinessIndex\Admin\Flash;
use ReadinessIndex\Admin\Screen;
use ReadinessIndex\Admin\ScoreView;
use ReadinessIndex\Admin\ViewModel;
use ReadinessIndex\Api\Client;
use ReadinessIndex\Api\SiteCache;
use ReadinessIndex\Connect\Connector;
use ReadinessIndex\Settings;
use ReadinessIndex\Strings;
use ReadinessIndex\Tests\Support\Fixtures;
use ReadinessIndex\Tests\Support\WpTestCase;

/**
 * Every state of the Score screen, driven by the golden fixtures and judged against the
 * shared strings file read independently of the plugin's own loader.
 */
final class ScoreScreenTest extends WpTestCase {

	const KEY = 'air_0a1b2c3d_abcdefghijklmnopqrstuvwxyz012345';

	/**
	 * The strings file, read here without the plugin's own loader.
	 *
	 * @var array<string,string>
	 */
	private $s;

	protected function set_up() {
		parent::set_up();
		$this->s                               = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/strings/en.json' ), true );
		$this->options[ Settings::OPTION_KEY ] = self::KEY;
		Functions\when( 'is_user_logged_in' )->justReturn( true );
	}

	private function html( array $flags = array(), int $user = 7 ): string {
		$model = Screen::build( $user, $flags, new Client( 'https://readinessindex.io', self::KEY ), false );
		ob_start();
		ScoreView::render( $model );
		return (string) ob_get_clean();
	}

	private function text( string $html ): string {
		return html_entity_decode( strip_tags( $html ), ENT_QUOTES );
	}

	private function fill( string $key, array $vars = array() ): string {
		$text = $this->s[ $key ];
		foreach ( $vars as $name => $value ) {
			$text = str_replace( '{' . $name . '}', (string) $value, $text );
		}
		return $text;
	}

	public function test_unconnected_shows_the_two_sentences_and_a_connect_button_and_calls_nothing(): void {
		unset( $this->options[ Settings::OPTION_KEY ] );
		$model = Screen::build( 7, array(), null, false );
		ob_start();
		ScoreView::render( $model );
		$html = (string) ob_get_clean();

		$this->assertSame( ViewModel::UNCONNECTED, $model->state );
		$this->assertStringContainsString( $this->fill( 'state.unconnected.title' ), $html );
		$this->assertStringContainsString( $this->fill( 'state.unconnected.body' ), $html );
		$this->assertStringContainsString( $this->fill( 'connect.button' ), $html );
		$this->assertStringContainsString( 'name="action" value="readiness_index_connect"', $html );
		$this->assertStringContainsString( 'nonce-readiness_index_connect', $html );
		$this->assertCount( 0, $this->requests );
	}

	public function test_unconnected_after_pressing_connect_says_it_is_waiting(): void {
		unset( $this->options[ Settings::OPTION_KEY ] );
		Connector::start_url( 7 );
		$model = Screen::build( 7, array(), null, false );
		ob_start();
		ScoreView::render( $model );
		$this->assertStringContainsString( $this->fill( 'connect.waiting' ), (string) ob_get_clean() );
	}

	public function test_preflight_findings_are_explained_and_do_not_remove_the_button(): void {
		unset( $this->options[ Settings::OPTION_KEY ] );
		$model            = Screen::build( 7, array(), null, false );
		$model->preflight = array( 'unreachable.staging_password', 'connect.site_unreachable' );
		ob_start();
		ScoreView::render( $model );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( $this->fill( 'unreachable.staging_password' ), $html );
		$this->assertStringContainsString( $this->fill( 'connect.site_unreachable' ), $html );
		$this->assertStringContainsString( 'readiness_index_connect', $html );
	}

	public function test_never_scanned(): void {
		$this->respond( Fixtures::response( 'site/never-scanned' ) );
		$html = $this->html();
		$text = $this->text( $html );
		$this->assertStringContainsString( $this->fill( 'state.never_scanned.title' ), $text );
		$this->assertStringContainsString( $this->fill( 'state.never_scanned.body' ), $text );
		$this->assertStringContainsString( $this->fill( 'score.scan_now' ), $text );
		$this->assertStringContainsString(
			$this->fill(
				'score.rescans.left',
				array(
					'left'  => 5,
					'total' => 5,
				)
			),
			$text
		);
		$this->assertStringContainsString( $this->fill( 'score.rescans.lifetime', array( 'total' => 5 ) ), $text );
		$this->assertStringNotContainsString( 'disabled', $html );
		$this->assertStringNotContainsString( $this->s['upgrade.monitoring_basic.label'], $text, 'The monitoring line waits for a score.' );
	}

	public function test_pending_and_running_disable_scan_and_mark_themselves_for_polling(): void {
		foreach ( array(
			'site/pending' => array( 'state.pending.title', 'state.pending.body' ),
			'site/running' => array( 'state.running.title', 'state.running.body_unknown' ),
		) as $fixture => $keys ) {
			$this->respond( Fixtures::response( $fixture ) );
			SiteCache::clear();
			$html = $this->html();
			foreach ( $keys as $key ) {
				$this->assertStringContainsString( $this->fill( $key ), $this->text( $html ), $fixture );
			}
			$this->assertStringContainsString( 'data-ri-poll="1"', $html, $fixture );
			$this->assertStringContainsString( 'disabled="disabled"', $html, $fixture );
			$this->assertStringContainsString( 'id="ri-state-title"', $html );
		}
	}

	public function test_done_shows_whole_integers_grade_verdict_dimensions_and_facts(): void {
		$this->respond( Fixtures::response( 'site/done-first-scan' ) );
		$html = $this->html();
		$text = $this->text( $html );
		$body = Fixtures::load( 'site/done-first-scan' )['response']['body'];

		$this->assertStringContainsString( $this->fill( 'state.done.title' ), $text );
		$this->assertStringContainsString( $this->fill( 'score.value', array( 'score' => $body['score']['value'] ) ), $text );
		$this->assertStringContainsString( $this->fill( 'score.grade', array( 'grade' => $body['score']['grade'] ) ), $text );
		$this->assertStringContainsString( $body['score']['verdict'], $text );
		$this->assertStringContainsString( $this->fill( 'score.change.first' ), $text );
		$this->assertStringContainsString( $this->fill( 'score.index', array( 'version' => '11.5' ) ), $text );
		$this->assertStringContainsString( $this->fill( 'score.pages_read', array( 'pages' => $body['scan']['pages_read'] ) ), $text );
		$this->assertStringContainsString( $this->fill( 'score.sample_note' ), $text );
		$this->assertStringContainsString( $this->fill( 'score.scan_again' ), $text );
		$this->assertStringContainsString( '<table', $html );
		$this->assertSame( 7, substr_count( $html, '<meter' ) );
		foreach ( $body['score']['dimensions'] as $dimension ) {
			$this->assertStringContainsString(
				$this->fill(
					'score.dimension_row',
					array(
						'name'     => $dimension['name'],
						'earned'   => $dimension['earned'],
						'possible' => $dimension['possible'],
					)
				),
				$text
			);
		}
		$this->assertDoesNotMatchRegularExpression( '/\d+\.\d+ out of|\d\.0\b/', $text );
	}

	public function test_the_change_sentences_up_down_same_not_comparable_and_first(): void {
		$base  = Fixtures::load( 'site/done-with-change' )['response']['body'];
		$cases = array(
			array(
				array(
					'comparable'   => true,
					'score_change' => 4,
				),
				'score.change.up',
				array( 'delta' => 4 ),
			),
			array(
				array(
					'comparable'   => true,
					'score_change' => -3,
				),
				'score.change.down',
				array( 'delta' => 3 ),
			),
			array(
				array(
					'comparable'   => true,
					'score_change' => 0,
				),
				'score.change.same',
				array(),
			),
			array( array( 'comparable' => false ), 'score.change.not_comparable', array() ),
			array( null, 'score.change.first', array() ),
		);
		foreach ( $cases as $case ) {
			$body                    = $base;
			$body['score']['change'] = $case[0];
			$this->respond(
				array(
					'response' => array( 'code' => 200 ),
					'headers'  => array(),
					'body'     => json_encode( $body ),
				)
			);
			SiteCache::clear();
			$this->assertStringContainsString( $this->fill( $case[1], $case[2] ), $this->text( $this->html() ), $case[1] );
		}
	}

	public function test_a_gated_score_says_so_beside_the_number(): void {
		$body                   = Fixtures::load( 'site/done-first-scan' )['response']['body'];
		$body['score']['gated'] = true;
		$this->respond(
			array(
				'response' => array( 'code' => 200 ),
				'headers'  => array(),
				'body'     => json_encode( $body ),
			)
		);
		$this->assertStringContainsString( $this->fill( 'score.gated' ), $this->text( $this->html() ) );
	}

	public function test_failed_shows_the_problem_the_hint_and_the_last_score_beneath(): void {
		$this->respond( Fixtures::response( 'site/failed' ) );
		$text    = $this->text( $this->html() );
		$problem = Fixtures::load( 'site/failed' )['response']['body']['scan']['problem'];
		$this->assertStringContainsString( $this->fill( 'state.failed.title' ), $text );
		$this->assertStringContainsString( $problem, $text );
		$this->assertStringContainsString( $this->fill( 'state.failed.hint' ), $text );

		$body          = Fixtures::load( 'site/failed' )['response']['body'];
		$body['score'] = Fixtures::load( 'site/done-first-scan' )['response']['body']['score'];
		$this->respond(
			array(
				'response' => array( 'code' => 200 ),
				'headers'  => array(),
				'body'     => json_encode( $body ),
			)
		);
		SiteCache::clear();
		$this->assertStringContainsString( $this->fill( 'score.value', array( 'score' => $body['score']['value'] ) ), $this->text( $this->html() ) );
	}

	public function test_quota_shows_its_title_the_error_sentence_and_the_upgrade_and_no_scan_button(): void {
		$this->respond( Fixtures::response( 'site/no-rescans-left' ) );
		$html = $this->html();
		$text = $this->text( $html );
		$this->assertStringContainsString( $this->fill( 'state.quota.title' ), $text );
		$this->assertStringContainsString( $this->fill( 'error.quota' ), $text );
		$this->assertStringContainsString( $this->fill( 'upgrade.upgrade_basic.label' ), $text );
		$this->assertStringContainsString( $this->fill( 'upgrade.upgrade_basic.body' ), $text );
		$this->assertStringNotContainsString( 'readiness_index_scan', $html );
		$this->assertStringContainsString( 'https://readinessindex.io/billing/checkout/basic?return=' . rawurlencode( 'https://example.com/wp-admin/admin.php?page=readiness-index' ), $html );
	}

	public function test_a_refused_scan_shows_the_servers_own_quota_message(): void {
		$this->respond( Fixtures::response( 'site/done-first-scan' ) );
		Flash::put( 7, 'scan', 'quota', array( 'message' => 'The server\'s own words.' ) );
		$this->assertStringContainsString( $this->fill( 'state.quota.body', array( 'message' => 'The server\'s own words.' ) ), $this->text( $this->html() ) );
	}

	public function test_unreachable_shows_the_sentence_for_its_reason_ending_in_nothing_was_used(): void {
		foreach ( array( 'no_answer', 'staging_password', 'maintenance_mode', 'robots_blocked' ) as $reason ) {
			$this->respond( Fixtures::response( 'site/never-scanned' ) );
			SiteCache::clear();
			Flash::put(
				7,
				'scan',
				'unreachable',
				array(
					'reason'  => $reason,
					'message' => 'x',
				)
			);
			$text = $this->text( $this->html() );
			$this->assertStringContainsString( $this->fill( 'state.unreachable.title' ), $text, $reason );
			$this->assertStringContainsString( $this->fill( 'unreachable.' . $reason ), $text, $reason );
			$this->assertStringContainsString( 'Nothing was used.', $text, $reason );
		}
	}

	public function test_update_required_is_shown_above_whatever_else_is_shown(): void {
		$this->respond( Fixtures::response( 'site/update-required' ) );
		$text = $this->text( $this->html() );
		$this->assertStringContainsString( $this->fill( 'state.update_required.title' ), $text );
		$this->assertStringContainsString( $this->fill( 'state.update_required.body' ), $text );
		$this->assertStringContainsString( $this->fill( 'state.never_scanned.title' ), $text, 'The notice never replaces the screen.' );
		$this->assertLessThan( strpos( $text, $this->fill( 'state.never_scanned.title' ) ), strpos( $text, $this->fill( 'state.update_required.title' ) ) );
	}

	public function test_revoked_shows_both_sentences_connect_again_and_keeps_the_last_score(): void {
		$this->respond( Fixtures::response( 'site/done-first-scan' ), Fixtures::response( 'errors/unauthenticated' ) );
		$this->html();
		$html = $this->html();
		$text = $this->text( $html );
		$this->assertStringContainsString( $this->fill( 'state.revoked.title' ), $text );
		$this->assertStringContainsString( $this->fill( 'state.revoked.body' ), $text );
		$this->assertStringContainsString( $this->fill( 'state.reconnect.title' ), $text );
		$this->assertStringContainsString( $this->fill( 'common.connect' ), $text );
		$this->assertStringContainsString( 'readiness_index_connect', $html );
		$this->assertStringContainsString( 'Grade', $text, 'The last score is still there.' );
	}

	public function test_disabled_shows_its_sentences_and_no_action(): void {
		$this->respond( Fixtures::response( 'errors/account-disabled' ) );
		$html = $this->html();
		$this->assertStringContainsString( $this->fill( 'state.disabled.title' ), $this->text( $html ) );
		$this->assertStringContainsString( $this->fill( 'state.disabled.body' ), $this->text( $html ) );
		$this->assertStringNotContainsString( '<form', $html );
	}

	public function test_offline_offers_try_again(): void {
		$this->respond( new WP_Error_Double() );
		$html = $this->html();
		$this->assertStringContainsString( $this->fill( 'state.offline.title' ), $this->text( $html ) );
		$this->assertStringContainsString( $this->fill( 'common.retry' ), $this->text( $html ) );
		$this->assertStringContainsString( 'readiness_index_refresh', $html );
	}

	public function test_rate_limited_shows_the_last_answer_and_waits_without_calling_again(): void {
		$first                    = Fixtures::response( 'site/done-first-scan' );
		$first['headers']['etag'] = '"e"';
		$this->respond( $first, Fixtures::response( 'errors/rate-limited' ) );
		$this->html();
		$html = $this->html();
		$this->assertStringContainsString( 'Grade', $this->text( $html ) );
		$this->assertStringNotContainsString( $this->fill( 'state.rate_limited.body' ), $this->text( $html ), 'The sentence is only for when there is nothing else to show.' );

		$this->requests = array();
		$this->html();
		$this->assertCount( 0, $this->requests, 'During the back-off no call is made.' );
	}

	public function test_rate_limited_with_nothing_cached_says_wait(): void {
		$this->respond( Fixtures::response( 'errors/rate-limited' ) );
		$this->assertStringContainsString( $this->fill( 'state.rate_limited.body' ), $this->text( $this->html() ) );
	}

	public function test_a_failure_on_our_side_keeps_the_last_answer_on_screen(): void {
		$this->respond( Fixtures::response( 'site/done-first-scan' ), Fixtures::response( 'errors/internal-error' ) );
		$this->html();
		$this->assertStringContainsString( 'Grade', $this->text( $this->html() ) );
	}

	public function test_a_failure_with_nothing_cached_shows_the_servers_sentence(): void {
		$this->respond( Fixtures::response( 'errors/internal-error' ) );
		$this->assertStringContainsString( $this->fill( 'error.internal_error' ), $this->text( $this->html() ) );
	}

	public function test_a_contract_failure_is_shown_as_our_side_failing_never_as_a_half_screen(): void {
		$body = Fixtures::load( 'site/done-first-scan' )['response']['body'];
		unset( $body['score']['dimensions'] );
		$this->respond(
			array(
				'response' => array( 'code' => 200 ),
				'headers'  => array(),
				'body'     => json_encode( $body ),
			)
		);
		$text = $this->text( $this->html() );
		$this->assertStringContainsString( $this->fill( 'error.internal_error' ), $text );
		$this->assertStringNotContainsString( 'Grade', $text );
	}

	public function test_the_flash_after_connecting_names_the_account_and_the_disconnect_hint(): void {
		$this->respond( Fixtures::response( 'site/never-scanned' ) );
		Settings::store_connection( 'Full report', '0123456789abcdef0123456789abcdef' );
		Flash::put( 7, 'connect', Connector::CONNECTED );
		$text = $this->text( $this->html() );
		$this->assertStringContainsString( $this->fill( 'connect.success', array( 'account' => 'Full report' ) ), $text );
		$this->assertStringContainsString( $this->fill( 'connect.disconnect_hint' ), $text );
	}

	public function test_connect_refusals_each_have_their_sentence(): void {
		foreach ( array(
			Connector::DENIED         => 'connect.denied',
			Connector::STATE_MISMATCH => 'connect.state_mismatch',
			Connector::INVALID        => 'error.internal_error',
		) as $code => $key ) {
			$this->respond( Fixtures::response( 'site/never-scanned' ) );
			Flash::put( 7, 'connect', $code );
			$this->assertStringContainsString( $this->fill( $key ), $this->text( $this->html() ), $code );
		}
	}

	public function test_ownership_not_verified_offers_the_check_and_verified_does_not(): void {
		$this->respond( Fixtures::response( 'site/never-scanned' ) );
		$html = $this->html();
		$this->assertStringContainsString( $this->fill( 'score.not_verified' ), $this->text( $html ) );
		$this->assertStringContainsString( $this->fill( 'verify.serving' ), $this->text( $html ) );
		$this->assertStringContainsString( 'readiness_index_verify', $html );

		$body                     = Fixtures::load( 'site/never-scanned' )['response']['body'];
		$body['site']['verified'] = true;
		$this->respond(
			array(
				'response' => array( 'code' => 200 ),
				'headers'  => array(),
				'body'     => json_encode( $body ),
			)
		);
		SiteCache::clear();
		$html = $this->html();
		$this->assertStringContainsString( $this->fill( 'score.verified' ), $this->text( $html ) );
		$this->assertStringNotContainsString( 'readiness_index_verify', $html );
	}

	public function test_verify_results_use_the_shared_sentences(): void {
		foreach ( array(
			'verified'  => 'verify.verified',
			'not_found' => 'verify.not_found',
			'error'     => 'verify.error',
		) as $code => $key ) {
			$this->respond( Fixtures::response( 'site/never-scanned' ) );
			Flash::put( 7, 'verify', $code );
			$this->assertStringContainsString( $this->fill( $key ), $this->text( $this->html() ), $code );
		}
	}

	public function test_disconnect_goes_through_a_confirmation_with_cancel_and_says_when_done(): void {
		$this->respond( Fixtures::response( 'site/never-scanned' ) );
		$html = $this->html( array( 'confirm_disconnect' => true ) );
		$this->assertStringContainsString( $this->fill( 'settings.disconnect.confirm' ), $this->text( $html ) );
		$this->assertStringContainsString( $this->fill( 'common.cancel' ), $this->text( $html ) );
		$this->assertStringContainsString( 'readiness_index_disconnect', $html );

		Settings::forget_connection();
		Flash::put( 7, 'disconnect', 'done' );
		$this->assertStringContainsString( $this->fill( 'settings.disconnect.done' ), $this->text( $this->html() ) );
	}

	public function test_a_constant_key_cannot_be_disconnected_from_the_screen(): void {
		$this->respond( Fixtures::response( 'site/never-scanned' ) );
		$model                  = Screen::build( 7, array(), new Client( 'https://readinessindex.io', self::KEY ), false );
		$model->key_is_constant = true;
		ob_start();
		ScoreView::render( $model );
		$this->assertStringNotContainsString( 'ri_confirm=disconnect', (string) ob_get_clean() );
	}

	public function test_returning_from_checkout_refetches_and_shows_the_servers_answer(): void {
		SiteCache::write( '"old"', Fixtures::load( 'site/no-rescans-left' )['response']['body'] );
		$this->respond( Fixtures::response( 'site/basic-plan' ) );
		$text = $this->text( $this->html( array( 'returned' => true ) ) );
		$this->assertStringContainsString( $this->fill( 'upgrade.returned' ), $text );
		$this->assertStringContainsString(
			$this->fill(
				'score.rescans.monthly',
				array(
					'left'  => 10,
					'total' => 10,
				)
			),
			$text
		);
		$this->assertStringNotContainsString( $this->fill( 'state.quota.title' ), $text );
		$this->assertCount( 1, $this->requests );
		$this->assertArrayNotHasKey( 'If-None-Match', $this->requests[0]['args']['headers'] );
	}

	public function test_a_paid_account_shows_a_pack_link_only_because_the_server_says_so(): void {
		$this->respond( Fixtures::response( 'site/basic-plan' ) );
		$html = $this->html();
		$this->assertStringContainsString( $this->fill( 'upgrade.pack' ), $this->text( $html ) );
		$this->assertStringContainsString( 'href="https://readinessindex.io/dashboard"', $html );
		$this->assertStringNotContainsString( $this->fill( 'score.rescans.lifetime', array( 'total' => 10 ) ), $this->text( $html ) );
		$this->assertStringNotContainsString( $this->fill( 'score.sample_note' ), $this->text( $html ) );

		$body                              = Fixtures::load( 'site/basic-plan' )['response']['body'];
		$body['allowance']['can_buy_pack'] = false;
		$this->respond(
			array(
				'response' => array( 'code' => 200 ),
				'headers'  => array(),
				'body'     => json_encode( $body ),
			)
		);
		SiteCache::clear();
		$this->assertStringNotContainsString( $this->fill( 'upgrade.pack' ), $this->text( $this->html() ) );
	}

	public function test_a_free_account_with_rescans_left_gets_one_monitoring_line_after_a_scan_and_never_a_pack(): void {
		$this->respond( Fixtures::response( 'site/done-first-scan' ) );
		$html = $this->html();
		$text = $this->text( $html );
		$this->assertSame( 1, substr_count( $text, $this->fill( 'upgrade.monitoring_basic.label' ) ) );
		$this->assertStringContainsString( $this->fill( 'upgrade.monitoring_basic.body' ), $text );
		$this->assertStringContainsString( $this->fill( 'checkout.subscribe' ), $text );
		$this->assertStringContainsString( 'readiness_index_dismiss', $html );
		$this->assertStringNotContainsString( $this->fill( 'upgrade.pack' ), $text );
	}

	public function test_a_dismissed_monitoring_line_stays_closed_for_that_user_and_the_scan_button_stays(): void {
		Functions\when( 'get_user_meta' )->justReturn( '1' );
		$this->respond( Fixtures::response( 'site/done-first-scan' ) );
		$html = $this->html();
		$this->assertStringNotContainsString( $this->fill( 'upgrade.monitoring_basic.label' ), $this->text( $html ) );
		$this->assertStringContainsString( 'readiness_index_scan', $html );
	}

	public function test_billing_off_shows_nothing_to_buy(): void {
		$this->respond( Fixtures::response( 'site/never-scanned-billing-off' ) );
		$html = $this->html();
		$text = $this->text( $html );
		$this->assertStringNotContainsString( 'Basic', $text );
		$this->assertStringNotContainsString( 'checkout', $html );
		$this->assertStringNotContainsString( $this->fill( 'checkout.subscribe' ), $text );
	}

	public function test_the_upgrade_still_leaves_the_scan_button_while_rescans_remain(): void {
		$this->respond( Fixtures::response( 'site/done-first-scan' ) );
		$this->assertStringContainsString( 'readiness_index_scan', $this->html() );
	}

	public function test_the_key_is_never_in_the_output_in_any_state(): void {
		foreach ( array( 'site/done-first-scan', 'site/running', 'errors/unauthenticated', 'errors/internal-error', 'site/no-rescans-left' ) as $fixture ) {
			$this->respond( Fixtures::response( $fixture ) );
			SiteCache::clear();
			$this->transients = array();
			$html             = $this->html();
			$this->assertStringNotContainsString( self::KEY, $html, $fixture );
			$this->assertStringNotContainsString( substr( self::KEY, 12 ), $html, $fixture );
		}
	}

	public function test_a_flash_is_shown_once(): void {
		$this->respond( Fixtures::response( 'site/never-scanned' ) );
		Flash::put( 7, 'disconnect', 'done' );
		$this->assertStringContainsString( $this->fill( 'settings.disconnect.done' ), $this->text( $this->html() ) );
		SiteCache::clear();
		$this->assertStringNotContainsString( $this->fill( 'settings.disconnect.done' ), $this->text( $this->html() ) );
	}
}
