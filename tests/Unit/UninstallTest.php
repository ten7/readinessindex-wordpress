<?php
namespace ReadinessIndex\Tests;

use Brain\Monkey\Functions;
use ReadinessIndex\Changes\Log;
use ReadinessIndex\Fixers\Logic;
use ReadinessIndex\Fixers\Registry;
use ReadinessIndex\Fixers\Runner;
use ReadinessIndex\Fixers\State;
use ReadinessIndex\Settings;
use ReadinessIndex\Tests\Support\FakeFixer;
use ReadinessIndex\Tests\Support\FakeWpdb;
use ReadinessIndex\Tests\Support\Vectors;
use ReadinessIndex\Tests\Support\WpTestCase;
use ReadinessIndex\Uninstall;

/**
 * The data lifecycle: activation seeds, deactivation and uninstall leave or remove what they say.
 */
final class UninstallTest extends WpTestCase {

	/**
	 * The fake table.
	 *
	 * @var FakeWpdb
	 */
	private $db;

	protected function set_up() {
		parent::set_up();
		$this->db        = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->db; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The fake table.
		Functions\when( 'has_action' )->justReturn( false );
		Functions\when( 'delete_metadata' )->justReturn( true );
	}

	protected function tear_down() {
		unset( $GLOBALS['wpdb'] );
		Registry::use_fixers( null );
		parent::tear_down();
	}

	/**
	 * A fake fixer that has made a change.
	 */
	private function applied_fixer(): FakeFixer {
		$vector = Vectors::all()['llms-txt/pages-and-posts'][0];
		$fixer  = new FakeFixer( 'llms-txt', Logic\LlmsTxt::class, $vector['given'] );
		Registry::use_fixers( array( $fixer ) );
		Runner::apply( $fixer, array(), Runner::preview( $fixer, array() )['hash'], 1 );
		$this->options[ State::OPTION ]               = array( 'llms-txt' => array() );
		$this->options[ Settings::OPTION_KEY ]        = 'air_0a1b2c3d_abcdefghijklmnopqrstuvwxyz012345';
		$this->options[ Settings::OPTION_CONNECTION ] = array();
		return $fixer;
	}

	public function test_activation_seeds_the_empty_map_autoloaded_so_the_front_end_reads_it_for_free(): void {
		State::seed();
		$this->assertSame( array(), $this->options[ State::OPTION ] );
		$this->assertSame( 'yes', $this->autoload[ State::OPTION ] );
	}

	public function test_the_other_options_are_not_autoloaded(): void {
		Settings::set_remove_on_uninstall( true );
		$this->assertFalse( $this->autoload[ Settings::OPTION_REMOVE ] );
		State::set( 'llms-txt', array() );
		$this->assertTrue( $this->autoload[ State::OPTION ], 'the one option the front end needs' );
	}

	public function test_uninstall_without_the_setting_removes_the_plugins_data_and_undoes_nothing(): void {
		$fixer = $this->applied_fixer();
		$this->assertSame( 1, Log::count() );
		$fixer->calls = array();

		Uninstall::run();

		$this->assertNotContains( 'cleanup', $fixer->calls, 'the changes are left alone by default' );
		$this->assertSame( 0, Log::count(), 'the changes table is gone' );
		foreach ( Uninstall::OPTIONS as $option ) {
			$this->assertArrayNotHasKey( $option, $this->options, $option );
		}
	}

	public function test_uninstall_with_the_setting_cleans_every_fixer_up_first(): void {
		$fixer = $this->applied_fixer();
		Settings::set_remove_on_uninstall( true );
		$fixer->calls = array();

		Uninstall::run();

		$this->assertSame( array( 'cleanup' ), $fixer->calls );
		$this->assertNull( $fixer->state() );
		$this->assertSame( 0, Log::count() );
		$this->assertArrayNotHasKey( Settings::OPTION_REMOVE, $this->options );
	}

	public function test_uninstall_still_cleans_every_fixer_up_while_the_fixers_are_switched_off(): void {
		$this->assertFalse( \ReadinessIndex\Features::fixers(), 'the shipped default' );
		$fixer = $this->applied_fixer();
		Settings::set_remove_on_uninstall( true );
		$fixer->calls = array();

		Uninstall::run();

		$this->assertSame( array( 'cleanup' ), $fixer->calls );
		$this->assertSame( 0, Log::count() );
		$this->assertArrayNotHasKey( State::OPTION, $this->options );
	}

	public function test_a_fixer_whose_cleanup_throws_does_not_stop_the_uninstall(): void {
		$fixer                   = $this->applied_fixer();
		$fixer->throw_on_cleanup = true;
		Settings::set_remove_on_uninstall( true );

		Uninstall::run();

		$this->assertSame( 0, Log::count(), 'the uninstall still finished' );
		$this->assertArrayNotHasKey( State::OPTION, $this->options );
	}

	public function test_deactivation_clears_the_schedules_and_leaves_the_changes_and_the_state(): void {
		$this->applied_fixer();
		$this->events['readiness_index_poll_scan'] = 1;
		\ReadinessIndex\Plugin::deactivate();
		$this->assertArrayNotHasKey( 'readiness_index_poll_scan', $this->events );
		$this->assertSame( 1, Log::count() );
		$this->assertArrayHasKey( State::OPTION, $this->options );
	}
}
