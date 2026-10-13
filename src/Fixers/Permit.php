<?php
/**
 * The runner's permission to change a site.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Fixers;

/**
 * Passed to `apply`, `undo` and `cleanup`. Its constructor needs a `Runner`, and the runner
 * is the only file that creates one (`RegistryTest` greps for it), so the path to a change
 * always passes the capability check, the nonce, the conflict check and the changes table.
 */
final class Permit {

	/**
	 * The runner that issued it.
	 *
	 * @var Runner
	 */
	private $issuer;

	/**
	 * Issued for one change.
	 *
	 * @param Runner $issuer The runner that checked everything.
	 */
	public function __construct( Runner $issuer ) {
		$this->issuer = $issuer;
	}

	/**
	 * The runner that issued it, which is how a test proves the permit is genuine.
	 */
	public function issuer(): Runner {
		return $this->issuer;
	}
}
