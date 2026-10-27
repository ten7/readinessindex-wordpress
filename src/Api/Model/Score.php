<?php
/**
 * The finished score.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Api\Model;

use ReadinessIndex\Api\Shape;

/**
 * The `score` object. It can come from a scan older than `scan`'s: a failed rescan leaves
 * the last finished score on screen underneath its own failure.
 */
final class Score {

	/**
	 * Token of the finished scan these numbers come from.
	 *
	 * @var string
	 */
	public $scan;

	/**
	 * When it finished (ISO 8601), or null.
	 *
	 * @var string|null
	 */
	public $finished_at;

	/**
	 * The whole-integer score out of 100.
	 *
	 * @var int
	 */
	public $value;

	/**
	 * The grade's name.
	 *
	 * @var string
	 */
	public $grade;

	/**
	 * The verdict sentence, as the API wrote it.
	 *
	 * @var string
	 */
	public $verdict;

	/**
	 * Whether AI crawlers are turned away: a fact beside the number, never a cap on it.
	 *
	 * @var bool
	 */
	public $gated;

	/**
	 * The categories, in the API's order (the field is `dimensions` on the wire).
	 *
	 * @var Category[]
	 */
	public $categories = array();

	/**
	 * The change since the scan before, or null for a first scan.
	 *
	 * @var Change|null
	 */
	public $change;

	/**
	 * Builds the object from a decoded response.
	 *
	 * @param array<string,mixed> $data Decoded object.
	 * @param string              $at   Dotted path, for contract errors.
	 */
	public static function from_array( array $data, string $at = 'score' ): self {
		$out              = new self();
		$out->scan        = Shape::str( $data, 'scan', $at );
		$out->finished_at = Shape::str_or_null( $data, 'finished_at', $at );
		$out->value       = Shape::int( $data, 'value', $at );
		$out->grade       = Shape::str( $data, 'grade', $at );
		$out->verdict     = Shape::str( $data, 'verdict', $at );
		$out->gated       = Shape::bool( $data, 'gated', $at );
		foreach ( Shape::items( Shape::need( $data, 'dimensions', $at ), $at . '.dimensions' ) as $i => $row ) {
			$where             = $at . '.dimensions.' . $i;
			$out->categories[] = Category::from_array( Shape::object( $row, $where ), $where );
		}
		$change      = Shape::need( $data, 'change', $at );
		$out->change = null === $change ? null : Change::from_array( Shape::object( $change, $at . '.change' ), $at . '.change' );
		return $out;
	}
}
