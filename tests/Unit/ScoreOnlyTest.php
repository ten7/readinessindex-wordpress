<?php
namespace ReadinessIndex\Tests;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use ReadinessIndex\Admin\Menu;
use ReadinessIndex\Admin\Tabs;
use ReadinessIndex\Features;
use ReadinessIndex\Fixers\State;
use ReadinessIndex\Plugin;
use ReadinessIndex\Tests\Support\WpTestCase;

/**
 * Release 0.5.0 ships the Score alone. With the one switch at its default nothing of the
 * fixer side exists: no screen, no tab, no action, no filter, no stored option. The same code
 * is exercised with the switch on in the fixer tests, so it stays verified while it is off.
 */
final class ScoreOnlyTest extends WpTestCase {

	/**
	 * The hooks a fixer or the Fixes and Changes screens would add, by kind.
	 *
	 * @return array<string,array<int,string>>
	 */
	private function fixer_hooks(): array {
		return array(
			'actions' => array(
				'admin_post_readiness_index_apply',
				'admin_post_readiness_index_undo',
				'admin_post_readiness_index_save_settings',
				'admin_post_readiness_index_diagnose',
				'wp_ajax_readiness_index_fix_prompt',
				'after_plugin_row_readiness-index/readiness-index.php',
				'post_updated',
				'wp_head',
				'template_redirect',
				'shutdown',
			),
			'filters' => array(
				'robots_txt',
				'wp_robots',
				'wp_sitemaps_posts_entry',
				'wpseo_sitemap_entry',
				'rank_math/sitemap/entry',
				'wpseo_schema_webpage',
				'rank_math/json_ld',
				'query_vars',
				'admin_title',
			),
		);
	}

	public function test_the_fixers_are_off_by_default_and_only_a_strict_true_turns_them_on(): void {
		$this->assertFalse( Features::fixers() );
	}

	public function test_a_filter_that_returns_something_other_than_true_is_not_a_yes(): void {
		Filters\expectApplied( 'readiness_index_fixers' )->andReturn( '1' );
		$this->assertFalse( Features::fixers() );
	}

	public function test_the_filter_turns_them_on(): void {
		$this->fixers_on();
		$this->assertTrue( Features::fixers() );
	}

	public function test_off_the_menu_has_the_score_page_and_nothing_else(): void {
		Functions\when( 'add_menu_page' )->justReturn( 'toplevel_page_readiness-index' );
		Functions\expect( 'add_submenu_page' )->once()->with( 'readiness-index', \Mockery::any(), \Mockery::any(), 'manage_options', 'readiness-index', \Mockery::any() );
		Menu::add_menu();
		$this->addToAssertionCount( 1 );
	}

	public function test_on_the_menu_also_has_fixes_changes_and_the_hidden_preview(): void {
		$this->fixers_on();
		Functions\when( 'add_menu_page' )->justReturn( 'toplevel_page_readiness-index' );
		$slugs = array();
		Functions\when( 'add_submenu_page' )->alias(
			function ( $parent, $title, $label, $cap, $slug ) use ( &$slugs ) {
				$slugs[] = $slug;
				return '';
			}
		);
		Menu::add_menu();
		$this->assertSame( array( 'readiness-index', 'readiness-index-fixes', 'readiness-index-changes', 'readiness-index-preview' ), $slugs );
	}

	public function test_off_there_are_no_tabs(): void {
		ob_start();
		Tabs::render( 'readiness-index' );
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_on_there_are_three_tabs(): void {
		$this->fixers_on();
		Functions\when( 'esc_url' )->alias( fn( $url ) => (string) $url );
		ob_start();
		Tabs::render( 'readiness-index' );
		$html = (string) ob_get_clean();
		$this->assertSame( 3, substr_count( $html, '<a class="nav-tab' ) );
	}

	public function test_off_the_admin_registers_no_fixer_action_screen_or_filter_even_with_changes_stored(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'register_deactivation_hook' )->justReturn( null );
		Functions\when( 'register_activation_hook' )->justReturn( null );
		Functions\when( 'plugin_basename' )->justReturn( 'readiness-index/readiness-index.php' );
		// A site that applied every fixer under 0.4.0: none of it may run now.
		$this->options[ State::OPTION ] = array(
			'robots-txt'        => array( 'allow' => array( 'GPTBot' ) ),
			'robots-directives' => array(),
			'dates'             => array(),
			'entity-graph'      => array(),
			'llms-txt'          => array(),
		);
		foreach ( $this->fixer_hooks()['actions'] as $hook ) {
			Actions\expectAdded( $hook )->never();
		}
		foreach ( $this->fixer_hooks()['filters'] as $hook ) {
			Filters\expectAdded( $hook )->never();
		}
		// The Score side is still there.
		Actions\expectAdded( 'admin_post_readiness_index_scan' )->once();
		Actions\expectAdded( 'admin_post_readiness_index_disconnect' )->once();
		Actions\expectAdded( 'admin_post_readiness_index_verify' )->once();
		Actions\expectAdded( 'wp_ajax_readiness_index_progress' )->once();
		Actions\expectAdded( 'admin_menu' )->once();
		Plugin::boot();
		Plugin::wire_fixers();
		$this->addToAssertionCount( 1 );
	}

	public function test_off_a_front_end_request_hooks_no_fixer(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'register_deactivation_hook' )->justReturn( null );
		Functions\when( 'register_activation_hook' )->justReturn( null );
		$this->options[ State::OPTION ] = array(
			'robots-txt'   => array( 'allow' => array( 'GPTBot' ) ),
			'entity-graph' => array(),
			'llms-txt'     => array(),
			'dates'        => array(),
		);
		foreach ( $this->fixer_hooks()['filters'] as $hook ) {
			Filters\expectAdded( $hook )->never();
		}
		Actions\expectAdded( 'wp_head' )->never();
		Actions\expectAdded( 'template_redirect' )->never();
		Plugin::boot();
		Plugin::wire_fixers();
		$this->addToAssertionCount( 1 );
	}

	public function test_off_activation_and_upgrade_store_nothing(): void {
		Plugin::activate();
		Plugin::upgrade();
		$this->assertSame( array(), $this->options );
	}
}
