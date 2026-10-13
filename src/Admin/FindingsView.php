<?php
/**
 * What a fixer found, in words.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Admin;

use ReadinessIndex\Fixers\Logic\EntityGraph;
use ReadinessIndex\Fixers\Logic\LlmsTxt;
use ReadinessIndex\Fixers\SeoTools;
use ReadinessIndex\Strings;

/**
 * One block per fixer, from the findings in the preview. The sentences are the shared ones;
 * everything a scanned site or an owner typed is escaped where it is printed.
 */
final class FindingsView {

	/**
	 * Prints the findings of a preview.
	 *
	 * @param string              $fixer   Fixer id.
	 * @param array<string,mixed> $preview The preview, which holds `findings` and what WordPress added.
	 */
	public static function render( string $fixer, array $preview ): void {
		$findings = (array) ( $preview['findings'] ?? array() );
		$lines    = array();
		switch ( $fixer ) {
			case 'robots-txt':
				$lines = self::robots( $findings );
				break;
			case 'llms-txt':
				$lines = self::llms( $findings, $preview );
				break;
			case 'robots-directives':
				$lines = self::directives( $findings, $preview );
				break;
			case 'dates':
				$lines = self::dates( $findings );
				break;
			case 'entity-graph':
				$lines = self::graph( $findings, $preview );
				break;
		}
		if ( array() !== $lines ) {
			echo '<ul class="ri-findings">';
			foreach ( $lines as $line ) {
				echo '<li>' . esc_html( $line ) . '</li>';
			}
			echo '</ul>';
		}
		if ( 'entity-graph' === $fixer ) {
			self::writes( $preview );
			self::graphs( $preview );
		}
	}

	/**
	 * The robots.txt findings.
	 *
	 * @param array<string,mixed> $f Findings.
	 * @return string[]
	 */
	private static function robots( array $f ): array {
		$lines   = array();
		$blocked = (array) ( $f['blocked_agents'] ?? array() );
		$lines[] = array() === $blocked ? Strings::get( 'fixer.robots_txt.none_blocked' ) : Strings::get( 'fixer.robots_txt.blocked', array( 'agents' => implode( ', ', $blocked ) ) );
		if ( 'declared' === ( $f['content_signals'] ?? '' ) ) {
			$lines[] = Strings::get( 'fixer.robots_txt.signals_declared' );
		}
		if ( 'present' === ( $f['policy_comment'] ?? '' ) ) {
			$lines[] = Strings::get( 'fixer.robots_txt.policy_present' );
		}
		return $lines;
	}

	/**
	 * The llms.txt findings.
	 *
	 * @param array<string,mixed> $f       Findings.
	 * @param array<string,mixed> $preview The preview.
	 * @return string[]
	 */
	private static function llms( array $f, array $preview ): array {
		if ( 'no_content' === ( $preview['reason'] ?? '' ) ) {
			return array( Strings::get( 'fixer.llms_txt.no_content' ) );
		}
		$lines   = array();
		$lines[] = Strings::get(
			'fixer.llms_txt.coverage',
			array(
				'listed'  => (int) ( $f['listed'] ?? 0 ),
				'total'   => (int) ( $f['sitemap_count'] ?? 0 ),
				'percent' => (int) ( $f['coverage_percent'] ?? 0 ),
			)
		);
		$lines[] = ! empty( $f['tracks_sitemap'] ) ? Strings::get( 'fixer.llms_txt.tracks' ) : Strings::get( 'fixer.llms_txt.lags' );
		if ( ! empty( $f['truncated'] ) ) {
			$lines[] = Strings::get( 'fixer.llms_txt.truncated', array( 'limit' => LlmsTxt::MAX_ITEMS ) );
		}
		if ( isset( $f['tooling'] ) && '' !== (string) ( $f['tooling_name'] ?? '' ) ) {
			$lines[] = Strings::get( 'fixer.llms_txt.served_by', array( 'tooling' => (string) $f['tooling_name'] ) );
		}
		return $lines;
	}

	/**
	 * The stray-directive findings.
	 *
	 * @param array<string,mixed> $f       Findings.
	 * @param array<string,mixed> $preview The preview.
	 * @return string[]
	 */
	private static function directives( array $f, array $preview ): array {
		$lines = array();
		$pages = (array) ( $f['pages'] ?? array() );
		if ( array() === $pages && array() === (array) ( $f['deferred'] ?? array() ) ) {
			$lines[] = Strings::get( 'fixer.robots_directives.none' );
		} elseif ( array() !== $pages ) {
			$lines[] = Strings::get( 'fixer.robots_directives.found', array( 'count' => count( $pages ) ) );
			foreach ( $pages as $page ) {
				$lines[] = Strings::get(
					'fixer.robots_directives.removed',
					array(
						'path'       => (string) $page['path'],
						'directives' => implode( ', ', (array) $page['removed'] ),
					)
				);
			}
		}
		foreach ( (array) ( $f['deferred'] ?? array() ) as $row ) {
			$lines[] = Strings::get(
				'fixer.robots_directives.deferred',
				array(
					'tooling' => SeoTools::name( (string) $row['tooling'] ),
					'path'    => (string) $row['path'],
				)
			);
		}
		foreach ( (array) ( $f['headers'] ?? array() ) as $row ) {
			$lines[] = Strings::get( 'fixer.robots_directives.header', array( 'path' => (string) $row['path'] ) );
		}
		foreach ( (array) ( $preview['theme'] ?? array() ) as $row ) {
			$lines[] = Strings::get(
				'fixer.robots_directives.theme',
				array(
					'path'       => (string) $row['path'],
					'directives' => implode( ', ', (array) $row['directives'] ),
				)
			);
		}
		return $lines;
	}

