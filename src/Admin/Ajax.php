<?php
/**
 * Progress of a running scan, for the screen's polling script.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Admin;

use ReadinessIndex\Api\ApiError;
use ReadinessIndex\Api\Client;
use ReadinessIndex\Scan\Poller;
use ReadinessIndex\Strings;

/**
 * One `admin-ajax` action. It returns the sentences the script puts on screen, already
 * looked up in the shared strings, so the script holds none of its own.
 */
final class Ajax {

	const ACTION        = 'readiness_index_progress';
	const PROMPT_ACTION = 'readiness_index_fix_prompt';

	/**
	 * Registers the action. There is no `nopriv` variant: a visitor has no scan to watch.
	 */
	public static function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( self::class, 'handle' ) );
	}

	/**
	 * The fix prompt action, registered only when the fixers are switched on.
	 */
	public static function register_fixers(): void {
		add_action( 'wp_ajax_' . self::PROMPT_ACTION, array( self::class, 'handle_prompt' ) );
	}

	/**
	 * The hook callback: capability, nonce, then the payload.
	 */
	public static function handle(): void {
		if ( ! current_user_can( Actions::CAPABILITY ) ) {
			wp_send_json_error( null, 403 );
		}
		check_ajax_referer( self::ACTION, 'nonce' );
		wp_send_json_success( self::payload() );
	}

	/**
	 * The fix prompt of one outstanding test, for the row that was just opened. Capability and
	 * nonce first; the test id must have the shape of one, so nothing else reaches the API path.
	 *
	 * The prompt quotes the scanned site, so it is returned as a string for the script to put
	 * in a `<pre>` with `textContent`, and never as markup.
	 */
	public static function handle_prompt(): void {
		if ( ! current_user_can( Actions::CAPABILITY ) ) {
			wp_send_json_error( null, 403 );
		}
		check_ajax_referer( self::PROMPT_ACTION, 'nonce' );
		$check = isset( $_POST['check'] ) && is_string( $_POST['check'] ) ? Menu::check_id( sanitize_text_field( wp_unslash( $_POST['check'] ) ) ) : '';
		if ( '' === $check ) {
			wp_send_json_error( null, 400 );
		}
		try {
			$prompt = ( new Client() )->fix_prompt( $check );
		} catch ( ApiError $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 200 );
		}
		wp_send_json_success(
			array(
				'prompt' => (string) $prompt->untrusted_prompt,
				'notice' => Strings::get( 'fixes.hand_on.untrusted' ),
				'copy'   => Strings::get( 'fixes.hand_on.copy' ),
				'copied' => Strings::get( 'common.copied' ),
			)
		);
	}

	/**
	 * What the script needs to know after one poll.
	 *
	 * `reload` tells it to stop and reload the screen, which then shows the finished score or
	 * the failure from `site()`. `retry_after` is a pause to respect. Nothing here is a price.
	 *
	 * @param Client|null $client API client, or null for the stored one.
	 * @return array{state:string,finished:bool,reload:bool,title:string,body:string,retry_after:int}
	 */
	public static function payload( ?Client $client = null ): array {
		$out = array(
			'state'       => 'none',
			'finished'    => true,
			'reload'      => true,
			'title'       => '',
			'body'        => '',
			'retry_after' => 0,
		);
		try {
			$status = Poller::observe( $client );
		} catch ( ApiError $e ) {
			$out['finished'] = false;
			$out['reload']   = false;
			if ( 'rate_limited' === $e->key ) {
				$out['retry_after'] = $e->retry_after();
			} elseif ( 'unauthenticated' === $e->key || 'account_disabled' === $e->key || 'not_found' === $e->key ) {
				$out['finished'] = true;
				$out['reload']   = true;
			}
			return $out;
		}
		if ( null === $status ) {
			return $out;
		}

		$out['state']    = $status->state;
		$out['finished'] = $status->is_finished();
		$out['reload']   = $status->is_finished();

		if ( 'pending' === $status->state ) {
			$out['title'] = Strings::get( 'state.pending.title' );
			$out['body']  = Strings::get( 'state.pending.body' );
		} elseif ( 'running' === $status->state ) {
			$out['title'] = Strings::get( 'state.running.title' );
			$out['body']  = ( null !== $status->pages_read && null !== $status->pages_total )
				? Strings::get(
					'state.running.body',
					array(
						'read'  => $status->pages_read,
						'total' => $status->pages_total,
					)
				)
				: Strings::get( 'state.running.body_unknown' );
		}
		return $out;
	}
}
