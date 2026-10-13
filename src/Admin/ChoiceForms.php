<?php
/**
 * The choices a fixer asks the owner to make.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Admin;

use ReadinessIndex\Fixers\Fixer;

/**
 * One small GET form per fixer, on the preview page, so a choice is a link and the preview
 * recomputes without scripts. Filled in with the fixers.
 */
final class ChoiceForms {

	/**
	 * Prints a fixer's choice form, if it has one.
	 *
	 * @param Fixer               $fixer   The fixer.
	 * @param array<string,mixed> $choices The current choices.
	 */
	public static function render( Fixer $fixer, array $choices ): void {
		unset( $fixer, $choices );
	}
}
