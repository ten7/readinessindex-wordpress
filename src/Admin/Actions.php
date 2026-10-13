<?php
/**
 * The admin-post actions: Connect, its return, Disconnect, Scan now, Check ownership.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Admin;

use ReadinessIndex\Api\ApiError;
use ReadinessIndex\Api\Client;
use ReadinessIndex\Api\SiteCache;
use ReadinessIndex\Connect\Connector;
use ReadinessIndex\Connect\Verifier;
use ReadinessIndex\Scan\Poller;
use ReadinessIndex\Scan\Starter;
use ReadinessIndex\Settings;

/**
 * Each public `handle_*` method is a hook callback: it checks the capability first, then the
 * nonce, then calls a `do_*` method that holds the logic and returns, so the logic can be
 * tested without a redirect and an `exit`.
 *
 * The capability check comes before the nonce check on purpose: a person without the
 * capability learns nothing from a nonce error about whether the action exists.
 */
final class Actions {

	const CAPABILITY = 'manage_options';

	const SAVE_SETTINGS = 'readiness_index_save_settings';

	/**
	 * Registers every action. The return from readinessindex.io is also registered for
	 * visitors who are not signed in, which is explained at `handle_callback`.
	 */
	public static function register(): void {
		add_action( 'admin_post_' . Connector::ACTION_START, array( self::class, 'handle_connect' ) );
		add_action( 'admin_post_' . Connector::ACTION_CALLBACK, array( self::class, 'handle_callback' ) );
		add_action( 'admin_post_nopriv_' . Connector::ACTION_CALLBACK, array( self::class, 'handle_callback' ) );
		add_action( 'admin_post_readiness_index_disconnect', array( self::class, 'handle_disconnect' ) );
		add_action( 'admin_post_readiness_index_scan', array( self::class, 'handle_scan' ) );
		add_action( 'admin_post_readiness_index_verify', array( self::class, 'handle_verify' ) );
		add_action( 'admin_post_readiness_index_refresh', array( self::class, 'handle_refresh' ) );
		add_action( 'admin_post_readiness_index_dismiss', array( self::class, 'handle_dismiss' ) );
		add_action( 'admin_post_' . self::SAVE_SETTINGS, array( self::class, 'handle_save_settings' ) );
	}

