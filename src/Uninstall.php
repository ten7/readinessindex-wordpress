<?php
/**
 * What deleting the plugin removes.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex;

use ReadinessIndex\Connect\Connector;
use ReadinessIndex\Scan\Poller;
use ReadinessIndex\Scan\Starter;

/**
 * Called by `uninstall.php` for each site. The plugin stores only its own options, a few
 * short-lived transients and two pieces of user meta (a closed offer and a closed notice), and changes nothing else on the site, so
 * deleting it removes all of that and there is nothing to undo.
 *
 * The connection key is forgotten here and not revoked: revoking is done by the owner on
 * readinessindex.io, where the key lives, and the plugin makes no request while it is deleted.
 */
final class Uninstall {

	const OPTIONS = array(
		Settings::OPTION_KEY,
		Settings::OPTION_CONNECTION,
		Settings::OPTION_SITE_CACHE,
		Settings::OPTION_ACTIVE,
		Starter::LOCK_OPTION,
	);

	/**
	 * Cleans up one site.
	 */
	public static function run(): void {
		global $wpdb;

		foreach ( self::OPTIONS as $option ) {
			delete_option( $option );
		}
		wp_clear_scheduled_hook( Poller::HOOK );
		wp_clear_scheduled_hook( Connector::VERIFY_HOOK );
		delete_metadata( 'user', 0, Admin\Screen::DISMISS_META, '', true );
		delete_metadata( 'user', 0, Admin\Notices::DISMISS_META, '', true );

		// Every transient the plugin sets starts `ri_` and some carry a user id or a hash in the
		// name (the pending Connect state, the waiting marker, a user's flash message), so they
		// cannot be listed. A site with an external object cache holds them there and lets them
		// expire on their own; `ri_backoff` is deleted by name for that case as well.
		delete_transient( Admin\Screen::BACKOFF_TRANSIENT );
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall only, and there is no API that deletes transients by prefix.
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name is $wpdb's own.
				$wpdb->esc_like( '_transient_ri_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_ri_' ) . '%'
			)
		);
	}
}
