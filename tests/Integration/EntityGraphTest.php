<?php
namespace ReadinessIndex\Tests\Integration;

use ReadinessIndex\Fixers\Impl\EntityGraphFixer;
use ReadinessIndex\Fixers\Logic\GraphValidator;
use ReadinessIndex\Fixers\Registry;
use ReadinessIndex\Fixers\Runner;
use ReadinessIndex\Fixers\Runtime\EntityGraph;
use ReadinessIndex\Fixers\SeoTools;
use ReadinessIndex\Fixers\State;
use ReadinessIndex\Tests\Integration\Support\IntegrationCase;

/**
 * The entity graph fixer in a real WordPress, on a site with no SEO plugin: the facts the owner
 * supplies, the graph each kind of page prints, its structural validity, that it can never close
 * the script element it is printed in, and every refusal.
 */
final class EntityGraphTest extends IntegrationCase {

	/**
	 * The owner's choices, as a form would send them.
	 *
	 * @return array<string,mixed>
	 */
	private function raw(): array {
		return array(
			'organization'    => array(
				'legal_name'     => 'Example Company, LLC',
				'description'    => "A family-run   garden\ncenter.",
				'founding_date'  => '1987-04-01',
				'address'        => array(
					'street'  => '12 Main Street',
					'country' => 'US',
				),
				'area_served'    => "Sangamon County\n\n",
				'leaders'        => "Pat Rivera\nSam Chen",
				'contact_points' => array(
					array(
						'type'      => 'customer service',
						'email'     => 'hello@example.com',
						'telephone' => '+1-555-0100',
					),
					array(
						'type'  => '',
						'email' => 'dropped@example.com',
					),
				),
				'same_as'        => "https://www.wikidata.org/wiki/Q42\nhttp://not-https.example/x\n  https://ror.org/05gq02987  \nhttps://www.wikidata.org/wiki/Q42\n\n",
			),
			'search_template' => 'https://example.com/?s={search_term_string}',
		);
	}

	/**
	 * What a page prints in its head.
	 *
	 * @param string $url A URL on the site.
	 */
	private function head( string $url ): string {
		$this->go_to( $url );
		ob_start();
		EntityGraph::print_graph();
		return (string) ob_get_clean();
	}

	/**
	 * The graph document in printed markup.
	 *
	 * @param string $markup The head.
	 * @return array<string,mixed>
	 */
	private function document( string $markup ): array {
		$this->assertSame( 1, preg_match( '#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#s', $markup, $m ), $markup );
		$doc = json_decode( trim( $m[1] ), true );
		$this->assertIsArray( $doc, 'the block is valid JSON' );
		return $doc;
	}

	/**
	 * Applies the owner's choices.
	 *
	 * @param array<string,mixed> $raw Form input.
	 * @return array<string,mixed> The sanitized choices.
	 */
	private function apply( array $raw ): array {
		$fixer = new EntityGraphFixer();
		Registry::use_fixers( array( $fixer ) );
		$choices = $fixer->sanitize_choices( $raw );
		$result  = Runner::apply( $fixer, $choices, Runner::preview( $fixer, $choices )['hash'], 1 );
		$this->assertSame( 'applied', $result['outcome'] );
		return $choices;
	}

	public function test_the_form_is_sanitized_to_exactly_the_shape_the_vectors_use(): void {
		$choices = ( new EntityGraphFixer() )->sanitize_choices( $this->raw() );

		$this->assertSame( 'Example Company, LLC', $choices['organization']['legal_name'] );
		$this->assertSame( 'A family-run garden center.', $choices['organization']['description'] );
		$this->assertSame( array( 'Sangamon County' ), $choices['organization']['area_served'] );
		$this->assertSame( array( 'Pat Rivera', 'Sam Chen' ), $choices['organization']['leaders'] );
		$this->assertSame(
			array(
				array(
					'type'      => 'customer service',
					'email'     => 'hello@example.com',
					'telephone' => '+1-555-0100',
				),
			),
			$choices['organization']['contact_points'],
			'a row with no kind is dropped'
		);
		$this->assertSame( array( 'https://www.wikidata.org/wiki/Q42', 'https://ror.org/05gq02987' ), $choices['organization']['same_as'], 'https only, trimmed, no duplicates' );
		$this->assertSame( 'https://example.com/?s={search_term_string}', $choices['search_template'], 'the placeholder survives sanitizing' );
	}

	public function test_a_bad_date_a_bad_email_and_a_missing_page_are_dropped_not_stored(): void {
		$raw     = array(
			'organization' => array(
				'founding_date'  => 'last spring',
				'contact_points' => array(
					array(
						'type'  => 'sales',
						'email' => 'not an email',
					),
				),
			),
			'about_page'   => 99999,
		);
		$choices = ( new EntityGraphFixer() )->sanitize_choices( $raw );
		$this->assertArrayNotHasKey( 'founding_date', $choices['organization'] );
		$this->assertArrayNotHasKey( 'email', $choices['organization']['contact_points'][0] );
		$this->assertArrayNotHasKey( 'about_page', $choices );
	}

	public function test_the_home_page_prints_one_linked_graph_with_one_organization_and_one_website_search_action(): void {
		$this->apply( $this->raw() );
		$this->next_request();
		$doc = $this->document( $this->head( home_url( '/' ) ) );

		$types = array_map(
			static function ( $node ) {
				return $node['@type'];
			},
			$doc['@graph']
		);
		$this->assertSame( array( 'Organization', 'WebSite', 'WebPage' ), $types );
		$this->assertSame( array(), GraphValidator::problems( $doc ) );
		$this->assertSame( 'SearchAction', $doc['@graph'][1]['potentialAction'][0]['@type'] );
		$this->assertSame( array( 'Pat Rivera', 'Sam Chen' ), $doc['@graph'][0]['founder'], 'names as text, not Person nodes' );
		$this->assertSame( untrailingslashit( home_url() ) . '/#organization', $doc['@graph'][2]['about']['@id'] );
	}

