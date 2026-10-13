<?php
/**
 * Running and remembering diagnostics.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Diagnostics;

/**
 * Two diagnostics make requests to the site itself, so they run when the owner asks and not
 * when a screen loads. The last result of each is kept in one non-autoloaded option, so the row
 * can show it and the Copy button has something to copy.
 */
final class Runner {

	const OPTION = 'readiness_index_diagnostics';

	/**
	 * The diagnostics that make requests, by key.
	 */
	const RUNNABLE = array( 'cdn', 'js_only' );

	/**
	 * Runs one and stores its result.
	 *
	 * @param string $key `cdn` or `js_only`.
	 * @return Result|null Null for a key that is not runnable.
	 */
	public static function run( string $key ): ?Result {
		if ( ! in_array( $key, self::RUNNABLE, true ) ) {
			return null;
		}
		$result = 'cdn' === $key ? CdnAccess::run() : JsOnly::run();
		$all    = self::all();

		$all[ $key ] = $result->to_array();
		update_option( self::OPTION, $all, false );
		return $result;
	}

	/**
	 * The last result of one, or null.
	 *
	 * @param string $key Diagnostic key.
	 */
	public static function last( string $key ): ?Result {
		$all = self::all();
		return isset( $all[ $key ] ) ? Result::from_array( $all[ $key ] ) : null;
	}

	/**
	 * Every stored result.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function all(): array {
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) ? $all : array();
	}
}
