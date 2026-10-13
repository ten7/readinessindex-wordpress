<?php
/**
 * One message that survives a redirect.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Admin;

/**
 * Admin actions end in a redirect, and the result of the action has to be on the screen
 * after it. A per-user transient carries it, and it is read once and deleted. Nothing about
 * the result goes in the URL: a refusal's sentence in a query string is a link that can
 * put words on an administrator's screen.
 */
final class Flash {

	const TTL = 300;

	/**
	 * Remembers a message for the user's next screen.
	 *
	 * @param int                  $user_id User id.
	 * @param string               $kind    connect, scan, verify or disconnect.
	 * @param string               $code    Outcome: a Connector constant, an API error key, or a verify state.
	 * @param array<string,string> $extra  Server sentence and reason, when the outcome has them.
	 */
	public static function put( int $user_id, string $kind, string $code, array $extra = array() ): void {
		set_transient(
			self::name( $user_id ),
			array(
				'kind'    => $kind,
				'code'    => $code,
				'message' => isset( $extra['message'] ) ? $extra['message'] : '',
				'reason'  => isset( $extra['reason'] ) ? $extra['reason'] : '',
			),
			self::TTL
		);
	}

	/**
	 * Returns and forgets the user's message.
	 *
	 * @param int $user_id User id.
	 * @return array{kind:string,code:string,message:string,reason:string}|null
	 */
	public static function take( int $user_id ): ?array {
		$flash = get_transient( self::name( $user_id ) );
		if ( ! is_array( $flash ) || ! isset( $flash['kind'], $flash['code'] ) ) {
			return null;
		}
		delete_transient( self::name( $user_id ) );
		return array(
			'kind'    => (string) $flash['kind'],
			'code'    => (string) $flash['code'],
			'message' => isset( $flash['message'] ) ? (string) $flash['message'] : '',
			'reason'  => isset( $flash['reason'] ) ? (string) $flash['reason'] : '',
		);
	}

	/**
	 * Transient name.
	 *
	 * @param int $user_id User id.
	 */
	private static function name( int $user_id ): string {
		return 'ri_flash_' . $user_id;
	}
}
