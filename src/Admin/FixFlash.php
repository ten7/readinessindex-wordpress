<?php
/**
 * The result of the last apply or undo, carried across the redirect.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Admin;

/**
 * Like `Flash`, for a runner result: a per-user transient, read once and deleted. Nothing about
 * the result goes in the URL, and what is stored is the outcome and reason codes the screen
 * turns into sentences, never a sentence of its own.
 */
final class FixFlash {

	const TTL = 300;

	/**
	 * Remembers a runner result for the user's next screen.
	 *
	 * @param int                 $user_id User id.
	 * @param array<string,mixed> $result  The runner's result.
	 */
	public static function put( int $user_id, array $result ): void {
		set_transient( 'ri_fixflash_' . $user_id, $result, self::TTL );
	}

	/**
	 * Returns and forgets the user's result.
	 *
	 * @param int $user_id User id.
	 * @return array<string,mixed>|null
	 */
	public static function take( int $user_id ): ?array {
		$result = get_transient( 'ri_fixflash_' . $user_id );
		if ( ! is_array( $result ) || ! isset( $result['outcome'] ) ) {
			return null;
		}
		delete_transient( 'ri_fixflash_' . $user_id );
		return $result;
	}
}
