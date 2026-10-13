<?php
namespace ReadinessIndex\Tests;

use Brain\Monkey\Functions;
use ReadinessIndex\Admin\ChangesView;
use ReadinessIndex\Admin\Diff;
use ReadinessIndex\Admin\Outcome;
use ReadinessIndex\Admin\PreviewView;
use ReadinessIndex\Admin\Screen;
use ReadinessIndex\Changes\Log;
use ReadinessIndex\Fixers\Logic;
use ReadinessIndex\Fixers\Registry;
use ReadinessIndex\Fixers\Runner;
use ReadinessIndex\Settings;
use ReadinessIndex\Tests\Support\FakeFixer;
use ReadinessIndex\Tests\Support\FakeWpdb;
use ReadinessIndex\Tests\Support\Vectors;
use ReadinessIndex\Tests\Support\WpTestCase;

/**
 * The Changes tab, the preview page and the sentences a result becomes.
 */
final class ChangesAndPreviewTest extends WpTestCase {

	protected function set_up() {
		parent::set_up();
		$GLOBALS['wpdb'] = new FakeWpdb(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The fake table.
		Functions\when( 'has_action' )->justReturn( false );
		Functions\when( 'get_userdata' )->alias(
			function ( $id ) {
				return 7 === $id ? (object) array( 'display_name' => 'Pat Admin' ) : false;
			}
		);
	}

	protected function tear_down() {
		unset( $GLOBALS['wpdb'] );
		Registry::use_fixers( null );
		parent::tear_down();
	}

	/**
	 * A fake fixer from a vector.
	 *
	 * @param string $name Vector key.
	 */
	private function fixer( string $name ): FakeFixer {
		$vector = Vectors::all()[ $name ][0];
		$map    = array(
			'llms-txt'   => Logic\LlmsTxt::class,
			'robots-txt' => Logic\RobotsTxt::class,
		);
		$fixer  = new FakeFixer( $vector['fixer'], $map[ $vector['fixer'] ], $vector['given'] );
		Registry::use_fixers( array( $fixer ) );
		return $fixer;
	}

	private function changes_html( ?array $result = null ): string {
		ob_start();
		ChangesView::render( Screen::light( 7 ), $result );
		return (string) ob_get_clean();
	}

	private function preview_html( FakeFixer $fixer, array $choices ): string {
		ob_start();
		PreviewView::render( Screen::light( 7 ), $fixer, $choices );
		return (string) ob_get_clean();
	}

	public function test_changes_empty_state_makes_no_api_call_and_works_unconnected(): void {
		$html = $this->changes_html();
		$this->assertStringContainsString( Strings_get( 'changes.empty' ), $html );
		$this->assertCount( 0, $this->requests );
		$this->assertStringContainsString( 'name="remove_on_uninstall"', $html );
		$this->assertStringContainsString( 'nonce-readiness_index_save_settings', $html );
		$this->assertStringNotContainsString( "checked='checked'", $html, 'off unless the owner turned it on' );
	}

	public function test_the_remove_on_delete_box_reflects_the_setting(): void {
		$this->options[ Settings::OPTION_REMOVE ] = '1';
		$this->assertStringContainsString( "checked='checked'", $this->changes_html() );
	}

	public function test_every_change_is_listed_newest_first_with_what_when_who_and_undo_where_possible(): void {
		$fixer   = $this->fixer( 'llms-txt/pages-and-posts' );
		$choices = array();
		Runner::apply( $fixer, $choices, Runner::preview( $fixer, $choices )['hash'], 7 );
		Runner::undo( 1, 7 );
		Runner::apply( $fixer, $choices, Runner::preview( $fixer, $choices )['hash'], 7 );

		$html = $this->changes_html();

		$this->assertSame( 3, substr_count( $html, '<tr class="ri-change' ) );
		$this->assertLessThan( strpos( $html, 'ri-change-apply-undone' ), strpos( $html, 'ri-change-apply-applied' ), 'newest first' );
		$this->assertStringContainsString( 'Applied Fake fixer title', $html );
		$this->assertStringContainsString( 'Undid Fake fixer title', $html );
		$this->assertStringContainsString( 'Pat Admin', $html );
		$this->assertSame( 1, substr_count( $html, 'name="action" value="readiness_index_undo"' ), 'Undo only on the applied row that nothing replaced' );
		$this->assertStringContainsString( 'name="change" value="3"', $html );
		foreach ( array( 'what', 'when', 'who', 'action' ) as $column ) {
			$this->assertStringContainsString( '>' . Strings_get( 'changes.col.' . $column ) . '</th>', $html );
		}
	}

	public function test_a_superseded_row_has_no_undo_and_says_why(): void {
		$fixer = $this->fixer( 'robots-txt/wordpress-default-add-signals-and-policy' );
		$first = Vectors::all()['robots-txt/wordpress-default-add-signals-and-policy'][0]['given']['choices'];
		Runner::apply( $fixer, $first, Runner::preview( $fixer, $first )['hash'], 7 );
		$second           = $first;
		$second['policy'] = false;
		Runner::apply( $fixer, $second, Runner::preview( $fixer, $second )['hash'], 7 );

		$html = $this->changes_html();
		$this->assertSame( 1, substr_count( $html, 'name="action" value="readiness_index_undo"' ) );
		$this->assertStringContainsString( 'ri-change-apply-superseded', $html );
		$this->assertStringContainsString( Strings_get( 'changes.superseded' ), $html );
	}

	public function test_after_an_undo_the_screen_says_so(): void {
		$html = $this->changes_html(
			array(
				'outcome' => 'undone',
				'fixer'   => 'llms-txt',
			)
		);
		$this->assertStringContainsString( Strings_get( 'fixes.undone' ), $html );
	}

	public function test_the_preview_shows_before_and_after_and_changes_nothing(): void {
		$fixer = $this->fixer( 'llms-txt/pages-and-posts' );
		$html  = $this->preview_html( $fixer, array() );

		$this->assertStringContainsString( '>' . Strings_get( 'fixes.before' ) . '</h4>', $html );
		$this->assertStringContainsString( '>' . Strings_get( 'fixes.after' ) . '</h4>', $html );
		$this->assertStringContainsString( '<pre class="ri-pre">', $html );
		$this->assertStringContainsString( 'ri-add', $html );
		$this->assertNotContains( 'apply', $fixer->calls );
		$this->assertSame( 0, Log::count() );
		$this->assertNull( $fixer->state() );
	}

	public function test_the_apply_form_carries_the_hash_of_this_preview_a_nonce_and_the_fixer(): void {
		$fixer = $this->fixer( 'llms-txt/pages-and-posts' );
		$hash  = Runner::preview( $fixer, array() )['hash'];
		$html  = $this->preview_html( $fixer, array() );

		$this->assertStringContainsString( '<form method="post" class="ri-apply"', $html );
		$this->assertStringContainsString( 'name="action" value="readiness_index_apply"', $html );
		$this->assertStringContainsString( 'name="ri_hash" value="' . $hash . '"', $html );
		$this->assertStringContainsString( 'name="fixer" value="llms-txt"', $html );
		$this->assertStringContainsString( 'nonce-readiness_index_apply', $html );
		$this->assertDoesNotMatchRegularExpression( '/<button type="submit" class="button button-primary" disabled/', $html );
		$this->assertStringContainsString( '>' . Strings_get( 'common.cancel' ) . '</a>', $html );
	}

	public function test_a_conflict_offers_the_text_to_paste_and_disables_apply(): void {
		$fixer   = $this->fixer( 'robots-txt/physical-file-wins' );
		$choices = Vectors::all()['robots-txt/physical-file-wins'][0]['given']['choices'];
		$html    = $this->preview_html( $fixer, $choices );

		$this->assertStringContainsString( htmlspecialchars( Strings_get( 'fixes.conflict.physical_file' ), ENT_QUOTES ), $html );
		$this->assertStringContainsString( '>' . Strings_get( 'fixes.paste' ) . '</h3>', $html );
		$this->assertStringContainsString( '# BEGIN Readiness Index', $html );
		$this->assertMatchesRegularExpression( '/<button type="submit" class="button button-primary" disabled="disabled">/', $html );
	}

	public function test_an_unchanged_preview_says_nothing_to_change_and_cannot_be_applied(): void {
		$fixer = $this->fixer( 'robots-txt/nothing-chosen-nothing-changes' );
		$html  = $this->preview_html( $fixer, Vectors::all()['robots-txt/nothing-chosen-nothing-changes'][0]['given']['choices'] );
		$this->assertStringContainsString( htmlspecialchars( Strings_get( 'fixes.nothing_to_change' ), ENT_QUOTES ), $html );
		$this->assertMatchesRegularExpression( '/class="button button-primary" disabled="disabled"/', $html );
	}

	public function test_text_in_a_preview_is_escaped(): void {
		$fixer = $this->fixer( 'llms-txt/pages-and-posts' );
		$fixer->facts['content']['sections'][0]['items'][0]['title'] = '<script>alert(1)</script>';
		$html = $this->preview_html( $fixer, array() );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	public function test_the_diff_marks_only_the_lines_that_differ(): void {
		$diff = Diff::lines( "a\nb\nc\n", "a\nx\nc\nd\n" );
		$this->assertSame( array( array( '=', 'a' ), array( '-', 'b' ), array( '=', 'c' ) ), $diff['before'] );
		$this->assertSame( array( array( '=', 'a' ), array( '+', 'x' ), array( '=', 'c' ), array( '+', 'd' ) ), $diff['after'] );
		$this->assertSame( array(), Diff::lines( '', '' )['before'] );
	}

	public function test_every_outcome_becomes_a_sentence_from_the_strings(): void {
		$cases = array(
			array(
				array(
					'outcome' => 'applied',
					'live'    => true,
					'cache'   => null,
				),
				'fixes.live.yes',
			),
			array(
				array(
					'outcome' => 'applied',
					'live'    => false,
					'cache'   => 'wp_rocket',
				),
				'WP Rocket',
			),
			array(
				array(
					'outcome' => 'applied',
					'live'    => null,
					'cache'   => null,
				),
				'fixes.live.unknown',
			),
			array(
				array(
					'outcome' => 'unchanged',
					'reason'  => 'already_present',
				),
				'fixes.already_present',
			),
			array(
				array(
					'outcome' => 'unchanged',
					'reason'  => 'nothing_to_change',
				),
				'fixes.nothing_to_change',
			),
			array( array( 'outcome' => 'stale' ), 'fixes.stale' ),
			array( array( 'outcome' => 'failed' ), 'fixes.failed' ),
			array(
				array(
					'outcome' => 'refused',
					'reasons' => array( 'physical_file' ),
				),
				'fixes.conflict.physical_file',
			),
			array(
				array(
					'outcome' => 'refused',
					'reasons' => array( 'tooling_owns_it' ),
					'tooling' => 'yoast',
				),
				'Yoast SEO controls this setting',
			),
			array(
				array(
					'outcome' => 'refused',
					'reason'  => 'superseded',
				),
				'fixes.undo.superseded',
			),
			array(
				array(
					'outcome' => 'refused',
					'reason'  => 'already_undone',
				),
				'fixes.undo.already',
			),
			array(
				array(
					'outcome' => 'refused',
					'reasons' => array( 'search_engines_discouraged', 'subdirectory_site', 'both_seo_tools' ),
				),
				'fixes.reason.both_seo_tools',
			),
		);
		foreach ( $cases as $case ) {
			$notice = Outcome::notice( $case[0] );
			$want   = \ReadinessIndex\Strings::has( $case[1] ) ? Strings_get( $case[1] ) : $case[1];
			$this->assertStringContainsString( $want, $notice['title'] . ' ' . $notice['body'], wp_json_encode( $case[0] ) );
		}
		$this->assertSame(
			'warning',
			Outcome::notice(
				array(
					'outcome' => 'applied',
					'live'    => false,
					'cache'   => null,
				)
			)['type'],
			'an applied change that is not live is a warning'
		);
	}
}

/**
 * A shared sentence with nothing filled in.
 *
 * @param string $key Key.
 */
function Strings_get( string $key ): string { // phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed,Generic.Files.OneObjectStructurePerFile.MultipleFound,WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid,PEAR.NamingConventions.ValidFunctionName.NotCamelCaps -- Test helper.
	return \ReadinessIndex\Strings::get( $key );
}