	/**
	 * Capability, then nonce. Ends the request on failure.
	 *
	 * @param string $nonce_action The nonce action, which is the `admin-post` action's name.
	 */
	public static function guard( string $nonce_action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'default' ), '', array( 'response' => 403 ) ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- A WordPress core sentence, in the visitor's language.
		}
		check_admin_referer( $nonce_action );
	}

	/**
	 * Ends an action by returning to the screen.
	 */
	private static function back(): void {
		wp_safe_redirect( ScoreView::screen_url() );
		exit;
	}

	/**
	 * Connect: sends the browser to readinessindex.io.
	 */
	public static function handle_connect(): void {
		self::guard( Connector::ACTION_START );
		// The origin is a constant, not user input, and is not on WordPress's own-host list.
		wp_redirect( Connector::start_url( get_current_user_id() ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	/**
	 * The return from readinessindex.io: a cross-site POST of key, state, plan and token.
	 *
	 * A cross-site POST does not carry a SameSite=Lax cookie, so a signed-in administrator
	 * can arrive here looking signed out, and `admin_post_nopriv_` is the hook that fires.
	 * Refusing there would make Connect fail for most people, so a pending state is relayed:
	 * the fields are posted again from this site to this site, a same-site request that
	 * carries the cookie, and the signed-in branch below does the real work. Nothing is
	 * stored before then, and a post that does not carry a state this site minted is dropped
	 * before anything is echoed.
	 */
	public static function handle_callback(): void {
		$fields = self::callback_fields();
		// Posted once already and still not signed in means the person really is signed out.
		$relayed = isset( $_POST['ri_relayed'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The state is the CSRF defense of a cross-site return, as documented above.
		$plan    = self::callback_plan( $fields, is_user_logged_in(), $relayed );

		if ( 'login' === $plan ) {
			wp_safe_redirect( wp_login_url( ScoreView::screen_url() ) );
			exit;
		}
		if ( 'relay' === $plan ) {
			self::print_relay( $fields );
			exit;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'default' ), '', array( 'response' => 403 ) ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- A WordPress core sentence.
		}

		Flash::put( get_current_user_id(), 'connect', self::do_callback( $fields, get_current_user_id() ) );
		self::back();
	}

	/**
	 * What to do with a return: `login`, `relay` or `process`.
	 *
	 * @param array<string,string> $fields    Sanitized callback fields.
	 * @param bool                 $logged_in Whether the request carries a signed-in session.
	 * @param bool                 $relayed   Whether this post is already the relay's second hop.
	 */
	public static function callback_plan( array $fields, bool $logged_in, bool $relayed ): string {
		if ( $logged_in ) {
			return 'process';
		}
		if ( $relayed || ! Connector::state_is_pending( $fields['state'] ?? '' ) ) {
			return 'login';
		}
		return 'relay';
	}

	/**
	 * Applies a return and reports the outcome.
	 *
	 * @param array<string,string> $fields  Sanitized callback fields.
	 * @param int                  $user_id The signed-in administrator.
	 * @return string A Connector outcome constant.
	 */
	public static function do_callback( array $fields, int $user_id ): string {
		return Connector::receive( $fields, $user_id );
	}

	/**
	 * The callback's fields, each sanitized the moment it is read and length-capped.
	 *
	 * @return array<string,string>
	 */
	private static function callback_fields(): array {
		$out = array();
		foreach ( array( 'key', 'state', 'plan', 'verify_token', 'error' ) as $name ) {
			if ( isset( $_POST[ $name ] ) && is_string( $_POST[ $name ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- See handle_callback.
				$out[ $name ] = substr( sanitize_text_field( wp_unslash( $_POST[ $name ] ) ), 0, Connector::MAX_FIELD ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized here.
			}
		}
		return $out;
	}

	/**
	 * Prints the page that posts the return again from this site.
	 *
	 * @param array<string,string> $fields The fields to carry.
	 */
	private static function print_relay( array $fields ): void {
		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );
		echo '<!doctype html><html><head><meta charset="utf-8"><meta name="robots" content="noindex"></head><body>';
		echo '<form id="ri-relay" method="post" action="' . esc_url( Connector::callback_url() ) . '">';
		foreach ( $fields as $name => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" />';
		}
		echo '<input type="hidden" name="ri_relayed" value="1" />';
		echo '<noscript><button type="submit">' . esc_html( \ReadinessIndex\Strings::get( 'common.connect' ) ) . '</button></noscript>';
		echo '</form>';
		wp_print_inline_script_tag( 'document.getElementById("ri-relay").submit();' );
		echo '</body></html>';
	}

	/**
	 * Disconnect: forgets the key on this site. It does not revoke it; the owner does that
	 * on the website, and a new Connect replaces it.
	 */
	public static function handle_disconnect(): void {
		self::guard( 'readiness_index_disconnect' );
		self::do_disconnect( get_current_user_id() );
		self::back();
	}

	/**
	 * Forgets everything the connection gave this site, and stops everything it started.
	 *
	 * @param int $user_id The signed-in administrator.
	 */
	public static function do_disconnect( int $user_id ): void {
		Settings::forget_connection();
		SiteCache::clear();
		Poller::clear_all();
		delete_transient( Screen::BACKOFF_TRANSIENT );
		Flash::put( $user_id, 'disconnect', 'done' );
	}

	/**
	 * Scan now.
	 */
	public static function handle_scan(): void {
		self::guard( 'readiness_index_scan' );
		self::do_scan( get_current_user_id() );
		self::back();
	}

	/**
	 * Starts a scan and records a refusal in the server's own words.
	 *
	 * @param int         $user_id The signed-in administrator.
	 * @param Client|null $client  API client, or null for the stored one.
	 */
	public static function do_scan( int $user_id, ?Client $client = null ): void {
		try {
			Starter::start( $client ?? new Client() );
		} catch ( ApiError $e ) {
			Flash::put(
				$user_id,
				'scan',
				$e->key,
				array(
					'message' => $e->getMessage(),
					'reason'  => $e->reason(),
				)
			);
			SiteCache::clear();
		}
	}

	/**
	 * Check ownership.
	 */
	public static function handle_verify(): void {
		self::guard( 'readiness_index_verify' );
		$result = Verifier::check();
		Flash::put( get_current_user_id(), 'verify', $result['key'], array( 'message' => $result['message'] ) );
		self::back();
	}

	/**
	 * Try again: forgets the cached answer and the back-off so the next load asks afresh.
	 */
	public static function handle_refresh(): void {
		self::guard( 'readiness_index_refresh' );
		SiteCache::clear();
		delete_transient( Screen::BACKOFF_TRANSIENT );
		self::back();
	}

	/**
	 * Closes the monitoring line for this user.
	 */
	public static function handle_dismiss(): void {
		self::guard( 'readiness_index_dismiss' );
		update_user_meta( get_current_user_id(), Screen::DISMISS_META, '1' );
		self::back();
	}

	/**
	 * Saves the one setting on the Changes tab.
	 */
	public static function handle_save_settings(): void {
		self::guard( self::SAVE_SETTINGS );
		Settings::set_remove_on_uninstall( isset( $_POST['remove_on_uninstall'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['remove_on_uninstall'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by guard above.
		wp_safe_redirect( Urls::changes() );
		exit;
	}
}
