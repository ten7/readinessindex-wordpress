<?php
/**
 * Rank Math.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Fixers\Integration;

use ReadinessIndex\Fixers\Logic\EntityGraph;
use ReadinessIndex\Fixers\Runtime\Facts;

/**
 * Hands Rank Math the organization's facts. Its name, logo and extra profiles are options in
 * `rank-math-options-titles`, written with the owner's confirmation and recorded for Undo; the
 * rest goes through its `rank_math/json_ld` filter, keyed by `@id`.
 */
final class RankMath implements Tool {

	const TITLES = 'rank-math-options-titles';

	/**
	 * The key `SeoTools` uses.
	 */
	public function key(): string {
		return 'rank_math';
	}

	/**
	 * Whether Rank Math is active and its settings are where this code expects them.
	 */
	public function supported(): bool {
		if ( ! defined( 'RANK_MATH_VERSION' ) ) {
			return false;
		}
		$titles = get_option( self::TITLES );
		return is_array( $titles ) && array_key_exists( 'knowledgegraph_type', $titles ) && array_key_exists( 'knowledgegraph_name', $titles );
	}

	/**
	 * The writes Rank Math needs for these choices.
	 *
	 * @param array<string,mixed> $choices   The owner's choices.
	 * @param string              $site_name The site's name.
	 * @return array<int,array{option:string,key:string,before:mixed,after:mixed}>
	 */
	public function plan( array $choices, string $site_name ): array {
		$titles = (array) get_option( self::TITLES, array() );
		$org    = (array) ( $choices['organization'] ?? array() );
		$plan   = array();
		$add    = function ( string $key, $after ) use ( &$plan, $titles ): void {
			$before = $titles[ $key ] ?? null;
			if ( $before !== $after ) {
				$plan[] = array(
					'option' => self::TITLES,
					'key'    => $key,
					'before' => $before,
					'after'  => $after,
				);
			}
		};
		$add( 'knowledgegraph_type', 'company' );
		$add( 'knowledgegraph_name', $site_name );
		if ( ! empty( $org['logo'] ) ) {
			$add( 'knowledgegraph_logo', (string) $org['logo'] );
			$id = (int) attachment_url_to_postid( (string) $org['logo'] );
			if ( $id > 0 ) {
				$add( 'knowledgegraph_logo_id', $id );
			}
		}
		$same_as = EntityGraph::organization( 'https://x', 'x', $org, null )['sameAs'] ?? array();
		if ( array() !== $same_as ) {
			$have  = '' === (string) ( $titles['social_additional_profiles'] ?? '' ) ? array() : preg_split( '/\r\n|\r|\n/', (string) $titles['social_additional_profiles'] );
			$union = array_values( array_unique( array_merge( array_filter( array_map( 'trim', (array) $have ) ), $same_as ) ) );
			$add( 'social_additional_profiles', implode( "\n", $union ) );
		}
		return $plan;
	}

	/**
	 * Makes the writes and returns what they replaced.
	 *
	 * @param array<int,array{option:string,key:string,before:mixed,after:mixed}> $plan A plan.
	 * @return array<string,array<string,mixed>>
	 */
	public function write( array $plan ): array {
		$titles = (array) get_option( self::TITLES, array() );
		$prior  = array();
		foreach ( $plan as $write ) {
			$prior[ self::TITLES ][ $write['key'] ] = $titles[ $write['key'] ] ?? null;
			$titles[ $write['key'] ]                = $write['after'];
		}
		if ( array() !== $plan ) {
			update_option( self::TITLES, $titles );
		}
		return $prior;
	}

	/**
	 * Puts back the values `write` replaced.
	 *
	 * @param array<string,array<string,mixed>> $prior Prior values by option and key.
	 */
	public function restore( array $prior ): void {
		$titles = (array) get_option( self::TITLES, array() );
		foreach ( (array) ( $prior[ self::TITLES ] ?? array() ) as $key => $value ) {
			if ( null === $value ) {
				unset( $titles[ $key ] );
			} else {
				$titles[ $key ] = $value;
			}
		}
		update_option( self::TITLES, $titles );
	}

	/**
	 * Rank Math prints an organization without a logo, so nothing is needed.
	 *
	 * @param array<string,mixed> $choices The owner's choices.
	 */
	public function missing( array $choices ): ?string {
		unset( $choices );
		return null;
	}

	/**
	 * Adds the facts through Rank Math's filters.
	 *
	 * @param array<string,mixed> $choices The stored choices.
	 */
	public function register( array $choices ): void {
		unset( $choices );
		add_filter( 'rank_math/json_ld', array( self::class, 'graph' ), 98 );
		add_filter( 'rank_math/json_ld/search_url', array( self::class, 'search_url' ) );
	}

	/**
	 * Rank Math's search address: the owner's, when it is valid.
	 *
	 * @param string $url Rank Math's own.
	 * @return string
	 */
	public static function search_url( $url ) {
		$template = Facts::search_template();
		return null === $template ? $url : $template;
	}

	/**
	 * Rank Math's graph: the organization's other facts, on its `publisher` node.
	 *
	 * @param array<string,mixed> $data The graph by node name.
	 * @return array<string,mixed>
	 */
	public static function graph( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		$facts = Facts::organization();
		if ( null !== $facts && isset( $data['publisher'] ) && is_array( $data['publisher'] ) && 'Organization' === $data['publisher']['@type'] ) {
			foreach ( $facts as $key => $value ) {
				if ( in_array( $key, array( 'name', 'url', 'logo', 'sameAs' ), true ) ) {
					continue;
				}
				$data['publisher'][ $key ] = $value;
			}
		}
		return $data;
	}
}
