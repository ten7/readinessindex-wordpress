<?php
/**
 * Whether a page's content needs JavaScript to appear.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Diagnostics;

use ReadinessIndex\Fixers\Content\PageSample;
use ReadinessIndex\Http\SiteFetcher;
use ReadinessIndex\Strings;

/**
 * AIR-2.1 and AIR-2.2. For a few of the site's own pages, compares the text in the raw HTML (what
 * a crawler that does not run scripts reads) with the text of the post's own content, and names the
 * likely causes it can see in the markup. It never runs a script, never fixes anything and asks no
 * host but this site's own.
 */
final class JsOnly {

	/** The share of the post's own text that must be in the raw HTML. */
	const MIN_SHARE = 0.5;

	/** A page with less text than this in its source is too short to judge. */
	const MIN_CHARS = 200;

	/**
	 * Runs the comparison over a few pages.
	 */
	public static function run(): Result {
		$result  = new Result( 'js_only' );
		$posts   = get_posts(
			array(
				'post_type'        => array( 'page', 'post' ),
				'post_status'      => 'publish',
				'has_password'     => false,
				'numberposts'      => 5,
				'orderby'          => 'modified',
				'order'            => 'DESC',
				'suppress_filters' => true,
			)
		);
		$flagged = 0;
		$causes  = array();
		$looked  = 0;
		foreach ( $posts as $post ) {
			$own = self::words( (string) apply_filters( 'the_content', $post->post_content ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's own filter.
			if ( strlen( $own ) < self::MIN_CHARS ) {
				continue;
			}
			$got = SiteFetcher::get( (string) get_permalink( $post ) );
			if ( null === $got || 200 !== $got['status'] ) {
				continue;
			}
			++$looked;
			$raw   = self::words( self::strip_chrome( $got['body'] ) );
			$share = self::share( $own, $raw );
			if ( $share < self::MIN_SHARE ) {
				++$flagged;
				$result->items[] = Strings::get(
					'diagnostic.js_only.row',
					array(
						'path'    => PageSample::path( (string) get_permalink( $post ) ),
						'percent' => (int) round( $share * 100 ),
					)
				);
				$causes          = array_merge( $causes, self::causes( $got['body'] ) );
			}
		}
		$result->clean = 0 === $flagged;
		if ( 0 === $looked ) {
			$result->clean   = false;
			$result->lines[] = Strings::get( 'diagnostic.js_only.nothing_to_compare' );
		} elseif ( 0 === $flagged ) {
			$result->lines[] = Strings::get( 'diagnostic.js_only.result.clean' );
		} else {
			$result->lines[] = Strings::get( 'diagnostic.js_only.result.flagged', array( 'count' => $flagged ) );
			foreach ( array_unique( $causes ) as $cause ) {
				$result->lines[] = Strings::get( 'diagnostic.js_only.cause.' . $cause );
			}
		}
		return $result;
	}

	/**
	 * The share of the post's own words found in the raw page, from 0 to 1.
	 *
	 * @param string $own The post's own text, normalized.
	 * @param string $raw The raw page's text, normalized.
	 */
	public static function share( string $own, string $raw ): float {
		$split = preg_split( '/\s+/', $own, -1, PREG_SPLIT_NO_EMPTY );
		$words = array_values( array_unique( false === $split ? array() : $split ) );
		if ( array() === $words ) {
			return 1.0;
		}
		$split = preg_split( '/\s+/', $raw, -1, PREG_SPLIT_NO_EMPTY );
		$have  = array_flip( false === $split ? array() : $split );
		$hit   = 0;
		foreach ( $words as $word ) {
			if ( isset( $have[ $word ] ) ) {
				++$hit;
			}
		}
		return $hit / count( $words );
	}

	/**
	 * Text normalized for comparison: tags and entities removed, lower-cased, punctuation gone.
	 *
	 * @param string $html HTML or text.
	 */
	public static function words( string $html ): string {
		$text = wp_strip_all_tags( $html );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = mb_strtolower( $text, 'UTF-8' );
		return trim( preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text ) ?? '' );
	}

	/**
	 * A page's markup with the parts that are not its content removed.
	 *
	 * @param string $html A page.
	 */
	private static function strip_chrome( string $html ): string {
		return (string) preg_replace( '#<(script|style|noscript|template)\b[^>]*>.*?</\1>#is', ' ', $html );
	}

	/**
	 * The likely causes visible in the markup.
	 *
	 * @param string $html The raw page.
	 * @return string[] Keys of `diagnostic.js_only.cause.*`.
	 */
	public static function causes( string $html ): array {
		$out = array();
		if ( preg_match( '/<div[^>]+id=["\'](root|app|__next|__nuxt)["\'][^>]*>\s*<\/div>/i', $html ) || false !== strpos( $html, 'data-reactroot' ) || false !== strpos( $html, 'ng-app' ) ) {
			$out[] = 'app_shell';
		}
		if ( false !== strpos( $html, '__NEXT_DATA__' ) || false !== strpos( $html, '__NUXT__' ) ) {
			$out[] = 'framework';
		}
		if ( preg_match( '/<noscript[^>]*>.{200,}<\/noscript>/is', $html ) ) {
			$out[] = 'noscript_fallback';
		}
		$elementor = false !== stripos( $html, 'elementor-widget' ) && false !== stripos( $html, 'data-settings' );
		$divi      = false !== stripos( $html, 'et_pb_' ) && false !== stripos( $html, 'data-animation' );
		if ( $elementor || $divi ) {
			$out[] = 'builder_animation';
		}
		if ( preg_match( '/class=["\'][^"\']*(lazyload|lazy-load|js-lazy)[^"\']*["\']/i', $html ) ) {
			$out[] = 'lazy_content';
		}
		return $out;
	}
}
