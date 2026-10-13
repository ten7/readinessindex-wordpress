<?php
namespace ReadinessIndex\Tests\Integration;

use ReadinessIndex\Fixers\Hooks;
use ReadinessIndex\Tests\Integration\Support\IntegrationCase;

/**
 * The plugin's cost on a front-end request when nothing has been applied: no database query and
 * well under a millisecond. The one option it reads is autoloaded, so it is in memory already; this
 * fails the day a change makes the front end ask the database or do real work for a site that has
 * not used a fixer.
 */
final class PerformanceBudgetTest extends IntegrationCase {

	public function test_a_front_end_request_with_nothing_applied_adds_no_query_and_under_a_millisecond(): void {
		global $wpdb;
		wp_load_alloptions();
		$this->next_request();

		$queries = $wpdb->num_queries;
		$runs    = 200;
		$start   = microtime( true );
		for ( $i = 0; $i < $runs; $i++ ) {
			Hooks::register();
		}
		$each = ( microtime( true ) - $start ) / $runs;

		$this->assertSame( $queries, $wpdb->num_queries, 'no query' );
		$this->assertLessThan( 0.001, $each, 'under 1 ms per request, measured ' . round( $each * 1000000 ) . ' microseconds' );
	}

	public function test_it_hooks_nothing_the_front_end_would_run_when_nothing_is_applied(): void {
		global $wp_filter;
		$before = array_map(
			'count',
			array_map(
				static function ( $hook ) {
					return $hook->callbacks;
				},
				$wp_filter
			)
		);
		$this->next_request();
		$after = array_map(
			'count',
			array_map(
				static function ( $hook ) {
					return $hook->callbacks;
				},
				$wp_filter
			)
		);
		$this->assertSame( $before, $after );
	}
}
