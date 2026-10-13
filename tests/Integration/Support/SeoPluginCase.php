<?php
/**
 * Base class for tests against a real SEO plugin.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Tests\Integration\Support;

use ReadinessIndex\Fixers\Impl\EntityGraphFixer;
use ReadinessIndex\Fixers\Registry;
use ReadinessIndex\Fixers\Runner;
use ReadinessIndex\Fixers\SeoTools;

/**
 * The shared steps of the Yoast SEO and Rank Math tests: a logo in the media library, the owner's
 * choices, applying them, and reading the structured data the plugin really prints. Each test
 * skips itself unless the plugin was loaded (`READINESS_INDEX_TEST_SEO`, see the bootstrap).
 */
abstract class SeoPluginCase extends IntegrationCase {

	/**
	 * The attachment id of a logo.
	 *
	 * @var int
	 */
	protected $logo;

	/**
	 * Set up: the plugin's own detection, not a forced one.
	 */
	public function set_up() {
		parent::set_up();
		SeoTools::use_active( null );
		update_option( 'blogname', 'Example Co' );
		// A real 1 by 1 PNG, so the SEO plugin can read an image from the media library.
		$png        = (string) base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- A fixed test image.
		$upload     = wp_upload_bits( 'logo-' . wp_rand() . '.png', null, $png );
		$this->logo = (int) wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => 'Logo',
				'post_status'    => 'inherit',
			),
			$upload['file']
		);
		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $this->logo, wp_generate_attachment_metadata( $this->logo, $upload['file'] ) );
	}

	/**
	 * The owner's choices, as a form sends them.
	 *
	 * @return array<string,mixed>
	 */
	protected function raw(): array {
		return array(
			'organization'    => array(
				'legal_name' => 'Example Company, LLC',
				'logo'       => (string) $this->logo,
				'address'    => array( 'street' => '12 Main Street' ),
				'same_as'    => "https://www.wikidata.org/wiki/Q42\nhttps://ror.org/05gq02987",
			),
			'search_template' => 'https://example.com/?s={search_term_string}',
		);
	}

	/**
	 * Applies the choices through the runner.
	 *
	 * @return array<string,mixed> The runner's result.
	 */
	protected function apply_choices(): array {
		$fixer = new EntityGraphFixer();
		Registry::use_fixers( array( $fixer ) );
		$choices = $fixer->sanitize_choices( $this->raw() );
		$result  = Runner::apply( $fixer, $choices, Runner::preview( $fixer, $choices )['hash'], 1 );
		$this->assertSame( 'applied', $result['outcome'] );
		$this->next_request();
		return $result;
	}

	/**
	 * The JSON-LD documents the home page prints, in order, and whether one is this plugin's own.
	 *
	 * @param string $url A URL on the site.
	 * @return array{docs:array<int,array<string,mixed>>,own:bool}
	 */
	protected function printed( string $url ): array {
		$this->go_to( $url );
		ob_start();
		do_action( 'wp_head' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's hook.
		$head = (string) ob_get_clean();
		$docs = array();
		if ( preg_match_all( '#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#s', $head, $m ) ) {
			foreach ( $m[1] as $json ) {
				$docs[] = json_decode( trim( $json ), true );
			}
		}
		return array(
			'docs' => $docs,
			'own'  => false !== strpos( $head, 'readiness-index-graph' ),
		);
	}

	/**
	 * Every node of a set of documents.
	 *
	 * @param array<int,array<string,mixed>> $docs Documents.
	 * @return array<int,array<string,mixed>>
	 */
	protected function nodes( array $docs ): array {
		$out = array();
		foreach ( $docs as $doc ) {
			foreach ( (array) ( $doc['@graph'] ?? array() ) as $node ) {
				$out[] = $node;
			}
		}
		return $out;
	}

	/**
	 * Asserts the graph is the one the ticket wants: one Organization, one WebSite with a
	 * search action, no `@id` defined twice, and nothing printed by this plugin.
	 *
	 * @param array{docs:array<int,array<string,mixed>>,own:bool} $printed What was printed.
	 */
	protected function assert_one_graph( array $printed ): void {
		$nodes = $this->nodes( $printed['docs'] );
		$types = array();
		$ids   = array();
		$sites = 0;
		foreach ( $nodes as $node ) {
			foreach ( (array) ( $node['@type'] ?? array() ) as $type ) {
				$types[] = $type;
			}
			if ( isset( $node['@id'] ) ) {
				$ids[] = $node['@id'];
			}
			if ( in_array( 'WebSite', (array) $node['@type'], true ) && ! empty( $node['potentialAction'] ) ) {
				++$sites;
			}
		}
		$this->assertSame( 1, count( array_keys( $types, 'Organization', true ) ), 'exactly one Organization' );
		$this->assertSame( 1, count( array_keys( $types, 'WebSite', true ) ), 'exactly one WebSite' );
		$this->assertSame( 1, $sites, 'the WebSite has a search action' );
		$this->assertSame( count( $ids ), count( array_unique( $ids ) ), 'no @id is defined twice' );
		$this->assertFalse( $printed['own'], 'this plugin prints no block of its own' );
	}
}
