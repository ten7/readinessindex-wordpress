<?php
/**
 * Honest published and modified dates.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Fixers\Impl;

use ReadinessIndex\Fixers\Content\DateItems;
use ReadinessIndex\Fixers\Logic\Dates;
use ReadinessIndex\Fixers\SeoTools;
use ReadinessIndex\Http\SiteFetcher;

/**
 * AIR-4.8 and AIR-5.4: the sitemap's `lastmod` and the schema `dateModified` follow when the
 * content really changed, never today's date and never a date this plugin wrote into WordPress.
 * It does not touch a stored modified date; it only changes what is published.
 */
final class DatesFixer extends AbstractFixer {

	/**
	 * The id the contract names.
	 */
	public function id(): string {
		return 'dates';
	}

	/**
	 * The tests it moves.
	 *
	 * @return string[]
	 */
	public function checks(): array {
		return array( 'AIR-4.8', 'AIR-5.4' );
	}

	/**
	 * Facts: the posts' own times (GMT), the clock, and who owns the sitemap.
	 *
	 * @return array<string,mixed>
	 */
	public function detect(): array {
		$core = (bool) apply_filters( 'wp_sitemaps_enabled', '0' !== (string) get_option( 'blog_public', '1' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's own hook.
		return array(
			'site'             => $this->site(),
			'tooling'          => SeoTools::active(),
			'files'            => array(),
			'now'              => DateItems::now(),
			'platform_sitemap' => $core ? 'core' : 'none',
			'items'            => DateItems::collect(),
		);
	}

	/**
	 * The pure logic.
	 *
	 * @param array<string,mixed> $given Facts, state and choices.
	 * @return array<string,mixed>
	 */
	public function evaluate( array $given ): array {
		return Dates::evaluate( $given );
	}

	/**
	 * Flags what the dates fixer corrects in what WordPress recorded: a modified date before the
	 * published date, and one in the future. They are flagged, shown and corrected only in what is
	 * published; the date stored in WordPress is never rewritten.
	 *
	 * @param array<string,mixed> $ev    The evaluation.
	 * @param array<string,mixed> $given The facts.
	 * @return array<string,mixed>
	 */
	protected function annotate( array $ev, array $given ): array {
		$flags = array();
		$now   = Dates::utc( (string) ( $given['now'] ?? '' ) );
		foreach ( (array) ( $given['items'] ?? array() ) as $item ) {
			$published = Dates::utc( $item['published'] ?? null );
			$recorded  = Dates::utc( $item['modified_recorded'] ?? null );
			if ( null === $published || null === $recorded || null === $now || $published > $now ) {
				continue;
			}
			if ( $recorded < $published ) {
				$flags[] = array(
					'path'   => $item['path'],
					'reason' => 'modified_before_published',
				);
			} elseif ( $recorded > $now ) {
				$flags[] = array(
					'path'   => $item['path'],
					'reason' => 'modified_in_future',
				);
			}
		}
		$ev['flags'] = $flags;
		return $ev;
	}

	/**
	 * Looks at the sitemap the site serves and compares each entry's `lastmod` with the dates
	 * worked out.
	 *
	 * @param array<string,mixed> $ev The applied evaluation.
	 * @return array{live:bool|null,reason:string}
	 */
	public function verify( array $ev ): array {
		$sitemap = $ev['findings']['channels']['sitemap'] ?? 'core';
		if ( 'none' === $sitemap ) {
			return array(
				'live'   => null,
				'reason' => 'unreachable',
			);
		}
		$urls = 'core' === $sitemap
			? array( '/wp-sitemap-posts-post-1.xml', '/wp-sitemap-posts-page-1.xml' )
			: array( '/post-sitemap.xml', '/page-sitemap.xml' );

		$want = array();
		foreach ( $ev['output']['items'] as $item ) {
			if ( null !== $item['lastmod'] ) {
				$want[ $item['url'] ] = $item['lastmod'];
			}
		}
		$checked = 0;
		foreach ( $urls as $path ) {
			$got = SiteFetcher::get( home_url( $path ) );
			if ( null === $got || 200 !== $got['status'] ) {
				continue;
			}
			if ( ! preg_match_all( '#<url>\s*<loc>([^<]+)</loc>(?:\s*<lastmod>([^<]*)</lastmod>)?#', $got['body'], $m, PREG_SET_ORDER ) ) {
				continue;
			}
			foreach ( $m as $entry ) {
				$loc = html_entity_decode( $entry[1], ENT_QUOTES, 'UTF-8' );
				if ( ! isset( $want[ $loc ] ) ) {
					continue;
				}
				++$checked;
				if ( ! isset( $entry[2] ) || $entry[2] !== $want[ $loc ] ) {
					return array(
						'live'   => false,
						'reason' => 'different',
					);
				}
			}
		}
		return 0 === $checked ? array(
			'live'   => null,
			'reason' => 'unreachable',
		) : array(
			'live'   => true,
			'reason' => '',
		);
	}
}
