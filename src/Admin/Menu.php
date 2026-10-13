<?php
/**
 * The Readiness menu and the assets of its screen.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Admin;

use ReadinessIndex\Fixers\Registry;
use ReadinessIndex\Fixers\Runner;
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
		Runner::register();
	}

	/**
	 * Adds the menu entry, its three tabs as submenu pages, and the hidden preview page.
	 */
	public static function add_menu(): void {
		add_menu_page(
			Strings::get( 'app.name' ),
			Strings::get( 'menu.title' ),
			Actions::CAPABILITY,
			Urls::SCORE,
			array( self::class, 'render' ),
			'dashicons-chart-bar',
			80
		);
		add_submenu_page( Urls::SCORE, Strings::get( 'tab.score' ), Strings::get( 'tab.score' ), Actions::CAPABILITY, Urls::SCORE, array( self::class, 'render' ) );
		add_submenu_page( Urls::SCORE, Strings::get( 'tab.fixes' ), Strings::get( 'tab.fixes' ), Actions::CAPABILITY, Urls::FIXES, array( self::class, 'render_fixes' ) );
		add_submenu_page( Urls::SCORE, Strings::get( 'tab.changes' ), Strings::get( 'tab.changes' ), Actions::CAPABILITY, Urls::CHANGES, array( self::class, 'render_changes' ) );
		// An empty parent registers a page that has no menu entry and is still reachable by its
		// address, under the same capability. A null parent is deprecated.
		add_submenu_page( '', Strings::get( 'fixes.preview' ), '', Actions::CAPABILITY, Urls::PREVIEW, array( self::class, 'render_preview' ) );
	}

	/**
	 * Prints the Score tab.
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
	 * Prints the Fixes tab. The same single call to `GET /site` as the Score tab.
	 */
	public static function render_fixes(): void {
		if ( ! current_user_can( Actions::CAPABILITY ) ) {
			return;
		}
		$user  = get_current_user_id();
		$query = array(
			'ri_kind' => isset( $_GET['ri_kind'] ) ? sanitize_key( wp_unslash( $_GET['ri_kind'] ) ) : 'all', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only: a filter.
			'ri_open' => isset( $_GET['ri_open'] ) ? self::check_id( sanitize_text_field( wp_unslash( $_GET['ri_open'] ) ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only: which row to show open.
		);
		$model = Screen::build( $user );
		FixesView::render( $model, $query, FixFlash::take( $user ) );
	}

	/**
	 * Prints the Changes tab. It reads only the plugin's own table, so it needs no API call.
	 */
	public static function render_changes(): void {
		if ( ! current_user_can( Actions::CAPABILITY ) ) {
			return;
		}
		$user  = get_current_user_id();
		$paged = isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only: a page number.
		ChangesView::render( Screen::light( $user ), FixFlash::take( $user ), $paged );
	}

	/**
	 * Prints a fixer's preview. A GET: it shows and never changes.
	 */
	public static function render_preview(): void {
		if ( ! current_user_can( Actions::CAPABILITY ) ) {
			return;
		}
		$user  = get_current_user_id();
		$id    = isset( $_GET['fixer'] ) ? sanitize_key( wp_unslash( $_GET['fixer'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only: which preview.
		$fixer = Registry::get( $id );
		if ( null === $fixer ) {
			wp_safe_redirect( Urls::fixes() );
			exit;
		}
		if ( isset( $_GET['choices'] ) && is_array( $_GET['choices'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only: the choices to preview.
			$choices = $fixer->sanitize_choices( wp_unslash( $_GET['choices'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by the fixer.
		} else {
			$choices = $fixer->state() ?? $fixer->sanitize_choices( array() );
		}
		PreviewView::render( Screen::light( $user ), $fixer, $choices, FixFlash::take( $user ) );
	}

	/**
	 * A test id such as `AIR-1.2`, or an empty string for anything else.
	 *
	 * @param string $value Candidate.
	 */
	public static function check_id( string $value ): string {
		return 1 === preg_match( '/^AIR-\d{1,2}\.\d{1,2}$/D', $value ) ? $value : '';
	}

	/**
	 * Loads the script and style on this screen only.
	 *
	 * @param string $hook The admin page hook.
	 */
	public static function enqueue( string $hook ): void {
		// Every screen of the plugin: the top-level page, its submenu pages and the hidden
		// preview. Their hook names all end in the page slug.
		if ( false === strpos( $hook, 'readiness-index' ) ) {
			return;
		}
		wp_enqueue_style( 'readiness-index-admin', plugins_url( 'assets/admin.css', READINESS_INDEX_FILE ), array(), READINESS_INDEX_VERSION );
		wp_enqueue_script( 'readiness-index-admin', plugins_url( 'assets/admin.js', READINESS_INDEX_FILE ), array(), READINESS_INDEX_VERSION, true );
		wp_localize_script(
			'readiness-index-admin',
			'readinessIndex',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'action'       => Ajax::ACTION,
				'nonce'        => wp_create_nonce( Ajax::ACTION ),
				'promptAction' => Ajax::PROMPT_ACTION,
				'promptNonce'  => wp_create_nonce( Ajax::PROMPT_ACTION ),
				'minMs'        => 5000,
				'maxMs'        => 15000,
			)
		);
	}
}
