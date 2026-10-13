<?php
/**
 * Printing the entity graph, or handing it to the tool that prints one.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Fixers\Runtime;

use ReadinessIndex\Fixers\Content\GraphPages;
use ReadinessIndex\Fixers\Integration;
use ReadinessIndex\Fixers\Logic;
use ReadinessIndex\Fixers\SeoTools;

/**
 * One `application/ld+json` block per page, built from stored facts on each request: no query
 * beyond the ones the page already makes. With any SEO plugin that prints its own graph active,
 * this prints nothing and the facts go to the plugin's own filters, so a page never carries two
 * graphs that disagree about who the organization is.
 */
final class EntityGraph {

	/**
	 * The JSON flags that keep a hostile title from closing the script element: angle brackets,
	 * ampersands and quotes become unicode escapes.
	 */
	const JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES;

	/**
	 * Adds the printer and each integrated tool's filters.
	 *
	 * @param array<string,mixed> $choices The stored choices.
	 */
	public static function register( array $choices ): void {
		add_action( 'wp_head', array( self::class, 'print_graph' ), 1 );
		foreach ( self::tools() as $tool ) {
			$tool->register( $choices );
		}
	}

	/**
	 * The integrations whose plugin is active and whose settings are where they should be.
	 *
	 * @return Integration\Tool[]
	 */
	public static function tools(): array {
		$active = SeoTools::active();
		$out    = array();
		foreach ( array(
			'yoast'     => new Integration\Yoast(),
			'rank_math' => new Integration\RankMath(),
		) as $key => $tool ) {
			if ( in_array( $key, $active, true ) && $tool->supported() ) {
				$out[] = $tool;
			}
		}
		return $out;
	}

	/**
	 * Whether a plugin that prints its own graph is active, so this prints none.
	 */
	public static function another_graph_is_printed(): bool {
		return array() !== array_intersect( SeoTools::active(), SeoTools::GRAPH_OWNERS );
	}

	/**
	 * Builds the graph for a page from the stored facts.
	 *
	 * @param array<string,mixed> $choices The stored choices.
	 * @param array<string,mixed> $page    The page.
	 * @return array<string,mixed>
	 */
	public static function document( array $choices, array $page ): array {
		list( $search ) = Logic\EntityGraph::search_action( isset( $choices['search_template'] ) && is_string( $choices['search_template'] ) ? $choices['search_template'] : null );
		$language       = (string) get_bloginfo( 'language' );
		return Logic\EntityGraph::page_graph(
			Facts::site_url(),
			(string) get_bloginfo( 'name' ),
			(array) ( $choices['organization'] ?? array() ),
			array(
				'about_url'   => Facts::page_url( 'about_page' ),
				'contact_url' => Facts::page_url( 'contact_page' ),
			),
			$page,
			$search,
			'' === $language ? null : $language
		);
	}

	/**
	 * Prints the block for the request.
	 */
	public static function print_graph(): void {
		$choices = Facts::choices();
		if ( null === $choices || self::another_graph_is_printed() ) {
			return;
		}
		$page = GraphPages::current();
		if ( null === $page ) {
			return;
		}
		$json = wp_json_encode( self::document( $choices, $page ), self::JSON_FLAGS );
		if ( ! is_string( $json ) ) {
			return;
		}
		// Printed by hand rather than with wp_print_inline_script_tag: on a theme without HTML5
		// script support WordPress wraps the body in a CDATA comment, which turns this JSON into
		// something no crawler can parse. The JSON flags above make a closing tag impossible, so
		// the body is safe to print as it is.
		echo '<script type="application/ld+json" class="readiness-index-graph">' . $json . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON encoded with the hex flags: no tag, ampersand or quote can survive in it.
	}
}
