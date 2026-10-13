<?php
/**
 * A role that fixes a test.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Api\Model;

use ReadinessIndex\Api\Shape;

/**
 * An entry of `fixed_by`: who does or drives the work, and who that is.
 */
final class FixedBy {

	/**
	 * Role name.
	 *
	 * @var string
	 */
	public $role;

	/**
	 * Who the role is.
	 *
	 * @var string
	 */
	public $who;

	/**
	 * Builds the object from a decoded response.
	 *
	 * @param array<string,mixed> $data Decoded object.
	 * @param string              $at   Dotted path, for contract errors.
	 */
	public static function from_array( array $data, string $at ): self {
		$out       = new self();
		$out->role = Shape::str( $data, 'role', $at );
		$out->who  = Shape::str( $data, 'who', $at );
		return $out;
	}

	/**
	 * Reads a `fixed_by` list.
	 *
	 * @param mixed  $rows Decoded list.
	 * @param string $at   Dotted path.
	 * @return FixedBy[]
	 */
	public static function list_from( $rows, string $at ): array {
		$out = array();
		foreach ( Shape::items( $rows, $at ) as $i => $row ) {
			$out[] = self::from_array( Shape::object( $row, $at . '.' . $i ), $at . '.' . $i );
		}
		return $out;
	}
}
