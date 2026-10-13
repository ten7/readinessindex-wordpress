<?php
/**
 * The server's next step for the account.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Api\Model;

use ReadinessIndex\Api\Shape;

/**
 * `allowance.next_step`. The plugin holds no price and no purchase rule: this object is
 * the whole of what it knows about upgrading, and it carries no price.
 */
final class NextStep {

	const KEYS = array( 'upgrade_basic', 'monitoring_basic' );

	/**
	 * Which step: `upgrade_basic` or `monitoring_basic`.
	 *
	 * @var string
	 */
	public $key;

	/**
	 * The server's label.
	 *
	 * @var string
	 */
	public $label;

	/**
	 * Where the owner goes, on the website.
	 *
	 * @var string
	 */
	public $url;

	/**
	 * The server's sentence.
	 *
	 * @var string
	 */
	public $message;

	/**
	 * Builds the object from a decoded response.
	 *
	 * @param array<string,mixed> $data Decoded object.
	 * @param string              $at   Dotted path, for contract errors.
	 */
	public static function from_array( array $data, string $at ): self {
		$out          = new self();
		$out->key     = Shape::one_of( $data, 'key', $at, self::KEYS );
		$out->label   = Shape::str( $data, 'label', $at );
		$out->url     = Shape::str( $data, 'url', $at );
		$out->message = Shape::str( $data, 'message', $at );
		return $out;
	}
}
