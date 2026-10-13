<?php
namespace ReadinessIndex\Tests\Integration;

use ReadinessIndex\Changes\Log;
use ReadinessIndex\Changes\Schema;
use ReadinessIndex\Fixers\Impl\RobotsTxtFixer;
use ReadinessIndex\Fixers\Registry;
use ReadinessIndex\Fixers\Runner;
use ReadinessIndex\Fixers\State;
use ReadinessIndex\Plugin;
use ReadinessIndex\Settings;
use ReadinessIndex\Tests\Integration\Support\IntegrationCase;
use ReadinessIndex\Uninstall;

/**
 * Activation, upgrade, deactivation and uninstall in a real WordPress with a real table.
 */
final class LifecycleTest extends IntegrationCase {

	/**
	 * Applies a robots.txt change so there is something on record.
	 */
	private function applied(): int {
		update_option( 'blogname', 'Example Co' );
		$fixer = new RobotsTxtFixer();
		Registry::use_fixers( array( $fixer ) );
		$choices = $fixer->sanitize_choices(
			array(
				'content_signals' => array(
					'search'   => 'yes',
					'ai-input' => 'yes',
					'ai-train' => 'no',
				),
				'policy'          => '1',
			)
		);
		$result  = Runner::apply( $fixer, $choices, Runner::preview( $fixer, $choices )['hash'], 1 );
		$this->assertSame( 'applied', $result['outcome'] );
		return $result['change'];
	}

	public function test_activation_creates_the_table_and_the_autoloaded_empty_map(): void {
		global $wpdb;
		delete_option( State::OPTION );
		delete_option( Schema::VERSION_OPTION );
		Plugin::activate();

		$this->assertSame( Schema::table(), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Schema::table() ) ) );
		$this->assertSame( Schema::VERSION, (int) get_option( Schema::VERSION_OPTION ) );
		$this->assertSame( array(), get_option( State::OPTION ) );
		$this->assertContains( $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", State::OPTION ) ), array( 'yes', 'on' ), 'read for free on every request' );
		$this->assertContains( $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Schema::VERSION_OPTION ) ), array( 'no', 'off' ), 'the version is only read in the admin' );
	}

	public function test_a_site_that_got_new_files_without_being_reactivated_is_brought_up_to_date_once(): void {
		delete_option( State::OPTION );
		delete_option( Schema::VERSION_OPTION );
		Plugin::upgrade();
		$this->assertSame( array(), get_option( State::OPTION ) );
		$this->assertSame( Schema::VERSION, (int) get_option( Schema::VERSION_OPTION ) );

		update_option( State::OPTION, array( 'robots-txt' => array() ) );
		Plugin::upgrade();
		$this->assertSame( array( 'robots-txt' => array() ), get_option( State::OPTION ), 'a current site is left alone' );
	}

	public function test_a_row_round_trips_with_its_json_verbatim_including_an_empty_choice_set(): void {
		$id  = Log::insert(
			array(
				'fixer'   => 'dates',
				'checks'  => array( 'AIR-4.8', 'AIR-5.4' ),
				'status'  => 'applied',
				'user_id' => 3,
				'prior'   => null,
				'new'     => array(),
				'extra'   => array( 'writes' => array( 'wpseo_titles' => array( 'company_name' => '' ) ) ),
			)
		);
		$row = Log::find( $id );
		global $wpdb;
		$table = Schema::table();
		$this->assertSame( '{}', $wpdb->get_var( $wpdb->prepare( "SELECT new_json FROM {$table} WHERE id = %d", $id ) ), 'stored as the vectors write it' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own table.
		$this->assertSame( array( 'AIR-4.8', 'AIR-5.4' ), $row['checks'] );
		$this->assertNull( $row['prior'] );
		$this->assertSame( array(), $row['new'] );
		$this->assertSame( 3, $row['user_id'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $row['created_gmt'] );
	}

	public function test_deactivation_keeps_the_record_and_the_stored_choices(): void {
		$this->applied();
		Plugin::deactivate();
		$this->assertSame( 1, Log::count() );
		$this->assertNotNull( State::get( 'robots-txt' ) );
	}

	public function test_uninstall_removes_the_plugins_data_and_undoes_nothing_by_default(): void {
		$this->applied();
		update_option( Settings::OPTION_KEY, 'air_0a1b2c3d_abcdefghijklmnopqrstuvwxyz012345', false );
		$state = State::get( 'robots-txt' );

		Uninstall::run();

		global $wpdb;
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Schema::table() ) ), 'the table is gone' );
		foreach ( Uninstall::OPTIONS as $option ) {
			$this->assertFalse( get_option( $option ), $option );
		}
		$this->assertNotNull( $state, 'the change was in place until the data was removed, and it was not undone first' );
	}

	public function test_uninstall_with_the_setting_cleans_every_fixer_up_first(): void {
		$this->applied();
		Settings::set_remove_on_uninstall( true );
		$called = 0;
		$probe  = static function () use ( &$called ) {
			++$called;
		};
		add_action( 'update_option_' . State::OPTION, $probe );

		Uninstall::run();

		$this->assertGreaterThan( 0, $called, 'the fixer turned itself off before the data went' );
		$this->assertFalse( get_option( State::OPTION ) );
	}
}
