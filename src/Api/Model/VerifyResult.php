<?php
/**
 * The answer to `POST /cms/v1/verify`.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Api\Model;

use ReadinessIndex\Api\Shape;

/**
 * Success only: a file that was not found is a `not_verified` error, not this object.
 */
final class VerifyResult {

	/**
	 * Whether ownership is verified.
	 *
	 * @var bool
	 */
	public $verified;

	/**
	 * The server's word for it (`verified`, or that the proof already stood).
	 *
	 * @var string
	 */
	public $state;

	/**
	 * Builds the object from a decoded response.
	 *
	 * @param array<string,mixed> $data Decoded body.
	 */
	public static function from_array( array $data ): self {
		$out           = new self();
		$out->verified = Shape::bool( $data, 'verified', '$' );
		$out->state    = Shape::str( $data, 'state', '$' );
		return $out;
	}
}
