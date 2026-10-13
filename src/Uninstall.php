<?php
/**
 * What deleting the plugin removes.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex;

use ReadinessIndex\Changes\Schema;
use ReadinessIndex\Fixers\Runner;
use ReadinessIndex\Fixers\State;

/**
 * Called by `uninstall.php` for each site. It removes the plugin's own tables and options and
 * does not undo what the fixers changed, unless the owner turned on "Remove my changes when I
 * delete the plugin", in which case each fixer's `cleanup()` runs first. The default is to
 * leave the changes alone because the plugin's changes are filters that stop being served when
 * it is deleted anyway, and an uninstall that also rewrites a client's settings is a surprise.
 */
final class Uninstall {

	const OPTIONS = array(
		'readiness_index_key',
		'readiness_index_connection',
		'readiness_index_site_cache',
		'readiness_index_active_scan',
		'readiness_index_scan_lock',
		Settings::OPTION_REMOVE,
		State::OPTION,
		Schema::VERSION_OPTION,
		'readiness_index_llms',
	);

	/**
	 * Cleans up one site.
	 */
	public static function run(): void {
		if ( Settings::remove_on_uninstall() ) {
			Runner::cleanup_all();
		}
		Schema::drop();
		foreach ( self::OPTIONS as $option ) {
			delete_option( $option );
		}
		wp_clear_scheduled_hook( 'readiness_index_poll_scan' );
		wp_clear_scheduled_hook( 'readiness_index_verify_soon' );
		delete_metadata( 'user', 0, 'readiness_index_dismissed_monitoring', '', true );
		delete_metadata( 'post', 0, '_readiness_index_changed_gmt', '', true );
		delete_transient( 'ri_backoff' );
	}
}
