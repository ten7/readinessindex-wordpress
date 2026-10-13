<?php
/**
 * A notice from the server.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Api\Model;

use ReadinessIndex\Api\Shape;

/**
 * An entry of `notices`. Today the only key is `module_update_required`; an unknown key
 * is kept and ignored by the screen, never an error.
 */
final class Notice {

	const UPDATE_REQUIRED = 'module_update_required';

	/**
	 * Notice key.
	 *
	 * @var string
	 */
	public $key;

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
		$out->key     = Shape::str( $data, 'key', $at );
		$out->message = Shape::str( $data, 'message', $at );
		return $out;
	}
}
