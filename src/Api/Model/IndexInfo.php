<?php
/**
 * The version of the Index that scored the site.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Api\Model;

use ReadinessIndex\Api\Shape;

/**
 * The `index` object.
 */
final class IndexInfo {

	/**
	 * Version, such as 11.5.
	 *
	 * @var string
	 */
	public $version;

	/**
	 * Release date.
	 *
	 * @var string
	 */
	public $date;

	/**
	 * Builds the object from a decoded response.
	 *
	 * @param array<string,mixed> $data Decoded object.
	 * @param string              $at   Dotted path, for contract errors.
	 */
	public static function from_array( array $data, string $at = 'index' ): self {
		$out          = new self();
		$out->version = Shape::str( $data, 'version', $at );
		$out->date    = Shape::str( $data, 'date', $at );
		return $out;
	}
}
