<?php
/**
 * The facts the entity graph is built from, read from the stored choices.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Fixers\Runtime;

use ReadinessIndex\Fixers\Logic\EntityGraph;
use ReadinessIndex\Fixers\State;

/**
 * One place that turns the stored choices into the organization node, the search action and the
 * About and Contact addresses, for the plugin's own graph and for the two SEO tools' filters alike,
 * so the facts cannot differ between them.
 */
final class Facts {

	/**
	 * The stored choices, or null when the fixer is off.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function choices(): ?array {
		return State::get( 'entity-graph' );
	}

	/**
	 * The site's address without a trailing slash.
	 */
	public static function site_url(): string {
		return untrailingslashit( home_url() );
	}

	/**
	 * The address of a page the owner named, when it is published.
	 *
	 * @param string $key `about_page` or `contact_page`.
	 */
	public static function page_url( string $key ): ?string {
		$choices = self::choices();
		$id      = (int) ( $choices[ $key ] ?? 0 );
		if ( $id <= 0 || 'publish' !== get_post_status( $id ) ) {
			return null;
		}
		$url = get_permalink( $id );
		return is_string( $url ) ? $url : null;
	}

	/**
	 * The organization's node without its type and id, which is what a tool is handed.
	 *
	 * @return array<string,mixed>|null Null when the fixer is off.
	 */
	public static function organization(): ?array {
		$choices = self::choices();
		if ( null === $choices ) {
			return null;
		}
		return EntityGraph::organization( self::site_url(), (string) get_bloginfo( 'name' ), (array) ( $choices['organization'] ?? array() ), self::page_url( 'contact_page' ) );
	}

	/**
	 * The owner's search address when it is usable, else null.
	 */
	public static function search_template(): ?string {
		$choices  = self::choices();
		$template = $choices['search_template'] ?? null;
		if ( null === $choices || ! is_string( $template ) ) {
			return null;
		}
		list( , $ok ) = EntityGraph::search_action( $template );
		return $ok && EntityGraph::has( $template ) ? $template : null;
	}

	/**
	 * The `potentialAction` value for the WebSite node, or null.
	 *
	 * @return array<int,array<string,mixed>>|null
	 */
	public static function search_action(): ?array {
		$template = self::search_template();
		return null === $template ? null : EntityGraph::search_action( $template )[0];
	}
}
