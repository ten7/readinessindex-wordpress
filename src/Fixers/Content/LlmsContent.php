<?php
/**
 * The pages an llms.txt lists, chosen from the site's own content.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Fixers\Content;

/**
 * Pages and recent posts that are public, published and meant to be found: never a draft, a
 * private or password-protected page, a page an SEO plugin marks `noindex`, or one the site's own
 * sitemap query leaves out. Nothing is guessed: each line is a title, a permalink and the page's
 * own excerpt.
 */
final class LlmsContent {

	const PAGES_LIMIT = 200;
	const POSTS_LIMIT = 100;

	/**
	 * The content as the logic wants it: ordered sections of items, and the sitemap's size.
	 *
	 * @return array{sections:array<int,array{name:string,items:array<int,array<string,string>>}>,sitemap_count:int}
	 */
	public static function collect(): array {
		$sections = array();
		foreach ( array(
			'page' => self::PAGES_LIMIT,
			'post' => self::POSTS_LIMIT,
		) as $type => $limit ) {
			$object = get_post_type_object( $type );
			if ( null === $object ) {
				continue;
			}
			$items = array();
			foreach ( self::posts( $type, $limit ) as $post ) {
				$items[] = array(
					'title'       => html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' ),
					'url'         => (string) get_permalink( $post ),
					'description' => self::description( $post ),
				);
			}
			$sections[] = array(
				'name'  => (string) $object->labels->name,
				'items' => $items,
			);
		}
		return array(
			'sections'      => $sections,
			'sitemap_count' => self::sitemap_count(),
		);
	}

	/**
	 * Listable posts of one type.
	 *
	 * @param string $type  Post type.
	 * @param int    $limit How many at most.
	 * @return \WP_Post[]
	 */
	private static function posts( string $type, int $limit ): array {
		$args = array(
			'post_type'              => $type,
			'post_status'            => 'publish',
			'has_password'           => false,
			'posts_per_page'         => $limit,
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'meta_query'             => self::not_noindex(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded by posts_per_page, and rebuilt only when content is published.
		);
		if ( 'page' === $type ) {
			$args['orderby'] = array(
				'menu_order' => 'ASC',
				'title'      => 'ASC',
			);
		} else {
			$args['orderby'] = 'date';
			$args['order']   = 'DESC';
		}
		// Whatever the site already leaves out of its own sitemap, this leaves out too. The exclusion
		// is applied after the query, with room left in it for what is dropped, so the limit holds.
		$sitemap = apply_filters( 'wp_sitemaps_posts_query_args', array( 'post_type' => $type ), $type ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's own hook.
		$left    = is_array( $sitemap ) && ! empty( $sitemap['post__not_in'] ) ? array_map( 'absint', (array) $sitemap['post__not_in'] ) : array();

		$args['posts_per_page'] = $limit + count( $left );
		$query                  = new \WP_Query( $args );
		$posts                  = array();
		foreach ( $query->posts as $post ) {
			if ( $post instanceof \WP_Post && ! in_array( $post->ID, $left, true ) ) {
				$posts[] = $post;
			}
		}
		return array_slice( $posts, 0, $limit );
	}

	/**
	 * A meta query that drops anything an SEO plugin marked noindex.
	 *
	 * @return array<int|string,mixed>
	 */
	private static function not_noindex(): array {
		return array(
			'relation' => 'AND',
			array(
				'relation' => 'OR',
				array(
					'key'     => '_yoast_wpseo_meta-robots-noindex',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => '_yoast_wpseo_meta-robots-noindex',
					'value'   => '1',
					'compare' => '!=',
				),
			),
			array(
				'relation' => 'OR',
				array(
					'key'     => 'rank_math_robots',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => 'rank_math_robots',
					'value'   => 'noindex',
					'compare' => 'NOT LIKE',
				),
			),
			array(
				'relation' => 'OR',
				array(
					'key'     => '_seopress_robots_index',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => '_seopress_robots_index',
					'value'   => 'yes',
					'compare' => '!=',
				),
			),
		);
	}

	/**
	 * The page's own description: its excerpt, or the start of its text.
	 *
	 * @param \WP_Post $post A post.
	 */
	private static function description( \WP_Post $post ): string {
		$text = '' !== trim( $post->post_excerpt ) ? $post->post_excerpt : wp_trim_words( strip_shortcodes( $post->post_content ), 25, '' );
		return html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * How many URLs the site's sitemap lists, approximately: its published, listable content
	 * and its public taxonomy archives. Used only to say whether the file tracks the sitemap.
	 */
	private static function sitemap_count(): int {
		$count = 0;
		foreach ( get_post_types( array( 'public' => true ) ) as $type ) {
			if ( 'attachment' === $type ) {
				continue;
			}
			$counts = wp_count_posts( $type );
			$count += isset( $counts->publish ) ? (int) $counts->publish : 0;
		}
		return $count;
	}
}
