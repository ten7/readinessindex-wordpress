<?php
/**
 * The answer to `GET /cms/v1/site`.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Api\Model;

use ReadinessIndex\Api\Shape;

/**
 * One call fills the Score screen. The answer also lists outstanding tests (`fixes`); this plugin
 * does not draw them, so it does not read them, and a change to that list can never break it.
 */
final class SiteResponse {

	/**
	 * The site.
	 *
	 * @var SiteInfo
	 */
	public $site;

	/**
	 * The newest scan.
	 *
	 * @var ScanInfo
	 */
	public $scan;

	/**
	 * The last finished score, or null.
	 *
	 * @var Score|null
	 */
	public $score;

	/**
	 * What the account may do.
	 *
	 * @var Allowance
	 */
	public $allowance;

	/**
	 * The Index version, or null.
	 *
	 * @var IndexInfo|null
	 */
	public $index;

	/**
	 * Notices.
	 *
	 * @var Notice[]
	 */
	public $notices = array();

	/**
	 * Builds the object from a decoded response.
	 *
	 * @param array<string,mixed> $data Decoded body.
	 */
	public static function from_array( array $data ): self {
		$out            = new self();
		$out->site      = SiteInfo::from_array( Shape::object( Shape::need( $data, 'site', '$' ), 'site' ) );
		$out->scan      = ScanInfo::from_array( Shape::object( Shape::need( $data, 'scan', '$' ), 'scan' ) );
		$score          = Shape::need( $data, 'score', '$' );
		$out->score     = null === $score ? null : Score::from_array( Shape::object( $score, 'score' ) );
		$out->allowance = Allowance::from_array( Shape::object( Shape::need( $data, 'allowance', '$' ), 'allowance' ) );
		$index          = Shape::need( $data, 'index', '$' );
		$out->index     = null === $index ? null : IndexInfo::from_array( Shape::object( $index, 'index' ) );

		foreach ( Shape::items( Shape::need( $data, 'notices', '$' ), 'notices' ) as $i => $row ) {
			$out->notices[] = Notice::from_array( Shape::object( $row, 'notices.' . $i ), 'notices.' . $i );
		}
		return $out;
	}

	/**
	 * Whether the server says this module is too old.
	 */
	public function update_required(): bool {
		foreach ( $this->notices as $notice ) {
			if ( Notice::UPDATE_REQUIRED === $notice->key ) {
				return true;
			}
		}
		return false;
	}
}
