<?php
/**
 * Loads the golden fixer vectors copied from the web repository.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Tests\Support;

/**
 * Every fixer test reads its situation and its expected results from
 * `tests/fixtures/parity/fixers/`, which `bin/sync-contract` copies and pins. Nothing a fixer
 * is expected to produce is typed in a test.
 */
final class Vectors {

	/**
	 * The directory the vectors were copied to.
	 */
	public static function dir(): string {
		return dirname( __DIR__, 2 ) . '/fixtures/parity/fixers';
	}

	/**
	 * Every vector, keyed `fixer/case`, as a data provider wants them.
	 *
	 * @return array<string,array{0:array<string,mixed>}>
	 */
	public static function all(): array {
		$out   = array();
		$files = glob( self::dir() . '/*/*.json' );
		sort( $files );
		foreach ( $files as $file ) {
			$vector = json_decode( (string) file_get_contents( $file ), true );
			$key    = basename( dirname( $file ) ) . '/' . basename( $file, '.json' );

			$out[ $key ] = array( $vector );
		}
		return $out;
	}

	/**
	 * The vectors of one fixer.
	 *
	 * @param string $fixer Fixer id.
	 * @return array<string,array{0:array<string,mixed>}>
	 */
	public static function of( string $fixer ): array {
		return array_filter(
			self::all(),
			static function ( string $key ) use ( $fixer ): bool {
				return 0 === strpos( $key, $fixer . '/' );
			},
			ARRAY_FILTER_USE_KEY
		);
	}

	/**
	 * A value in a form two equal results share: object keys sorted, lists kept in order.
	 * Empty objects and empty lists are one thing in PHP and compare equal, which is the
	 * vector schema's own rule ("JSON objects compare without regard to key order").
	 *
	 * @param mixed $value Any decoded JSON value.
	 * @return mixed
	 */
	public static function canon( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( $is_list ) {
			return array_map( array( self::class, 'canon' ), $value );
		}
		ksort( $value );
		return array_map( array( self::class, 'canon' ), $value );
	}
}
