<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * It removes everything the plugin stored: the connection key, the ownership token, the
 * cached answer, the changes table and the markers. It does not revoke the key on
 * readinessindex.io, which the owner does from their profile there. It undoes what the fixers
 * changed only when the owner turned on "Remove my changes when I delete the plugin". It exits
 * unless WordPress started the uninstall, so opening this URL directly does nothing.
 *
 * @package ReadinessIndex
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// The plugin is not loaded during an uninstall, so its own autoloader is.
require_once plugin_dir_path( __FILE__ ) . 'src/Autoloader.php';
\ReadinessIndex\Autoloader::register( 'ReadinessIndex\\', plugin_dir_path( __FILE__ ) . 'src/' );

if ( is_multisite() ) {
	// Each site has its own options and its own table.
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $readiness_index_site ) {
		switch_to_blog( (int) $readiness_index_site );
		\ReadinessIndex\Uninstall::run();
		restore_current_blog();
	}
} else {
	\ReadinessIndex\Uninstall::run();
}
