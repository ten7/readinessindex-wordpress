<?php
/**
 * The answer to `POST /cms/v1/scan`.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Api\Model;

use ReadinessIndex\Api\Shape;

/**
 * `started` is false when a scan of this site was already pending or running: the same
 * scan comes back and nothing was spent, which is what makes a double click safe.
 */
final class ScanStarted {

	/**
	 * Scan token.
	 *
	 * @var string
	 */
	public $scan;

	/**
	 * State of the scan.
	 *
	 * @var string
	 */
	public $state;

	/**
	 * Whether this call started it.
	 *
	 * @var bool
	 */
	public $started;

	/**
	 * The allowance after the call.
	 *
	 * @var Allowance
	 */
	public $allowance;

	/**
	 * Builds the object from a decoded response.
	 *
	 * @param array<string,mixed> $data Decoded body.
	 */
	public static function from_array( array $data ): self {
		$out            = new self();
		$out->scan      = Shape::str( $data, 'scan', '$' );
		$out->state     = Shape::one_of( $data, 'state', '$', ScanInfo::STATES );
		$out->started   = Shape::bool( $data, 'started', '$' );
		$out->allowance = Allowance::from_array( Shape::object( Shape::need( $data, 'allowance', '$' ), 'allowance' ) );
		return $out;
	}
}
