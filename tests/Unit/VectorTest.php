<?php
namespace ReadinessIndex\Tests;

use ReadinessIndex\Fixers\Logic;
use ReadinessIndex\Tests\Support\Vectors;
use ReadinessIndex\Tests\Support\WpTestCase;

/**
 * Every golden vector, through the fixer logic: the exact preview, result, undo and second
 * apply the web repository's reference computed. The logic has no WordPress in it, so this
 * is the byte-for-byte comparison the parity contract asks for, one situation per case.
 */
final class VectorTest extends WpTestCase {

	/**
	 * The logic class for a fixer id.
	 *
	 * @param string              $fixer Fixer id.
	 * @param array<string,mixed> $given The vector's given.
	 * @return array<string,mixed>
	 */
	private function evaluate( string $fixer, array $given ): array {
		switch ( $fixer ) {
			case 'robots-txt':
				return Logic\RobotsTxt::evaluate( $given );
			case 'llms-txt':
				return Logic\LlmsTxt::evaluate( $given );
			case 'robots-directives':
				return Logic\RobotsDirectives::evaluate( $given );
			case 'dates':
				return Logic\Dates::evaluate( $given );
			case 'entity-graph':
				return Logic\EntityGraph::evaluate( $given );
		}
		$this->fail( 'No logic for ' . $fixer );
	}

	public function vectors(): array {
		return Vectors::all();
	}

	/**
	 * One vector, step for step.
	 *
	 * @dataProvider vectors
	 * @param array<string,mixed> $vector The vector.
	 */
	public function test_the_vector_is_reproduced_step_for_step( array $vector ): void {
		$given  = $vector['given'];
		$result = Logic\Lifecycle::run( $vector['fixer'], $given, $this->evaluate( $vector['fixer'], $given ) );

		foreach ( array( 'preview', 'apply', 'undo', 'reapply' ) as $step ) {
			if ( ! array_key_exists( $step, $vector ) || null === $vector[ $step ] ) {
				$this->assertArrayNotHasKey( $step, $result, $vector['case'] . ' has no ' . $step );
				continue;
			}
			$this->assertSame(
				wp_json_encode( Vectors::canon( $vector[ $step ] ) ),
				wp_json_encode( Vectors::canon( $result[ $step ] ) ),
				$vector['fixer'] . '/' . $vector['case'] . ': ' . $step
			);
		}
	}

	public function test_there_are_vectors_for_every_fixer(): void {
		foreach ( array( 'robots-txt', 'llms-txt', 'robots-directives', 'dates', 'entity-graph' ) as $fixer ) {
			$this->assertGreaterThanOrEqual( 11, count( Vectors::of( $fixer ) ), $fixer );
		}
		$this->assertSame( 64, count( Vectors::all() ) );
	}
}
