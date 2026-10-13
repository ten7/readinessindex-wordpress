<?php
/**
 * The last answer of `GET /cms/v1/site`, with its ETag.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Api;

use ReadinessIndex\Settings;

/**
 * One non-autoloaded option holding the validated body and the ETag that goes with it.
 *
 * It serves two purposes. The ETag lets a poll send `If-None-Match` and treat a 304 as
 * unchanged; and the body is "the last answer" the screens fall back to when the API is
 * rate limited or failing on its own side. It is cleared on disconnect, when a scan
 * starts and on Refresh, because each of those makes a cached answer wrong.
 */
final class SiteCache {

	/**
	 * Reads the stored entry.
	 *
	 * @return array{etag:string,body:array<string,mixed>}|null
	 */
	public static function read(): ?array {
		$stored = get_option( Settings::OPTION_SITE_CACHE, null );
		if ( ! is_array( $stored ) || ! isset( $stored['etag'], $stored['body'] ) || ! is_string( $stored['etag'] ) || ! is_array( $stored['body'] ) ) {
			return null;
		}
		return array(
			'etag' => $stored['etag'],
			'body' => $stored['body'],
		);
	}

	/**
	 * Stores an entry. The body has already been validated by the model.
	 *
	 * @param string              $etag The response's ETag, or an empty string.
	 * @param array<string,mixed> $body The decoded body.
	 */
	public static function write( string $etag, array $body ): void {
		update_option(
			Settings::OPTION_SITE_CACHE,
			array(
				'etag' => $etag,
				'body' => $body,
			),
			false
		);
	}

	/**
	 * Forgets the entry.
	 */
	public static function clear(): void {
		delete_option( Settings::OPTION_SITE_CACHE );
	}

	/**
	 * The cached answer as an object, or null when there is none or it no longer parses.
	 */
	public static function last_answer(): ?Model\SiteResponse {
		$entry = self::read();
		if ( null === $entry ) {
			return null;
		}
		try {
			return Model\SiteResponse::from_array( $entry['body'] );
		} catch ( ApiError $e ) {
			// A cached body from an older module version that no longer matches the contract.
			return null;
		}
	}
}
