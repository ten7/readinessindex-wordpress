<?php
/**
 * Reading a decoded response without trusting it.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Api;

use ReadinessIndex\Strings;

/**
 * Typed accessors that raise a `contract` error for a missing or mistyped field.
 *
 * The model classes read only the fields the contract names, through these. A field the
 * contract marks required and the server did not send is a contract error and never an
 * "undefined index" notice: a notice would print into wp-admin, or be silenced, and the
 * screen would draw a half-built score as though it were whole.
 */
final class Shape {

	/**
	 * The error every accessor raises.
	 *
	 * @param string $where Dotted path of the field, for the developer's log line.
	 */
	public static function fail( string $where ): ApiError {
		return new ApiError( ApiError::CONTRACT, Strings::get( 'error.internal_error' ), 0, array( 'path' => $where ) );
	}

	/**
	 * A required object (associative array).
	 *
	 * @param mixed  $value Decoded value.
	 * @param string $where Dotted path.
	 * @return array<string,mixed>
	 * @throws ApiError When it is not an object.
	 */
	public static function object( $value, string $where ): array {
		if ( ! is_array( $value ) || ( array() !== $value && array_keys( $value ) === range( 0, count( $value ) - 1 ) ) ) {
			throw self::fail( $where );
		}
		return $value;
	}

	/**
	 * A required list.
	 *
	 * @param mixed  $value Decoded value.
	 * @param string $where Dotted path.
	 * @return array<int,mixed>
	 * @throws ApiError When it is not a list.
	 */
	public static function items( $value, string $where ): array {
		if ( ! is_array( $value ) || ( array() !== $value && array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) ) {
			throw self::fail( $where );
		}
		return $value;
	}

	/**
	 * A required key of an object, whatever its value (including null).
	 *
	 * @param array<string,mixed> $data  Object.
	 * @param string              $key   Field name.
	 * @param string              $where Dotted path of the object.
	 * @return mixed
	 * @throws ApiError When the key is absent.
	 */
	public static function need( array $data, string $key, string $where ) {
		if ( ! array_key_exists( $key, $data ) ) {
			throw self::fail( $where . '.' . $key );
		}
		return $data[ $key ];
	}

	/**
	 * A required string.
	 *
	 * @param array<string,mixed> $data  Object.
	 * @param string              $key   Field name.
	 * @param string              $where Dotted path of the object.
	 * @throws ApiError When absent or not a string.
	 */
	public static function str( array $data, string $key, string $where ): string {
		$value = self::need( $data, $key, $where );
		if ( ! is_string( $value ) ) {
			throw self::fail( $where . '.' . $key );
		}
		return $value;
	}

	/**
	 * A required string that may be null.
	 *
	 * @param array<string,mixed> $data  Object.
	 * @param string              $key   Field name.
	 * @param string              $where Dotted path of the object.
	 * @throws ApiError When absent or neither a string nor null.
	 */
	public static function str_or_null( array $data, string $key, string $where ): ?string {
		$value = self::need( $data, $key, $where );
		if ( null !== $value && ! is_string( $value ) ) {
			throw self::fail( $where . '.' . $key );
		}
		return $value;
	}

	/**
	 * A required integer. A float is refused: points are whole integers everywhere.
	 *
	 * @param array<string,mixed> $data  Object.
	 * @param string              $key   Field name.
	 * @param string              $where Dotted path of the object.
	 * @throws ApiError When absent or not an integer.
	 */
	public static function int( array $data, string $key, string $where ): int {
		$value = self::need( $data, $key, $where );
		if ( ! is_int( $value ) ) {
			throw self::fail( $where . '.' . $key );
		}
		return $value;
	}

	/**
	 * A required integer that may be null.
	 *
	 * @param array<string,mixed> $data  Object.
	 * @param string              $key   Field name.
	 * @param string              $where Dotted path of the object.
	 * @throws ApiError When absent or neither an integer nor null.
	 */
	public static function int_or_null( array $data, string $key, string $where ): ?int {
		$value = self::need( $data, $key, $where );
		if ( null !== $value && ! is_int( $value ) ) {
			throw self::fail( $where . '.' . $key );
		}
		return $value;
	}

	/**
	 * A required boolean.
	 *
	 * @param array<string,mixed> $data  Object.
	 * @param string              $key   Field name.
	 * @param string              $where Dotted path of the object.
	 * @throws ApiError When absent or not a boolean.
	 */
	public static function bool( array $data, string $key, string $where ): bool {
		$value = self::need( $data, $key, $where );
		if ( ! is_bool( $value ) ) {
			throw self::fail( $where . '.' . $key );
		}
		return $value;
	}

	/**
	 * A required string restricted to a set of values.
	 *
	 * @param array<string,mixed> $data    Object.
	 * @param string              $key     Field name.
	 * @param string              $where   Dotted path of the object.
	 * @param array<int,string>   $allowed Allowed values.
	 * @throws ApiError When absent or not one of them.
	 */
	public static function one_of( array $data, string $key, string $where, array $allowed ): string {
		$value = self::str( $data, $key, $where );
		if ( ! in_array( $value, $allowed, true ) ) {
			throw self::fail( $where . '.' . $key );
		}
		return $value;
	}
}
