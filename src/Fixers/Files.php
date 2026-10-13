<?php
/**
 * Looking for a real file where WordPress would otherwise answer.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Fixers;

/**
 * A real `robots.txt` or `llms.txt` in the web root is served by the web server before
 * WordPress is asked, so a change made through WordPress would never be seen. The fixers refuse,
 * and offer the exact text to paste into that file.
 */
final class Files {

	/**
	 * Whether a real file of this name is in the web root.
	 *
	 * @param string $name A file name such as `robots.txt`.
	 */
	public static function physical( string $name ): bool {
		foreach ( self::roots() as $root ) {
			if ( is_file( $root . $name ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The directories the web server may serve the site's root from: WordPress's own, and its
	 * parent when WordPress lives in a subdirectory of the site's address.
	 *
	 * @return string[] Each ending in a slash.
	 */
	public static function roots(): array {
		$roots = array( trailingslashit( ABSPATH ) );
		if ( function_exists( 'get_home_path' ) ) {
			$roots[] = trailingslashit( get_home_path() );
		}
		return array_values( array_unique( $roots ) );
	}
}
