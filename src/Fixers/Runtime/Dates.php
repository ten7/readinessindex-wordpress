<?php
/**
 * Publishing the dates the fixer works out.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Fixers\Runtime;

use ReadinessIndex\Fixers\Content\DateItems;
use ReadinessIndex\Fixers\Logic;
use ReadinessIndex\Fixers\SeoTools;

/**
 * Sets `lastmod` in the sitemap and `datePublished` and `dateModified` in the structured data
 * from when the content really changed. It does nothing where an SEO plugin owns the sitemap or
 * the graph by itself: the same dates go through that plugin's own filters, keyed by the same
 * facts, and a second set is never printed. It never rewrites a date stored in WordPress.
 */
final class Dates {

	/**
	 * Adds the hooks. Each tool's filters are added whether or not the tool is active: a filter
	 * on a hook nobody fires costs nothing, and it needs no guess at the tool's version.
	 *
	 * @param array<string,mixed> $choices The stored choices.
	 */
	public static function register( array $choices ): void {
		unset( $choices );
		add_action( 'post_updated', array( self::class, 'record_change' ), 10, 3 );
		add_filter( 'wp_sitemaps_posts_entry', array( self::class, 'core_sitemap_entry' ), 10, 2 );
		add_filter( 'wpseo_sitemap_entry', array( self::class, 'tool_sitemap_entry' ), 10, 3 );
		add_filter( 'rank_math/sitemap/entry', array( self::class, 'tool_sitemap_entry' ), 10, 3 );
		add_filter( 'wpseo_schema_webpage', array( self::class, 'yoast_webpage' ), 10, 2 );
		add_filter( 'rank_math/json_ld', array( self::class, 'rank_math_json_ld' ), 99, 2 );
	}

	/**
	 * The dates for one post, or null when it gets none (a draft, a scheduled post, a post with
	 * no published date).
	 *
	 * @param \WP_Post $post A post.
	 * @return array{datePublished:string,dateModified:string,lastmod:string}|null
	 */
	public static function for_post( \WP_Post $post ): ?array {
		$ev   = Logic\Dates::evaluate(
			array(
				'site'             => array(),
				'tooling'          => array(),
				'files'            => array(),
				'platform_sitemap' => 'core',
				'now'              => DateItems::now(),
				'items'            => array( DateItems::item( $post ) ),
				'state'            => null,
				'choices'          => array(),
			)
		);
		$item = $ev['output']['items'][0];
		return null === $item['datePublished'] ? null : array(
			'datePublished' => $item['datePublished'],
			'dateModified'  => $item['dateModified'],
			'lastmod'       => $item['lastmod'],
		);
	}

	/**
	 * Records when the title, body or excerpt last really changed.
	 *
	 * Called as a post is updated, with both versions in hand, so it can tell a real edit from
	 * a save that touched nothing (a deploy script, a bulk update, a plugin re-saving). A real
	 * edit is stamped with the time WordPress modified it. A save that changed nothing keeps the
	 * time of the last real change, or, for a post this plugin has never seen change, the
	 * modified time the post had before the save.
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $after   The post as saved.
	 * @param \WP_Post $before  The post as it was.
	 */
	public static function record_change( $post_id, $after, $before ): void {
		if ( ! $after instanceof \WP_Post || ! $before instanceof \WP_Post ) {
			return;
		}
		$changed = $after->post_title !== $before->post_title || $after->post_content !== $before->post_content || $after->post_excerpt !== $before->post_excerpt;
		if ( $changed ) {
			$when = DateItems::iso( (string) $after->post_modified_gmt );
		} elseif ( '' === (string) get_post_meta( (int) $post_id, DateItems::META, true ) ) {
			$when = DateItems::iso( (string) $before->post_modified_gmt );
		} else {
			return;
		}
		if ( null !== $when ) {
			update_post_meta( (int) $post_id, DateItems::META, $when );
		}
	}

	/**
	 * Core's sitemap entry for a post.
	 *
	 * @param array<string,mixed> $entry The entry.
	 * @param \WP_Post            $post  The post.
	 * @return array<string,mixed>
	 */
	public static function core_sitemap_entry( $entry, $post ) {
		if ( ! is_array( $entry ) || ! $post instanceof \WP_Post || self::tool_owns_sitemap() ) {
			return $entry;
		}
		$dates = self::for_post( $post );
		if ( null !== $dates ) {
			$entry['lastmod'] = $dates['lastmod'];
		}
		return $entry;
	}

	/**
	 * Yoast's and Rank Math's sitemap entry for a post: their own `mod` key.
	 *
	 * @param array<string,mixed> $url    The entry.
	 * @param string              $type   The kind of entry.
	 * @param mixed               $source The post, when the entry is for one.
	 * @return array<string,mixed>
	 */
	public static function tool_sitemap_entry( $url, $type = '', $source = null ) {
		unset( $type );
		if ( ! is_array( $url ) || ! $source instanceof \WP_Post ) {
			return $url;
		}
		$dates = self::for_post( $source );
		if ( null !== $dates ) {
			$url['mod'] = $dates['lastmod'];
		}
		return $url;
	}

	/**
	 * Yoast's WebPage piece.
	 *
	 * @param array<string,mixed> $data    The piece.
	 * @param mixed               $context Yoast's context, which holds the post.
	 * @return array<string,mixed>
	 */
	public static function yoast_webpage( $data, $context = null ) {
		$post = is_object( $context ) && isset( $context->post ) && $context->post instanceof \WP_Post ? $context->post : null;
		if ( ! is_array( $data ) || null === $post ) {
			return $data;
		}
		$dates = self::for_post( $post );
		if ( null !== $dates ) {
			$data['datePublished'] = $dates['datePublished'];
			$data['dateModified']  = $dates['dateModified'];
		}
		return $data;
	}

	/**
	 * Rank Math's graph: its WebPage node.
	 *
	 * @param array<string,mixed> $data   The graph.
	 * @param mixed               $jsonld Rank Math's JSON-LD object.
	 * @return array<string,mixed>
	 */
	public static function rank_math_json_ld( $data, $jsonld = null ) {
		unset( $jsonld );
		$post = get_post();
		if ( ! is_array( $data ) || ! $post instanceof \WP_Post || ! isset( $data['WebPage'] ) || ! is_array( $data['WebPage'] ) ) {
			return $data;
		}
		$dates = self::for_post( $post );
		if ( null !== $dates ) {
			$data['WebPage']['datePublished'] = $dates['datePublished'];
			$data['WebPage']['dateModified']  = $dates['dateModified'];
		}
		return $data;
	}

	/**
	 * Whether Yoast or Rank Math produces the sitemap, so core's is not the one served.
	 */
	private static function tool_owns_sitemap(): bool {
		return array() !== array_intersect( SeoTools::active(), array( 'yoast', 'rank_math' ) );
	}
}
