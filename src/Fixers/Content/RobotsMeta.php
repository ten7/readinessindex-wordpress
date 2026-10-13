<?php
/**
 * Reading what a page tells crawlers about excerpts.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Fixers\Content;

/**
 * Parses a page's own HTML and headers for robots directives, so the stray-directive fixer
 * reports what is really emitted and who emits it, and never what it supposes.
 */
final class RobotsMeta {

	/**
	 * The robots meta tags of a document.
	 *
	 * WordPress prints its own as `<meta name='robots' content='...' />` with single quotes. A
	 * tag written with double quotes is a theme's or a plugin's own markup, which a filter on
	 * `wp_robots` cannot change; the two are returned apart for that reason.
	 *
	 * @param string $html The document.
	 * @return array{core:string[],other:string[]} Tokens in order.
	 */
	public static function meta( string $html ): array {
		$out = array(
			'core'  => array(),
			'other' => array(),
		);
		// Only the head: a robots tag in the body is content, not an instruction.
		$end  = stripos( $html, '</head>' );
		$head = false === $end ? $html : substr( $html, 0, $end );
		if ( ! preg_match_all( '/<meta\b[^>]*>/i', $head, $tags ) ) {
			return $out;
		}
		foreach ( $tags[0] as $tag ) {
			if ( ! preg_match( '/\bname\s*=\s*([\'"])robots\1/i', $tag, $name ) ) {
				continue;
			}
			if ( ! preg_match( '/\bcontent\s*=\s*([\'"])(.*?)\1/is', $tag, $content ) ) {
				continue;
			}
			$bucket = "'" === $name[1] ? 'core' : 'other';
			foreach ( self::tokens( html_entity_decode( $content[2], ENT_QUOTES, 'UTF-8' ) ) as $token ) {
				$out[ $bucket ][] = $token;
			}
		}
		return $out;
	}

	/**
	 * A directive list split into tokens.
	 *
	 * @param string $directives Comma-separated directives.
	 * @return string[]
	 */
	public static function tokens( string $directives ): array {
		$out = array();
		foreach ( explode( ',', $directives ) as $part ) {
			$part = trim( $part );
			if ( '' !== $part ) {
				$out[] = $part;
			}
		}
		return $out;
	}

	/**
	 * Directives sent in an `X-Robots-Tag` header. A part may name a crawler first
	 * (`googlebot: noarchive`); that prefix is removed, and `max-snippet:0` is not mistaken for one.
	 *
	 * @param string $header The header's value.
	 * @return string[]
	 */
	public static function header_tokens( string $header ): array {
		$out = array();
		foreach ( self::tokens( $header ) as $part ) {
			$at = strpos( $part, ':' );
			if ( false !== $at ) {
				$name = strtolower( trim( substr( $part, 0, $at ) ) );
				if ( ! in_array( $name, array( 'max-snippet', 'max-image-preview', 'max-video-preview', 'unavailable_after' ), true ) ) {
					$part = trim( substr( $part, $at + 1 ) );
				}
			}
			if ( '' !== $part ) {
				$out[] = $part;
			}
		}
		return $out;
	}
}
