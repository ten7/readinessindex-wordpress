<?php
/**
 * The Connect flow with the Readiness account.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Connect;

use ReadinessIndex\Api\SiteCache;
use ReadinessIndex\Settings;

/**
 * Starts the flow (the redirect to `/connect/cms`) and receives its return (the POST the
 * browser makes to this site's callback).
 *
 * The `state` is the only thing standing between this site and a stranger's key, because
 * the return is a cross-site POST that cannot carry a WordPress nonce. So it is random,
 * bound to the user who pressed Connect, expires, and is spent by the first attempt that
 * finds it, whatever that attempt then does with the key.
 */
final class Connector {

	const ACTION_START    = 'readiness_index_connect';
	const ACTION_CALLBACK = 'readiness_index_callback';
	const STATE_TTL       = 1200;
	const VERIFY_HOOK     = 'readiness_index_verify_soon';

	/** Longest value a callback field may have before it is refused unread. */
	const MAX_FIELD = 200;

	/**
	 * Outcomes of a callback, each mapped to a sentence by the screen.
	 */
	const CONNECTED      = 'connected';
	const DENIED         = 'denied';
	const STATE_MISMATCH = 'state_mismatch';
	const INVALID        = 'invalid';

	/**
	 * The site's root as the server wants it: https, the host, nothing else.
	 */
	public static function site_url(): string {
		$home = wp_parse_url( home_url() );
		if ( ! is_array( $home ) || empty( $home['host'] ) ) {
			return '';
		}
		$scheme = isset( $home['scheme'] ) ? $home['scheme'] : 'https';
		return $scheme . '://' . $home['host'];
	}

	/**
	 * Where the browser returns to: the platform's own `admin-post.php`, with our action.
	 */
	public static function callback_url(): string {
		return admin_url( 'admin-post.php' ) . '?action=' . self::ACTION_CALLBACK;
	}

	/**
	 * Mints a state for a user, remembers it, and returns the URL to send the browser to.
	 *
	 * @param int $user_id The administrator who pressed Connect.
	 */
	public static function start_url( int $user_id ): string {
		$state = bin2hex( random_bytes( 20 ) );
		$hash  = hash( 'sha256', $state );

		set_transient(
			self::state_name( $hash ),
			array(
				'user' => $user_id,
				'hash' => $hash,
				'exp'  => time() + self::STATE_TTL,
			),
			self::STATE_TTL
		);
		// So the Score screen can say it is waiting if the person comes back without finishing.
		set_transient( self::waiting_name( $user_id ), 1, self::STATE_TTL );

		return Settings::origin() . '/connect/cms?' . http_build_query(
			array(
				'platform' => 'wordpress',
				'site'     => self::site_url(),
				'callback' => self::callback_url(),
				'state'    => $state,
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
	}

	/**
	 * Whether this user pressed Connect recently and has not come back.
	 *
	 * @param int $user_id User id.
	 */
	public static function is_waiting( int $user_id ): bool {
		return false !== get_transient( self::waiting_name( $user_id ) );
	}

	/**
	 * Whether a state is one this site minted and has not spent or let expire.
	 *
	 * Used by the pre-login relay to refuse a post that cannot be ours before it is echoed
	 * back, without spending the state, which only a logged-in user may do.
	 *
	 * @param string $state Candidate state.
	 */
	public static function state_is_pending( string $state ): bool {
		return null !== self::find_state( $state );
	}

	/**
	 * Handles the return. Nothing is stored until every check has passed.
	 *
	 * The caller has already checked `manage_options`; the checks here are the state, the
	 * outcome the server reports and the shape of what it sent.
	 *
	 * @param array<string,string> $fields  Sanitized callback fields: key, state, plan, verify_token, error.
	 * @param int                  $user_id The signed-in user.
	 * @return string One of the outcome constants.
	 */
	public static function receive( array $fields, int $user_id ): string {
		$state = isset( $fields['state'] ) ? $fields['state'] : '';
		$entry = self::find_state( $state );

		// Unknown, expired or malformed: nothing to spend, and nothing to learn from.
		if ( null === $entry || $entry['user'] !== $user_id ) {
			return self::STATE_MISMATCH;
		}

		// Single use. Spent here, before the key is looked at, so a replay of a captured
		// callback finds nothing even when the first attempt carried a malformed key.
		delete_transient( self::state_name( $entry['hash'] ) );
		delete_transient( self::waiting_name( $user_id ) );

		if ( isset( $fields['error'] ) && 'access_denied' === $fields['error'] ) {
			return self::DENIED;
		}

		$key   = isset( $fields['key'] ) ? $fields['key'] : '';
		$token = isset( $fields['verify_token'] ) ? $fields['verify_token'] : '';
		if ( ! Settings::key_has_valid_shape( $key ) || 1 !== preg_match( Settings::TOKEN_PATTERN, $token ) ) {
			return self::INVALID;
		}

		$plan = isset( $fields['plan'] ) ? substr( $fields['plan'], 0, 100 ) : '';

		Settings::store_key( $key );
		Settings::store_connection( $plan, $token );
		// A new key can belong to a different account, and the old answer was theirs.
		SiteCache::clear();
		delete_option( Settings::OPTION_ACTIVE );

		// The server fetches the ownership file from this site. Doing that during the request
		// that is still answering the browser can starve a host with one PHP worker.
		wp_clear_scheduled_hook( self::VERIFY_HOOK );
		wp_schedule_single_event( time() + 10, self::VERIFY_HOOK );

		return self::CONNECTED;
	}

	/**
	 * The stored entry for a state, when it is valid.
	 *
	 * @param string $state Candidate state.
	 * @return array{user:int,hash:string,exp:int}|null
	 */
	private static function find_state( string $state ): ?array {
		if ( '' === $state || strlen( $state ) > self::MAX_FIELD || 1 !== preg_match( '/^[\x21-\x7e]+$/D', $state ) ) {
			return null;
		}
		$hash  = hash( 'sha256', $state );
		$entry = get_transient( self::state_name( $hash ) );
		if ( ! is_array( $entry ) || ! isset( $entry['user'], $entry['hash'], $entry['exp'] ) ) {
			return null;
		}
		// The lookup is by hash, so a timing difference could only reveal that a hash is
		// present; the comparison itself is still constant-time.
		if ( ! is_string( $entry['hash'] ) || ! hash_equals( $entry['hash'], $hash ) ) {
			return null;
		}
		// An object cache may keep a transient past its time. The expiry is our own as well.
		if ( ! is_int( $entry['exp'] ) || $entry['exp'] < time() ) {
			delete_transient( self::state_name( $hash ) );
			return null;
		}
		return array(
			'user' => (int) $entry['user'],
			'hash' => $entry['hash'],
			'exp'  => $entry['exp'],
		);
	}

	/**
	 * Transient name for a state's hash.
	 *
	 * @param string $hash SHA-256 of the state.
	 */
	private static function state_name( string $hash ): string {
		return 'ri_state_' . substr( $hash, 0, 40 );
	}

	/**
	 * Transient name for a user's waiting marker.
	 *
	 * @param int $user_id User id.
	 */
	private static function waiting_name( int $user_id ): string {
		return 'ri_waiting_' . $user_id;
	}
}
