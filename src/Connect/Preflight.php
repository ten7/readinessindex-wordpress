<?php
/**
 * A check of this site's own side, before Connect.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Connect;

/**
 * Explains, and never blocks, the things on this site that would make the scanner read
 * the wrong page or none: a password in front of it, maintenance mode, a robots.txt that
 * shuts every crawler out, a host that is not on the public internet.
 *
 * It looks only at what WordPress already knows. A loopback request to the site would
 * be an outbound call outside the API client, and would be wrong on the many hosts that
 * cannot reach themselves. The server checks the real thing again at scan time, so a
 * false negative here costs one explained refusal and a false positive costs a sentence
 * the owner can ignore, which is why none of it blocks.
 */
final class Preflight {

	/**
	 * Which sentences to show, as keys of the shared strings.
	 *
	 * @param string              $home_url    The site's home URL.
	 * @param array<string,mixed> $server      The `$_SERVER` array.
	 * @param bool                $blog_public Whether search engines are allowed (Settings, Reading).
	 * @param bool                $maintenance Whether core's `.maintenance` file exists.
	 * @param string|null         $robots_txt  A physical robots.txt's text, or null when there is none.
	 * @return array<int,string>
	 */
	public static function notices( string $home_url, array $server, bool $blog_public, bool $maintenance, ?string $robots_txt ): array {
		$keys = array();

		if ( self::is_private_or_insecure( $home_url ) ) {
			$keys[] = 'connect.site_unreachable';
		}
		if ( self::has_basic_auth( $server ) ) {
			$keys[] = 'unreachable.staging_password';
		}
		if ( $maintenance ) {
			$keys[] = 'unreachable.maintenance_mode';
		}
		if ( ! $blog_public || ( null !== $robots_txt && self::robots_blocks_everyone( $robots_txt ) ) ) {
			$keys[] = 'unreachable.robots_blocked';
		}
		return $keys;
	}

	/**
	 * The notices for this site, gathered from WordPress.
	 *
	 * @return array<int,string>
	 */
	public static function for_this_site(): array {
		$robots = null;
		$path   = ABSPATH . 'robots.txt';
		if ( is_readable( $path ) && filesize( $path ) < 262144 ) {
			$robots = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file, size-capped above.
		}
		return self::notices(
			home_url(),
			$_SERVER, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Only isset checks are made on it; nothing is stored or printed.
			! in_array( (string) get_option( 'blog_public', '1' ), array( '0' ), true ),
			file_exists( ABSPATH . '.maintenance' ),
			$robots
		);
	}

	/**
	 * Whether the home URL is http, or a host that cannot be reached from the internet.
	 *
	 * @param string $home_url Home URL.
	 */
	public static function is_private_or_insecure( string $home_url ): bool {
		$parts = wp_parse_url( $home_url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return true;
		}
		if ( ! isset( $parts['scheme'] ) || 'https' !== $parts['scheme'] ) {
			return true;
		}
		$host = strtolower( trim( $parts['host'], '[]' ) );

		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return false === filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}
		if ( false === strpos( $host, '.' ) ) {
			return true; // A single label: localhost, a Docker service name, an intranet name.
		}
		foreach ( array( '.localhost', '.local', '.test', '.invalid', '.internal', '.lan', '.home.arpa' ) as $suffix ) {
			if ( 1 === preg_match( '/' . preg_quote( $suffix, '/' ) . '$/D', $host ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether this request came through HTTP Basic authentication.
	 *
	 * A password in front of wp-admin is nearly always a password in front of the whole
	 * site (Pantheon's and most hosts' staging locks work that way), and a scanner that
	 * meets it reads the login box and scores that.
	 *
	 * @param array<string,mixed> $server The `$_SERVER` array.
	 */
	public static function has_basic_auth( array $server ): bool {
		if ( ! empty( $server['PHP_AUTH_USER'] ) ) {
			return true;
		}
		foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $header ) {
			if ( isset( $server[ $header ] ) && is_string( $server[ $header ] ) && 0 === stripos( $server[ $header ], 'basic ' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a robots.txt shuts out every crawler: `Disallow: /` for `*` with no `Allow: /`.
	 *
	 * A narrower rule (`Disallow: /wp-admin/`) is not a blanket block and must not be
	 * reported as one: this sentence tells the owner the scanner cannot read anything.
	 *
	 * @param string $robots_txt Text of a robots.txt.
	 */
	public static function robots_blocks_everyone( string $robots_txt ): bool {
		$in_star_group = false;
		$last_was_ua   = false;
		$disallow_all  = false;
		$allow_all     = false;

		foreach ( preg_split( '/\r\n|\r|\n/', $robots_txt ) as $line ) {
			$line = trim( (string) preg_replace( '/#.*$/', '', $line ) );
			if ( '' === $line || false === strpos( $line, ':' ) ) {
				continue;
			}
			list( $field, $value ) = array_map( 'trim', explode( ':', $line, 2 ) );
			$field                 = strtolower( $field );

			if ( 'user-agent' === $field ) {
				// A run of consecutive user-agent lines is one group.
				$in_star_group = $last_was_ua ? ( $in_star_group || '*' === $value ) : '*' === $value;
				$last_was_ua   = true;
				continue;
			}
			$last_was_ua = false;
			if ( ! $in_star_group ) {
				continue;
			}
			if ( 'disallow' === $field && '/' === $value ) {
				$disallow_all = true;
			} elseif ( 'allow' === $field && '/' === $value ) {
				$allow_all = true;
			}
		}
		return $disallow_all && ! $allow_all;
	}
}
