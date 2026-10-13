<?php
/**
 * Which fixers are on, and with which choices.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Fixers;

/**
 * One option, `readiness_index_fixers`: a map of fixer id to the choices stored for it. It is
 * the whole of what a fixer stores, which is what makes Undo exact.
 *
 * It is the one option that is autoloaded. Every other option here is read in the admin or by
 * the API client, but the front end needs this one on every request to know which filters to
 * add, and an autoloaded option costs no query where a missing or non-autoloaded one costs
 * one. The activation hook writes it, empty, so a site with nothing applied reads it for free.
 */
final class State {

	const OPTION = 'readiness_index_fixers';

	/**
	 * The stored choices of one fixer, or null when it is off.
	 *
	 * @param string $id Fixer id.
	 * @return array<string,mixed>|null
	 */
	public static function get( string $id ): ?array {
		$all = self::all();
		return array_key_exists( $id, $all ) ? $all[ $id ] : null;
	}

	/**
	 * Every stored fixer.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function all(): array {
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) ? $all : array();
	}

	/**
	 * Stores choices for a fixer, or removes it.
	 *
	 * @param string                   $id      Fixer id.
	 * @param array<string,mixed>|null $choices Choices, or null to turn the fixer off.
	 */
	public static function set( string $id, ?array $choices ): void {
		$all = self::all();
		if ( null === $choices ) {
			unset( $all[ $id ] );
		} else {
			$all[ $id ] = $choices;
		}
		update_option( self::OPTION, $all, true );
	}

	/**
	 * Writes the empty map on activation, so reading it never costs a query.
	 */
	public static function seed(): void {
		add_option( self::OPTION, array(), '', 'yes' );
	}
}
