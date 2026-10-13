<?php
/**
 * The posts whose dates the dates fixer looks at.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Fixers\Content;

/**
 * Published and scheduled pages and posts, with the three times the fixer needs: when each was
 * published, what WordPress recorded as its last modification, and when this plugin last saw its
 * title, body or excerpt change. Every time is read from a GMT column, so nothing depends on the
 * site's timezone or on a daylight-saving change.
 */
final class DateItems {

	const META     = '_readiness_index_changed_gmt';
	const MAX      = 500;
	const ZERO_GMT = '0000-00-00 00:00:00';

	/**
	 * A GMT column as an ISO time with an explicit offset, or null when it is empty.
	 *
	 * @param string $gmt `Y-m-d H:i:s` in GMT, as WordPress stores it.
	 */
	public static function iso( string $gmt ): ?string {
		if ( '' === $gmt || self::ZERO_GMT === $gmt ) {
			return null;
		}
		return str_replace( ' ', 'T', $gmt ) . '+00:00';
	}

	/**
	 * One post as an item.
	 *
	 * @param \WP_Post $post A post.
	 * @return array<string,mixed>
	 */
	public static function item( \WP_Post $post ): array {
		$changed = get_post_meta( $post->ID, self::META, true );
		return array(
			'path'               => PageSample::path( (string) get_permalink( $post ) ),
			'url'                => (string) get_permalink( $post ),
			'published'          => self::iso( (string) $post->post_date_gmt ),
			'modified_recorded'  => self::iso( (string) $post->post_modified_gmt ),
			'content_changed_at' => is_string( $changed ) && '' !== $changed ? $changed : null,
		);
	}

	/**
	 * The items the preview covers: the most recently modified, up to a limit.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function collect(): array {
		$types = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) );
		$posts = get_posts(
			array(
				'post_type'        => $types,
				'post_status'      => array( 'publish', 'future' ),
				'has_password'     => false,
				'numberposts'      => self::MAX,
				'orderby'          => 'modified',
				'order'            => 'DESC',
				'suppress_filters' => true,
			)
		);
		return array_map( array( self::class, 'item' ), $posts );
	}

	/**
	 * Now, as the fixer's clock.
	 */
	public static function now(): string {
		return gmdate( 'Y-m-d\TH:i:s' ) . '+00:00';
	}
}
