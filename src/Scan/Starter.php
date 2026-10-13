<?php
/**
 * Starting a scan once, however many times the button is pressed.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Scan;

use ReadinessIndex\Api\ApiError;
use ReadinessIndex\Api\Client;
use ReadinessIndex\Api\Model\ScanStarted;
use ReadinessIndex\Api\SiteCache;
use ReadinessIndex\Settings;

/**
 * Guards `POST /cms/v1/scan` against a double click.
 *
 * The server already returns the running scan with `started: false` and spends nothing,
 * which is the real protection. This is the second: two requests that arrive together
 * would both reach the server, and the second would wait on the first for no reason.
 * The lock is an option added with `add_option`, which fails atomically when the row
 * exists, where a transient check-then-set would let both through.
 */
final class Starter {

	const LOCK_OPTION  = 'readiness_index_scan_lock';
	const LOCK_SECONDS = 60;
	/** An active-scan marker older than this is treated as forgotten, not as a scan. */
	const ACTIVE_SECONDS = 1800;

	/**
	 * Starts a scan, or returns the one this site already has.
	 *
	 * @param Client $client API client.
	 * @return ScanStarted|null Null when another request holds the lock or a scan is already marked active.
	 * @throws ApiError From the API (`quota`, `unreachable`, `unauthenticated` and the rest).
	 */
	public static function start( Client $client ): ?ScanStarted {
		$active = self::active();
		if ( null !== $active ) {
			return null;
		}
		if ( ! self::take_lock() ) {
			return null;
		}
		try {
			$started = $client->start_scan();
		} finally {
			delete_option( self::LOCK_OPTION );
		}

		// A scan that started changes what site() says; a cached answer would show the old state.
		SiteCache::clear();
		update_option(
			Settings::OPTION_ACTIVE,
			array(
				'token' => $started->scan,
				'since' => time(),
			),
			false
		);
		Poller::schedule();
		return $started;
	}

	/**
	 * The marker of a scan this site is watching, or null.
	 *
	 * @return array{token:string,since:int}|null
	 */
	public static function active(): ?array {
		$marker = get_option( Settings::OPTION_ACTIVE, null );
		if ( ! is_array( $marker ) || ! isset( $marker['token'], $marker['since'] ) || ! is_string( $marker['token'] ) || ! is_int( $marker['since'] ) ) {
			return null;
		}
		if ( $marker['since'] < time() - self::ACTIVE_SECONDS ) {
			return null;
		}
		return array(
			'token' => $marker['token'],
			'since' => $marker['since'],
		);
	}

	/**
	 * Takes the lock, clearing one left behind by a request that died.
	 */
	private static function take_lock(): bool {
		// The third argument is the deprecated one, the fourth the autoload flag.
		if ( add_option( self::LOCK_OPTION, time(), '', false ) ) {
			return true;
		}
		$held = get_option( self::LOCK_OPTION, 0 );
		if ( is_int( $held ) && $held < time() - self::LOCK_SECONDS ) {
			delete_option( self::LOCK_OPTION );
			return (bool) add_option( self::LOCK_OPTION, time(), '', false );
		}
		return false;
	}
}
