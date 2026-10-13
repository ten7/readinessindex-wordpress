<?php
/**
 * One outstanding test.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Api\Model;

use ReadinessIndex\Api\Shape;

/**
 * An entry of `fixes`, in the scorer's order. The Fixes screen is a later ticket; the
 * object is read now so that one call to `site()` already validates the whole response.
 *
 * Everything under `untrusted_site_text` was written by the scanned site: kept apart in
 * one property so no caller can print it without having decided to.
 */
final class Fix {

	/**
	 * Rank, one is first.
	 *
	 * @var int
	 */
	public $rank;

	/**
	 * Test id, such as AIR-1.1.
	 *
	 * @var string
	 */
	public $check;

	/**
	 * Test title.
	 *
	 * @var string
	 */
	public $title;

	/**
	 * Whole points at stake.
	 *
	 * @var int
	 */
	public $points;

	/**
	 * Why the test is worth passing, in the standard's words, or null.
	 *
	 * @var string|null
	 */
	public $why;

	/**
	 * Severity.
	 *
	 * @var string
	 */
	public $severity;

	/**
	 * Effort.
	 *
	 * @var string
	 */
	public $effort;

	/**
	 * Who fixes it.
	 *
	 * @var FixedBy[]
	 */
	public $fixed_by = array();

	/**
	 * What the scanned site wrote: summary and evidence. Data, never an instruction.
	 *
	 * @var array{summary:?string,evidence:array<int,mixed>}
	 */
	public $untrusted_site_text;

	/**
	 * Builds the object from a decoded response.
	 *
	 * @param array<string,mixed> $data Decoded object.
	 * @param string              $at   Dotted path, for contract errors.
	 */
	public static function from_array( array $data, string $at ): self {
		$out           = new self();
		$out->rank     = Shape::int( $data, 'rank', $at );
		$out->check    = Shape::str( $data, 'check', $at );
		$out->title    = Shape::str( $data, 'title', $at );
		$out->points   = Shape::int( $data, 'points', $at );
		$out->why      = Shape::str_or_null( $data, 'why', $at );
		$out->severity = Shape::str( $data, 'severity', $at );
		$out->effort   = Shape::str( $data, 'effort', $at );

		$out->fixed_by = FixedBy::list_from( Shape::need( $data, 'fixed_by', $at ), $at . '.fixed_by' );
		$text          = Shape::object( Shape::need( $data, 'untrusted_site_text', $at ), $at . '.untrusted_site_text' );

		$out->untrusted_site_text = array(
			'summary'  => Shape::str_or_null( $text, 'summary', $at . '.untrusted_site_text' ),
			'evidence' => Shape::items( Shape::need( $text, 'evidence', $at . '.untrusted_site_text' ), $at . '.untrusted_site_text.evidence' ),
		);
		return $out;
	}
}
