<?php
/**
 * Page caches that can hide a change.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Fixers;

/**
 * A change that is saved but not served is, nine times in ten, a page cache. This names the
 * ones the plugin knows (so the message can say which) and asks a detected one to clear itself
 * through the function or action its own documentation gives, and never by deleting its files.
 */
final class PageCache {

	/**
	 * Product names by key. Names of products, which are proper nouns and not sentences.
	 */
	const NAMES = array(
		'wp_rocket'  => 'WP Rocket',
		'w3tc'       => 'W3 Total Cache',
		'litespeed'  => 'LiteSpeed Cache',
		'cloudflare' => 'Cloudflare APO',
		'pantheon'   => 'Pantheon',
		'wpengine'   => 'WP Engine',
	);

	/**
	 * The key of the page cache this site runs, or null.
	 */
	public static function detect(): ?string {
		if ( function_exists( 'rocket_clean_domain' ) ) {
			return 'wp_rocket';
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			return 'w3tc';
		}
		if ( defined( 'LSCWP_V' ) || has_action( 'litespeed_purge_all' ) ) {
			return 'litespeed';
		}
		if ( class_exists( 'CF\WordPress\Hooks' ) || has_action( 'cloudflare_purge_everything' ) ) {
			return 'cloudflare';
		}
		// Hosts that cache in front of WordPress and give a function to clear it.
		if ( function_exists( 'pantheon_clear_edge_all' ) ) {
			return 'pantheon';
		}
		if ( class_exists( 'WpeCommon', false ) && method_exists( 'WpeCommon', 'purge_varnish_cache' ) ) {
			return 'wpengine';
		}
		return null;
	}

	/**
	 * The product's name.
	 *
	 * @param string $key A key from `detect`.
	 */
	public static function name( string $key ): string {
		return self::NAMES[ $key ] ?? $key;
	}

	/**
	 * Clears the detected cache through its documented public entry point.
	 *
	 * @return string|null The key purged, or null when none is installed.
	 */
	public static function purge(): ?string {
		$key = self::detect();
		switch ( $key ) {
			case 'wp_rocket':
				rocket_clean_domain();
				break;
			case 'w3tc':
				w3tc_flush_all();
				break;
			case 'litespeed':
				do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's documented purge action.
				break;
			case 'cloudflare':
				do_action( 'cloudflare_purge_everything' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The Cloudflare plugin's documented purge action.
				break;
			case 'pantheon':
				pantheon_clear_edge_all();
				break;
			case 'wpengine':
				\WpeCommon::purge_varnish_cache();
				break;
		}
		return $key;
	}
}
