<?php
/**
 * The change since the scan before.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Api\Model;

use ReadinessIndex\Api\Shape;

/**
 * `score.change`. `comparable` is null for a first scan and false across an Index major
 * version, where the server gives no delta and neither may a screen.
 */
final class Change {

	/**
	 * True, false, or null (a first scan).
	 *
	 * @var bool|null
	 */
	public $comparable;

	/**
	 * The whole-point change, or null when there is none to give.
	 *
	 * @var int|null
	 */
	public $score_change;

	/**
	 * Builds the object from a decoded response.
	 *
	 * @param array<string,mixed> $data Decoded object.
	 * @param string              $at   Dotted path, for contract errors.
	 */
	public static function from_array( array $data, string $at = 'score.change' ): self {
		$out        = new self();
		$comparable = Shape::need( $data, 'comparable', $at );
		if ( null !== $comparable && ! is_bool( $comparable ) ) {
			throw Shape::fail( $at . '.comparable' );
		}
		$out->comparable   = $comparable;
		$delta             = array_key_exists( 'score_change', $data ) ? $data['score_change'] : null;
		$out->score_change = is_int( $delta ) ? $delta : null;
		return $out;
	}
}
