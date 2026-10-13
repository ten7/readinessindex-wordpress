<?php
/**
 * The answer to `GET /cms/v1/scan/{scan}`.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Api\Model;

use ReadinessIndex\Api\Shape;

/**
 * Where one scan of this site is, with progress while it runs.
 */
final class ScanStatus {

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
	 * When it finished, or null.
	 *
	 * @var string|null
	 */
	public $finished_at;

	/**
	 * Pages read so far, or null when the API gives no progress.
	 *
	 * @var int|null
	 */
	public $pages_read;

	/**
	 * Pages the scan expects to read, or null.
	 *
	 * @var int|null
	 */
	public $pages_total;

	/**
	 * For a failed scan, one plain sentence.
	 *
	 * @var string|null
	 */
	public $problem;

	/**
	 * Builds the object from a decoded response.
	 *
	 * @param array<string,mixed> $data Decoded body.
	 */
	public static function from_array( array $data ): self {
		$out              = new self();
		$out->scan        = Shape::str( $data, 'scan', '$' );
		$out->state       = Shape::one_of( $data, 'state', '$', ScanInfo::STATES );
		$out->finished_at = Shape::str_or_null( $data, 'finished_at', '$' );
		$out->problem     = Shape::str_or_null( $data, 'problem', '$' );
		$progress         = Shape::need( $data, 'progress', '$' );
		if ( null !== $progress ) {
			$progress         = Shape::object( $progress, 'progress' );
			$out->pages_read  = Shape::int_or_null( $progress, 'pages_read', 'progress' );
			$out->pages_total = Shape::int_or_null( $progress, 'pages_total', 'progress' );
			Shape::int_or_null( $progress, 'checks_done', 'progress' );
			Shape::int_or_null( $progress, 'checks_total', 'progress' );
		}
		return $out;
	}

	/**
	 * Whether the scan has ended, well or badly.
	 */
	public function is_finished(): bool {
		return 'done' === $this->state || 'failed' === $this->state;
	}
}
