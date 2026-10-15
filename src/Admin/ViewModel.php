<?php
/**
 * Everything a screen needs to draw itself, decided before anything is printed.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Admin;

use ReadinessIndex\Api\ApiError;
use ReadinessIndex\Api\Model\SiteResponse;

/**
 * Plain data. The state is chosen in `Screen::build` in the order `screens.md` gives, and the
 * view only draws what is here.
 */
final class ViewModel {

	const UNCONNECTED   = 'unconnected';
	const NEVER_SCANNED = 'never_scanned';
	const PENDING       = 'pending';
	const RUNNING       = 'running';
	const DONE          = 'done';
	const FAILED        = 'failed';
	const REVOKED       = 'revoked';
	const DISABLED      = 'disabled';
	const OFFLINE       = 'offline';
	const RATE_LIMITED  = 'rate_limited';
	const ERROR         = 'error';

	/**
	 * The state of the screen.
	 *
	 * @var string
	 */
	public $state = self::UNCONNECTED;

	/**
	 * The answer being drawn: fresh, or the last one when the call failed.
	 *
	 * @var SiteResponse|null
	 */
	public $site;

	/**
	 * Whether `site` is an older answer shown because the call failed.
	 *
	 * @var bool
	 */
	public $stale = false;

	/**
	 * The failure behind an `error` state, or the one that made `site` stale.
	 *
	 * @var ApiError|null
	 */
	public $error;

	/**
	 * A result carried over from the action the person just took.
	 *
	 * @var array{kind:string,code:string,message:string,reason:string}|null
	 */
	public $flash;

	/**
	 * Whether the person pressed Connect and has not come back.
	 *
	 * @var bool
	 */
	public $waiting = false;

	/**
	 * Sentence keys from the pre-flight check of this site's own side.
	 *
	 * @var array<int,string>
	 */
	public $preflight = array();

	/**
	 * Whether Checkout has just returned (`readiness=subscribed`).
	 *
	 * @var bool
	 */
	public $returned = false;

	/**
	 * Whether the person closed the monitoring line.
	 *
	 * @var bool
	 */
	public $monitoring_dismissed = false;

	/**
	 * Whether the key comes from wp-config.php, in which case it cannot be forgotten here.
	 *
	 * @var bool
	 */
	public $key_is_constant = false;

	/**
	 * Whether a scan is being watched, so the page polls.
	 *
	 * @var bool
	 */
	public $polling = false;

	/**
	 * Whether the confirm-disconnect step is showing.
	 *
	 * @var bool
	 */
	public $confirming_disconnect = false;
}
