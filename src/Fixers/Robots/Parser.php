<?php
/**
 * Reading robots.txt the way RFC 9309 does.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Fixers\Robots;

/**
 * Groups, governing groups and the longest-match rule, with no WordPress in it.
 *
 * Two jobs use it and must agree: the robots.txt fixer decides which crawlers are blocked
 * from the whole site, and the verify step asks whether a crawler may fetch a path in the
 * file the site really serves. `tests/Unit/RobotsParserTest.php` holds it to the RFC's own
 * examples, so neither job is trusting a reading nobody checked.
 */
final class Parser {

	/**
	 * Lines of a body: line ends normalized to LF, one final line end ignored, and no lines
	 * at all for an empty body.
	 *
	 * @param string $text A robots.txt body.
	 * @return string[]
	 */
	public static function lines_of( string $text ): array {
		$body = str_replace( array( "\r\n", "\r" ), "\n", $text );
		if ( '' !== $body && "\n" === substr( $body, -1 ) ) {
			$body = substr( $body, 0, -1 );
		}
		return '' === $body ? array() : explode( "\n", $body );
	}

	/**
	 * One line as a field and a value, or null for a blank line, a comment or a line with no
	 * colon. The field is lower-cased and the value has any trailing comment removed.
	 *
	 * @param string $line A line.
	 * @return array{0:string,1:string}|null
	 */
	public static function directive( string $line ): ?array {
		$stripped = trim( $line );
		if ( '' === $stripped || '#' === $stripped[0] ) {
			return null;
		}
		$at = strpos( $stripped, ':' );
		if ( false === $at ) {
			return null;
		}
		$name  = strtolower( trim( substr( $stripped, 0, $at ) ) );
		$value = substr( $stripped, $at + 1 );
		$hash  = strpos( $value, '#' );
		if ( false !== $hash ) {
			$value = substr( $value, 0, $hash );
		}
		return array( $name, trim( $value ) );
	}

	/**
	 * Groups: user-agent lines in a run share the rules after them. A rule before any
	 * user-agent line belongs to nothing, and `Sitemap` belongs to no group.
	 *
	 * @param string[] $lines Lines of a body.
	 * @return Group[]
	 */
	public static function groups( array $lines ): array {
		$found     = array();
		$current   = null;
		$expecting = false;
		foreach ( $lines as $index => $line ) {
			$parsed = self::directive( $line );
			if ( null === $parsed ) {
				continue;
			}
			list( $name, $value ) = $parsed;
			if ( 'user-agent' === $name || 'useragent' === $name ) {
				if ( null === $current || ! $expecting ) {
					$current = new Group();
					$found[] = $current;
				}
				$expecting        = true;
				$current->ua[]    = $index;
				$current->names[] = $value;
				continue;
			}
			$expecting = false;
			if ( 'sitemap' !== $name && null !== $current ) {
				$current->rules[] = array( $index, $name, $value );
			}
		}
		return $found;
	}

	/**
	 * The groups that govern an agent: every group naming it, or, when none does, every group
	 * naming `*`.
	 *
	 * @param string  $agent  Product token.
	 * @param Group[] $groups Groups.
	 * @return Group[]
	 */
	public static function governing( string $agent, array $groups ): array {
		$named = array();
		foreach ( $groups as $group ) {
			if ( $group->names_agent( $agent ) ) {
				$named[] = $group;
			}
		}
		if ( array() !== $named ) {
			return $named;
		}
		$star = array();
		foreach ( $groups as $group ) {
			if ( in_array( '*', $group->names, true ) ) {
				$star[] = $group;
			}
		}
		return $star;
	}

	/**
	 * The fixer's definition of a blanket block: `Disallow: /` governs the agent and no
	 * `Allow: /` does. Other restrictions (a path, a wildcard) are the owner's.
	 *
	 * @param string  $agent  Product token.
	 * @param Group[] $groups Groups.
	 */
	public static function blanket_blocked( string $agent, array $groups ): bool {
		$disallow = false;
		$allow    = false;
		foreach ( self::governing( $agent, $groups ) as $group ) {
			foreach ( $group->rules as $rule ) {
				if ( '/' !== $rule[2] ) {
					continue;
				}
				if ( 'disallow' === $rule[1] ) {
					$disallow = true;
				} elseif ( 'allow' === $rule[1] ) {
					$allow = true;
				}
			}
		}
		return $disallow && ! $allow;
	}

	/**
	 * Whether an agent may fetch a path, by RFC 9309: the governing groups merge, the longest
	 * matching rule wins, Allow wins a tie, and no matching rule means allowed. Rules match from
	 * the start of the path; `*` matches any run of characters and a trailing `$` anchors the end.
	 *
	 * @param string $body  A robots.txt body.
	 * @param string $agent Product token.
	 * @param string $path  Path with any query, beginning with a slash.
	 */
	public static function allows( string $body, string $agent, string $path ): bool {
		$groups = self::groups( self::lines_of( $body ) );
		$best   = -1;
		$allow  = true;
		foreach ( self::governing( $agent, $groups ) as $group ) {
			foreach ( $group->rules as $rule ) {
				if ( 'allow' !== $rule[1] && 'disallow' !== $rule[1] ) {
					continue;
				}
				// An empty Disallow allows everything and an empty Allow matches nothing.
				if ( '' === $rule[2] ) {
					continue;
				}
				if ( ! self::matches( $rule[2], $path ) ) {
					continue;
				}
				$length = strlen( $rule[2] );
				$is_all = 'allow' === $rule[1];
				if ( $length > $best || ( $length === $best && $is_all ) ) {
					$best  = $length;
					$allow = $is_all;
				}
			}
		}
		return $allow;
	}

	/**
	 * Whether a rule's path pattern matches a path.
	 *
	 * @param string $pattern Rule value.
	 * @param string $path    Path.
	 */
	private static function matches( string $pattern, string $path ): bool {
		$anchored = '$' === substr( $pattern, -1 );
		if ( $anchored ) {
			$pattern = substr( $pattern, 0, -1 );
		}
		$regex = '';
		foreach ( explode( '*', $pattern ) as $i => $part ) {
			$regex .= ( 0 === $i ? '' : '.*' ) . preg_quote( $part, '#' );
		}
		return 1 === preg_match( '#^' . $regex . ( $anchored ? '$' : '' ) . '#s', $path );
	}
}
