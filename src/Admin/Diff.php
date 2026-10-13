<?php
/**
 * A line diff for the preview.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Admin;

/**
 * Longest-common-subsequence over lines. The two columns of a preview show the same text the
 * golden vectors compare, with the lines that differ marked, so what the owner reads is the
 * change and not a re-description of it.
 */
final class Diff {

	/** The most cells of the alignment table worth building. */
	const MAX_CELLS = 2000000;

	/**
	 * Marks each line of a "before" and an "after" text as kept, removed or added.
	 *
	 * @param string $before The text now.
	 * @param string $after  The text after the change.
	 * @return array{before:array<int,array{0:string,1:string}>,after:array<int,array{0:string,1:string}>} Lines as [mark, text]; mark is `=`, `-` or `+`.
	 */
	public static function lines( string $before, string $after ): array {
		$a = '' === $before ? array() : explode( "\n", rtrim( $before, "\n" ) );
		$b = '' === $after ? array() : explode( "\n", rtrim( $after, "\n" ) );
		$n = count( $a );
		$m = count( $b );
		if ( $n * $m > self::MAX_CELLS ) {
			// Too large to align: show it whole rather than spend the memory.
			return array(
				'before' => array_map(
					static function ( string $line ): array {
						return array( '-', $line );
					},
					$a
				),
				'after'  => array_map(
					static function ( string $line ): array {
						return array( '+', $line );
					},
					$b
				),
			);
		}

		// A table of common-subsequence lengths, filled from the end.
		$len = array_fill( 0, $n + 1, array_fill( 0, $m + 1, 0 ) );
		for ( $i = $n - 1; $i >= 0; $i-- ) {
			for ( $j = $m - 1; $j >= 0; $j-- ) {
				$len[ $i ][ $j ] = $a[ $i ] === $b[ $j ] ? $len[ $i + 1 ][ $j + 1 ] + 1 : max( $len[ $i + 1 ][ $j ], $len[ $i ][ $j + 1 ] );
			}
		}
		$out_before = array();
		$out_after  = array();
		$i          = 0;
		$j          = 0;
		while ( $i < $n && $j < $m ) {
			if ( $a[ $i ] === $b[ $j ] ) {
				$out_before[] = array( '=', $a[ $i ] );
				$out_after[]  = array( '=', $b[ $j ] );
				++$i;
				++$j;
			} elseif ( $len[ $i + 1 ][ $j ] >= $len[ $i ][ $j + 1 ] ) {
				$out_before[] = array( '-', $a[ $i++ ] );
			} else {
				$out_after[] = array( '+', $b[ $j++ ] );
			}
		}
		while ( $i < $n ) {
			$out_before[] = array( '-', $a[ $i++ ] );
		}
		while ( $j < $m ) {
			$out_after[] = array( '+', $b[ $j++ ] );
		}
		return array(
			'before' => $out_before,
			'after'  => $out_after,
		);
	}
}
