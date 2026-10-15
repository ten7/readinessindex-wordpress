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
		register_activation_hook( READINESS_INDEX_FILE, array( self::class, 'activate' ) );
		register_deactivation_hook( READINESS_INDEX_FILE, array( self::class, 'deactivate' ) );

		// First on `init`, so a request for the ownership file is answered before any theme
		// or plugin code that could redirect or 404 it.
		add_action( 'init', array( Connect\OwnershipFile::class, 'maybe_serve' ), 1 );

		// Cron runs outside the admin, so these are registered on every request.
		add_action( Scan\Poller::HOOK, array( Scan\Poller::class, 'run' ) );
		add_action( Connect\Connector::VERIFY_HOOK, array( Connect\Verifier::class, 'run' ) );

		// Everything on the fixer side is wired from here and from nowhere else. Late, because a
		// plugin that loads after this one (Yoast SEO, Rank Math) defines the constants that
		// feature detection reads only once its own file has loaded, and because the switch's
		// filter can come from a plugin that loads after this one.
		add_action( 'plugins_loaded', array( self::class, 'wire_fixers' ), 20 );

		if ( is_admin() ) {
			Admin\Menu::register();
			// A comparison of two numbers; the table is created or upgraded only when it is behind.
			add_action( 'admin_init', array( self::class, 'upgrade' ) );
			Admin\Notices::register();
			add_action( 'admin_init', array( Admin\Privacy::class, 'add_policy_content' ) );
		}
	}

	/**
	 * Registers the fixer side, only when `Features::fixers()` says it is on. Off, it adds no
	 * hook, no screen, no action and no front-end filter, and a change a site applied under an
	 * earlier version is therefore not served.
	 */
	public static function wire_fixers(): void {
		if ( ! Features::fixers() ) {
			return;
		}
		// Only fixers the owner applied add anything, and with none applied this adds no hook.
		Fixers\Hooks::register();
		if ( is_admin() ) {
			Admin\Menu::register_fixers();
			Admin\Actions::register_fixers();
			Admin\Ajax::register_fixers();
			Admin\Notices::register_fixers();
			Fixers\Runner::register();
		}
	}

	/**
	 * Brings a site that got new files without being reactivated (an update, a deploy) to what
	 * activation would have made: the table, and the option the front end reads for free.
	 */
	public static function upgrade(): void {
		// Nothing to create while the fixers are off: no table, no autoloaded option. Turning the
		// switch on later finds the version behind and does this then.
		if ( ! Features::fixers() || (int) get_option( Changes\Schema::VERSION_OPTION, 0 ) >= Changes\Schema::VERSION ) {
			return;
		}
		Fixers\State::seed();
		Changes\Schema::install();
	}

	/**
	 * Activation: the empty map of fixers (so the front end reads it for free) and the table.
	 * A network activation runs this once for the network, and each site creates its own table
	 * the first time its admin loads, which `maybe_upgrade` does.
	 */
	public static function activate(): void {
		if ( ! Features::fixers() ) {
			return;
		}
		Fixers\State::seed();
		Changes\Schema::install();
	}

	/**
	 * Deactivation: stops every scheduled event, so nothing keeps calling the API. The changes
	 * stay on record and resume when the plugin is activated again; nothing is undone, and the
	 * Changes tab and the Plugins screen say so.
	 */
	public static function deactivate(): void {
		Scan\Poller::clear_all();
	}
}
