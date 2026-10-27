<?php
namespace ReadinessIndex\Tests;

use Brain\Monkey\Functions;
use ReadinessIndex\Admin\Actions;
use ReadinessIndex\Admin\Screen;
use ReadinessIndex\Admin\ScoreView;
use ReadinessIndex\Api\Client;
use ReadinessIndex\Api\Model\Category;
use ReadinessIndex\Api\SiteCache;
use ReadinessIndex\Settings;
use ReadinessIndex\Tests\Support\Fixtures;
use ReadinessIndex\Tests\Support\WpTestCase;

/**
 * The structure of the Score screen once there is a score: one heading outline, the five sections
 * in their order, one primary button, the weakest row, the description lists, the watch aside and
 * its per-user dismissal, and the bug that printed a link label as the account.
 */
final class ScoreLayoutTest extends WpTestCase {

	const KEY = 'air_0a1b2c3d_abcdefghijklmnopqrstuvwxyz012345';

	/**
	 * Per-user meta, by user then key.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private $meta = array();

	protected function set_up() {
		parent::set_up();
		$this->options[ Settings::OPTION_KEY ] = self::KEY;
		$this->meta                            = array();
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_user_meta' )->alias( fn( $user, $key ) => $this->meta[ $user ][ $key ] ?? '' );
		Functions\when( 'update_user_meta' )->alias(
			function ( $user, $key, $value ) {
				$this->meta[ $user ][ $key ] = $value;
				return true;
			}
		);
	}

	private function html( int $user = 7 ): string {
		SiteCache::clear();
		$model = Screen::build( $user, array(), new Client( 'https://readinessindex.io', self::KEY ), false );
		ob_start();
		ScoreView::render( $model );
		return (string) ob_get_clean();
	}

	private function text( string $html ): string {
		return trim( preg_replace( '/\s+/', ' ', html_entity_decode( strip_tags( $html ), ENT_QUOTES ) ) );
	}

	private function with( string $fixture, callable $edit ): void {
		$body = Fixtures::load( $fixture )['response']['body'];
		$edit( $body );
		$this->respond(
			array(
				'response' => array( 'code' => 200 ),
				'headers'  => array(),
				'body'     => json_encode( $body ),
			)
		);
	}

	public function test_the_outline_is_one_h1_then_one_h2_per_section_in_the_stated_order(): void {
		$this->respond( Fixtures::response( 'site/done-first-scan' ) );
		$html = $this->html();

		$this->assertSame( 1, substr_count( $html, '<h1' ) );
		preg_match_all( '#<h([1-6])[^>]*>(.*?)</h\1>#s', $html, $m );
		$this->assertSame( array( '1', '2', '2', '2', '2', '2' ), $m[1], 'No skipped level, one h2 each.' );
		$this->assertSame(
			array( 'Readiness Index', "Your site's Readiness score", 'Where the points are', 'Scan details', 'Watch this site every week', 'Connection' ),
			array_map(
				function ( $h ) {
					return html_entity_decode( strip_tags( $h ), ENT_QUOTES );
				},
				$m[2]
			)
		);
		$this->assertSame( 5, substr_count( $html, 'aria-labelledby="' ) );
		$this->assertSame( 4, substr_count( $html, '<section' ) );
		$this->assertSame( 1, substr_count( $html, '<aside' ) );
		// Each section is labelled by the heading it holds.
		preg_match_all( '#aria-labelledby="([^"]+)"#', $html, $labels );
		foreach ( $labels[1] as $id ) {
			$this->assertStringContainsString( 'id="' . $id . '"', $html );
		}
	}

	public function test_the_status_line_is_a_plain_status_above_the_heading_and_not_a_heading(): void {
		$this->respond( Fixtures::response( 'site/done-first-scan' ) );
		$html = $this->html();
		$this->assertSame( 1, preg_match( '#<p class="ri-status" role="status">Scan finished \x{00B7} October 9, 2026 12:00 pm</p><h2#u', $html ), $html );
		$this->assertStringNotContainsString( 'Scan finished</h2>', $html );
	}

	public function test_other_states_ride_in_the_status_line_when_there_is_a_score(): void {
		$running = function ( array &$body ): void {
			$body['scan']['state'] = 'running';
			$body['score']         = Fixtures::load( 'site/done-first-scan' )['response']['body']['score'];
		};
		$this->with( 'site/running', $running );
		$html = $this->html();
		$this->assertStringContainsString( 'role="status" id="ri-state" aria-live="polite"><span id="ri-state-title">Scanning your site</span>', $html );
		$this->assertStringContainsString( 'data-ri-poll="1"', $html );
		$this->assertStringContainsString( 'disabled="disabled"', $html, 'Scan again is disabled while a scan runs.' );

		$this->with(
			'site/failed',
			function ( array &$body ): void {
				$body['score'] = Fixtures::load( 'site/done-first-scan' )['response']['body']['score'];
			}
		);
		$html = $this->html();
		$this->assertStringContainsString( '<p class="ri-status" role="status">Scan failed. Try again.</p>', $html );
		$this->assertSame( 5, substr_count( $html, '<h2' ), 'Still five sections with a failed rescan.' );
	}

	public function test_the_score_and_the_grade_exist_as_text_with_the_number_alone_in_strong(): void {
		$this->respond( Fixtures::response( 'site/done-with-change' ) );
		$html = $this->html();
		$body = Fixtures::load( 'site/done-with-change' )['response']['body']['score'];
		$this->assertStringContainsString( '<strong class="ri-score-value">' . $body['value'] . '</strong><span class="ri-score-of"> out of 100</span>', $html );
		$this->assertStringContainsString( '<strong>Grade: ' . $body['grade'] . '.</strong> ' . $body['verdict'] . ' Up 6 since the last scan.</p>', $html );
	}

	public function test_exactly_one_primary_button_and_it_is_scan_again_beside_the_count(): void {
		$this->respond( Fixtures::response( 'site/done-first-scan' ) );
		$html = $this->html();
		$this->assertSame( 1, substr_count( $html, 'button-primary' ) );
		$this->assertSame( 1, preg_match( '#<div class="ri-actions"><form[^>]*>.*?<button type="submit" class="button button-primary">Scan again</button></form><span class="ri-rescans"><strong>5 of 5</strong> rescans left\. A free account includes 5 rescans for the life of the account\. They do not come back\.</span></div>#s', $html ), $html );
		$this->assertSame( 1, substr_count( $html, 'class="ri-rescans"' ), 'The count is one element.' );
	}

	public function test_the_run_together_rescan_text_is_gone(): void {
		$this->respond( Fixtures::response( 'site/done-first-scan' ) );
		$text = $this->text( $this->html() );
		$this->assertStringNotContainsString( 'rescans left A free', $text );
		$this->assertStringContainsString( '5 of 5 rescans left. A free account', $text );
	}

	public function test_zero_left_disables_the_button_and_says_so_in_one_sentence(): void {
		$this->with(
			'site/done-first-scan',
			function ( array &$body ): void {
				$body['allowance']['rescans_left'] = 0;
			}
		);
		$html = $this->html();
		$this->assertStringContainsString( '<button type="submit" class="button button-primary" disabled="disabled">Scan again</button>', $html );
		$this->assertStringContainsString( '<span class="ri-rescans">No rescans left. A paid plan includes more.</span>', $html );
		$this->assertStringNotContainsString( 'A free account includes', $html );
	}

	public function test_a_paid_plan_uses_the_servers_numbers_and_never_the_free_sentence(): void {
		$this->with(
			'site/done-first-scan',
			function ( array &$body ): void {
				$body['allowance']['lifetime']      = false;
				$body['allowance']['rescans_left']  = 7;
				$body['allowance']['rescans_total'] = 10;
				$body['allowance']['next_step']     = null;
			}
		);
		$html = $this->html();
		$this->assertStringContainsString( '<span class="ri-rescans"><strong>7 of 10</strong> rescans left this month.</span>', $html );
		$this->assertStringNotContainsString( 'A free account includes', $html );
		$this->assertStringNotContainsString( 'A free scan reads a sample', $html, 'The sampling sentence is for free plans only.' );
		$this->assertStringNotContainsString( '<aside', $html );

		$this->with(
			'site/done-first-scan',
			function ( array &$body ): void {
				$body['allowance']['lifetime']      = false;
				$body['allowance']['rescans_left']  = 0;
				$body['allowance']['rescans_total'] = 10;
				$body['allowance']['next_step']     = null;
			}
		);
		$this->assertStringContainsString( 'No rescans left this month.', $this->html() );
	}

	public function test_the_breakdown_is_a_real_table_in_the_servers_order_with_sentence_case_names(): void {
		$this->respond( Fixtures::response( 'site/done-first-scan' ) );
		$html = $this->html();
		$this->assertSame( 1, preg_match( '#<thead><tr><th scope="col">Category</th><th scope="col" class="ri-num">Score</th><th scope="col" class="ri-meter"><span class="screen-reader-text">Progress</span></th></tr></thead>#', $html ), $html );
		preg_match_all( '#<tr><th scope="row">(.*?)</th>#', $html, $rows );
		$this->assertSame(
			array( '<strong>Reach</strong> <span class="ri-weakest">(weakest)</span>', 'Read', 'Understand', 'Act' ),
			$rows[1],
			'The server order is kept.'
		);
		$this->assertStringContainsString( '<td class="ri-num"><strong>19</strong> / 25</td>', $html );
		$this->assertStringContainsString( '<meter min="0" max="25" value="19" aria-label="Reach"></meter>', $html );
		$this->assertSame( 4, substr_count( $html, '<meter ' ) );
		$this->assertSame( 4, substr_count( $html, '></meter>' ) );
		$this->assertSame( 4, preg_match_all( '#<meter [^>]*aria-label="[^"]+"#', $html ), 'Every meter is named.' );
		$this->assertStringContainsString( 'class="widefat striped ri-categories"', $html );
	}

	public function test_the_weakest_row_is_bold_with_the_word_for_it_and_ties_are_all_flagged(): void {
		$this->respond( Fixtures::response( 'site/done-first-scan' ) );
		$html = $this->html();
		$this->assertSame( 1, substr_count( $html, '(weakest)' ) );
		$this->assertStringContainsString( '<strong>Reach</strong> <span class="ri-weakest">(weakest)</span>', $html );

		$this->with(
			'site/done-first-scan',
			function ( array &$body ): void {
				$body['score']['dimensions'][2]['earned'] = 19; // Understand, 19/25, ties Reach's 19/25 as the lowest share.
			}
		);
		$html = $this->html();
		$this->assertSame( 2, substr_count( $html, '(weakest)' ) );
		$this->assertStringContainsString( '<strong>Reach</strong> <span class="ri-weakest">(weakest)</span>', $html );
		$this->assertStringContainsString( '<strong>Understand</strong> <span class="ri-weakest">(weakest)</span>', $html );
	}

	public function test_nothing_is_weakest_when_every_row_has_the_same_share(): void {
		$rows = array(
			Category::from_array(
				array(
					'id'       => 1,
					'name'     => 'A',
					'earned'   => 15,
					'possible' => 15,
				),
				'd'
			),
			Category::from_array(
				array(
					'id'       => 2,
					'name'     => 'B',
					'earned'   => 8,
					'possible' => 8,
				),
				'd'
			),
		);
		$this->assertSame( array(), ScoreView::weakest( $rows ) );
		$this->assertSame( array(), ScoreView::weakest( array() ) );
	}

	public function test_sentence_case_lowers_title_case_words_and_leaves_acronyms(): void {
		$this->assertSame( 'Primary indicators', ScoreView::sentence_case( 'Primary Indicators' ) );
		$this->assertSame( 'Structured data and the entity graph', ScoreView::sentence_case( 'Structured data and the entity graph' ) );
		$this->assertSame( 'Crawl, index and JSON-LD hygiene', ScoreView::sentence_case( 'Crawl, Index and JSON-LD Hygiene' ) );
		$this->assertSame( 'AI interfaces', ScoreView::sentence_case( 'AI Interfaces' ) );
	}

	public function test_scan_details_is_a_description_list_and_the_sampling_sentence_sits_with_pages_read(): void {
		$this->respond( Fixtures::response( 'site/done-first-scan' ) );
		$html = $this->html();
		$this->assertSame( 1, preg_match( '#<h2 id="ri-details-title">Scan details</h2><dl class="ri-dl"><dt>Scanned</dt><dd>October 9, 2026 12:00 pm</dd><dt>Pages read</dt><dd>187 pages\. A free scan reads a sample of your pages, not all of them\. A paid plan reads more\.</dd><dt>Index version</dt><dd>12\.1</dd></dl>#', $html ), $html );
	}

	public function test_the_watch_aside_offers_the_server_step_as_a_secondary_button_with_return_and_not_now(): void {
		$this->respond( Fixtures::response( 'site/done-first-scan' ) );
		$html = $this->html();
		$this->assertSame( 1, preg_match( '#<aside class="card ri-card ri-watch ri-upgrade-monitoring_basic" aria-labelledby="ri-watch-title"><h2 id="ri-watch-title">Watch this site every week</h2><p>Basic scans this site every week and emails you when the score drops\.</p><div class="ri-actions"><a class="button" href="https://readinessindex\.io/billing/checkout/basic\?return=[^"]+">Watch this site every week</a>.*?<button type="submit" class="button-link">Not now</button>#s', $html ), $html );
		$this->assertStringNotContainsString( 'Subscribe to Basic', $html );
		$this->assertStringContainsString( 'name="action" value="readiness_index_dismiss"', $html );
		$this->assertStringContainsString( 'nonce-readiness_index_dismiss', $html );
	}

	public function test_a_step_that_is_not_about_weekly_watching_uses_the_servers_label_for_the_button(): void {
		$this->respond( Fixtures::response( 'site/no-rescans-left' ) );
		$html  = $this->html();
		$label = Fixtures::load( 'site/no-rescans-left' )['response']['body']['allowance']['next_step']['label'];
		$this->assertStringContainsString( '<aside', $html );
		$this->assertStringContainsString( '">' . $label . '</a>', $html );
		$this->assertStringNotContainsString( 'Not now', $html, 'The upgrade is never dismissed.' );
	}

	public function test_no_aside_when_the_server_names_no_step_which_is_already_subscribed_or_billing_off(): void {
		$this->respond( Fixtures::response( 'site/basic-plan' ) );
		$this->assertStringNotContainsString( '<aside', $this->html() );
		$this->with(
			'site/done-first-scan',
			function ( array &$body ): void {
				$body['allowance']['next_step'] = null;
			}
		);
		$this->assertStringNotContainsString( '<aside', $this->html() );
	}

	public function test_not_now_persists_for_that_user_only(): void {
		$this->respond( Fixtures::response( 'site/done-first-scan' ) );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'wp_safe_redirect' )->alias(
			function () {
				throw new \RuntimeException( 'redirected' );
			}
		);
		$this->assertStringContainsString( '<aside', $this->html( 7 ) );
		try {
			Actions::handle_dismiss();
			$this->fail( 'The handler returns to the screen.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		}
		$this->assertSame( '1', $this->meta[7]['readiness_index_dismissed_monitoring'] );
		$this->assertStringNotContainsString( '<aside', $this->html( 7 ), 'Gone for the user who closed it.' );
		$this->assertStringContainsString( '<aside', $this->html( 8 ), 'Still there for another user.' );
		$this->assertStringContainsString( 'readiness_index_scan', $this->html( 7 ), 'Scan again stays.' );
	}

	public function test_connected_as_is_the_host_the_key_is_bound_to_and_never_the_plan_label(): void {
		$this->respond( Fixtures::response( 'site/done-first-scan' ) );
		Settings::store_connection( 'Full report', '0123456789abcdef0123456789abcdef' );
		$html = $this->html();
		$this->assertStringContainsString( '<dt>Connected as</dt><dd>example.com</dd>', $html );
		$this->assertStringNotContainsString( 'Connected as Full report', $this->text( $html ) );
		$this->assertStringNotContainsString( '<dd>Full report</dd>', $html );
	}

	public function test_the_connection_section_says_verified_or_not_and_disconnect_is_a_quiet_button(): void {
		$this->with(
			'site/done-first-scan',
			function ( array &$body ): void {
				$body['site']['verified'] = true;
			}
		);
		$html = $this->html();
		$this->assertStringContainsString( '<dt>Ownership</dt><dd>Verified. This site serves /.well-known/readinessindex-verify so Readiness Index can confirm it&#039;s yours.</dd>', $html );
		$this->assertStringNotContainsString( 'readiness_index_verify', $html );
		$this->assertStringContainsString( '<button type="submit" class="button ri-danger">Disconnect</button>', $html );
		$this->assertSame( 1, substr_count( $html, 'button-primary' ) );
		$this->assertStringNotContainsString( 'button-primary">Disconnect', $html );
		$this->assertStringContainsString( 'name="ri_confirm" value="disconnect"', $html );
		$this->assertStringContainsString( 'name="_wpnonce" value="nonce-readiness_index_confirm_disconnect"', $html );
		$this->assertStringNotContainsString( '_wp_http_referer', $html );
		// Disconnect comes after the data, last in the last section.
		$this->assertGreaterThan( strpos( $html, 'Connected as' ), strpos( $html, '>Disconnect<' ) );
	}

	public function test_an_unverified_site_keeps_its_check_action_inside_the_connection_section(): void {
		$this->respond( Fixtures::response( 'site/done-first-scan' ) );
		$html = $this->html();
		$this->assertStringContainsString( '<dd>Not verified yet.', $html );
		$this->assertSame( 1, preg_match( '#<h2 id="ri-connection-title">.*readiness_index_verify.*Disconnect#s', $html ), $html );
	}

	public function test_a_key_from_wp_config_offers_no_disconnect(): void {
		$this->respond( Fixtures::response( 'site/done-first-scan' ) );
		$model                  = Screen::build( 7, array(), new Client( 'https://readinessindex.io', self::KEY ), false );
		$model->key_is_constant = true;
		ob_start();
		ScoreView::render( $model );
		$html = (string) ob_get_clean();
		$this->assertStringNotContainsString( 'ri_confirm', $html );
		$this->assertStringContainsString( 'Connection', $html );
	}

	public function test_the_full_report_link_is_a_plain_link_under_the_actions_row(): void {
		$this->respond( Fixtures::response( 'site/done-first-scan' ) );
		$html = $this->html();
		$this->assertGreaterThan( strpos( $html, 'class="ri-actions"' ), strpos( $html, 'class="ri-report"' ) );
		$this->assertLessThan( strpos( $html, 'id="ri-breakdown-title"' ), strpos( $html, 'class="ri-report"' ) );
	}

	public function test_the_pack_link_follows_the_servers_say_so_on_a_screen_with_a_score(): void {
		$this->with(
			'site/basic-plan',
			function ( array &$body ): void {
				$body['score'] = Fixtures::load( 'site/done-first-scan' )['response']['body']['score'];
				$body['scan']  = Fixtures::load( 'site/done-first-scan' )['response']['body']['scan'];
			}
		);
		$html = $this->html();
		$this->assertStringContainsString( '<p class="ri-pack"><a href="https://readinessindex.io/dashboard">Buy more rescans</a></p>', $html );
		$this->with(
			'site/basic-plan',
			function ( array &$body ): void {
				$body['score']                     = Fixtures::load( 'site/done-first-scan' )['response']['body']['score'];
				$body['scan']                      = Fixtures::load( 'site/done-first-scan' )['response']['body']['scan'];
				$body['allowance']['can_buy_pack'] = false;
			}
		);
		$this->assertStringNotContainsString( 'Buy more rescans', $this->html() );
	}

	public function test_hostile_data_is_escaped_in_every_new_place(): void {
		$bad = '<img src=x onerror=alert(1)>"\'';
		$this->with(
			'site/done-first-scan',
			function ( array &$body ) use ( $bad ): void {
				$body['site']['host']                   = $bad;
				$body['score']['grade']                 = $bad;
				$body['score']['verdict']               = $bad;
				$body['score']['dimensions'][0]['name'] = $bad;
				$body['index']['version']               = $bad;
			}
		);
		$html = $this->html();
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringNotContainsString( 'onerror=alert(1)>', $html );
		$this->assertStringContainsString( '&lt;img src=x onerror=alert(1)&gt;', $html );
	}

	public function test_no_price_and_no_inline_color_reach_the_markup(): void {
		$this->respond( Fixtures::response( 'site/done-first-scan' ) );
		$html = $this->html();
		$this->assertDoesNotMatchRegularExpression( '/[$€£]\s*\d|style="[^"]*(color|font)/iu', $html );
	}

	public function test_the_stylesheet_carries_only_layout_and_the_one_danger_color(): void {
		$css = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/admin.css' );
		$this->assertStringNotContainsString( 'font-family: system', $css );
		preg_match_all( '/(?<![\w-])#[0-9a-f]{3,6}\b/i', $css, $m );
		$this->assertSame( array( '#b32d2e', '#b32d2e' ), $m[0], 'The Score screen sets no color but the danger text.' );
	}
}
