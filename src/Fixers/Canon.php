<?php
/**
 * Comparing stored values.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Fixers;

/**
 * Two values are the same when they have the same shape and content, whatever the order of an
 * object's keys. An empty list and an empty object are one thing in PHP and compare equal,
 * which is the vector schema's own rule.
 */
final class Canon {

	/**
	 * A value with object keys sorted, for comparing and hashing.
	 *
	 * @param mixed $value Any decoded JSON value.
	 * @return mixed
	 */
	public static function sort( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$is_list = array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) {
			ksort( $value );
		}
		return array_map( array( self::class, 'sort' ), $value );
	}

	/**
	 * Whether two values are the same.
	 *
	 * @param mixed $a One.
	 * @param mixed $b The other.
	 */
	public static function same( $a, $b ): bool {
		return self::sort( $a ) === self::sort( $b );
	}

	/**
	 * A stable hash of a value.
	 *
	 * @param mixed $value Any decoded JSON value.
	 */
	public static function hash( $value ): string {
		return hash( 'sha256', (string) wp_json_encode( self::sort( $value ) ) );
	}
}
