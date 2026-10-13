<?php
/**
 * The pages the entity graph is drawn for.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Fixers\Content;

use ReadinessIndex\Fixers\Runtime\Dates;
use ReadinessIndex\Fixers\State;

/**
 * A page as the graph logic wants it: its address, its kind, its title, the real hierarchy above it
 * and, when the dates fixer is on, its honest dates. The breadcrumb comes from the page's own
 * ancestors and the site's posts page, never from a guess.
 */
final class GraphPages {

	/**
	 * The home page.
	 *
	 * @return array<string,mixed>
	 */
	public static function home(): array {
		return array(
			'url'   => home_url( '/' ),
			'kind'  => 'home',
			'title' => (string) get_bloginfo( 'name' ),
		);
	}

	/**
	 * One post as a page.
	 *
	 * @param \WP_Post $post A published post or page.
	 * @return array<string,mixed>
	 */
	public static function from_post( \WP_Post $post ): array {
		if ( (int) get_option( 'page_on_front' ) === (int) $post->ID && 'page' === get_option( 'show_on_front' ) ) {
			return self::home();
		}
		$crumbs = array();
		if ( 'page' === $post->post_type ) {
			foreach ( array_reverse( get_post_ancestors( $post ) ) as $ancestor ) {
				$crumbs[] = self::crumb( (int) $ancestor );
			}
		} elseif ( (int) get_option( 'page_for_posts' ) > 0 ) {
			$blog = (int) get_option( 'page_for_posts' );
			foreach ( array_reverse( get_post_ancestors( $blog ) ) as $ancestor ) {
				$crumbs[] = self::crumb( (int) $ancestor );
			}
			$crumbs[] = self::crumb( $blog );
		}
		$page = array(
			'url'        => (string) get_permalink( $post ),
			'kind'       => 'page' === $post->post_type ? 'page' : 'post',
			'title'      => html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' ),
			'breadcrumb' => array_values( array_filter( $crumbs ) ),
		);
		if ( null !== State::get( 'dates' ) ) {
			$dates = Dates::for_post( $post );
			if ( null !== $dates ) {
				$page['dates'] = array(
					'datePublished' => $dates['datePublished'],
					'dateModified'  => $dates['dateModified'],
				);
			}
		}
		return $page;
	}

	/**
	 * One step of a breadcrumb.
	 *
	 * @param int $id A page id.
	 * @return array{name:string,url:string}|null
	 */
	private static function crumb( int $id ): ?array {
		$url = get_permalink( $id );
		if ( ! is_string( $url ) ) {
			return null;
		}
		return array(
			'name' => html_entity_decode( wp_strip_all_tags( get_the_title( $id ) ), ENT_QUOTES, 'UTF-8' ),
			'url'  => $url,
		);
	}

	/**
	 * The page being requested, or null when the graph is not drawn for it (archives, search,
	 * a missing page, anything that is not a page of the site's own content).
	 *
	 * @return array<string,mixed>|null
	 */
	public static function current(): ?array {
		if ( is_front_page() ) {
			return self::home();
		}
		if ( is_home() ) {
			$blog = (int) get_option( 'page_for_posts' );
			$post = $blog > 0 ? get_post( $blog ) : null;
			return $post instanceof \WP_Post && 'publish' === $post->post_status ? self::from_post( $post ) : null;
		}
		if ( is_singular() ) {
			$post = get_queried_object();
			if ( $post instanceof \WP_Post && 'publish' === $post->post_status && '' === $post->post_password ) {
				return self::from_post( $post );
			}
		}
		return null;
	}

	/**
	 * A few pages for the preview: the home page, the About and Contact pages, and one page below
	 * another so the trail is shown.
	 *
	 * @param int[] $ids Page ids the owner named.
	 * @return array<int,array<string,mixed>>
	 */
	public static function sample( array $ids ): array {
		$pages = array( self::home() );
		$seen  = array();
		foreach ( $ids as $id ) {
			$post = $id > 0 ? get_post( $id ) : null;
			if ( $post instanceof \WP_Post && 'publish' === $post->post_status ) {
				$seen[ $post->ID ] = true;
				$pages[]           = self::from_post( $post );
			}
		}
		$deep = get_posts(
			array(
				'post_type'           => 'page',
				'post_status'         => 'publish',
				'post_parent__not_in' => array( 0 ),
				'numberposts'         => 1,
			)
		);
		$more = array() !== $deep ? $deep : get_posts(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
				'numberposts' => 1,
			)
		);
		foreach ( $more as $post ) {
			if ( ! isset( $seen[ $post->ID ] ) ) {
				$pages[] = self::from_post( $post );
			}
		}
		return $pages;
	}
}
