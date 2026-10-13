<?php
/**
 * Choosing the state of the Score screen.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Admin;

use ReadinessIndex\Api\ApiError;
use ReadinessIndex\Api\Client;
use ReadinessIndex\Api\SiteCache;
use ReadinessIndex\Connect\Connector;
use ReadinessIndex\Connect\Preflight;
use ReadinessIndex\Settings;

/**
 * Implements "How a state is decided" from `docs/contract/screens.md`: at most one call to
 * `GET /cms/v1/site` per load, read in a fixed order, so that the situation a person is in
 * is decided by the API's answer and never by what this module guesses.
 */
final class Screen {

	const BACKOFF_TRANSIENT = 'ri_backoff';
	const DISMISS_META      = 'readiness_index_dismissed_monitoring';

	/**
	 * Builds the model for one load of the screen.
	 *
	 * @param int         $user_id        The signed-in administrator.
	 * @param array       $flags          View-only request flags: `returned` and `confirm_disconnect`.
	 * @param Client|null $client         API client, or null for the stored one.
	 * @param bool        $with_preflight Whether to gather the pre-flight notices.
	 */
	public static function build( int $user_id, array $flags = array(), ?Client $client = null, bool $with_preflight = true ): ViewModel {
		$model                        = new ViewModel();
		$model->flash                 = Flash::take( $user_id );
		$model->returned              = ! empty( $flags['returned'] );
		$model->confirming_disconnect = ! empty( $flags['confirm_disconnect'] );
		$model->key_is_constant       = Settings::key_is_constant();
		$model->account_label         = Settings::connection()['plan'];
		$model->monitoring_dismissed  = '1' === (string) get_user_meta( $user_id, self::DISMISS_META, true );

		// 1. No key stored: unconnected.
		if ( ! Settings::is_connected() ) {
			$model->state     = ViewModel::UNCONNECTED;
			$model->waiting   = Connector::is_waiting( $user_id );
			$model->preflight = $with_preflight ? Preflight::for_this_site() : array();
			return $model;
		}

		// Coming back from Checkout: the cached answer predates the subscription.
		if ( $model->returned ) {
			SiteCache::clear();
			delete_transient( self::BACKOFF_TRANSIENT );
		}

		// A recent 429 is honored without another call, so the screen never hammers a limit.
		if ( false !== get_transient( self::BACKOFF_TRANSIENT ) ) {
			$last = SiteCache::last_answer();
			return self::from_last( $model, $last, ViewModel::RATE_LIMITED );
		}

		try {
			$site = ( $client ?? new Client() )->site();
		} catch ( ApiError $e ) {
			return self::from_failure( $model, $e );
		}

		return self::from_site( $model, $site );
	}

	/**
	 * Fills the model from a fresh answer: `scan.state` is the state.
	 *
	 * @param ViewModel                              $model Model.
	 * @param \ReadinessIndex\Api\Model\SiteResponse $site  The answer.
	 */
	private static function from_site( ViewModel $model, $site ): ViewModel {
		$model->site = $site;
		switch ( $site->scan->state ) {
			case 'pending':
				$model->state = ViewModel::PENDING;
				break;
			case 'running':
				$model->state = ViewModel::RUNNING;
				break;
			case 'done':
				$model->state = ViewModel::DONE;
				break;
			case 'failed':
				$model->state = ViewModel::FAILED;
				break;
			default:
				$model->state = ViewModel::NEVER_SCANNED;
		}
		$model->polling = $site->scan->is_active();
		return $model;
	}

	/**
	 * Fills the model when the call failed.
	 *
	 * @param ViewModel $model Model.
	 * @param ApiError  $e     The failure.
	 */
	private static function from_failure( ViewModel $model, ApiError $e ): ViewModel {
		$model->error = $e;
		$last         = SiteCache::last_answer();

		switch ( $e->key ) {
			case ApiError::OFFLINE:
				$model->state = ViewModel::OFFLINE;
				return $model;
			case 'unauthenticated':
				// The last score stays under the notice: Connect replaces the key and loses nothing.
				$model->state = ViewModel::REVOKED;
				$model->site  = $last;
				$model->stale = null !== $last;
				return $model;
			case 'account_disabled':
				$model->state = ViewModel::DISABLED;
				return $model;
			case 'rate_limited':
				set_transient( self::BACKOFF_TRANSIENT, 1, $e->retry_after() );
				return self::from_last( $model, $last, ViewModel::RATE_LIMITED );
			default:
				// internal_error, a contract error, anything else: the last answer stays on screen.
				return self::from_last( $model, $last, ViewModel::ERROR );
		}
	}

	/**
	 * Draws the last answer when there is one, and the named state when there is not.
	 *
	 * @param ViewModel                                   $model    Model.
	 * @param \ReadinessIndex\Api\Model\SiteResponse|null $last     The last answer.
	 * @param string                                      $fallback State when there is no last answer.
	 */
	private static function from_last( ViewModel $model, $last, string $fallback ): ViewModel {
		if ( null === $last ) {
			$model->state = $fallback;
			return $model;
		}
		$model = self::from_site( $model, $last );
		// A stale answer is not polled: the call that would poll it is the one that failed.
		$model->polling = false;
		$model->stale   = true;
		return $model;
	}
}
