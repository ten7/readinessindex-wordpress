<?php
/**
 * Wiring: which hooks exist, and when.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex;

/**
 * The only file that knows every moving part. Admin code is registered only in the admin,
 * so a front-end request loads the autoloader, this class and the ownership-file check, and
 * nothing else.
 */
final class Plugin {

	/**
	 * Registers hooks. Called once, from the main plugin file.
	 */
	public static function boot(): void {
		register_deactivation_hook( READINESS_INDEX_FILE, array( self::class, 'deactivate' ) );

		// First on `init`, so a request for the ownership file is answered before any theme
		// or plugin code that could redirect or 404 it.
		add_action( 'init', array( Connect\OwnershipFile::class, 'maybe_serve' ), 1 );

		// Cron runs outside the admin, so these are registered on every request.
		add_action( Scan\Poller::HOOK, array( Scan\Poller::class, 'run' ) );
		add_action( Connect\Connector::VERIFY_HOOK, array( Connect\Verifier::class, 'run' ) );

		if ( is_admin() ) {
			Admin\Menu::register();
			Admin\Notices::register();
			add_action( 'admin_init', array( Admin\Privacy::class, 'add_policy_content' ) );
		}
	}

	/**
	 * Deactivation: stops every scheduled event, so nothing keeps calling the API. The connection
	 * stays, so activating the plugin again picks up where it was.
	 */
	public static function deactivate(): void {
		Scan\Poller::clear_all();
	}
}
