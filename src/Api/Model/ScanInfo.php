<?php
/**
 * The newest full scan of the site.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Api\Model;

use ReadinessIndex\Api\Shape;

/**
 * The `scan` object of `GET /cms/v1/site`: where the newest scan is, whatever became of it.
 */
final class ScanInfo {

	const STATES = array( 'none', 'pending', 'running', 'done', 'failed' );

	/**
	 * One of none, pending, running, done, failed.
	 *
	 * @var string
	 */
	public $state;

	/**
	 * The scan's token, or null with no scan.
	 *
	 * @var string|null
	 */
	public $token;

	/**
	 * When it finished (ISO 8601), or null.
	 *
	 * @var string|null
	 */
	public $finished_at;

	/**
	 * Pages read: the finished count, or progress so far while running.
	 *
	 * @var int|null
	 */
	public $pages_read;

	/**
	 * Whether the crawl stopped at its page ceiling.
	 *
	 * @var bool
	 */
	public $truncated_pages;

	/**
	 * Whether the crawl stopped at its time budget.
	 *
	 * @var bool
	 */
	public $truncated_time;

	/**
	 * For a failed scan, one plain sentence.
	 *
	 * @var string|null
	 */
	public $problem;

	/**
	 * Builds the object from a decoded response.
	 *
	 * @param array<string,mixed> $data Decoded object.
	 * @param string              $at   Dotted path, for contract errors.
	 */
	public static function from_array( array $data, string $at = 'scan' ): self {
		$out              = new self();
		$out->state       = Shape::one_of( $data, 'state', $at, self::STATES );
		$out->token       = Shape::str_or_null( $data, 'token', $at );
		$out->finished_at = Shape::str_or_null( $data, 'finished_at', $at );
		$out->pages_read  = Shape::int_or_null( $data, 'pages_read', $at );
		$truncated        = Shape::object( Shape::need( $data, 'truncated', $at ), $at . '.truncated' );

		$out->truncated_pages = Shape::bool( $truncated, 'pages', $at . '.truncated' );
		$out->truncated_time  = Shape::bool( $truncated, 'time', $at . '.truncated' );
		$out->problem         = Shape::str_or_null( $data, 'problem', $at );
		return $out;
	}

	/**
	 * Whether a scan is queued or running.
	 */
	public function is_active(): bool {
		return 'pending' === $this->state || 'running' === $this->state;
	}
}
