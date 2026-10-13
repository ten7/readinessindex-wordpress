<?php
/**
 * The Readiness menu and the assets of its screen.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Admin;

use ReadinessIndex\Strings;

/**
 * Registers the top-level Readiness menu and prints the Score screen. Loaded only in the
 * admin: nothing here exists on a front-end request.
 */
final class Menu {

	/**
	 * Hooks the menu and the assets.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		Actions::register();
		Ajax::register();
	}

	/**
	 * Adds the menu entry for people who can manage the site.
	 */
	public static function add_menu(): void {
		add_menu_page(
			Strings::get( 'app.name' ),
			Strings::get( 'menu.title' ),
			Actions::CAPABILITY,
			ScoreView::SCREEN_SLUG,
			array( self::class, 'render' ),
			'dashicons-chart-bar',
			80
		);
	}

	/**
	 * Prints the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( Actions::CAPABILITY ) ) {
			return;
		}
		// These flags only choose what to display and change nothing, so they carry no nonce.
		$flags = array(
			'returned'           => isset( $_GET['readiness'] ) && 'subscribed' === sanitize_key( wp_unslash( $_GET['readiness'] ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
			'confirm_disconnect' => isset( $_GET['ri_confirm'] ) && 'disconnect' === sanitize_key( wp_unslash( $_GET['ri_confirm'] ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		);
		$model = Screen::build( get_current_user_id(), $flags );
		ScoreView::render( $model );
	}

	/**
	 * Loads the script and style on this screen only.
	 *
	 * @param string $hook The admin page hook.
	 */
	public static function enqueue( string $hook ): void {
		if ( 'toplevel_page_' . ScoreView::SCREEN_SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'readiness-index-admin', plugins_url( 'assets/admin.css', READINESS_INDEX_FILE ), array(), READINESS_INDEX_VERSION );
		wp_enqueue_script( 'readiness-index-admin', plugins_url( 'assets/admin.js', READINESS_INDEX_FILE ), array(), READINESS_INDEX_VERSION, true );
		wp_localize_script(
			'readiness-index-admin',
			'readinessIndex',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => Ajax::ACTION,
				'nonce'   => wp_create_nonce( Ajax::ACTION ),
				'minMs'   => 5000,
				'maxMs'   => 15000,
			)
		);
	}
}
