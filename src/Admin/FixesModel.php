<?php
/**
 * Which rows the Fixes tab shows, and what each one can do.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Admin;

use ReadinessIndex\Api\Model\Fix;
use ReadinessIndex\Api\Model\SiteResponse;
use ReadinessIndex\Fixers\Fixer;
use ReadinessIndex\Fixers\Registry;
use ReadinessIndex\Fixers\SeoTools;

/**
 * The server says which tests are outstanding and in what order, and never what this plugin can
 * fix. That is decided here, from the local registry and the tools active on the site, so a row
 * is exactly one of three kinds and the module never promises a change it cannot make.
 */
final class FixesModel {

	const FIX_HERE = 'fix_here';
	const FIX_WITH = 'fix_with';
	const HAND_ON  = 'hand_on';

	const KINDS = array( 'all', self::FIX_HERE, self::FIX_WITH, self::HAND_ON );

	/**
	 * Every row, in the server's order and never re-sorted.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public $rows = array();

	/**
	 * The rows after the filter.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public $shown = array();

	/**
	 * Points the plugin can reach now, whole integers.
	 *
	 * @var int
	 */
	public $reachable = 0;

	/**
	 * Points the site is missing.
	 *
	 * @var int
	 */
	public $total = 0;

	/**
	 * The kind filter in effect.
	 *
	 * @var string
	 */
	public $filter = 'all';

	/**
	 * Builds the model.
	 *
	 * @param SiteResponse $site   The site answer.
	 * @param string       $filter A kind from `KINDS`.
	 */
	public static function build( SiteResponse $site, string $filter = 'all' ): self {
		$model         = new self();
		$model->filter = in_array( $filter, self::KINDS, true ) ? $filter : 'all';
		$active        = SeoTools::active();

		foreach ( $site->fixes as $fix ) {
			$row               = self::row( $fix, $active );
			$model->rows[]     = $row;
			$model->total     += $fix->points;
			$model->reachable += self::FIX_HERE === $row['kind'] && $row['reachable'] ? $fix->points : 0;
			if ( 'all' === $model->filter || $row['kind'] === $model->filter ) {
				$model->shown[] = $row;
			}
		}
		return $model;
	}

	/**
	 * One row.
	 *
	 * @param Fix      $fix    The outstanding test.
	 * @param string[] $active Active SEO tool keys.
	 * @return array<string,mixed>
	 */
	private static function row( Fix $fix, array $active ): array {
		$row   = array(
			'fix'        => $fix,
			'kind'       => self::HAND_ON,
			'fixer'      => null,
			'reachable'  => false,
			'tooling'    => null,
			'diagnostic' => Diagnostics::key_for( $fix->check ),
		);
		$fixer = Registry::for_check( $fix->check );
		if ( null === $fixer ) {
			return $row;
		}
		$row['fixer']     = $fixer;
		$row['reachable'] = $fixer->reachable();
		if ( $row['reachable'] ) {
			$row['kind'] = self::FIX_HERE;
			return $row;
		}
		// A tool the plugin only has instructions for owns this: say where, exactly.
		$tool  = SeoTools::instructed( $active );
		$field = null === $tool ? null : SeoTools::organization_field( $tool );
		if ( null !== $tool && null !== $field && self::is_graph_check( $fixer ) ) {
			$row['kind']    = self::FIX_WITH;
			$row['tooling'] = array(
				'name'    => SeoTools::name( $tool ),
				'setting' => $field['setting'],
				'url'     => admin_url( $field['page'] ),
			);
			return $row;
		}
		// A fixer that cannot reach this site, with nothing else to point at, is still the
		// plugin's: the preview says why, and the points are not counted.
		$row['kind'] = self::FIX_HERE;
		return $row;
	}

	/**
	 * Whether a fixer is the structured-data one.
	 *
	 * @param Fixer $fixer A fixer.
	 */
	private static function is_graph_check( Fixer $fixer ): bool {
		return 'entity-graph' === $fixer->id();
	}
}
