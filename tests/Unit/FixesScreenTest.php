<?php
namespace ReadinessIndex\Tests;

use Brain\Monkey\Functions;
use ReadinessIndex\Admin\FixesModel;
use ReadinessIndex\Admin\FixesView;
use ReadinessIndex\Admin\Menu;
use ReadinessIndex\Admin\Screen;
use ReadinessIndex\Api\Client;
use ReadinessIndex\Api\Model\SiteResponse;
use ReadinessIndex\Changes\Log;
use ReadinessIndex\Fixers\Logic;
use ReadinessIndex\Fixers\Registry;
use ReadinessIndex\Fixers\SeoTools;
use ReadinessIndex\Settings;
use ReadinessIndex\Tests\Support\FakeFixer;
use ReadinessIndex\Tests\Support\FakeWpdb;
use ReadinessIndex\Tests\Support\Fixtures;
use ReadinessIndex\Tests\Support\Vectors;
use ReadinessIndex\Tests\Support\WpTestCase;

/**
 * The Fixes tab: the three row kinds the local registry decides, the points banner, every
 * state of the list, and the rule that a prompt quoting the scanned site is only ever text.
 */
final class FixesScreenTest extends WpTestCase {

	const KEY = 'air_0a1b2c3d_abcdefghijklmnopqrstuvwxyz012345';

	/**
	 * The strings, read without the plugin's loader.
	 *
	 * @var array<string,string>
	 */
	private $s;

