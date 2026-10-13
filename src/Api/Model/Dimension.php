<?php
/**
 * One of the seven dimensions of the score.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Api\Model;

use ReadinessIndex\Api\Shape;

/**
 * A row of the dimensions table. Whole integers: points are never decimals.
 */
final class Dimension {

	/**
	 * Dimension number.
	 *
	 * @var int
	 */
	public $id;

	/**
	 * Dimension name.
	 *
	 * @var string
	 */
	public $name;

	/**
	 * Points earned.
	 *
	 * @var int
	 */
	public $earned;

	/**
	 * Points possible.
	 *
	 * @var int
	 */
	public $possible;

	/**
	 * Builds the object from a decoded response.
	 *
	 * @param array<string,mixed> $data Decoded object.
	 * @param string              $at   Dotted path, for contract errors.
	 */
	public static function from_array( array $data, string $at ): self {
		$out           = new self();
		$out->id       = Shape::int( $data, 'id', $at );
		$out->name     = Shape::str( $data, 'name', $at );
		$out->earned   = Shape::int( $data, 'earned', $at );
		$out->possible = Shape::int( $data, 'possible', $at );
		return $out;
	}
}
