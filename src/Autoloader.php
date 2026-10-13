<?php
/**
 * A small PSR-4 autoloader.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex;

/**
 * Maps a namespace prefix to a directory and loads classes from it on demand.
 *
 * Composer's autoloader is for development only. The plugin is released as the
 * repository's files without `vendor/`, so a runtime that relied on it would
 * fatal on the first class lookup of every real install.
 */
final class Autoloader {

	/**
	 * Registers a prefix with the SPL autoload stack.
	 *
	 * @param string $prefix   Namespace prefix with a trailing backslash.
	 * @param string $base_dir Directory the prefix maps to, with a trailing slash.
	 * @return callable The registered loader, so a test can unregister it.
	 */
	public static function register( string $prefix, string $base_dir ): callable {
		$loader = static function ( string $class_name ) use ( $prefix, $base_dir ): void {
			self::load( $class_name, $prefix, $base_dir );
		};
		spl_autoload_register( $loader );
		return $loader;
	}

	/**
	 * Resolves a class name to a file and requires it when it exists.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @param string $prefix     Namespace prefix with a trailing backslash.
	 * @param string $base_dir   Directory the prefix maps to, with a trailing slash.
	 */
	public static function load( string $class_name, string $prefix, string $base_dir ): void {
		if ( 0 !== strncmp( $class_name, $prefix, strlen( $prefix ) ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_file( $file ) ) {
			require_once $file;
		}
	}
}
