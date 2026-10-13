<?php
/**
 * What a fixer found, in words.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Admin;

/**
 * One block per fixer, from the findings in the preview. Filled in with the fixers.
 */
final class FindingsView {

	/**
	 * Prints the findings of a preview.
	 *
	 * @param string              $fixer    Fixer id.
	 * @param array<string,mixed> $findings The preview's findings.
	 */
	public static function render( string $fixer, array $findings ): void {
		unset( $fixer, $findings );
	}
}
