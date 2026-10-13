<?php
namespace ReadinessIndex\Tests\Integration;

use ReadinessIndex\Changes\Log;
use ReadinessIndex\Fixers\Impl\EntityGraphFixer;
use ReadinessIndex\Fixers\Integration\Yoast;
use ReadinessIndex\Fixers\Runner;
use ReadinessIndex\Fixers\SeoTools;
use ReadinessIndex\Tests\Integration\Support\SeoPluginCase;

/**
 * The entity graph through the real Yoast SEO (`READINESS_INDEX_TEST_SEO=yoast`): the plugin's
 * options are written with confirmation and restored byte for byte, the facts go through its
 * filters, and the page carries exactly one graph.
 */
final class YoastTest extends SeoPluginCase {

	public function set_up() {
		if ( ! defined( 'WPSEO_VERSION' ) ) {
			$this->markTestSkipped( 'Yoast SEO is not loaded. Run with READINESS_INDEX_TEST_SEO=yoast.' );
		}
		parent::set_up();
	}

	/**
	 * The options the integration writes, as they are now.
	 *
	 * @return array<string,mixed>
	 */
	private function options(): array {
		$titles = get_option( 'wpseo_titles' );
		$social = get_option( 'wpseo_social' );
		return array(
			'titles' => $titles,
			'social' => $social,
		);
	}

	public function test_the_integration_is_active_supported_and_the_only_one_detected(): void {
		$this->assertContains( 'yoast', SeoTools::active() );
		$this->assertTrue( ( new Yoast() )->supported() );
		$this->assertNotContains( 'rank_math', SeoTools::active() );
	}

	public function test_the_preview_shows_every_setting_that_will_be_written_before_and_after(): void {
		$fixer   = new EntityGraphFixer();
		$preview = Runner::preview( $fixer, $fixer->sanitize_choices( $this->raw() ) );

		$this->assertSame( 'through_tooling', $preview['findings']['mode'] );
		$keys = array_map(
			static function ( $write ) {
				return $write['key'];
			},
			$preview['writes']
		);
		$this->assertContains( 'company_name', $keys );
		$this->assertContains( 'company_logo_id', $keys );
		$this->assertContains( 'other_social_urls', $keys );
		$this->assertSame( array(), $preview['conflicts'] );
	}

	public function test_apply_prints_one_graph_through_yoast_and_undo_restores_every_option_byte_for_byte(): void {
		$before = $this->options();
		$result = $this->apply_choices();

		$after = $this->options();
		$this->assertSame( 'Example Co', $after['titles']['company_name'] );
		$this->assertSame( $this->logo, (int) $after['titles']['company_logo_id'] );
		$this->assertSame( array( 'https://www.wikidata.org/wiki/Q42', 'https://ror.org/05gq02987' ), $after['social']['other_social_urls'] );

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
		$this->assertSame( 'Example Company, LLC', $org['legalName'], 'a fact Yoast has no field for goes through its filter' );
		$this->assertSame( '12 Main Street', $org['address']['streetAddress'] );
		$this->assertContains( 'https://ror.org/05gq02987', $org['sameAs'] );

		$this->assertSame( 'undone', Runner::undo( $result['change'], 1 )['outcome'] );
		$this->assertSame( $before, $this->options(), 'every option is back to what it was' );
		$this->assertSame( 'undone', Log::find( $result['change'] )['status'] );
	}

	public function test_an_organization_name_the_owner_already_set_in_yoast_is_shown_as_a_change_and_returned_on_undo(): void {
		\WPSEO_Options::set( 'company_name', 'Hand-entered name', 'wpseo_titles' );
		$fixer   = new EntityGraphFixer();
		$preview = Runner::preview( $fixer, $fixer->sanitize_choices( $this->raw() ) );
		$names   = array_values(
			array_filter(
				$preview['writes'],
				static function ( $write ) {
					return 'company_name' === $write['key'];
				}
			)
		);
		$this->assertSame( 'Hand-entered name', $names[0]['before'], 'the value that will be replaced is shown' );

		$result = $this->apply_choices();
		$this->assertSame( 'Example Co', get_option( 'wpseo_titles' )['company_name'] );
		Runner::undo( $result['change'], 1 );
		$this->assertSame( 'Hand-entered name', get_option( 'wpseo_titles' )['company_name'] );
	}

	public function test_an_edit_made_in_yoast_after_apply_blocks_an_undo_that_would_overwrite_it(): void {
		$result = $this->apply_choices();
		$state  = \ReadinessIndex\Fixers\State::get( 'entity-graph' );
		update_option( \ReadinessIndex\Fixers\State::OPTION, array( 'entity-graph' => $state + array( 'edited' => true ) ) );

		$this->assertSame( 'superseded', Runner::undo( $result['change'], 1 )['reason'] );
		$this->assertSame( 'Example Co', get_option( 'wpseo_titles' )['company_name'], 'nothing was overwritten' );
	}
}
