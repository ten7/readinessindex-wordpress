<?php
/**
 * A structural check of a JSON-LD graph, with no network and no outside validator.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Fixers\Logic;

/**
 * Finds the faults that make a linked graph useless to a crawler: a node defined twice, a
 * reference to a node that is not in the document, a node with no type. It is deliberately
 * small. It says nothing about schema.org vocabulary, which is not this plugin's to judge.
 */
final class GraphValidator {

	/**
	 * Problems in one JSON-LD document, as `code: @id` strings. Empty when the graph is sound.
	 *
	 * @param array<string,mixed> $document A document with an `@graph`.
	 * @return string[]
	 */
	public static function problems( array $document ): array {
		$problems = array();
		$graph    = $document['@graph'] ?? null;
		if ( ! is_array( $graph ) || array() === $graph ) {
			return array( 'no_graph' );
		}
		$defined = array();
		foreach ( $graph as $node ) {
			if ( ! is_array( $node ) || ! isset( $node['@id'] ) ) {
				$problems[] = 'node_without_id';
				continue;
			}
			if ( ! isset( $node['@type'] ) ) {
				$problems[] = 'missing_type: ' . $node['@id'];
			}
			if ( isset( $defined[ $node['@id'] ] ) ) {
				$problems[] = 'duplicate_id: ' . $node['@id'];
			}
			$defined[ $node['@id'] ] = true;
		}
		foreach ( $graph as $node ) {
			if ( is_array( $node ) ) {
				self::walk( $node, $defined, $problems, true );
			}
		}
		return array_values( array_unique( $problems ) );
	}

	/**
	 * Checks every nested reference, an object holding only an `@id`, resolves.
	 *
	 * @param array<mixed>       $value    A node or part of one.
	 * @param array<string,true> $defined  The ids defined at the top of the graph.
	 * @param string[]           $problems Problems found, appended to.
	 * @param bool               $top      Whether this is a top-level node, which defines itself.
	 */
	private static function walk( array $value, array $defined, array &$problems, bool $top ): void {
		if ( ! $top && array( '@id' ) === array_keys( $value ) && is_string( $value['@id'] ) && ! isset( $defined[ $value['@id'] ] ) ) {
			$problems[] = 'dangling_reference: ' . $value['@id'];
		}
		foreach ( $value as $inner ) {
			if ( is_array( $inner ) ) {
				self::walk( $inner, $defined, $problems, false );
			}
		}
	}
}
