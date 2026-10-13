<?php
/**
 * What the llms.txt fixer would serve.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Fixers\Logic;

/**
 * The format of `/llms.txt` once the list of pages is chosen. Which content types, which menu
 * and in what order is WordPress's own business; what the file looks like after that is the
 * same on every CMS, and the golden vectors pin it.
 */
final class LlmsTxt {

	const MAX_ITEMS         = 1000;
	const DESCRIPTION_CHARS = 160;
	const SUMMARY_CHARS     = 300;
	/** AIR-1.10's own threshold: the file tracks the sitemap when it lists at least this share. */
	const COVERAGE_PERCENT = 75;

	/**
	 * Evaluates the fixer.
	 *
	 * @param array<string,mixed> $given Site facts.
	 * @return array<string,mixed> Evaluation.
	 */
	public static function evaluate( array $given ): array {
		$baseline                = (string) $given['baseline']['llms.txt'];
		list( $text, $findings ) = self::text( $given );
		$tooling                 = '';
		foreach ( (array) $given['tooling'] as $tool ) {
			if ( 'llms_txt_module' === $tool || 'llms_txt_plugin' === $tool ) {
				$tooling = $tool;
				break;
			}
		}
		if ( 0 === $findings['listed'] ) {
			$status = 'unchanged';
			$reason = 'no_content';
		} elseif ( ! empty( $given['files']['llms.txt'] ) ) {
			$status = 'conflict';
			$reason = 'physical_file';
		} elseif ( '' !== $tooling ) {
			$status              = 'conflict';
			$reason              = 'other_tool';
			$findings['tooling'] = $tooling;
		} elseif ( $text === $baseline ) {
			$status = 'unchanged';
			$reason = 'nothing_to_change';
		} else {
			$status = 'ready';
			$reason = null;
		}
		$changes = 'unchanged' === $status ? array() : array(
			array(
				'target' => 'llms.txt',
				'before' => $baseline,
				'after'  => $text,
			),
		);
		return array(
			'status'   => $status,
			'reason'   => $reason,
			'findings' => $findings,
			'changes'  => $changes,
			'output'   => array( 'llms.txt' => 'no_content' === $reason ? $baseline : $text ),
			'baseline' => array( 'llms.txt' => $baseline ),
			'paste'    => 'conflict' === $status ? $text : null,
		);
	}

	/**
	 * The file and the findings about it.
	 *
	 * @param array<string,mixed> $given Site facts.
	 * @return array{0:string,1:array<string,mixed>}
	 */
	private static function text( array $given ): array {
		$site   = $given['site'];
		$listed = 0;
		$lines  = array( '# ' . Text::one_line( (string) $site['name'] ), '' );
		$about  = Text::clip( Text::one_line( $site['description'] ?? '' ), self::SUMMARY_CHARS );
		if ( '' !== $about ) {
			array_push( $lines, '> ' . $about, '' );
		}
		foreach ( $given['content']['sections'] as $section ) {
			$items = array();
			foreach ( $section['items'] as $item ) {
				$url = Text::one_line( $item['url'] ?? '' );
				if ( '' === $url || $listed + count( $items ) >= self::MAX_ITEMS ) {
					continue;
				}
				$title   = str_replace( array( '[', ']' ), array( '(', ')' ), Text::one_line( $item['title'] ?? '' ) );
				$title   = '' === $title ? $url : $title;
				$note    = Text::clip( Text::one_line( $item['description'] ?? '' ), self::DESCRIPTION_CHARS );
				$items[] = '- [' . $title . '](' . $url . ')' . ( '' === $note ? '' : ': ' . $note );
			}
			if ( array() === $items ) {
				continue;
			}
			$listed += count( $items );
			array_push( $lines, '## ' . Text::one_line( (string) $section['name'] ), '' );
			foreach ( $items as $line ) {
				$lines[] = $line;
			}
			$lines[] = '';
		}
		$text     = rtrim( implode( "\n", $lines ), "\n" ) . "\n";
		$total    = (int) $given['content']['sitemap_count'];
		$coverage = $total > 0 ? intdiv( $listed * 100, $total ) : 0;
		return array(
			$text,
			array(
				'listed'           => $listed,
				'sitemap_count'    => $total,
				'coverage_percent' => $coverage,
				'tracks_sitemap'   => $total > 0 && $coverage >= self::COVERAGE_PERCENT,
				'truncated'        => $listed >= self::MAX_ITEMS,
			),
		);
	}
}
