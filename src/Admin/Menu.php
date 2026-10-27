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

	const CONFIRM_ACTION = 'readiness_index_confirm_disconnect';

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
	 * Adds the menu entry and its one page, Score.
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
		add_submenu_page( ScoreView::SCREEN_SLUG, Strings::get( 'tab.score' ), Strings::get( 'tab.score' ), Actions::CAPABILITY, ScoreView::SCREEN_SLUG, array( self::class, 'render' ) );
	}

	/**
	 * Prints the Score screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( Actions::CAPABILITY ) ) {
			return;
		}
		// The confirm step is reached by a form that carries a nonce, so a link somebody else wrote
		// cannot put an administrator on it. `readiness=subscribed` is the other flag: the address
		// the service returns to after Checkout, which cannot carry a nonce, and it only chooses
		// whether to say welcome back and to ask the service afresh, so a forged one does no more
		// than pressing Try again.
		$confirmed = isset( $_GET['_wpnonce'] ) && false !== wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), self::CONFIRM_ACTION );
		$flags     = array(
			'returned'           => isset( $_GET['readiness'] ) && 'subscribed' === sanitize_key( wp_unslash( $_GET['readiness'] ) ),
			'confirm_disconnect' => $confirmed && isset( $_GET['ri_confirm'] ) && 'disconnect' === sanitize_key( wp_unslash( $_GET['ri_confirm'] ) ),
		);
		$model     = Screen::build( get_current_user_id(), $flags );
		ScoreView::render( $model );
	}

	/**
	 * Loads the script and style on this screen only.
	 *
	 * @param string $hook The admin page hook.
	 */
	public static function enqueue( string $hook ): void {
		// The plugin's one screen: its hook name ends in the page slug.
		if ( false === strpos( $hook, 'readiness-index' ) ) {
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
