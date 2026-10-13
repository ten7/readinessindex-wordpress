<?php
/**
 * Stray noarchive and nosnippet directives.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Fixers\Impl;

use ReadinessIndex\Fixers\Content\PageSample;
use ReadinessIndex\Fixers\Content\RobotsMeta;
use ReadinessIndex\Fixers\Logic\RobotsDirectives;
use ReadinessIndex\Fixers\Runtime;
use ReadinessIndex\Fixers\SeoTools;
use ReadinessIndex\Http\SiteFetcher;

/**
 * AIR-1.6, through `wp_robots`, after a look at the site's own pages that finds who emits each
 * directive. What an SEO plugin or the server sets is reported and left alone; what WordPress or
 * a theme sets through `wp_robots` is removed; a theme's own hand-written tag is reported
 * and not fixed, because no filter reaches it.
 */
final class RobotsDirectivesFixer extends AbstractFixer {

	const SAMPLE_TRANSIENT = 'ri_directives_sample';
	const SAMPLE_TTL       = 60;

	/**
	 * Tools that own the robots meta of the pages they are active on.
	 */
	const OWNERS = array( 'yoast', 'rank_math', 'aioseo', 'seopress', 'tsf' );

	/**
	 * Hand-written theme tags found by the last look, by path.
	 *
	 * @var array<string,string[]>
	 */
	private $theme = array();

	/**
	 * The id the contract names.
	 */
	public function id(): string {
		return 'robots-directives';
	}

	/**
	 * The tests it moves.
	 *
	 * @return string[]
	 */
	public function checks(): array {
		return array( 'AIR-1.6' );
	}

	/**
	 * The tool that owns the robots meta on this site, or null.
	 */
	private function owner(): ?string {
		$found = array_values( array_intersect( self::OWNERS, SeoTools::active() ) );
		return array() === $found ? null : $found[0];
	}

	/**
	 * Facts: what each sampled page emits, by source.
	 *
	 * @return array<string,mixed>
	 */
	public function detect(): array {
		$owner = $this->owner();
		$pages = array();
		$theme = array();
		foreach ( $this->sample() as $row ) {
			$page = array(
				'path'       => $row['path'],
				'directives' => $row['core'],
				'source'     => null === $owner ? 'core' : 'tooling:' . $owner,
			);
			if ( array() !== $row['header'] ) {
				$page['header'] = $row['header'];
			}
			if ( array() !== $row['other'] ) {
				$theme[ $row['path'] ] = $row['other'];
			}
			$pages[] = $page;
		}
		$this->theme = $theme;
		return array(
			'site'    => $this->site(),
			'tooling' => SeoTools::active(),
			'files'   => array(),
			'pages'   => $pages,
		);
	}

	/**
	 * Looks at the sample pages as an anonymous visitor would, without this fixer's own filter.
	 * Kept for a minute, so the preview and the Apply that follows it read the same thing.
	 *
	 * @return array<int,array{path:string,core:string[],other:string[],header:string[]}>
	 */
	private function sample(): array {
		$cached = get_transient( self::SAMPLE_TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$rows = array();
		foreach ( PageSample::urls() as $url ) {
			$got = SiteFetcher::get( add_query_arg( Runtime\Directives::BASELINE_FLAG, '1', $url ) );
			if ( null === $got || 200 !== $got['status'] ) {
				continue;
			}
			$meta   = RobotsMeta::meta( $got['body'] );
			$rows[] = array(
				'path'   => PageSample::path( $url ),
				'core'   => $meta['core'],
				'other'  => $meta['other'],
				'header' => RobotsMeta::header_tokens( $got['headers']['x-robots-tag'] ?? '' ),
			);
		}
		set_transient( self::SAMPLE_TRANSIENT, $rows, self::SAMPLE_TTL );
		return $rows;
	}

	/**
	 * The pure logic.
	 *
	 * @param array<string,mixed> $given Facts, state and choices.
	 * @return array<string,mixed>
	 */
	public function evaluate( array $given ): array {
		return RobotsDirectives::evaluate( $given );
	}

	/**
	 * Whether a change can be made here: no plugin owns the robots meta.
	 */
	public function reachable(): bool {
		return null === $this->owner();
	}

	/**
	 * Adds what only WordPress can say: directives a theme wrote by hand.
	 *
	 * @param array<string,mixed> $ev    The evaluation.
	 * @param array<string,mixed> $given The facts.
	 * @return array<string,mixed>
	 */
	protected function annotate( array $ev, array $given ): array {
		unset( $given );
		$strays = array();
		foreach ( $this->theme as $path => $tokens ) {
			$found = array_values( array_filter( $tokens, array( RobotsDirectives::class, 'is_stray' ) ) );
			if ( array() !== $found ) {
				$strays[] = array(
					'path'       => $path,
					'directives' => $found,
				);
			}
		}
		$ev['theme'] = $strays;
		return $ev;
	}

	/**
	 * Looks at the pages again, without the baseline flag, and says whether the stray
	 * directives are gone from the ones WordPress emits.
	 *
	 * @param array<string,mixed> $ev The applied evaluation.
	 * @return array{live:bool|null,reason:string}
	 */
	public function verify( array $ev ): array {
		delete_transient( self::SAMPLE_TRANSIENT );
		$seen = 0;
		foreach ( $ev['output']['pages'] as $page ) {
			$got = SiteFetcher::get( home_url( $page['path'] ) );
			if ( null === $got || 200 !== $got['status'] ) {
				continue;
			}
			++$seen;
			$now  = RobotsMeta::meta( $got['body'] )['core'];
			$want = array_map( 'strtolower', $page['directives'] );
			if ( array_map( 'strtolower', $now ) !== $want ) {
				return array(
					'live'   => false,
					'reason' => 'different',
				);
			}
		}
		return 0 === $seen ? array(
			'live'   => null,
			'reason' => 'unreachable',
		) : array(
			'live'   => true,
			'reason' => '',
		);
	}
}
