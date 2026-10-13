<?php
/**
 * The connected site, as the API names it.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Api\Model;

use ReadinessIndex\Api\Shape;

/**
 * The `site` object of `GET /cms/v1/site`.
 */
final class SiteInfo {

	/**
	 * The host the key is bound to.
	 *
	 * @var string
	 */
	public $host;

	/**
	 * The stored state of the ownership proof.
	 *
	 * @var bool
	 */
	public $verified;

	/**
	 * Builds the object from a decoded response.
	 *
	 * @param array<string,mixed> $data Decoded object.
	 * @param string              $at   Dotted path, for contract errors.
	 */
	public static function from_array( array $data, string $at = 'site' ): self {
		$out           = new self();
		$out->host     = Shape::str( $data, 'host', $at );
		$out->verified = Shape::bool( $data, 'verified', $at );
		return $out;
	}
}
