<?php
/**
 * Every fixer this plugin has.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Fixers;

/**
 * The local table of what the plugin can fix: the server never says. A fixer that is not in
 * `CLASSES` does not exist as far as the Fixes screen, the runner and the uninstall are
 * concerned, so `RegistryTest` fails when a class implements `Fixer` and is missing here.
 */
final class Registry {

	/**
	 * The fixers, in the order the Fixes screen offers them.
	 */
	const CLASSES = array(
		Impl\RobotsTxtFixer::class,
		Impl\LlmsTxtFixer::class,
	);

	/**
	 * Instances, built once.
	 *
	 * @var array<string,Fixer>|null
	 */
	private static $built = null;

	/**
	 * Replacement fixers for a test, or null for the real ones.
	 *
	 * @var Fixer[]|null
	 */
	private static $override = null;

	/**
	 * Swaps in a set of fixers, or restores the real ones with null.
	 *
	 * @param Fixer[]|null $fixers Fixers.
	 */
	public static function use_fixers( ?array $fixers ): void {
		self::$override = $fixers;
		self::$built    = null;
	}

	/**
	 * Every fixer, by id.
	 *
	 * @return array<string,Fixer>
	 */
	public static function all(): array {
		if ( null !== self::$built ) {
			return self::$built;
		}
		$list = self::$override;
		if ( null === $list ) {
			$list = array();
			foreach ( self::CLASSES as $class ) {
				$list[] = new $class();
			}
		}
		self::$built = array();
		foreach ( $list as $fixer ) {
			self::$built[ $fixer->id() ] = $fixer;
		}
		return self::$built;
	}

	/**
	 * One fixer by id, or null. The id is only ever looked up, never turned into a class name.
	 *
	 * @param string $id Fixer id.
	 */
	public static function get( string $id ): ?Fixer {
		$all = self::all();
		return $all[ $id ] ?? null;
	}

	/**
	 * The fixer that moves an Index test, or null when this plugin cannot fix it.
	 *
	 * @param string $check Test id, such as `AIR-1.1`.
	 */
	public static function for_check( string $check ): ?Fixer {
		foreach ( self::all() as $fixer ) {
			if ( in_array( $check, $fixer->checks(), true ) ) {
				return $fixer;
			}
		}
		return null;
	}
}
