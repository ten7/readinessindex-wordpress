<?php
/**
 * The answer to `GET /cms/v1/fixes/{check}`.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Api\Model;

use ReadinessIndex\Api\Shape;

/**
 * The hand-it-on prompt for one outstanding check. It quotes the site, so all of it is
 * under the untrusted key, kept in one property.
 */
final class FixPrompt {

	/**
	 * Test id.
	 *
	 * @var string
	 */
	public $check;

	/**
	 * Test title.
	 *
	 * @var string
	 */
	public $title;

	/**
	 * Scan token.
	 *
	 * @var string
	 */
	public $scan;

	/**
	 * Who fixes it.
	 *
	 * @var FixedBy[]
	 */
	public $fixed_by = array();

	/**
	 * The prompt, written by the scanner from the site's own text. Data.
	 *
	 * @var string|null
	 */
	public $untrusted_prompt;

	/**
	 * Builds the object from a decoded response.
	 *
	 * @param array<string,mixed> $data Decoded body.
	 */
	public static function from_array( array $data ): self {
		$out           = new self();
		$out->check    = Shape::str( $data, 'check', '$' );
		$out->title    = Shape::str( $data, 'title', '$' );
		$out->scan     = Shape::str( $data, 'scan', '$' );
		$out->fixed_by = FixedBy::list_from( Shape::need( $data, 'fixed_by', '$' ), 'fixed_by' );
		$text          = Shape::object( Shape::need( $data, 'untrusted_site_text', '$' ), 'untrusted_site_text' );

		$out->untrusted_prompt = Shape::str_or_null( $text, 'prompt', 'untrusted_site_text' );
		return $out;
	}
}