	public function test_a_page_below_another_prints_a_trail_from_its_real_ancestors(): void {
		$parent = (int) self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Services',
			)
		);
		$child  = (int) self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Lawn care',
				'post_parent' => $parent,
			)
		);
		$this->set_permalink_structure( '/%postname%/' );
		$this->apply( $this->raw() );
		$this->next_request();
		$doc   = $this->document( $this->head( get_permalink( $child ) ) );
		$trail = array();
		foreach ( $doc['@graph'] as $node ) {
			if ( 'BreadcrumbList' === $node['@type'] ) {
				foreach ( $node['itemListElement'] as $item ) {
					$trail[] = $item['name'];
				}
			}
		}
		$this->assertSame( array( 'Home', 'Services', 'Lawn care' ), $trail );
		$this->assertSame( array(), GraphValidator::problems( $doc ) );
	}

	public function test_a_title_cannot_close_the_script_element_or_inject_markup(): void {
		$id = (int) self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Fish < Chips > Peas & "q" </script>',
			)
		);
		$this->apply( $this->raw() );
		$this->next_request();
		$markup = $this->head( get_permalink( $id ) );

		$this->assertSame( 1, substr_count( $markup, '</script>' ), 'the only closing tag is the block\'s own' );
		$this->assertStringContainsString( '<', $markup );
		$this->assertStringNotContainsString( '<script>alert', $markup );
		$this->assertStringNotContainsString( '"q"', $markup, 'quotes are escaped too' );
		$this->assertSame( array(), GraphValidator::problems( $this->document( $markup ) ) );
	}

	public function test_nothing_prints_for_a_search_a_404_or_an_archive(): void {
		$this->apply( $this->raw() );
		$this->next_request();
		$this->assertSame( '', $this->head( home_url( '/?s=garden' ) ) );
		$this->assertSame( '', $this->head( home_url( '/?p=999999' ) ) );
	}

	public function test_nothing_prints_beside_another_plugins_graph(): void {
		$this->apply( $this->raw() );
		foreach ( array( 'aioseo', 'seopress', 'tsf', 'schema_pro', 'yoast', 'rank_math' ) as $tool ) {
			SeoTools::use_active( array( $tool ) );
			$this->assertSame( '', $this->head( home_url( '/' ) ), $tool );
		}
	}

	public function test_another_graph_printer_is_refused_with_the_setting_to_change(): void {
		SeoTools::use_active( array( 'aioseo' ) );
		$fixer   = new EntityGraphFixer();
		$choices = $fixer->sanitize_choices( $this->raw() );
		$preview = Runner::preview( $fixer, $choices );

		$this->assertSame( array( 'other_tool' ), $preview['conflicts'] );
		$this->assertFalse( $fixer->reachable() );
		Registry::use_fixers( array( $fixer ) );
		$this->assertSame( 'refused', Runner::apply( $fixer, $choices, $preview['hash'], 1 )['outcome'] );
		$this->assertNull( State::get( 'entity-graph' ) );
	}

	public function test_both_yoast_and_rank_math_active_is_refused_and_never_guessed_at(): void {
		SeoTools::use_active( array( 'yoast', 'rank_math' ) );
		$fixer   = new EntityGraphFixer();
		$preview = Runner::preview( $fixer, $fixer->sanitize_choices( $this->raw() ) );
		$this->assertSame( array( 'both_seo_tools' ), $preview['conflicts'] );
		$this->assertFalse( $fixer->reachable() );
	}

	public function test_an_invalid_search_address_is_left_out_and_reported(): void {
		$fixer                  = new EntityGraphFixer();
		$raw                    = $this->raw();
		$raw['search_template'] = 'https://example.com/?s=no-placeholder';
		$preview                = Runner::preview( $fixer, $fixer->sanitize_choices( $raw ) );
		$this->assertSame( 'invalid', $preview['findings']['search_template'] );
		$this->assertStringNotContainsString( 'SearchAction', (string) wp_json_encode( $preview['output'] ) );
	}

	public function test_the_preview_checks_its_own_graph_and_undo_stops_printing(): void {
		$fixer   = new EntityGraphFixer();
		$preview = Runner::preview( $fixer, $fixer->sanitize_choices( $this->raw() ) );
		$this->assertSame( array(), $preview['validation'] );

		$choices = $this->apply( $this->raw() );
		$this->assertTrue( \ReadinessIndex\Fixers\Canon::same( $choices, State::get( 'entity-graph' ) ) );
		$change = \ReadinessIndex\Changes\Log::latest_applied( 'entity-graph' )['id'];
		Runner::undo( $change, 1 );
		$this->next_request();
		$this->assertSame( '', $this->head( home_url( '/' ) ) );
	}

	public function test_the_validator_finds_a_duplicate_a_dangling_reference_and_a_missing_type(): void {
		$this->assertSame( array( 'no_graph' ), GraphValidator::problems( array() ) );
		$bad      = array(
			'@graph' => array(
				array(
					'@type' => 'Organization',
					'@id'   => 'a',
				),
				array(
					'@type' => 'Organization',
					'@id'   => 'a',
				),
				array(
					'@type'     => 'WebSite',
					'@id'       => 'b',
					'publisher' => array( '@id' => 'missing' ),
				),
				array( '@id' => 'c' ),
			),
		);
		$problems = GraphValidator::problems( $bad );
		$this->assertContains( 'duplicate_id: a', $problems );
		$this->assertContains( 'dangling_reference: missing', $problems );
		$this->assertContains( 'missing_type: c', $problems );
	}
}
