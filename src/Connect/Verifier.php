<?php
/**
 * Asking the server to look for the ownership file.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Connect;

use ReadinessIndex\Api\ApiError;
use ReadinessIndex\Api\Client;
use ReadinessIndex\Api\SiteCache;
use ReadinessIndex\Settings;

/**
 * `POST /cms/v1/verify`, from the Check ownership button and from the single cron event
 * the Connect callback schedules.
 */
final class Verifier {

	/**
	 * Runs the check and returns which sentence the screen shows.
	 *
	 * @param Client|null $client API client, or null for the stored one.
	 * @return array{verified:bool,key:string,message:string} `key` is `verified`, `not_found`, `error`, or an API error key.
	 */
	public static function check( ?Client $client = null ): array {
		try {
			$result = ( $client ?? new Client() )->verify();
			// site.verified is stored on the server; the cached answer still says it is not.
			SiteCache::clear();
			return array(
				'verified' => $result->verified,
				'key'      => 'verified',
				'message'  => '',
			);
		} catch ( ApiError $e ) {
			if ( 'not_verified' === $e->key ) {
				$state = 'not_found' === $e->verify_state() ? 'not_found' : 'error';
				return array(
					'verified' => false,
					'key'      => $state,
					'message'  => $e->getMessage(),
				);
			}
			return array(
				'verified' => false,
				'key'      => $e->key,
				'message'  => $e->getMessage(),
			);
		}
	}

	/**
	 * The cron callback: one best-effort attempt, with nothing shown to anybody.
	 */
	public static function run(): void {
		if ( Settings::is_connected() ) {
			self::check();
		}
	}
}
