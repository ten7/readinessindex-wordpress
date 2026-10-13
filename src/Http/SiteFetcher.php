<?php
/**
 * Fetching this site's own pages.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Http;

/**
 * The one place that makes a request to the site itself, as an anonymous browser would: to
 * check that a change is really served, and to see which directives each page emits.
 *
 * It refuses any address whose host is not this site's, so no diagnostic can be turned into a
 * request to somewhere else, and it never sends a cookie, a nonce or the connection key. It
 * uses WordPress's safe request function, which refuses private and loopback targets except for
 * the site's own host, which is exactly the one allowed here. A request that fails is a check
 * that says it could not look, never one that is forced through.
 */
final class SiteFetcher {

	/**
	 * Fetches a URL of this site.
	 *
	 * @param string      $url        A URL on this site's host.
	 * @param string|null $user_agent The User-Agent to send, or null for a plain browser-like one.
	 * @return array{status:int,body:string,headers:array<string,string>}|null Null when it is not this site's, or could not be fetched.
	 */
	public static function get( string $url, ?string $user_agent = null ): ?array {
		if ( ! self::is_own( $url ) ) {
			return null;
		}
		// A query string no page cache has stored, so the answer is the origin's.
		$url      = add_query_arg( 'ri_check', (string) wp_rand( 100000, 999999 ), $url );
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'     => 8,
				'redirection' => 3,
				'sslverify'   => true,
				'cookies'     => array(),
				'headers'     => array(
					'Cache-Control' => 'no-cache',
					'Pragma'        => 'no-cache',
				),
				'user-agent'  => $user_agent ?? 'Mozilla/5.0 (compatible; ReadinessIndexSiteCheck)',
			)
		);
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$headers = array();
		$raw     = wp_remote_retrieve_headers( $response );
		foreach ( is_object( $raw ) && method_exists( $raw, 'getAll' ) ? $raw->getAll() : (array) $raw as $name => $value ) {
			$headers[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}
		return array(
			'status'  => (int) wp_remote_retrieve_response_code( $response ),
			'body'    => (string) wp_remote_retrieve_body( $response ),
			'headers' => $headers,
		);
	}

	/**
	 * Whether the site's own request was turned away rather than answered: a firewall's or a CDN's
	 * challenge, a rate limit, a password. The page that answered is then not the site's page, and
	 * comparing it with what the fixer made would say "not live" about a change that may be perfectly
	 * live. Callers say they could not look instead.
	 *
	 * @param array{status:int,body:string,headers:array<string,string>}|null $got A result of `get`.
	 */
	public static function was_turned_away( ?array $got ): bool {
		if ( null === $got ) {
			return true;
		}
		return in_array( $got['status'], array( 401, 403, 407, 429, 503 ), true ) || isset( $got['headers']['cf-mitigated'] );
	}

	/**
	 * Whether a URL is on this site's own host.
	 *
	 * @param string $url A URL.
	 */
	public static function is_own( string $url ): bool {
		$mine   = wp_parse_url( home_url() );
		$theirs = wp_parse_url( $url );
		if ( ! is_array( $mine ) || ! is_array( $theirs ) || empty( $mine['host'] ) || empty( $theirs['host'] ) ) {
			return false;
		}
		$scheme = $theirs['scheme'] ?? '';
		return in_array( $scheme, array( 'http', 'https' ), true ) && 0 === strcasecmp( $mine['host'], $theirs['host'] ) && ( $mine['port'] ?? null ) === ( $theirs['port'] ?? null );
	}
}
