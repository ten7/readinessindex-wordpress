<?php
namespace ReadinessIndex\Tests\Integration;

use ReadinessIndex\Fixers\Content\DateItems;
use ReadinessIndex\Fixers\Impl\DatesFixer;
use ReadinessIndex\Fixers\Registry;
use ReadinessIndex\Fixers\Runner;
use ReadinessIndex\Fixers\Runtime\Dates;
use ReadinessIndex\Fixers\State;
use ReadinessIndex\Tests\Integration\Support\IntegrationCase;

/**
 * The honest-dates fixer in a real WordPress: how it learns when content really changed, what it
 * publishes in the sitemap, that it reads GMT columns only (across a daylight-saving change and
 * with the site in another zone), and that it never writes a date into WordPress.
 */
final class DatesTest extends IntegrationCase {

	/**
	 * A published post with chosen GMT times.
	 *
	 * @param string $published Published, GMT.
	 * @param string $modified  Modified, GMT.
	 */
	private function post( string $published, string $modified ): int {
		global $wpdb;
		$id = (int) self::factory()->post->create(
			array(
				'post_title'    => 'A post ' . wp_rand(),
				'post_content'  => 'Text.',
				'post_status'   => 'publish',
				'post_date_gmt' => $published,
				'post_date'     => get_date_from_gmt( $published ),
			)
		);
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_modified_gmt' => $modified,
				'post_modified'     => get_date_from_gmt( $modified ),
			),
			array( 'ID' => $id )
		);
		clean_post_cache( $id );
		return $id;
	}

	public function test_a_real_edit_is_stamped_and_a_save_that_changed_nothing_keeps_the_last_real_time(): void {
		add_action( 'post_updated', array( Dates::class, 'record_change' ), 10, 3 );
		$id = $this->post( '2026-01-05 09:00:00', '2026-02-01 10:00:00' );

		// A save that changes nothing: a deploy script re-saving the page.
		wp_update_post( array( 'ID' => $id ) );
		$this->assertSame( '2026-02-01T10:00:00+00:00', get_post_meta( $id, DateItems::META, true ), 'the time the post had before the save' );

		// A real edit.
		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => 'A changed title',
			)
		);
		$changed = get_post_meta( $id, DateItems::META, true );
		$this->assertSame( DateItems::iso( get_post( $id )->post_modified_gmt ), $changed );
		$this->assertNotSame( '2026-02-01T10:00:00+00:00', $changed );

		// Touched again with no change: the real time is kept.
		wp_update_post( array( 'ID' => $id ) );
		$this->assertSame( $changed, get_post_meta( $id, DateItems::META, true ) );
	}

	public function test_dates_come_from_gmt_columns_across_a_daylight_saving_change_and_in_any_site_zone(): void {
		$utc = array();
		foreach ( array( 'UTC', 'America/Chicago', 'Pacific/Auckland' ) as $zone ) {
			update_option( 'timezone_string', $zone );
			// The night clocks went back in Chicago: 06:30 UTC on 2025-11-02 is 01:30 CDT, and 07:30 UTC is 01:30 CST.
			$a            = $this->post( '2025-10-01 12:00:00', '2025-11-02 06:30:00' );
			$b            = $this->post( '2025-10-01 12:00:00', '2025-11-02 07:30:00' );
			$utc[ $zone ] = array( Dates::for_post( get_post( $a ) ), Dates::for_post( get_post( $b ) ) );
		}
		$this->assertSame( $utc['UTC'], $utc['America/Chicago'] );
		$this->assertSame( $utc['UTC'], $utc['Pacific/Auckland'] );
		$this->assertSame( '2025-11-02T06:30:00+00:00', $utc['UTC'][0]['dateModified'] );
		$this->assertSame( '2025-11-02T07:30:00+00:00', $utc['UTC'][1]['dateModified'], 'the hour that happened twice is two different times' );
	}

	public function test_a_modified_date_before_the_published_date_or_in_the_future_is_not_believed(): void {
		$early = $this->post( '2026-03-10 09:00:00', '2026-03-01 09:00:00' );
		$late  = $this->post( '2026-03-10 09:00:00', gmdate( 'Y-m-d H:i:s', time() + 10 * DAY_IN_SECONDS ) );
		$this->assertSame( '2026-03-10T09:00:00+00:00', Dates::for_post( get_post( $early ) )['dateModified'] );
		$this->assertSame( '2026-03-10T09:00:00+00:00', Dates::for_post( get_post( $late ) )['dateModified'] );
	}

	public function test_a_scheduled_post_and_a_post_with_no_published_date_get_none(): void {
		$future = (int) self::factory()->post->create(
			array(
				'post_status' => 'future',
				'post_date'   => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			)
		);
		$this->assertNull( Dates::for_post( get_post( $future ) ) );
		$draft = (int) self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$this->assertNull( Dates::for_post( get_post( $draft ) ) );
	}

	public function test_the_core_sitemap_entry_carries_lastmod_in_utc_whatever_the_site_zone(): void {
		update_option( 'timezone_string', 'America/Chicago' );
		$id = $this->post( '2026-01-05 09:00:00', '2026-10-12 03:00:00' );
		update_post_meta( $id, DateItems::META, '2026-03-02T10:30:00+00:00' );
		add_filter( 'wp_sitemaps_posts_entry', array( Dates::class, 'core_sitemap_entry' ), 10, 2 );

		$entry = apply_filters( 'wp_sitemaps_posts_entry', array( 'loc' => get_permalink( $id ) ), get_post( $id ), 'post' );

		$this->assertSame( '2026-03-02T10:30:00+00:00', $entry['lastmod'] );
	}

	public function test_what_core_does_with_lastmod_is_pinned_per_release_and_the_filter_sets_it_either_way(): void {
		// The dates fixer sets `lastmod` itself, so it works on both sides of this. The behavior is
		// written down per release so that a release that changes it is noticed and reviewed: the
		// ticket asks for a test that fails if core's behavior changes. WordPress 6.4 builds the
		// entry from the permalink alone; 7.1 adds `lastmod` from `post_modified_gmt` in the site's
		// own zone, which is the instant the fixer would have written, in another notation.
		$id    = $this->post( '2026-01-05 09:00:00', '2026-10-12 03:00:00' );
		$posts = wp_sitemaps_get_server()->registry->get_provider( 'posts' );
		$mine  = array_values(
			array_filter(
				$posts->get_url_list( 1, 'post' ),
				static function ( $entry ) use ( $id ) {
					return get_permalink( $id ) === $entry['loc'];
				}
			)
		);
		$emits = isset( $mine[0]['lastmod'] );
		global $wp_version;
		if ( version_compare( $wp_version, '6.5', '<' ) ) {
			$this->assertFalse( $emits, 'WordPress ' . $wp_version . ' did not emit lastmod; if it does now, review the dates fixer\'s sitemap channel.' );
		} elseif ( version_compare( $wp_version, '7.1', '>=' ) ) {
			$this->assertTrue( $emits, 'WordPress ' . $wp_version . ' emitted lastmod; if it does not now, review the dates fixer\'s sitemap channel.' );
			$this->assertSame( strtotime( '2026-10-12 03:00:00 UTC' ), strtotime( $mine[0]['lastmod'] ), 'the same instant the fixer would write, in the site\'s zone' );
		} else {
			$this->markTestSkipped( 'WordPress ' . $wp_version . ' is between the two releases this behavior is pinned on; its behavior is not asserted.' );
		}
	}

	public function test_apply_writes_no_date_into_wordpress(): void {
		$id = $this->post( '2026-01-05 09:00:00', '2026-10-12 03:00:00' );
		update_post_meta( $id, DateItems::META, '2026-02-02T10:00:00+00:00' );
		$fixer = new DatesFixer();
		Registry::use_fixers( array( $fixer ) );
		$before = get_post( $id )->post_modified_gmt;

		$preview = Runner::preview( $fixer, array() );
		$result  = Runner::apply( $fixer, array(), $preview['hash'], 1 );

		$this->assertSame( 'applied', $result['outcome'] );
		clean_post_cache( $id );
		$this->assertSame( $before, get_post( $id )->post_modified_gmt );
		$this->assertSame( array(), State::get( 'dates' ) );
		Runner::undo( $result['change'], 1 );
		$this->assertNull( State::get( 'dates' ) );
	}

	public function test_the_preview_reads_the_posts_and_says_which_were_touched_without_a_change(): void {
		$id = $this->post( '2026-01-10 09:00:00', '2026-10-12 03:00:00' );
		update_post_meta( $id, DateItems::META, '2026-01-20T09:00:00+00:00' );
		$preview = Runner::preview( new DatesFixer(), array() );

		$this->assertContains( \ReadinessIndex\Fixers\Content\PageSample::path( get_permalink( $id ) ), $preview['findings']['touched_without_change'] );
		$this->assertSame( 'core', $preview['findings']['channels']['sitemap'] );
		$this->assertSame( 'own', $preview['findings']['channels']['jsonld'] );
	}

	public function test_the_preview_flags_a_modified_date_before_the_published_date_and_one_in_the_future(): void {
		$early = $this->post( '2026-03-10 09:00:00', '2026-03-01 09:00:00' );
		$late  = $this->post( '2026-03-10 09:00:00', gmdate( 'Y-m-d H:i:s', time() + 10 * DAY_IN_SECONDS ) );
		$flags = array();
		foreach ( Runner::preview( new DatesFixer(), array() )['flags'] as $flag ) {
			$flags[ $flag['path'] ] = $flag['reason'];
		}
		$this->assertSame( 'modified_before_published', $flags[ \ReadinessIndex\Fixers\Content\PageSample::path( get_permalink( $early ) ) ] );
		$this->assertSame( 'modified_in_future', $flags[ \ReadinessIndex\Fixers\Content\PageSample::path( get_permalink( $late ) ) ] );
		clean_post_cache( $early );
		$this->assertSame( '2026-03-01 09:00:00', get_post( $early )->post_modified_gmt, 'the stored date is flagged, never rewritten' );
	}
}
