<?php
/**
 * What the honest-dates fixer would publish.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Fixers\Logic;

/**
 * AIR-4.8 and AIR-5.4: sitemap `lastmod` and schema `dateModified` follow when the content
 * really changed. A bulk update, a migration or a deploy script moves the CMS's own modified
 * time without the content moving, and then both are noise that crawlers stop trusting.
 *
 * Every date is UTC, written `YYYY-MM-DDTHH:MM:SS+00:00`, and the current time is never used
 * as one: `now` is an input, so a test across a daylight-saving change gives the same answer
 * as one in July.
 */
final class Dates {

	const CLUSTER_PERCENT   = 60;
	const CLUSTER_MIN_PAGES = 5;

	/**
	 * Evaluates the fixer.
	 *
	 * @param array<string,mixed> $given Site facts.
	 * @return array<string,mixed> Evaluation.
	 */
	public static function evaluate( array $given ): array {
		$now = self::utc( $given['now'] );
		if ( null === $now ) {
			throw new \InvalidArgumentException( 'now is required' );
		}
		$items_out     = array();
		$baseline_out  = array();
		$skipped       = array();
		$touched       = array();
		$recorded_days = array();
		foreach ( $given['items'] as $item ) {
			$published = self::utc( $item['published'] ?? null );
			$recorded  = self::utc( $item['modified_recorded'] ?? null );
			$changed   = self::utc( $item['content_changed_at'] ?? null );
			if ( null !== $recorded ) {
				$recorded_days[] = $recorded->format( 'Y-m-d' );
			}
			// What is served with the fixer off: the CMS's own recorded values, as it wrote them.
			$baseline_out[] = array(
				'path'          => $item['path'],
				'url'           => $item['url'],
				'datePublished' => $item['published'] ?? null,
				'dateModified'  => $item['modified_recorded'] ?? null,
				'lastmod'       => $item['modified_recorded'] ?? null,
			);
			$left_out       = array(
				'path'          => $item['path'],
				'url'           => $item['url'],
				'datePublished' => null,
				'dateModified'  => null,
				'lastmod'       => null,
			);
			if ( null === $published ) {
				$skipped[]   = array(
					'path'   => $item['path'],
					'reason' => 'no_published_date',
				);
				$items_out[] = $left_out;
				continue;
			}
			if ( $published > $now ) {
				$skipped[]   = array(
					'path'   => $item['path'],
					'reason' => 'scheduled',
				);
				$items_out[] = $left_out;
				continue;
			}
			$real = $changed ?? $recorded ?? $published;
			if ( null !== $recorded && null !== $changed && $changed < $recorded ) {
				$touched[] = $item['path'];
			}
			// A modified time before the published time, or after now, is not believed.
			if ( $real < $published || $real > $now ) {
				$real = $published;
			}
			$items_out[] = array(
				'path'          => $item['path'],
				'url'           => $item['url'],
				'datePublished' => self::stamp( $published ),
				'dateModified'  => self::stamp( $real ),
				'lastmod'       => self::stamp( $real ),
			);
		}

		$counts = array_count_values( $recorded_days );
		$most   = array() === $counts ? 0 : max( $counts );
		$share  = array() === $recorded_days ? 0 : intdiv( $most * 100, count( $recorded_days ) );
		$tools  = (array) $given['tooling'];

		$findings = array(
			'channels'               => array(
				'jsonld'  => self::channel( $tools, array( 'yoast', 'rank_math', 'metatag' ), 'own' ),
				'sitemap' => self::channel( $tools, array( 'simple_xml_sitemap', 'yoast', 'rank_math' ), (string) $given['platform_sitemap'] ),
			),
			'clustered'              => count( $recorded_days ) >= self::CLUSTER_MIN_PAGES && $share > self::CLUSTER_PERCENT,
			'touched_without_change' => $touched,
			'skipped'                => $skipped,
		);

		$changes = array();
		foreach ( $baseline_out as $i => $before ) {
			$after = $items_out[ $i ];
			if ( $before === $after ) {
				continue;
			}
			$changes[] = array(
				'target' => $after['path'],
				'before' => self::dump( $before ),
				'after'  => self::dump( $after ),
			);
		}
		return array(
			'status'   => array() === $changes ? 'unchanged' : 'ready',
			'reason'   => array() === $changes ? 'nothing_to_change' : null,
			'findings' => $findings,
			'changes'  => $changes,
			'output'   => array( 'items' => $items_out ),
			'baseline' => array( 'items' => $baseline_out ),
			'paste'    => null,
		);
	}

	/**
	 * A date in UTC, or null for none. A value with no offset is read as UTC.
	 *
	 * @param string|null $value ISO 8601 text.
	 */
	public static function utc( ?string $value ): ?\DateTimeImmutable {
		if ( null === $value || '' === $value ) {
			return null;
		}
		try {
			return ( new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) ) )->setTimezone( new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/**
	 * The one form every date is written in.
	 *
	 * @param \DateTimeImmutable $moment A moment.
	 */
	public static function stamp( \DateTimeImmutable $moment ): string {
		return $moment->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s' ) . '+00:00';
	}

	/**
	 * The first of the tools that is active, else the default.
	 *
	 * @param string[] $tooling Active tools.
	 * @param string[] $order   Tools in order of preference.
	 * @param string   $fallback Used when none is active.
	 */
	private static function channel( array $tooling, array $order, string $fallback ): string {
		foreach ( $order as $tool ) {
			if ( in_array( $tool, $tooling, true ) ) {
				return $tool;
			}
		}
		return $fallback;
	}

	/**
	 * The three dates of an item as the preview shows them: a JSON object with a space after
	 * each colon and comma, which is how the vectors write it.
	 *
	 * @param array<string,mixed> $item An item.
	 */
	private static function dump( array $item ): string {
		$parts = array();
		foreach ( array( 'datePublished', 'dateModified', 'lastmod' ) as $key ) {
			$parts[] = '"' . $key . '": ' . wp_json_encode( $item[ $key ], JSON_UNESCAPED_SLASHES );
		}
		return '{' . implode( ', ', $parts ) . '}';
	}
}
