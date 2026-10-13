<?php
/**
 * Removing stray directives from the robots meta WordPress prints.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Fixers\Runtime;

use ReadinessIndex\Fixers\Logic\RobotsDirectives;
use ReadinessIndex\Fixers\SeoTools;

/**
 * A filter on `wp_robots`, the array WordPress turns into the robots meta tag. It removes
 * `noarchive`, `nosnippet` and `max-snippet:0`, and never touches `noindex`, `nofollow` or a
 * post meta setting. With an SEO plugin active the plugin owns what is emitted and this
 * does nothing at all: removing a directive behind its back would leave its screen saying one
 * thing and the site doing another.
 */
final class Directives {

	const PRIORITY = 9999;

	/**
	 * The request flag the site's own check sends to see the page without this filter.
	 */
	const BASELINE_FLAG = 'readiness_index_baseline';

	/**
	 * Adds the filter when the fixer is on.
	 *
	 * @param array<string,mixed> $choices The stored choices.
	 */
	public static function register( array $choices ): void {
		unset( $choices );
		add_filter( 'wp_robots', array( self::class, 'filter' ), self::PRIORITY );
	}

	/**
	 * The filter.
	 *
	 * @param array<string,mixed> $robots Directives by name; `true` prints the name, anything else `name:value`.
	 * @return array<string,mixed>
	 */
	public static function filter( $robots ) {
		if ( ! is_array( $robots ) || isset( $_GET[ self::BASELINE_FLAG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A display flag the site's own check sends; it only skips this filter.
			return $robots;
		}
		if ( array() !== array_intersect( SeoTools::active(), array( 'yoast', 'rank_math', 'aioseo', 'seopress', 'tsf' ) ) ) {
			return $robots;
		}
		foreach ( $robots as $name => $value ) {
			if ( ! is_string( $name ) ) {
				continue;
			}
			$token = true === $value || '' === $value ? $name : $name . ':' . $value;
			if ( false !== $value && RobotsDirectives::is_stray( $token ) ) {
				unset( $robots[ $name ] );
			}
		}
		return $robots;
	}
}
