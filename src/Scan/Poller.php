<?php
/**
 * Watching a scan: one status call, shared by the screen's polling and the cron fallback.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Scan;

use ReadinessIndex\Api\ApiError;
use ReadinessIndex\Api\Client;
use ReadinessIndex\Api\Model\ScanStatus;
use ReadinessIndex\Api\SiteCache;
use ReadinessIndex\Settings;

/**
 * The cron event is the fallback for an owner who started a scan and closed the tab. It
 * reschedules itself while the scan is active and removes itself when the scan finishes,
 * on Disconnect and on deactivation; an event that outlived those would keep calling an
 * API with a key the owner had thrown away.
 */
final class Poller {

	const HOOK     = 'readiness_index_poll_scan';
	const INTERVAL = 60;

	/**
	 * Schedules the next check unless one is already waiting.
	 *
	 * @param int $delay Seconds from now.
	 */
	public static function schedule( int $delay = self::INTERVAL ): void {
		if ( false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + max( 1, $delay ), self::HOOK );
		}
	}

	/**
	 * Removes the event and the marker of the scan being watched.
	 */
	public static function clear(): void {
		wp_clear_scheduled_hook( self::HOOK );
		delete_option( Settings::OPTION_ACTIVE );
	}

	/**
	 * Removes every event the plugin has scheduled. Used on Disconnect and deactivation.
	 */
	public static function clear_all(): void {
		self::clear();
		wp_clear_scheduled_hook( \ReadinessIndex\Connect\Connector::VERIFY_HOOK );
	}

	/**
	 * Asks the server where the watched scan is, and tidies up when it has ended.
	 *
	 * @param Client|null $client API client, or null for the stored one.
	 * @return ScanStatus|null The status, or null when no scan is being watched.
	 * @throws ApiError When the call fails; the caller decides what to show.
	 */
	public static function observe( ?Client $client = null ): ?ScanStatus {
		$active = Starter::active();
		if ( null === $active || ! Settings::is_connected() ) {
			self::clear();
			return null;
		}
		$status = ( $client ?? new Client() )->scan_status( $active['token'] );
		if ( $status->is_finished() ) {
			// The finished score is what site() has to show next, so a stale copy goes.
			SiteCache::clear();
			self::clear();
		}
		return $status;
	}

	/**
	 * The cron callback.
	 *
	 * @param Client|null $client API client, or null for the stored one.
	 */
	public static function run( ?Client $client = null ): void {
		try {
			$status = self::observe( $client );
		} catch ( ApiError $e ) {
			if ( 'unauthenticated' === $e->key || 'account_disabled' === $e->key || 'not_found' === $e->key ) {
				// The connection is gone or the scan is not ours to watch: no reason to ask again.
				self::clear();
				return;
			}
			self::schedule( 'rate_limited' === $e->key ? $e->retry_after() : self::INTERVAL );
			return;
		}
		if ( null !== $status && ! $status->is_finished() ) {
			self::schedule();
		}
	}
}
