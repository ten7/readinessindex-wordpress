<?php
/**
 * One of the four categories of the score: Reach, Read, Understand and Act.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Api\Model;

use ReadinessIndex\Api\Shape;

/**
 * A row of the category table. The API still calls the list `dimensions`, which is its name
 * for the same thing, and the number of rows is the server's: the plugin draws whatever it is
 * sent, so a later Index with other categories needs no release here. Whole integers: points
 * are never decimals.
 */
final class Category {

	/**
	 * Category number.
	 *
	 * @var int
	 */
	public $id;

	/**
	 * Category name.
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
