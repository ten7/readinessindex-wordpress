<?php
/**
 * One group of a robots.txt file.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Fixers\Robots;

/**
 * A run of `User-agent` lines and the rule lines after them, as RFC 9309 reads them. Plain
 * data: the line indexes are positions in the file's own line list, so a fixer can remove
 * exactly the lines that are about one crawler and no others.
 */
final class Group {

	/**
	 * Line indexes of the `User-agent` lines.
	 *
	 * @var int[]
	 */
	public $ua = array();

	/**
	 * The product tokens those lines name, in order.
	 *
	 * @var string[]
	 */
	public $names = array();

	/**
	 * Rule lines: index, lower-cased field name, value.
	 *
	 * @var array<int,array{0:int,1:string,2:string}>
	 */
	public $rules = array();

	/**
	 * Whether the group names an agent, ignoring case.
	 *
	 * @param string $agent Product token.
	 */
	public function names_agent( string $agent ): bool {
		foreach ( $this->names as $name ) {
			if ( 0 === strcasecmp( $name, $agent ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the group names no one but this agent.
	 *
	 * @param string $agent Product token.
	 */
	public function only_names( string $agent ): bool {
		foreach ( $this->names as $name ) {
			if ( 0 !== strcasecmp( $name, $agent ) ) {
				return false;
			}
		}
		return true;
	}
}
