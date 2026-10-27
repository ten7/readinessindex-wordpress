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
	 * The plugin's own sentences, loaded once.
	 *
	 * @var array<string,string>|null
	 */
	private static $local = null;

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
		self::$path  = $path;
		self::$all   = null;
		self::$local = null;
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
	 * A sentence cut in two at one placeholder, so a screen can wrap that one value in its own
	 * element (the score's number is bold, a count is bold) without the sentence being written
	 * in pieces. Translators keep one sentence; the caller keeps the markup. The text before and
	 * after the value come back as plain text, and the value itself is the caller's to print.
	 *
	 * @param string                   $key         Dotted key.
	 * @param string                   $placeholder The placeholder to cut at, without braces.
	 * @param array<string,string|int> $vars        The other placeholders' values.
	 * @return array{0:string,1:string} The text before and after the placeholder.
	 */
	public static function around( string $key, string $placeholder, array $vars = array() ): array {
		$mark  = "\x1f";
		$parts = explode( $mark, self::get( $key, array_merge( $vars, array( $placeholder => $mark ) ) ), 2 );
		return array( $parts[0], $parts[1] ?? '' );
	}

	/**
	 * Every string, for a test that walks them: the shared contract's, then the plugin's own
	 * for the situations the contract has no sentence for yet.
	 *
	 * @return array<string,string>
	 */
	public static function all(): array {
		if ( null !== self::$all ) {
			return self::$all;
		}
		$path      = self::$path ?? ( dirname( __DIR__ ) . '/strings/en.json' );
		self::$all = self::read( $path );
		// A key the contract has always wins, so a sentence the web repository later adds
		// replaces the plugin's own without an edit here. `StringsTest` fails on the overlap, so
		// the stale local copy is deleted rather than quietly shadowed.
		foreach ( self::local() as $key => $value ) {
			if ( ! isset( self::$all[ $key ] ) ) {
				self::$all[ $key ] = $value;
			}
		}
		return self::$all;
	}

	/**
	 * The sentences that exist only in this plugin: every one a screen of this module needs and
	 * `strings/en.json` does not have. They are proposed to the web repository's kit, and each
	 * is deleted here when the kit adds it.
	 *
	 * @return array<string,string>
	 */
	public static function local(): array {
		if ( null === self::$local ) {
			self::$local = self::read( dirname( __DIR__ ) . '/strings/plugin.en.json' );
		}
		return self::$local;
	}

	/**
	 * Reads one flat JSON file of strings.
	 *
	 * @param string $path File path.
	 * @return array<string,string>
	 */
	private static function read( string $path ): array {
		// Core's own reader for a JSON file on disk, so no file function is called here.
		$data = is_readable( $path ) ? wp_json_file_decode( $path, array( 'associative' => true ) ) : null;
		$out  = array();
		if ( is_array( $data ) ) {
			foreach ( $data as $key => $value ) {
				if ( is_string( $key ) && is_string( $value ) ) {
					$out[ $key ] = $value;
				}
			}
		}
		return $out;
	}
}
