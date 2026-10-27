<?php
/**
 * An answer the module cannot treat as success.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Api;

use RuntimeException;

/**
 * One exception for every way a call can fail.
 *
 * `key` is what the module switches on: a server error key (`quota`, `unreachable`,
 * `unauthenticated` and the rest of the contract's table), or one of two of ours that the
 * server never sends: `offline` (no answer at all, which is not an error key) and
 * `contract` (an answer that does not have the shape the contract promises, which is a
 * module or server bug and is shown as a failure on our side, never as a notice).
 *
 * `getMessage()` is the server's own sentence when it sent one, and a shared string
 * otherwise, so a screen can always print it.
 */
final class ApiError extends RuntimeException {

	const OFFLINE  = 'offline';
	const CONTRACT = 'contract';

	/**
	 * Machine key.
	 *
	 * @var string
	 */
	public $key;

	/**
	 * HTTP status, or 0 when there was no answer.
	 *
	 * @var int
	 */
	public $status;

	/**
	 * Extra fields the server named for this key (`reason`, `retry_after`, `state`, `allowance`, `next_step`).
	 *
	 * @var array<string,mixed>
	 */
	public $extra;

	/**
	 * Builds the error.
	 *
	 * @param string              $key     Machine key.
	 * @param string              $message One plain sentence.
	 * @param int                 $status  HTTP status, or 0.
	 * @param array<string,mixed> $extra   Extra fields.
	 */
	public function __construct( string $key, string $message, int $status = 0, array $extra = array() ) {
		parent::__construct( $message );
		$this->key    = $key;
		$this->status = $status;
		$this->extra  = $extra;
	}

	/**
	 * The `reason` of an `unreachable` answer, or an empty string.
	 */
	public function reason(): string {
		return isset( $this->extra['reason'] ) && is_string( $this->extra['reason'] ) ? $this->extra['reason'] : '';
	}

	/**
	 * Seconds to wait after `rate_limited`, never less than one.
	 */
	public function retry_after(): int {
		$seconds = isset( $this->extra['retry_after'] ) && is_int( $this->extra['retry_after'] ) ? $this->extra['retry_after'] : 60;
		return max( 1, $seconds );
	}

	/**
	 * The `state` of a `not_verified` answer (`not_found` or `error`).
	 */
	public function verify_state(): string {
		return isset( $this->extra['state'] ) && is_string( $this->extra['state'] ) ? $this->extra['state'] : '';
	}
}
