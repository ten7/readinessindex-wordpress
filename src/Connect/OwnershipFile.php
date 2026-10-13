<?php
/**
 * The ownership file the server asks this site to serve.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Connect;

use ReadinessIndex\Settings;

/**
 * Serves the owner's token as the exact body of `/.well-known/readinessindex-verify`.
 *
 * It is proof that the person controls the site's code, which DNS cannot give a plugin.
 * The token is a proof of control and not a credential: it opens nothing and may be on a
 * page, so serving it to anybody who asks is correct. It is the body and only the body,
 * with no newline, because the server compares it exactly.
 */
final class OwnershipFile {

	const PATH = '/.well-known/readinessindex-verify';

	/**
	 * What to serve for a request path, or null when the request is not for the file.
	 *
	 * @param string $path Request path without a query string.
	 */
	public static function body_for( string $path ): ?string {
		if ( self::PATH !== $path ) {
			return null;
		}
		$token = Settings::connection()['verify_token'];
		return 1 === preg_match( Settings::TOKEN_PATTERN, $token ) ? $token : null;
	}

	/**
	 * Answers the request itself when it is for the file. Hooked early on `init`.
	 *
	 * Runs on the front end for every request, so it does nothing but compare a string
	 * until the path matches.
	 */
	public static function maybe_serve(): void {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$uri  = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		if ( ! is_string( $path ) ) {
			return;
		}
		$body = self::body_for( $path );
		if ( null === $body ) {
			return;
		}
		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		nocache_headers();
		// The token has been matched against a 32-character hex pattern, so escaping changes nothing.
		echo esc_html( $body );
		exit;
	}
}
