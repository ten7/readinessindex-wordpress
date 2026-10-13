<?php
/**
 * Adding the filters of the fixers that are on.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Fixers;

/**
 * Called on every request, front end included, so it reads one autoloaded option and adds
 * hooks only for fixers the owner applied. With nothing applied it adds none and makes no query,
 * which a test holds it to.
 */
final class Hooks {

	/**
	 * Registers the runtime of each fixer that has stored choices.
	 */
	public static function register(): void {
		$on = State::all();
		if ( array() === $on ) {
			return;
		}
		if ( isset( $on['robots-txt'] ) ) {
			Runtime\RobotsTxt::register( $on['robots-txt'] );
		}
		if ( isset( $on['llms-txt'] ) ) {
			Runtime\LlmsTxt::register( $on['llms-txt'] );
		}
	}
}
