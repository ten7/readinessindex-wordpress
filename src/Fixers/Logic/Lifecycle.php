<?php
/**
 * Preview, apply, undo and apply again, from one evaluation.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Fixers\Logic;

/**
 * The lifecycle every fixer shares, as the golden vectors state it. A fixer's logic only
 * says what would be served with the fixer on and off; whether that is a change, a conflict
 * or a deferral, and what the stored record and the results of each step are, is decided
 * here and in one place, so no fixer can answer "apply twice" differently from another.
 */
final class Lifecycle {

	/**
	 * Builds the vector-shaped result of every step.
	 *
	 * @param string              $fixer Fixer id, such as `robots-txt`.
	 * @param array<string,mixed> $given The site's facts, with `state` and `choices`.
	 * @param array<string,mixed> $ev    The fixer's evaluation: status, reason, findings, changes, output, baseline, paste.
	 * @return array<string,mixed> `preview`, `apply` and, when apply applies, `undo` and `reapply`.
	 */
	public static function run( string $fixer, array $given, array $ev ): array {
		$state   = $given['state'] ?? null;
		$choices = $given['choices'] ?? null;
		$out     = array(
			'preview' => array(
				'status'   => $ev['status'],
				'reason'   => $ev['reason'] ?? null,
				'findings' => $ev['findings'],
				'changes'  => $ev['changes'],
				'paste'    => $ev['paste'] ?? null,
				'output'   => $ev['output'],
			),
		);

		if ( 'ready' === $ev['status'] ) {
			$out['apply']   = array(
				'status'   => 'applied',
				'reason'   => null,
				'recorded' => array(
					'fixer' => $fixer,
					'prior' => $state,
					'new'   => $choices,
				),
				'output'   => $ev['output'],
			);
			$out['undo']    = array(
				'status'   => 'undone',
				'recorded' => array(
					'fixer' => $fixer,
					'prior' => $choices,
					'new'   => $state,
				),
				'output'   => $ev['baseline'],
			);
			$out['reapply'] = array(
				'status'   => 'unchanged',
				'recorded' => null,
				'output'   => $ev['output'],
			);
		} elseif ( 'unchanged' === $ev['status'] ) {
			$out['apply'] = array(
				'status'   => 'unchanged',
				'reason'   => $ev['reason'] ?? null,
				'recorded' => null,
				'output'   => $ev['output'],
			);
		} else {
			// A conflict or a deferral changes nothing and records nothing: the baseline is what
			// keeps being served, and there is no undo because nothing was done.
			$out['apply'] = array(
				'status'   => 'refused',
				'reason'   => $ev['reason'],
				'recorded' => null,
				'output'   => $ev['baseline'],
			);
		}
		return $out;
	}
}
