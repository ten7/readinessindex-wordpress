<?php
/**
 * Notices outside the plugin's own screens.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Admin;

use ReadinessIndex\Changes\Log;
use ReadinessIndex\Strings;

/**
 * Two small ones: on a network-activated install, that per-site activation is supported; and,
 * with the fixers on, on the Plugins screen, what deactivating does to the changes.
 */
final class Notices {

	/**
	 * Hooks both.
	 */
	public static function register(): void {
		add_action( 'admin_notices', array( self::class, 'network' ) );
		add_action( 'network_admin_notices', array( self::class, 'network' ) );
	}

	/**
	 * The Plugins screen line about recorded changes, only when the fixers are switched on.
	 */
	public static function register_fixers(): void {
		add_action( 'after_plugin_row_' . plugin_basename( READINESS_INDEX_FILE ), array( self::class, 'plugin_row' ), 10, 0 );
	}

	/**
	 * A line under the plugin's row when it has changes on record.
	 */
	public static function plugin_row(): void {
		if ( ! current_user_can( Actions::CAPABILITY ) || 0 === Log::count() ) {
			return;
		}
		echo '<tr class="plugin-update-tr active"><td colspan="4" class="plugin-update colspanchange"><div class="notice inline notice-info notice-alt"><p>' . esc_html( Strings::get( 'plugin.row_note' ) ) . '</p></div></td></tr>';
	}

	/**
	 * On a network-activated install, on this plugin's own screens and the Plugins screen.
	 */
	public static function network(): void {
		if ( ! is_multisite() || ! current_user_can( Actions::CAPABILITY ) ) {
			return;
		}
		if ( ! is_plugin_active_for_network( plugin_basename( READINESS_INDEX_FILE ) ) ) {
			return;
		}
		$screen = get_current_screen();
		$id     = null === $screen ? '' : (string) $screen->id;
		if ( false === strpos( $id, 'readiness-index' ) && 0 !== strpos( $id, 'plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-info"><p>' . esc_html( Strings::get( 'network.notice' ) ) . '</p></div>';
	}
}
