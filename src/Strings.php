<?php
/**
 * The shared sentences.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex;

/**
 * Loads `strings/en.json`, the file the WordPress plugin and the Drupal module share.
 *
 * The file is copied from the web repository by `bin/sync-contract` and is the only place a
 * sentence is written. Code asks for a key. A missing key returns the key itself, which
 * is visibly wrong on screen and in a test, and never an empty string that reads as a
 * blank row.
 *
 * Returned text is plain, never HTML: the caller escapes it at the point it prints.
 */
final class Strings {

	/**
	 * The parsed file, loaded once.
	 *
	 * @var array<string,string>|null
	 */
	private static $all = null;

	/**
	 * The path of the strings file, overridable by a test.
	 *
	 * @var string|null
	 */
	private static $path = null;

	/**
	 * Points the loader at a different file and forgets what was loaded.
	 *
	 * @param string|null $path File path, or null for the shipped file.
	 */
	public static function use_file( ?string $path ): void {
		self::$path = $path;
		self::$all  = null;
	}

	/**
	 * Whether a key exists.
	 *
	 * @param string $key Dotted key.
	 */
	public static function has( string $key ): bool {
		return array_key_exists( $key, self::all() );
	}

	/**
	 * One sentence with its `{placeholders}` filled in.
	 *
	 * @param string                   $key  Dotted key, such as `score.value`.
	 * @param array<string,string|int> $vars Placeholder values, without the braces.
	 */
	public static function get( string $key, array $vars = array() ): string {
		$all = self::all();
		if ( ! isset( $all[ $key ] ) ) {
			return $key;
		}
		$text = $all[ $key ];
		foreach ( $vars as $name => $value ) {
			$text = str_replace( '{' . $name . '}', (string) $value, $text );
		}
		return $text;
	}

	/**
	 * Every string, for a test that walks them.
	 *
	 * @return array<string,string>
	 */
	public static function all(): array {
		if ( null !== self::$all ) {
			return self::$all;
		}
		$path = self::$path ?? ( dirname( __DIR__ ) . '/strings/en.json' );
		$raw  = is_readable( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file inside the plugin, not a URL.
		$data = false === $raw ? null : json_decode( $raw, true );

		self::$all = array();
		if ( is_array( $data ) ) {
			foreach ( $data as $key => $value ) {
				if ( is_string( $key ) && is_string( $value ) ) {
					self::$all[ $key ] = $value;
				}
			}
		}
		return self::$all;
	}
}
