<?php
/**
 * What the stray-directives fixer would emit.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Fixers\Logic;

/**
 * AIR-1.6: the directives that stop an assistant quoting a page (`noarchive`, `nosnippet`,
 * `max-snippet:0`), removed from the pages whose directives the CMS or the theme set, and
 * left alone, with the reason, where an SEO tool or a server header sets them.
 */
final class RobotsDirectives {

	const STRAY = array( 'noarchive', 'nosnippet', 'max-snippet:0' );

	/**
	 * Whether a directive token is one of the stray three, ignoring case and spaces.
	 *
	 * @param string $token A token.
	 */
	public static function is_stray( string $token ): bool {
		return in_array( strtolower( trim( $token ) ), self::STRAY, true );
	}

	/**
	 * Evaluates the fixer.
	 *
	 * @param array<string,mixed> $given Site facts.
	 * @return array<string,mixed> Evaluation.
	 */
	public static function evaluate( array $given ): array {
		$affected = array();
		$deferred = array();
		$headers  = array();
		$output   = array();
		$baseline = array();
		$changes  = array();
		foreach ( $given['pages'] as $page ) {
			$tokens = array_values( $page['directives'] );
			$strays = array_values( array_filter( $tokens, array( self::class, 'is_stray' ) ) );
			$source = (string) $page['source'];

			$baseline[] = array(
				'path'       => $page['path'],
				'directives' => $tokens,
			);
			$header     = array_values( array_filter( (array) ( $page['header'] ?? array() ), array( self::class, 'is_stray' ) ) );
			if ( array() !== $header ) {
				$headers[] = array(
					'path'       => $page['path'],
					'directives' => $header,
				);
			}
			$kept = $tokens;
			if ( array() !== $strays && 0 === strpos( $source, 'tooling:' ) ) {
				$deferred[] = array(
					'path'    => $page['path'],
					'tooling' => substr( $source, 8 ),
				);
			} elseif ( array() !== $strays ) {
				$affected[] = array(
					'path'    => $page['path'],
					'removed' => $strays,
				);
				$kept       = array_values(
					array_filter(
						$tokens,
						static function ( string $token ): bool {
							return ! self::is_stray( $token );
						}
					)
				);
			}
			$output[] = array(
				'path'       => $page['path'],
				'directives' => $kept,
			);
			if ( $kept !== $tokens ) {
				$changes[] = array(
					'target' => 'robots meta of ' . $page['path'],
					'before' => implode( ', ', $tokens ),
					'after'  => implode( ', ', $kept ),
				);
			}
		}
		if ( array() !== $affected ) {
			$status = 'ready';
			$reason = null;
		} elseif ( array() !== $deferred ) {
			$status = 'defer';
			$reason = 'tooling_owns_it';
		} else {
			$status = 'unchanged';
			$reason = 'nothing_to_change';
		}
		return array(
			'status'   => $status,
			'reason'   => $reason,
			'findings' => array(
				'pages'    => $affected,
				'deferred' => $deferred,
				'headers'  => $headers,
			),
			'changes'  => $changes,
			'output'   => array( 'pages' => $output ),
			'baseline' => array( 'pages' => $baseline ),
			'paste'    => null,
		);
	}
}
