<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * It removes everything the plugin stored: the connection key, the ownership token, the
 * cached answer and the markers. It does not revoke the key on readinessindex.io, which
 * the owner does from their profile there. It exits unless WordPress started the
 * uninstall, so opening this URL directly does nothing.
 *
 * @package ReadinessIndex
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

foreach ( array(
	'readiness_index_key',
	'readiness_index_connection',
	'readiness_index_site_cache',
	'readiness_index_active_scan',
	'readiness_index_scan_lock',
) as $readiness_index_option ) {
	delete_option( $readiness_index_option );
}

wp_clear_scheduled_hook( 'readiness_index_poll_scan' );
wp_clear_scheduled_hook( 'readiness_index_verify_soon' );

delete_metadata( 'user', 0, 'readiness_index_dismissed_monitoring', '', true );
delete_transient( 'ri_backoff' );
