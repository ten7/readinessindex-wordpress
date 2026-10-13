<?php
/**
 * Which Index tests have a diagnostic instead of a fix.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Admin;

/**
 * The three things the plugin can see and must not fix. Each is shown as a hand-it-on row with
 * its own copy. The measurements themselves live in `ReadinessIndex\Diagnostics`.
 */
final class Diagnostics {

	/**
	 * The diagnostic that explains an Index test, or null.
	 *
	 * @param string $check Test id.
	 */
	public static function key_for( string $check ): ?string {
		$map = array(
			'AIR-2.5' => 'alt_text',
			'AIR-1.2' => 'cdn',
			'AIR-2.1' => 'js_only',
			'AIR-2.2' => 'js_only',
		);
		return $map[ $check ] ?? null;
	}
}