	protected function set_up() {
		parent::set_up();
		$this->fixers_on();
		$this->s                               = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/strings/en.json' ), true );
		$this->options[ Settings::OPTION_KEY ] = self::KEY;
		$GLOBALS['wpdb']                       = new FakeWpdb(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The fake table.
		Functions\when( 'has_action' )->justReturn( false );
		SeoTools::use_active( array() );
	}

	protected function tear_down() {
		unset( $GLOBALS['wpdb'] );
		Registry::use_fixers( null );
		SeoTools::use_active( null );
		parent::tear_down();
	}

	/**
	 * A fake fixer for some Index tests.
	 *
	 * @param string   $id        Fixer id.
	 * @param string[] $checks    Tests it moves.
	 * @param bool     $reachable Whether it can apply here.
	 */
	private function fake( string $id, array $checks, bool $reachable = true ): FakeFixer {
		$vector              = Vectors::all()['llms-txt/pages-and-posts'][0];
		$fixer               = new FakeFixer( $id, Logic\LlmsTxt::class, $vector['given'] );
		$fixer->check_ids    = $checks;
		$fixer->is_reachable = $reachable;
		return $fixer;
	}

	private function html( string $fixture, array $query = array(), array $extra_responses = array() ): string {
		$this->respond( Fixtures::response( $fixture ), ...$extra_responses );
		$model = Screen::build( 7, array(), new Client( 'https://readinessindex.io', self::KEY ), false );
		ob_start();
		FixesView::render( $model, $query, null, new Client( 'https://readinessindex.io', self::KEY ) );
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

	public function test_rows_come_in_the_servers_order_with_points_and_roles(): void {
		$html = $this->html( 'site/done-with-change' );
		$text = $this->text( $html );

		$positions = array();
		foreach ( array( 'AIR-2.1', 'AIR-4.2', 'AIR-4.1' ) as $check ) {
			$positions[] = strpos( $html, 'id="fix-' . $check . '"' );
		}
		$sorted = $positions;
		sort( $sorted );
		$this->assertSame( $sorted, $positions, 'never re-sorted' );
		$this->assertStringContainsString( $this->fill( 'fixes.rank', array( 'rank' => 1 ) ), $text );
		$this->assertStringContainsString( $this->fill( 'fixes.points', array( 'points' => 3 ) ), $text );
		$this->assertStringContainsString( $this->fill( 'fixes.fixed_by', array( 'roles' => 'Front-end developer, Back-end developer' ) ), $text );
	}

	public function test_with_no_fixer_every_row_is_hand_it_on_and_the_banner_reaches_nothing(): void {
		Registry::use_fixers( array() );
		$html = $this->html( 'site/done-with-change' );
		$text = $this->text( $html );

		$this->assertSame( 3, substr_count( $html, 'ri-kind-hand-on' ) );
		$this->assertStringContainsString(
			$this->fill(
				'fixes.reachable',
				array(
					'reachable' => 0,
					'total'     => 5,
				)
			),
			$text
		);
		$this->assertStringContainsString( $this->fill( 'fixes.hand_on.explain' ), $text );
	}

	public function test_a_row_with_a_fixer_is_fix_it_here_and_its_points_are_counted(): void {
		Registry::use_fixers( array( $this->fake( 'dates', array( 'AIR-4.2' ) ) ) );
		$html = $this->html( 'site/done-with-change' );
		$text = $this->text( $html );

		$this->assertSame( 1, substr_count( $html, 'ri-kind-fix-here' ) );
		$this->assertSame( 2, substr_count( $html, 'ri-kind-hand-on' ) );
		$this->assertStringContainsString(
			$this->fill(
				'fixes.reachable',
				array(
					'reachable' => 1,
					'total'     => 5,
				)
			),
			$text
		);
		$this->assertStringContainsString( $this->fill( 'fixes.preview' ), $text );
		$this->assertStringContainsString( 'page=readiness-index-preview&amp;fixer=dates', $html );
	}

	public function test_points_are_whole_integers_and_sum_every_row_the_fixer_reaches(): void {
		Registry::use_fixers( array( $this->fake( 'dates', array( 'AIR-2.1', 'AIR-4.2', 'AIR-4.1' ) ) ) );
		$site  = SiteResponse::from_array( Fixtures::load( 'site/done-with-change' )['response']['body'] );
		$model = FixesModel::build( $site );

		$this->assertSame( 5, $model->reachable );
		$this->assertSame( 5, $model->total );
		$this->assertIsInt( $model->reachable );
		$this->assertDoesNotMatchRegularExpression( '/\d\.\d+ points/', $this->text( $this->html( 'site/done-with-change' ) ) );
	}

	public function test_a_fixer_that_cannot_reach_this_site_is_not_counted(): void {
		Registry::use_fixers( array( $this->fake( 'robots-txt', array( 'AIR-4.2' ), false ) ) );
		$site  = SiteResponse::from_array( Fixtures::load( 'site/done-with-change' )['response']['body'] );
		$model = FixesModel::build( $site );

		$this->assertSame( 0, $model->reachable );
		$this->assertSame( FixesModel::FIX_HERE, $model->rows[1]['kind'], 'still the plugin\'s row: the preview says why and offers the text to paste' );
	}

	public function test_with_yoast_active_the_graph_is_fix_it_here_and_with_another_tool_it_is_fix_it_with(): void {
		$site = SiteResponse::from_array( Fixtures::load( 'site/done-with-change' )['response']['body'] );

		SeoTools::use_active( array( 'yoast' ) );
		Registry::use_fixers( array( $this->fake( 'entity-graph', array( 'AIR-4.2' ), true ) ) );
		$with_yoast = FixesModel::build( $site );
		$this->assertSame( FixesModel::FIX_HERE, $with_yoast->rows[1]['kind'] );
		$this->assertSame( 1, $with_yoast->reachable );

		SeoTools::use_active( array( 'aioseo' ) );
		Registry::use_fixers( array( $this->fake( 'entity-graph', array( 'AIR-4.2' ), false ) ) );
		$with_aioseo = FixesModel::build( $site );
		$this->assertSame( FixesModel::FIX_WITH, $with_aioseo->rows[1]['kind'] );
		$this->assertSame( 'All in One SEO', $with_aioseo->rows[1]['tooling']['name'] );
		$this->assertSame( 0, $with_aioseo->reachable );

		Functions\when( 'admin_url' )->alias( fn( $p = '' ) => 'https://example.com/wp-admin/' . $p );
		$html = $this->html( 'site/done-with-change' );
		$text = $this->text( $html );
		$this->assertStringContainsString( 'Fix it with All in One SEO', $text );
		$this->assertStringContainsString( $this->fill( 'fixes.fix_with.open', array( 'tooling' => 'All in One SEO' ) ), $text );
		$this->assertStringContainsString( 'page=aioseo-search-appearance', $html );
	}

	public function test_the_kind_filter_is_kept_in_the_url_and_narrows_the_list(): void {
		Registry::use_fixers( array( $this->fake( 'dates', array( 'AIR-4.2' ) ) ) );
		$html = $this->html( 'site/done-with-change', array( 'ri_kind' => 'fix_here' ) );

		$this->assertSame( 1, substr_count( $html, 'class="ri-fix ' ) );
		$this->assertStringContainsString( 'ri_kind=fix_here', $html );
		$this->assertStringContainsString( 'aria-current="true"', $html );
	}

	public function test_a_perfect_score_and_a_site_never_scanned_say_so(): void {
		$body          = Fixtures::load( 'site/done-with-change' )['response']['body'];
		$body['fixes'] = array();
		$this->respond(
			array(
				'response' => array( 'code' => 200 ),
				'headers'  => array(),
				'body'     => wp_json_encode( $body ),
			)
		);
		$model = Screen::build( 7, array(), new Client( 'https://readinessindex.io', self::KEY ), false );
		ob_start();
		FixesView::render( $model );
		$this->assertStringContainsString( $this->fill( 'fixes.empty' ), $this->text( (string) ob_get_clean() ) );

		$text = $this->text( $this->html( 'site/never-scanned' ) );
		$this->assertStringContainsString( $this->fill( 'fixes.no_scan' ), $text );
	}

	public function test_a_check_the_index_has_retired_is_still_handed_on_not_dropped(): void {
		Registry::use_fixers( array() );
		$body                      = Fixtures::load( 'site/done-with-change' )['response']['body'];
		$body['fixes'][0]['check'] = 'AIR-99.9';
		$body['fixes'][0]['title'] = 'A test from a newer Index';
		$this->respond(
			array(
				'response' => array( 'code' => 200 ),
				'headers'  => array(),
				'body'     => wp_json_encode( $body ),
			)
		);
		$model = Screen::build( 7, array(), new Client( 'https://readinessindex.io', self::KEY ), false );
		ob_start();
		FixesView::render( $model );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'A test from a newer Index', $html );
		$this->assertStringContainsString( 'id="fix-AIR-99.9"', $html );
	}

	public function test_the_prompt_is_not_fetched_until_a_row_is_opened(): void {
		$this->html( 'site/done-with-change' );
		$this->assertCount( 1, $this->requests, 'only the one call to /site' );
		$this->assertStringNotContainsString( '/fixes/', $this->requests[0]['url'] );
	}

	public function test_an_opened_prompt_is_shown_as_escaped_text_never_as_html(): void {
		Registry::use_fixers( array() );
		$prompt                                  = Fixtures::load( 'fix-prompt/outstanding-check' )['response']['body'];
		$prompt['untrusted_site_text']['prompt'] = '<script>alert(1)</script><img src=x onerror=alert(2)> **bold**';
		$html                                    = $this->html(
			'site/done-with-change',
			array( 'ri_open' => 'AIR-2.1' ),
			array(
				array(
					'response' => array( 'code' => 200 ),
					'headers'  => array(),
					'body'     => wp_json_encode( $prompt ),
				),
			)
		);

		$this->assertStringContainsString( '/cms/v1/fixes/AIR-2.1', $this->requests[1]['url'] );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html );
		$this->assertStringContainsString( '<pre class="ri-prompt"', $html );
		$this->assertStringContainsString( $this->fill( 'fixes.hand_on.untrusted' ), $this->text( $html ) );
		$this->assertStringContainsString( $this->fill( 'fixes.hand_on.copy' ), $this->text( $html ) );
	}

