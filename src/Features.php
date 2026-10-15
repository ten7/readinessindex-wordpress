<?php
/**
 * The one switch for everything that changes a site.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex;

/**
 * Release 0.5.0 ships the Score alone. The fixers (the Fixes and Changes screens, Preview,
 * Apply and Undo, the diagnostics, and every filter that alters robots.txt, llms.txt, dates,
 * directives or structured data) are still in the repository and still tested, and this is
 * the only place that decides whether any of it exists at run time.
 *
 * Off is the default and is checked where things are registered, never inside a fixer, so
 * with it off no screen, no `admin-post` or `admin-ajax` action and no front-end hook of the
 * fixer side is added and nothing it stored earlier is served. A site that applied a change
 * under an earlier version stops serving it the moment this release is active; the stored
 * record stays untouched, so turning the switch on again resumes it, and uninstalling still
 * cleans it up (`Uninstall` does not consult this).
 *
 * Turn it on with `define( 'READINESS_INDEX_FIXERS', true );` in wp-config.php, or with the
 * `readiness_index_fixers` filter from a must-use plugin. Neither is an option an
 * administrator screen can set, so a stolen session cannot enable code that writes to the site.
 */
final class Features {

	const CONSTANT = 'READINESS_INDEX_FIXERS';

	/**
	 * Whether the fixer side of the plugin is switched on. Strictly `true`: a string, a one
	 * or a truthy value from a careless filter is not a yes.
	 */
	public static function fixers(): bool {
		$on = defined( self::CONSTANT ) && true === constant( self::CONSTANT );
		return true === apply_filters( 'readiness_index_fixers', $on );
	}
}
