<?php
/**
 * Which pages of the site a diagnostic looks at.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Fixers\Content;

/**
 * The home page and a handful of the site's own published, public pages: enough to see how the
 * site emits a directive without crawling it. Nothing here leaves the site.
 */
final class PageSample {

	/**
	 * Home, then the most recently modified public pages and posts.
	 *
	 * @param int $limit How many URLs at most, the home page included.
	 * @return string[]
	 */
	public static function urls( int $limit = 8 ): array {
		$urls  = array( home_url( '/' ) );
		$posts = get_posts(
			array(
				'post_type'        => array( 'page', 'post' ),
				'post_status'      => 'publish',
				'has_password'     => false,
				'numberposts'      => max( 0, $limit - 1 ),
				'orderby'          => 'modified',
				'order'            => 'DESC',
				'suppress_filters' => true,
			)
		);
		foreach ( $posts as $post ) {
			$url = get_permalink( $post );
			if ( is_string( $url ) && ! in_array( $url, $urls, true ) ) {
				$urls[] = $url;
			}
		}
		return array_slice( $urls, 0, $limit );
	}

	/**
	 * The path and query of a URL on this site, as the vectors write a page's `path`.
	 *
	 * @param string $url A URL on this site.
	 */
	public static function path( string $url ): string {
		$parts = wp_parse_url( $url );
		$path  = is_array( $parts ) && isset( $parts['path'] ) ? $parts['path'] : '/';
		$home  = wp_parse_url( home_url( '/' ) );
		$base  = is_array( $home ) && isset( $home['path'] ) ? rtrim( $home['path'], '/' ) : '';
		if ( '' !== $base && 0 === strpos( $path, $base ) ) {
			$path = substr( $path, strlen( $base ) );
		}
		$path = '' === $path ? '/' : $path;
		// Plain permalinks tell one page from another only by the query string.
		return is_array( $parts ) && ! empty( $parts['query'] ) ? $path . '?' . $parts['query'] : $path;
	}
}