	public function test_a_fix_it_here_row_offers_undo_only_while_the_fixer_is_applied(): void {
		$fixer = $this->fake( 'dates', array( 'AIR-4.2' ) );
		Registry::use_fixers( array( $fixer ) );
		$this->assertStringNotContainsString( 'readiness_index_undo', $this->html( 'site/done-with-change' ) );

		Log::insert(
			array(
				'fixer'  => 'dates',
				'checks' => array( 'AIR-4.2' ),
				'status' => 'applied',
				'prior'  => null,
				'new'    => array(),
			)
		);
		$this->responses = array();
		$this->requests  = array();
		$html            = $this->html( 'site/done-with-change' );
		$this->assertStringContainsString( 'name="action" value="readiness_index_undo"', $html );
		$this->assertStringContainsString( 'name="change" value="1"', $html );
		$this->assertStringContainsString( 'nonce-readiness_index_undo', $html );
	}

	public function test_copy_all_as_markdown_lists_every_row_and_escapes_markdown(): void {
		$body                      = Fixtures::load( 'site/done-with-change' )['response']['body'];
		$body['fixes'][0]['title'] = 'A **bold** [link](x) <b>title</b>';
		$this->respond(
			array(
				'response' => array( 'code' => 200 ),
				'headers'  => array(),
				'body'     => wp_json_encode( $body ),
			)
		);
		Registry::use_fixers( array() );
		$model = Screen::build( 7, array(), new Client( 'https://readinessindex.io', self::KEY ), false );
		ob_start();
		FixesView::render( $model );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="ri-markdown"', $html );
		$this->assertStringContainsString( '- **Fix 1** A bold link (x) b title /b (3 points)', html_entity_decode( $html, ENT_QUOTES ) );
		$this->assertStringNotContainsString( '<b>title</b>', $html );
	}

	public function test_the_check_id_shape_is_enforced_before_it_reaches_a_url(): void {
		$this->assertSame( 'AIR-1.2', Menu::check_id( 'AIR-1.2' ) );
		$this->assertSame( 'AIR-12.10', Menu::check_id( 'AIR-12.10' ) );
		foreach ( array( '', 'AIR-1', 'air-1.2', "AIR-1.2\n", 'AIR-1.2/../x', 'AIR-1.2?x=1', '../AIR-1.2' ) as $bad ) {
			$this->assertSame( '', Menu::check_id( $bad ), $bad );
		}
	}
}
