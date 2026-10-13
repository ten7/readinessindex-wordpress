<?php
namespace ReadinessIndex\Tests\Integration;

use ReadinessIndex\Fixers\Impl\EntityGraphFixer;
use ReadinessIndex\Fixers\Integration\RankMath;
use ReadinessIndex\Fixers\Runner;
use ReadinessIndex\Fixers\SeoTools;
use ReadinessIndex\Tests\Integration\Support\SeoPluginCase;

/**
 * The entity graph through the real Rank Math (`READINESS_INDEX_TEST_SEO=rank_math`).
 */
final class RankMathTest extends SeoPluginCase {

	public function set_up() {
		if ( ! defined( 'RANK_MATH_VERSION' ) ) {
			$this->markTestSkipped( 'Rank Math is not loaded. Run with READINESS_INDEX_TEST_SEO=rank_math.' );
		}
		parent::set_up();
		// What Rank Math's installer writes on activation, which a site that has it active already has.
		if ( ! is_array( get_option( 'rank-math-options-titles' ) ) ) {
			update_option(
				'rank-math-options-titles',
				array(
					'knowledgegraph_type' => 'person',
					'knowledgegraph_name' => 'Example Co',
				)
			);
		}
	}

	public function test_the_integration_is_active_and_supported(): void {
		$this->assertContains( 'rank_math', SeoTools::active() );
		$this->assertTrue( ( new RankMath() )->supported() );
	}

	public function test_apply_prints_one_graph_through_rank_math_and_undo_restores_its_options(): void {
		$before = get_option( 'rank-math-options-titles' );
		$result = $this->apply_choices();

		$titles = get_option( 'rank-math-options-titles' );
		$this->assertSame( 'company', $titles['knowledgegraph_type'] );
		$this->assertSame( 'Example Co', $titles['knowledgegraph_name'] );
		$this->assertStringContainsString( 'https://ror.org/05gq02987', $titles['social_additional_profiles'] );

		$printed = $this->printed( home_url( '/' ) );
		$this->assert_one_graph( $printed );
		$org = array_values(
			array_filter(
				$this->nodes( $printed['docs'] ),
				static function ( $node ) {
					return 'Organization' === $node['@type'];
				}
			)
		)[0];
		$this->assertSame( 'Example Company, LLC', $org['legalName'] );

		$this->assertSame( 'undone', Runner::undo( $result['change'], 1 )['outcome'] );
		$restored = get_option( 'rank-math-options-titles' );
		foreach ( array( 'knowledgegraph_type', 'knowledgegraph_name', 'knowledgegraph_logo', 'knowledgegraph_logo_id', 'social_additional_profiles' ) as $key ) {
			$this->assertSame( $before[ $key ] ?? null, $restored[ $key ] ?? null, $key );
		}
	}

	public function test_the_preview_lists_what_will_be_written(): void {
		$fixer   = new EntityGraphFixer();
		$preview = Runner::preview( $fixer, $fixer->sanitize_choices( $this->raw() ) );
		$keys    = array_map(
			static function ( $write ) {
				return $write['key'];
			},
			$preview['writes']
		);
		$this->assertContains( 'knowledgegraph_type', $keys, 'the site is said to be a company, not a person' );
		$this->assertContains( 'social_additional_profiles', $keys );
		$this->assertNotContains( 'knowledgegraph_name', $keys, 'already the site name: not written' );
	}
}
