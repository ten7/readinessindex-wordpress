<?php
/**
 * Turning a runner result into a sentence.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Admin;

use ReadinessIndex\Fixers\PageCache;
use ReadinessIndex\Fixers\SeoTools;
use ReadinessIndex\Strings;

/**
 * The runner returns codes and never prose. Every sentence here is looked up in the strings, so
 * what a refusal says is the shared contract's sentence wherever the contract has one.
 */
final class Outcome {

	/**
	 * The notice for a result.
	 *
	 * @param array<string,mixed> $result The runner's result.
	 * @return array{type:string,title:string,body:string}
	 */
	public static function notice( array $result ): array {
		switch ( $result['outcome'] ) {
			case 'applied':
				return array(
					'type'  => false === ( $result['live'] ?? null ) || null === ( $result['live'] ?? null ) ? 'warning' : 'success',
					'title' => Strings::get( 'fixes.applied' ),
					'body'  => self::live_sentence( $result ) . ' ' . Strings::get( 'fixes.rescan_hint' ),
				);
			case 'undone':
				return array(
					'type'  => 'success',
					'title' => '',
					'body'  => Strings::get( 'fixes.undone' ),
				);
			case 'unchanged':
				return array(
					'type'  => 'info',
					'title' => '',
					'body'  => 'already_present' === ( $result['reason'] ?? '' ) ? Strings::get( 'fixes.already_present' ) : Strings::get( 'fixes.nothing_to_change' ),
				);
			case 'stale':
				return array(
					'type'  => 'warning',
					'title' => '',
					'body'  => Strings::get( 'fixes.stale' ),
				);
			case 'failed':
				return array(
					'type'  => 'error',
					'title' => '',
					'body'  => Strings::get( 'fixes.failed' ),
				);
		}
		// A refusal: one sentence for each reason.
		$reasons = isset( $result['reasons'] ) ? (array) $result['reasons'] : array( (string) ( $result['reason'] ?? 'not_found' ) );
		$tooling = isset( $result['tooling'] ) ? SeoTools::name( (string) $result['tooling'] ) : '';
		$lines   = array();
		foreach ( $reasons as $reason ) {
			$lines[] = self::reason( (string) $reason, $tooling );
		}
		return array(
			'type'  => 'error',
			'title' => '',
			'body'  => implode( ' ', $lines ),
		);
	}

	/**
	 * Why a change was refused, in one sentence.
	 *
	 * @param string $reason  A reason code.
	 * @param string $tooling The tool's name, for a deferral.
	 */
	public static function reason( string $reason, string $tooling = '' ): string {
		$map = array(
			'physical_file'              => array( 'fixes.conflict.physical_file', array() ),
			'other_tool'                 => array( 'fixes.conflict.other_tool', array() ),
			'tooling_owns_it'            => array( 'fixes.defer.tooling_owns_it', array( 'tooling' => $tooling ) ),
			'search_engines_discouraged' => array( 'fixes.reason.search_engines_discouraged', array() ),
			'subdirectory_site'          => array( 'fixes.reason.subdirectory_site', array() ),
			'both_seo_tools'             => array( 'fixes.reason.both_seo_tools', array() ),
			'choices_incomplete'         => array( 'fixes.reason.choices_incomplete', array() ),
			'superseded'                 => array( 'fixes.undo.superseded', array() ),
			'already_undone'             => array( 'fixes.undo.already', array() ),
			'not_found'                  => array( 'fixes.undo.not_found', array() ),
		);
		if ( ! isset( $map[ $reason ] ) ) {
			return Strings::get( 'fixes.failed' );
		}
		return Strings::get( $map[ $reason ][0], $map[ $reason ][1] );
	}

	/**
	 * Whether the change is live, as one sentence: yes, no (naming a page cache when one is
	 * installed), or that the plugin could not look.
	 *
	 * @param array<string,mixed> $result The runner's result.
	 */
	public static function live_sentence( array $result ): string {
		$live = $result['live'] ?? null;
		if ( true === $live ) {
			return Strings::get( 'fixes.live.yes' );
		}
		if ( null === $live ) {
			return Strings::get( 'fixes.live.unknown' );
		}
		$cache = isset( $result['cache'] ) && null !== $result['cache'] ? (string) $result['cache'] : '';
		if ( '' !== $cache ) {
			return Strings::get( 'fixes.live.no' ) . ' ' . Strings::get( 'fixes.live.named_cache', array( 'cache' => PageCache::name( $cache ) ) );
		}
		return Strings::get( 'fixes.live.no' );
	}
}