	/**
	 * The dates findings.
	 *
	 * @param array<string,mixed> $f Findings.
	 * @return string[]
	 */
	private static function dates( array $f ): array {
		$lines = array();
		if ( ! empty( $f['clustered'] ) ) {
			$lines[] = Strings::get( 'fixer.dates.clustered' );
		}
		$touched = (array) ( $f['touched_without_change'] ?? array() );
		if ( array() !== $touched ) {
			$lines[] = Strings::get( 'fixer.dates.touched', array( 'count' => count( $touched ) ) );
		}
		foreach ( (array) ( $f['skipped'] ?? array() ) as $row ) {
			$lines[] = Strings::get(
				'fixer.dates.skipped_row',
				array(
					'path'   => (string) $row['path'],
					'reason' => Strings::get( 'fixer.dates.skipped.' . $row['reason'] ),
				)
			);
		}
		$channels = (array) ( $f['channels'] ?? array() );
		foreach ( array_unique(
			array_filter(
				array( $channels['jsonld'] ?? '', $channels['sitemap'] ?? '' ),
				static function ( $c ): bool {
					return in_array( $c, array( 'yoast', 'rank_math' ), true );
				}
			)
		) as $tool ) {
			$lines[] = Strings::get( 'fixes.through_tooling', array( 'tooling' => SeoTools::name( (string) $tool ) ) );
		}
		if ( 'none' === ( $channels['sitemap'] ?? '' ) ) {
			$lines[] = Strings::get( 'fixer.dates.no_sitemap' );
		}
		return $lines;
	}

	/**
	 * The entity graph findings.
	 *
	 * @param array<string,mixed> $f       Findings.
	 * @param array<string,mixed> $preview The preview.
	 * @return string[]
	 */
	private static function graph( array $f, array $preview ): array {
		$lines = array();
		if ( 'through_tooling' === ( $f['mode'] ?? '' ) ) {
			$lines[] = Strings::get( 'fixes.through_tooling', array( 'tooling' => SeoTools::name( (string) $f['tooling'] ) ) );
		}
		if ( 'invalid' === ( $f['search_template'] ?? '' ) ) {
			$lines[] = Strings::get( 'fixer.entity_graph.search_invalid', array( 'search_term_string' => '{search_term_string}' ) );
		}
		if ( 'logo_needed' === ( $preview['needs'] ?? null ) ) {
			$lines[] = Strings::get( 'fixer.entity_graph.logo_needed' );
		}
		if ( array() !== (array) ( $preview['validation'] ?? array() ) ) {
			$lines[] = Strings::get( 'fixer.entity_graph.invalid_graph', array( 'problems' => implode( '; ', (array) $preview['validation'] ) ) );
		}
		return $lines;
	}

	/**
	 * The settings of the SEO plugin that will be written, before and after.
	 *
	 * @param array<string,mixed> $preview The preview.
	 */
	private static function writes( array $preview ): void {
		$writes = (array) ( $preview['writes'] ?? array() );
		if ( array() === $writes ) {
			return;
		}
		echo '<h3>' . esc_html( Strings::get( 'preview.writes', array( 'tooling' => SeoTools::name( (string) ( $preview['tool'] ?? '' ) ) ) ) ) . '</h3><ul class="ri-writes">';
		foreach ( $writes as $write ) {
			echo '<li><code>' . esc_html( $write['option'] . ' / ' . $write['key'] ) . '</code> ' . esc_html(
				Strings::get(
					'preview.writes_change',
					array(
						'setting' => '',
						'before'  => self::show( $write['before'] ),
						'after'   => self::show( $write['after'] ),
					)
				)
			) . '</li>';
		}
		echo '</ul>';
	}

	/**
	 * A value in one line.
	 *
	 * @param mixed $value Any stored value.
	 */
	private static function show( $value ): string {
		if ( null === $value || '' === $value || array() === $value ) {
			return Strings::get( 'preview.empty_value' );
		}
		return is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES );
	}

	/**
	 * The graph each sampled page would print, as text.
	 *
	 * @param array<string,mixed> $preview The preview.
	 */
	private static function graphs( array $preview ): void {
		if ( empty( $preview['output']['prints_graph'] ) ) {
			return;
		}
		foreach ( (array) $preview['output']['pages'] as $page ) {
			echo '<details class="ri-graph"><summary>' . esc_html( Strings::get( 'preview.graph_for', array( 'url' => (string) $page['url'] ) ) ) . '</summary>';
			echo '<pre class="ri-pre">' . esc_html( (string) wp_json_encode( $page['graph'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) . '</pre></details>';
		}
	}
}
