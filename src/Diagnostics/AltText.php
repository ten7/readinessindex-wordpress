<?php
/**
 * Images in the media library with no alt text.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Diagnostics;

/**
 * AIR-2.5. Counts and links; never writes. A wrong description is worse than none, so alt text
 * is the owner's to write.
 */
final class AltText {

	const TRANSIENT = 'ri_alt_count';

	/**
	 * How many images have no alt text. Kept for ten minutes.
	 */
	public static function count(): int {
		$cached = get_transient( self::TRANSIENT );
		if ( false !== $cached ) {
			return (int) $cached;
		}
		global $wpdb;
		$count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A read-only count, cached in a transient just below.
			"SELECT COUNT(p.ID) FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} m ON ( m.post_id = p.ID AND m.meta_key = '_wp_attachment_image_alt' )
			WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%' AND p.post_mime_type <> 'image/svg+xml'
			AND ( m.meta_id IS NULL OR m.meta_value = '' )"
		);
		set_transient( self::TRANSIENT, $count, 10 * MINUTE_IN_SECONDS );
		return $count;
	}

	/**
	 * Where the owner writes them: the media library, as a list.
	 */
	public static function url(): string {
		return admin_url( 'upload.php?mode=list' );
	}
}
