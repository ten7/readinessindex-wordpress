<?php
namespace ReadinessIndex\Tests\Integration;

use ReadinessIndex\Diagnostics\AltText;
use ReadinessIndex\Diagnostics\CdnAccess;
use ReadinessIndex\Diagnostics\JsOnly;
use ReadinessIndex\Diagnostics\Result;
use ReadinessIndex\Diagnostics\Runner;
use ReadinessIndex\Http\SiteFetcher;
use ReadinessIndex\Tests\Integration\Support\IntegrationCase;

/**
 * The three diagnostics: they measure, they never fix, and they never leave the site.
 */
final class DiagnosticsTest extends IntegrationCase {

	/**
	 * An image attachment, with or without alt text.
	 *
	 * @param string|null $alt Alt text, or null for none.
	 */
	private function image( ?string $alt ): int {
		$id = (int) self::factory()->attachment->create(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => 'An image',
			)
		);
		if ( null !== $alt ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		}
		return $id;
	}

	public function test_images_without_alt_text_are_counted_and_nothing_is_written(): void {
		$none  = $this->image( null );
		$empty = $this->image( '' );
		$this->image( 'A tulip' );
		delete_transient( AltText::TRANSIENT );

		$this->assertSame( 2, AltText::count() );
		$this->assertSame( '', (string) get_post_meta( $none, '_wp_attachment_image_alt', true ), 'alt text is never written' );
		$this->assertSame( '', (string) get_post_meta( $empty, '_wp_attachment_image_alt', true ) );
		$this->assertStringContainsString( 'upload.php', AltText::url() );
	}

	public function test_crawlers_that_are_answered_differently_from_a_browser_are_named_with_a_source_hint_and_the_caveat(): void {
		$this->answers[ home_url( '/' ) ] = static function ( $url, $args ) {
			$blocked = false !== strpos( (string) ( $args['user-agent'] ?? '' ), 'GPTBot' ) || false !== strpos( (string) ( $args['user-agent'] ?? '' ), 'CCBot' );
			return array(
				'status'  => $blocked ? 403 : 200,
				'headers' => array(
					'cf-ray' => '8a1b',
					'server' => 'cloudflare',
				),
				'body'    => 'ok',
			);
		};
		$result                           = CdnAccess::run();

		$this->assertFalse( $result->clean );
		$this->assertStringContainsString( 'GPTBot, CCBot', $result->lines[0] );
		$this->assertCount( 2, $result->items );
		$this->assertStringContainsString( 'Cloudflare', implode( ' ', $result->lines ) );
		$this->assertStringContainsString( "not a real crawler's request", implode( ' ', $result->lines ), 'the plain statement that this is a hint and not proof' );
		$this->assertCount( 9, $this->requests, 'one for a browser and one for each of the eight crawlers' );
	}

	public function test_a_site_that_answers_everyone_alike_is_clean(): void {
		$this->answers[ home_url( '/' ) ] = array(
			'status' => 200,
			'body'   => 'ok',
		);
		$this->assertTrue( CdnAccess::run()->clean );
	}

	public function test_no_diagnostic_asks_anything_but_this_sites_own_host(): void {
		$this->assertNull( SiteFetcher::get( 'https://evil.example/collect' ) );
		$this->assertNull( SiteFetcher::get( 'http://127.0.0.1:9/' ) );
		$this->assertNull( SiteFetcher::get( 'https://' . wp_parse_url( home_url(), PHP_URL_HOST ) . '.evil.example/' ) );
		$this->assertSame( array(), $this->requests, 'not even an attempt was made' );

		$this->answers[ home_url( '/' ) ] = array( 'body' => 'ok' );
		CdnAccess::run();
		JsOnly::run();
		foreach ( $this->requests as $url ) {
			$this->assertSame( wp_parse_url( home_url(), PHP_URL_HOST ), wp_parse_url( $url, PHP_URL_HOST ), $url );
		}
	}

	public function test_a_page_that_is_an_empty_shell_until_a_script_runs_is_flagged_with_its_likely_cause(): void {
		$id                                    = (int) self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Services',
				'post_content' => str_repeat( 'We plant prune and design gardens for families across the whole valley. ', 8 ),
			)
		);
		$this->answers[ get_permalink( $id ) ] = array( 'body' => '<html><body><div id="root"></div><script src="app.js"></script></body></html>' ); // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Test markup, not output.
		$result                                = JsOnly::run();

		$this->assertFalse( $result->clean );
		$this->assertStringContainsString( 'empty application shell', implode( ' ', $result->lines ) );
		$this->assertStringContainsString( '0 percent', $result->items[0] );
	}

	public function test_a_page_that_carries_its_text_is_clean(): void {
		$text                                  = str_repeat( 'We plant prune and design gardens for families across the whole valley. ', 8 );
		$id                                    = (int) self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Services',
				'post_content' => $text,
			)
		);
		$this->answers[ get_permalink( $id ) ] = array( 'body' => '<html><body><main><p>' . $text . '</p></main></body></html>' );
		$this->assertTrue( JsOnly::run()->clean );
	}

	public function test_a_result_is_stored_and_read_back_and_becomes_markdown_that_cannot_inject_any(): void {
		$this->answers[ home_url( '/' ) ] = array( 'body' => 'ok' );
		Runner::run( 'cdn' );
		$last = Runner::last( 'cdn' );
		$this->assertInstanceOf( Result::class, $last );
		$this->assertNull( Runner::run( 'alt_text' ), 'only the two that make requests can be run' );
		$this->assertNull( Runner::run( 'robots; drop table' ) );

		$result          = new Result( 'cdn' );
		$result->lines[] = "A **bold** [link](x) <b>line</b>\nsecond";
		$result->items[] = 'GPTBot was answered `403`';
		$md              = $result->to_markdown( 'Title with # and *' );
		$this->assertStringNotContainsString( '**', $md );
		$this->assertStringNotContainsString( '<b>', $md );
		$this->assertStringNotContainsString( '`', $md );
		$this->assertStringStartsWith( '### Title with # and', $md );
	}

	public function test_the_diagnostics_never_call_a_fixer_or_write_to_the_site(): void {
		$files = array_merge( glob( dirname( __DIR__, 2 ) . '/src/Diagnostics/*.php' ), array( dirname( __DIR__, 2 ) . '/src/Http/SiteFetcher.php' ) );
		foreach ( $files as $file ) {
			$code = (string) file_get_contents( $file );
			$this->assertDoesNotMatchRegularExpression( '/\b(wp_update_post|wp_insert_post|update_post_meta|add_post_meta|wp_update_attachment_metadata)\s*\(/', $code, basename( $file ) );
			$this->assertDoesNotMatchRegularExpression( '/_wp_attachment_image_alt.*update_|update_.*_wp_attachment_image_alt/', $code, basename( $file ) );
		}
	}
}
