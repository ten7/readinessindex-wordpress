<?php
/**
 * Small text helpers shared by the fixer logic.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Fixers\Logic;

/**
 * The few string operations the reference fixers use, written once so every fixer clips and
 * collapses text the same way.
 */
final class Text {

	/**
	 * Whitespace collapsed to single spaces and trimmed, on one line.
	 *
	 * @param string|null $text Any text.
	 */
	public static function one_line( ?string $text ): string {
		$parts = preg_split( '/\s+/u', (string) $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $parts ) {
			// Not valid UTF-8: fall back to ASCII whitespace rather than lose the text.
			$parts = preg_split( '/\s+/', (string) $text, -1, PREG_SPLIT_NO_EMPTY );
			$parts = false === $parts ? array() : $parts;
		}
		return implode( ' ', $parts );
	}

	/**
	 * Clips to a number of characters, ending in an ellipsis: the first limit - 1 characters
	 * with trailing spaces removed, then the ellipsis. Text that fits is returned as it is.
	 *
	 * @param string $text  Text.
	 * @param int    $limit Longest allowed length in characters.
	 */
	public static function clip( string $text, int $limit ): string {
		if ( mb_strlen( $text, 'UTF-8' ) <= $limit ) {
			return $text;
		}
		return rtrim( mb_substr( $text, 0, $limit - 1, 'UTF-8' ), " \t\n\r\0\x0B" ) . "\u{2026}";
	}

	/**
	 * Trims the way a person would: spaces, tabs and line ends.
	 *
	 * @param string $text Text.
	 */
	public static function trim( string $text ): string {
		return trim( $text );
	}
}
