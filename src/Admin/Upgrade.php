<?php
/**
 * The upgrade surfaces, decided from the allowance alone.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Admin;

use ReadinessIndex\Api\Model\Allowance;
use ReadinessIndex\Strings;

/**
 * The plugin holds no price, no plan name and no rule about what an account may buy. The
 * server's `allowance` says whether there is a next step and which one; this class turns
 * that into at most one offer to show, and a pack link only when the server says a pack
 * can be bought. With `next_step` null (billing off) it returns nothing price-related.
 */
final class Upgrade {

	/**
	 * The one offer to show, or null.
	 *
	 * `monitoring_basic` is a single dismissible line beside the score once there is a score;
	 * `upgrade_basic` is shown at the moment the rescans are spent, and is never dismissed
	 * because the person cannot do what they came to do without it.
	 *
	 * @param Allowance $allowance             The server's allowance.
	 * @param bool      $has_score             Whether a scan has finished and there is a score on screen.
	 * @param bool      $monitoring_dismissed  Whether this user closed the monitoring line.
	 * @param string    $return_url            This plugin's own screen, which Checkout returns to.
	 * @param string    $origin                The API origin, to allow http only for a local origin.
	 * @return array{key:string,label:string,body:string,button:string,url:string,dismissible:bool}|null
	 */
	public static function offer( Allowance $allowance, bool $has_score, bool $monitoring_dismissed, string $return_url, string $origin ): ?array {
		$step = $allowance->next_step;
		if ( null === $step ) {
			return null;
		}
		if ( 'monitoring_basic' === $step->key && ( ! $has_score || $monitoring_dismissed ) ) {
			return null;
		}
		$url = self::checkout_url( $step->url, $return_url, $origin );
		if ( '' === $url ) {
			return null;
		}
		$label_key = 'upgrade.' . $step->key . '.label';
		$body_key  = 'upgrade.' . $step->key . '.body';
		return array(
			'key'         => $step->key,
			'label'       => Strings::has( $label_key ) ? Strings::get( $label_key ) : $step->label,
			'body'        => Strings::has( $body_key ) ? Strings::get( $body_key ) : $step->message,
			'button'      => Strings::get( 'checkout.subscribe' ),
			'url'         => $url,
			'dismissible' => 'monitoring_basic' === $step->key,
		);
	}

	/**
	 * The pack link, only when the server says a pack can be bought right now.
	 *
	 * @param Allowance $allowance The server's allowance.
	 * @param string    $origin    The API origin.
	 */
	public static function pack_url( Allowance $allowance, string $origin ): string {
		if ( ! $allowance->can_buy_pack || null === $allowance->pack_url ) {
			return '';
		}
		return self::safe_url( $allowance->pack_url, $origin );
	}

	/**
	 * The next step's URL with `?return=` this plugin's own screen appended.
	 *
	 * The server accepts the return only for this site's host and the plugin's own admin
	 * path, so this is a convenience for the owner and not a security boundary; the link
	 * itself is still refused unless it is a plain web address.
	 *
	 * @param string $url        The server's `next_step.url`.
	 * @param string $return_url The screen to come back to.
	 * @param string $origin     The API origin.
	 */
	public static function checkout_url( string $url, string $return_url, string $origin ): string {
		$safe = self::safe_url( $url, $origin );
		if ( '' === $safe ) {
			return '';
		}
		return $safe . ( false === strpos( $safe, '?' ) ? '?' : '&' ) . 'return=' . rawurlencode( $return_url );
	}

	/**
	 * A server-supplied address, or an empty string when it is not a plain web address.
	 *
	 * Everything the server says is escaped when printed, but `esc_url` alone keeps a
	 * `javascript:` address out only when the protocol list says so, so the list is explicit.
	 *
	 * @param string $url    Address.
	 * @param string $origin The API origin.
	 */
	public static function safe_url( string $url, string $origin ): string {
		$allowed = array( 'https' );
		if ( 0 === strpos( $origin, 'http://' ) ) {
			$allowed[] = 'http'; // A local development origin.
		}
		$clean = esc_url_raw( $url, $allowed );
		$parts = '' === $clean ? false : wp_parse_url( $clean );
		return ( is_array( $parts ) && ! empty( $parts['host'] ) ) ? $clean : '';
	}
}
