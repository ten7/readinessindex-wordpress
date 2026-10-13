<?php
/**
 * Addresses of the plugin's screens.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Admin;

/**
 * One place that knows the slugs, so a link on one screen cannot drift from the page another
 * registers.
 */
final class Urls {

	const SCORE   = 'readiness-index';
	const FIXES   = 'readiness-index-fixes';
	const CHANGES = 'readiness-index-changes';
	const PREVIEW = 'readiness-index-preview';

	/**
	 * A screen's address.
	 *
	 * @param string              $slug Page slug.
	 * @param array<string,mixed> $args Query arguments. Nested arrays are encoded as `a[b]=c`.
	 */
	public static function to( string $slug, array $args = array() ): string {
		$url = admin_url( 'admin.php?page=' . $slug );
		if ( array() !== $args ) {
			$url .= '&' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
		}
		return $url;
	}

	/**
	 * The Score tab.
	 */
	public static function score(): string {
		return self::to( self::SCORE );
	}

	/**
	 * The Fixes tab.
	 *
	 * @param array<string,string> $args Filters and the open row.
	 */
	public static function fixes( array $args = array() ): string {
		return self::to( self::FIXES, $args );
	}

	/**
	 * The Changes tab.
	 */
	public static function changes(): string {
		return self::to( self::CHANGES );
	}

	/**
	 * A fixer's preview page: linkable, and showing, never changing.
	 *
	 * @param string              $fixer   Fixer id.
	 * @param array<string,mixed> $choices The choices to preview.
	 */
	public static function preview( string $fixer, array $choices = array() ): string {
		$args = array( 'fixer' => $fixer );
		if ( array() !== $choices ) {
			$args['choices'] = $choices;
		}
		return self::to( self::PREVIEW, $args );
	}
}
