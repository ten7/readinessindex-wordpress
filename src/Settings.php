<?php
/**
 * Where the plugin keeps what it knows: the origin it talks to and the connection key.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex;

/**
 * Reads and writes the plugin's own options.
 *
 * Every option is written with autoload off. The key above all: WordPress loads
 * autoloaded options into memory on every request, front end included, and a secret
 * that is in memory on every page view is in a heap dump, a debug dump and an
 * object-cache snapshot that never needed it.
 */
final class Settings {

	/** The one host the plugin talks to. The readme says so and must keep saying so. */
	const DEFAULT_ORIGIN = 'https://readinessindex.io';

	const OPTION_KEY        = 'readiness_index_key';
	const OPTION_CONNECTION = 'readiness_index_connection';
	const OPTION_SITE_CACHE = 'readiness_index_site_cache';
	const OPTION_ACTIVE     = 'readiness_index_active_scan';
	const OPTION_REMOVE     = 'readiness_index_remove_on_uninstall';

	/**
	 * The shape the server mints: `air_`, eight hex characters, then a url-safe secret.
	 * A callback carrying anything else is refused before it is stored. The `D` modifier
	 * matters: without it `$` also matches before a trailing newline, and a key with one
	 * would be stored and later sent in an Authorization header.
	 */
	const KEY_PATTERN = '/^air_[0-9a-f]{8}_[A-Za-z0-9_-]{20,}$/D';

	/** The ownership token is 32 hex characters (verification.new_token on the server). */
	const TOKEN_PATTERN = '/^[0-9a-f]{32}$/D';

	/**
	 * The API origin, without a trailing slash.
	 *
	 * Overridable only by a constant in wp-config.php, never by an option or a request:
	 * an origin an administrator screen could change is an origin a stolen session could
	 * point at a server that collects the key.
	 */
	public static function origin(): string {
		$origin = self::DEFAULT_ORIGIN;
		if ( defined( 'READINESS_INDEX_API_ORIGIN' ) && is_string( READINESS_INDEX_API_ORIGIN ) && '' !== READINESS_INDEX_API_ORIGIN ) {
			$origin = READINESS_INDEX_API_ORIGIN;
		}
		return rtrim( $origin, '/' );
	}

	/**
	 * The connection key, or an empty string when there is none.
	 *
	 * A constant defined in wp-config.php wins, so a deployment that keeps secrets out
	 * of the database is not overridden by a stale row.
	 */
	public static function key(): string {
		if ( self::key_is_constant() ) {
			return (string) READINESS_INDEX_API_KEY;
		}
		$key = get_option( self::OPTION_KEY, '' );
		return is_string( $key ) ? $key : '';
	}

	/**
	 * Whether the key comes from wp-config.php, in which case Disconnect cannot remove it.
	 */
	public static function key_is_constant(): bool {
		return defined( 'READINESS_INDEX_API_KEY' ) && is_string( READINESS_INDEX_API_KEY ) && '' !== READINESS_INDEX_API_KEY;
	}

	/**
	 * Whether a connection key is held.
	 */
	public static function is_connected(): bool {
		return '' !== self::key();
	}

	/**
	 * Whether a string has the shape of a key the server mints.
	 *
	 * @param string $key Candidate key.
	 */
	public static function key_has_valid_shape( string $key ): bool {
		return 1 === preg_match( self::KEY_PATTERN, $key );
	}

	/**
	 * The last four characters of the key, the most that is ever shown or logged.
	 */
	public static function key_hint(): string {
		$key = self::key();
		return '' === $key ? '' : substr( $key, -4 );
	}

	/**
	 * Stores a key, replacing any held one.
	 *
	 * @param string $key Key with a valid shape.
	 */
	public static function store_key( string $key ): void {
		// The third argument of update_option is the autoload flag.
		update_option( self::OPTION_KEY, $key, false );
	}

	/**
	 * What the callback told us besides the key: the plan's label and the ownership token.
	 *
	 * @return array{plan:string,verify_token:string}
	 */
	public static function connection(): array {
		$stored = get_option( self::OPTION_CONNECTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		return array(
			'plan'         => isset( $stored['plan'] ) && is_string( $stored['plan'] ) ? $stored['plan'] : '',
			'verify_token' => isset( $stored['verify_token'] ) && is_string( $stored['verify_token'] ) ? $stored['verify_token'] : '',
		);
	}

	/**
	 * Stores the callback's plan label and ownership token.
	 *
	 * @param string $plan         The plan's label.
	 * @param string $verify_token The ownership token.
	 */
	public static function store_connection( string $plan, string $verify_token ): void {
		update_option(
			self::OPTION_CONNECTION,
			array(
				'plan'         => $plan,
				'verify_token' => $verify_token,
			),
			false
		);
	}

	/**
	 * Whether deleting the plugin also removes what its fixers changed. Off unless the owner
	 * turned it on: an uninstall that silently undoes a client's robots.txt is a surprise.
	 */
	public static function remove_on_uninstall(): bool {
		return '1' === (string) get_option( self::OPTION_REMOVE, '0' );
	}

	/**
	 * Stores the choice made on the Changes tab.
	 *
	 * @param bool $remove Whether to remove changes on uninstall.
	 */
	public static function set_remove_on_uninstall( bool $remove ): void {
		update_option( self::OPTION_REMOVE, $remove ? '1' : '0', false );
	}

	/**
	 * Forgets everything this site was told: the key, the token and the cached answer.
	 */
	public static function forget_connection(): void {
		delete_option( self::OPTION_KEY );
		delete_option( self::OPTION_CONNECTION );
		delete_option( self::OPTION_SITE_CACHE );
		delete_option( self::OPTION_ACTIVE );
	}
}
