<?php
namespace ReadinessIndex\Tests;

use Brain\Monkey\Functions;
use ReadinessIndex\Changes\Log;
use ReadinessIndex\Fixers\Canon;
use ReadinessIndex\Fixers\Logic;
use ReadinessIndex\Fixers\Registry;
use ReadinessIndex\Fixers\Runner;
use ReadinessIndex\Tests\Support\FakeFixer;
use ReadinessIndex\Tests\Support\FakeWpdb;
use ReadinessIndex\Tests\Support\Vectors;
use ReadinessIndex\Tests\Support\WpTestCase;

/**
 * The runner against the golden vectors and against every way it can go wrong: the order of its
 * steps, a stale preview, a conflict, a throwing apply, a failed verify, an undo that must
 * restore byte for byte and one that must refuse.
 */
final class RunnerTest extends WpTestCase {

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
	}

	protected function tear_down() {
		unset( $GLOBALS['wpdb'] );
		Registry::use_fixers( null );
		parent::tear_down();
	}

	/**
	 * The logic class of a fixer id.
	 *
	 * @param string $id Fixer id.
	 */
	private function logic( string $id ): string {
		$map = array(
			'robots-txt'        => Logic\RobotsTxt::class,
			'llms-txt'          => Logic\LlmsTxt::class,
			'robots-directives' => Logic\RobotsDirectives::class,
			'dates'             => Logic\Dates::class,
			'entity-graph'      => Logic\EntityGraph::class,
		);
		return $map[ $id ];
	}

	/**
	 * A fake fixer built from a vector.
	 *
	 * @param array<string,mixed> $vector A vector.
	 */
	private function fixer( array $vector ): FakeFixer {
		$fixer = new FakeFixer( $vector['fixer'], $this->logic( $vector['fixer'] ), $vector['given'] );
		Registry::use_fixers( array( $fixer ) );
		return $fixer;
	}

	public function vectors(): array {
		return Vectors::all();
	}

	/**
	 * Preview, apply, undo and apply again through the runner.
	 *
	 * @dataProvider vectors
	 * @param array<string,mixed> $vector The vector.
	 */
	public function test_the_runner_reproduces_every_vector( array $vector ): void {
		$fixer   = $this->fixer( $vector );
		$choices = $vector['given']['choices'];
		$label   = $vector['fixer'] . '/' . $vector['case'];

		$preview = Runner::preview( $fixer, $choices );
		foreach ( array( 'status', 'reason', 'findings', 'changes', 'paste', 'output' ) as $key ) {
			$this->assertTrue( Canon::same( $vector['preview'][ $key ], $preview[ $key ] ), $label . ': preview ' . $key );
		}

		$result = Runner::apply( $fixer, $choices, $preview['hash'], 7 );
		$apply  = $vector['apply'];
		$this->assertSame( $apply['status'], $result['outcome'], $label . ': apply status' );
		$this->assertTrue( Canon::same( $apply['output'], $fixer->served() ), $label . ': what is served after apply' );

		if ( 'applied' !== $apply['status'] ) {
			$this->assertSame( 0, Log::count(), $label . ': nothing recorded' );
			return;
		}

		$row = Log::find( $result['change'] );
		$this->assertSame( $apply['recorded']['fixer'], $row['fixer'], $label );
		$this->assertTrue( Canon::same( $apply['recorded']['prior'], $row['prior'] ), $label . ': recorded prior' );
		$this->assertTrue( Canon::same( $apply['recorded']['new'], $row['new'] ), $label . ': recorded new' );

		$again = Runner::apply( $fixer, $choices, Runner::preview( $fixer, $choices )['hash'], 7 );
		$this->assertSame( $vector['reapply']['status'], $again['outcome'], $label . ': apply twice' );
		$this->assertTrue( Canon::same( $vector['reapply']['output'], $fixer->served() ), $label . ': apply twice serves the same' );
		$this->assertSame( 1, Log::count(), $label . ': apply twice records nothing' );

		$undone = Runner::undo( $result['change'], 7 );
		$this->assertSame( 'undone', $undone['outcome'], $label );
		$this->assertTrue( Canon::same( $vector['undo']['output'], $fixer->served() ), $label . ': undo restores the baseline' );
		$this->assertTrue( Canon::same( $vector['undo']['recorded']['prior'], Log::find( 2 )['prior'] ), $label . ': undo record prior' );
		$this->assertTrue( Canon::same( $vector['undo']['recorded']['new'], Log::find( 2 )['new'] ), $label . ': undo record new' );
		$this->assertSame( $vector['given']['state'] ?? null, $fixer->state(), $label . ': the stored state is what it was' );
	}

	/**
	 * A ready llms.txt vector for the behavioral tests below.
	 *
	 * @return array{0:FakeFixer,1:array<string,mixed>}
	 */
	private function llms(): array {
		$vector = Vectors::all()['llms-txt/pages-and-posts'][0];
		return array( $this->fixer( $vector ), $vector );
	}

	public function test_steps_run_in_order_and_one_row_is_written(): void {
		list( $fixer, $vector ) = $this->llms();
		$choices                = $vector['given']['choices'];
		$hash                   = Runner::preview( $fixer, $choices )['hash'];
		$fixer->calls           = array();

		$result = Runner::apply( $fixer, $choices, $hash, 3 );

		$this->assertSame( 'applied', $result['outcome'] );
		$this->assertSame( array( 'detect', 'apply', 'verify' ), $fixer->calls );
		$this->assertSame( 1, Log::count() );
		$row = Log::find( 1 );
		$this->assertSame( 'applied', $row['status'] );
		$this->assertSame( 3, $row['user_id'] );
		$this->assertTrue( $row['verified'] );
	}

	public function test_a_stale_preview_is_not_applied_and_is_shown_again(): void {
		list( $fixer, $vector ) = $this->llms();
		$choices                = $vector['given']['choices'];
		$hash                   = Runner::preview( $fixer, $choices )['hash'];

		// The site changes while the owner reads: a page is published.
		$fixer->facts['content']['sections'][0]['items'][] = array(
			'title'       => 'New',
			'url'         => 'https://example.com/new/',
			'description' => '',
		);
		$result = Runner::apply( $fixer, $choices, $hash, 3 );

		$this->assertSame( 'stale', $result['outcome'] );
		$this->assertNull( $fixer->state() );
		$this->assertSame( 0, Log::count() );
		$this->assertNotContains( 'apply', $fixer->calls );
	}

	public function test_a_conflict_refuses_with_its_reasons_and_records_nothing(): void {
		$vector = Vectors::all()['robots-txt/physical-file-wins'][0];
		$fixer  = $this->fixer( $vector );
		$hash   = Runner::preview( $fixer, $vector['given']['choices'] )['hash'];

		$result = Runner::apply( $fixer, $vector['given']['choices'], $hash, 3 );

		$this->assertSame( 'refused', $result['outcome'] );
		$this->assertSame( array( 'physical_file' ), $result['reasons'] );
		$this->assertNotContains( 'apply', $fixer->calls );
		$this->assertSame( 0, Log::count() );
	}

	public function test_an_extra_conflict_the_site_adds_refuses_too(): void {
		list( $fixer, $vector ) = $this->llms();
		$fixer->extra_conflicts = array( 'search_engines_discouraged' );
		$choices                = $vector['given']['choices'];

		$result = Runner::apply( $fixer, $choices, Runner::preview( $fixer, $choices )['hash'], 3 );

		$this->assertSame( 'refused', $result['outcome'] );
		$this->assertSame( array( 'search_engines_discouraged' ), $result['reasons'] );
	}

	public function test_a_throwing_apply_puts_the_prior_value_back_and_is_recorded_as_failed(): void {
		list( $fixer, $vector ) = $this->llms();
		$fixer->throw_on_apply  = true;
		$choices                = $vector['given']['choices'];

		$result = Runner::apply( $fixer, $choices, Runner::preview( $fixer, $choices )['hash'], 3 );

		$this->assertSame( 'failed', $result['outcome'] );
		$this->assertNull( $fixer->state(), 'the stored state is what it was' );
		$this->assertSame( 'failed', Log::find( 1 )['status'] );
		$this->assertContains( 'undo', $fixer->calls );
	}

	public function test_a_failed_verify_is_recorded_and_does_not_roll_back_a_good_apply(): void {
		list( $fixer, $vector ) = $this->llms();
		$fixer->not_live        = true;
		$choices                = $vector['given']['choices'];

		$result = Runner::apply( $fixer, $choices, Runner::preview( $fixer, $choices )['hash'], 3 );

		$this->assertSame( 'applied', $result['outcome'] );
		$this->assertFalse( $result['live'] );
		$this->assertFalse( Log::find( 1 )['verified'] );
		$this->assertSame( 'applied', Log::find( 1 )['status'] );
		$this->assertSame( $choices, $fixer->state() );
	}

	public function test_undo_returns_the_prior_value_exactly_and_writes_a_row_of_its_own(): void {
		list( $fixer, $vector ) = $this->llms();
		$choices                = $vector['given']['choices'];
		Runner::apply( $fixer, $choices, Runner::preview( $fixer, $choices )['hash'], 3 );

		$result = Runner::undo( 1, 4 );

		$this->assertSame( 'undone', $result['outcome'] );
		$this->assertNull( $fixer->state() );
		$this->assertSame( 'undone', Log::find( 1 )['status'] );
		$undo = Log::find( 2 );
		$this->assertSame( 'undo', $undo['action'] );
		$this->assertSame( 4, $undo['user_id'] );
		$this->assertSame( 1, $undo['undoes'] );
		$this->assertSame( 'refused', Runner::undo( 1, 4 )['outcome'], 'a second undo is refused' );
		$this->assertSame( 'already_undone', Runner::undo( 1, 4 )['reason'] );
	}

	public function test_an_undo_is_refused_when_a_later_change_superseded_it(): void {
		$vector = Vectors::all()['robots-txt/wordpress-default-add-signals-and-policy'][0];
		$fixer  = $this->fixer( $vector );
		$first  = $vector['given']['choices'];
		Runner::apply( $fixer, $first, Runner::preview( $fixer, $first )['hash'], 3 );

		$second           = $first;
		$second['policy'] = false;
		Runner::apply( $fixer, $second, Runner::preview( $fixer, $second )['hash'], 3 );

		$result = Runner::undo( 1, 3 );

		$this->assertSame( 'refused', $result['outcome'] );
		$this->assertSame( 'superseded', $result['reason'] );
		$this->assertSame( $second, $fixer->state(), 'the newer change is untouched' );
		$this->assertSame( 'superseded', Log::find( 1 )['status'] );
	}

	public function test_an_undo_is_refused_after_the_value_was_edited_by_hand(): void {
		list( $fixer, $vector ) = $this->llms();
		$choices                = $vector['given']['choices'];
		Runner::apply( $fixer, $choices, Runner::preview( $fixer, $choices )['hash'], 3 );

		$fixer->stored = array( 'edited' => 'by hand' );
		$result        = Runner::undo( 1, 3 );

		$this->assertSame( 'refused', $result['outcome'] );
		$this->assertSame( 'superseded', $result['reason'] );
		$this->assertSame( array( 'edited' => 'by hand' ), $fixer->state() );
	}

	public function test_undo_of_something_that_does_not_exist_is_refused(): void {
		$this->assertSame( 'not_found', Runner::undo( 99, 1 )['reason'] );
	}

	public function test_the_handlers_check_the_capability_before_the_nonce_and_nothing_else_runs(): void {
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\expect( 'wp_die' )->once()->andThrow( new \RuntimeException( 'died' ) );
		Functions\expect( 'check_admin_referer' )->never();
		$this->expectException( \RuntimeException::class );
		Runner::handle_apply();
	}

	public function test_the_handlers_check_the_nonce_after_the_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\expect( 'check_admin_referer' )->once()->with( Runner::UNDO_ACTION )->andThrow( new \RuntimeException( 'bad nonce' ) );
		$this->expectExceptionMessage( 'bad nonce' );
		Runner::handle_undo();
	}

	public function test_a_get_request_applies_nothing(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		Functions\expect( 'wp_die' )->once()->with( '', '', array( 'response' => 405 ) )->andThrow( new \RuntimeException( 'method' ) );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		list( $fixer )             = $this->llms();
		try {
			Runner::handle_apply();
			$this->fail( 'A GET was accepted.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'method', $e->getMessage() );
		} finally {
			unset( $_SERVER['REQUEST_METHOD'] );
		}
		$this->assertNotContains( 'apply', $fixer->calls );
		$this->assertSame( 0, Log::count() );
	}
}
