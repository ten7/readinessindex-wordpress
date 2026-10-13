<?php
namespace ReadinessIndex\Tests;

use Brain\Monkey\Functions;
use ReadinessIndex\Diagnostics\CdnAccess;
use ReadinessIndex\Diagnostics\JsOnly;
use ReadinessIndex\Diagnostics\Result;
use ReadinessIndex\Fixers\Content\RobotsMeta;
use ReadinessIndex\Fixers\Hooks;
use ReadinessIndex\Fixers\Runtime\LlmsTxt;
use ReadinessIndex\Tests\Support\WpTestCase;

/**
 * The pure parts of the diagnostics, the meta parser and the llms.txt answer, without WordPress.
 */
final class DiagnosticPartsTest extends WpTestCase {

	public function test_a_refusal_is_a_status_that_differs_in_a_way_that_looks_like_one(): void {
		$this->assertFalse( CdnAccess::differs( 200, 200 ) );
		$this->assertTrue( CdnAccess::differs( 200, 403 ) );
		$this->assertTrue( CdnAccess::differs( 200, 429 ) );
		$this->assertTrue( CdnAccess::differs( 200, 503 ) );
		$this->assertFalse( CdnAccess::differs( 200, 301 ), 'a redirect is not a refusal' );
		$this->assertFalse( CdnAccess::differs( 403, 403 ), 'a site that refuses everyone is not singling crawlers out' );
	}

	public function test_headers_suggest_the_network_in_front_of_the_site(): void {
		$this->assertSame( array( 'Cloudflare' ), CdnAccess::hints( array( 'cf-ray' => 'x' ) ) );
		$this->assertSame( array( 'Sucuri' ), CdnAccess::hints( array( 'x-sucuri-id' => '1' ) ) );
		$this->assertSame( array( 'Amazon CloudFront' ), CdnAccess::hints( array( 'via' => '1.1 abc.cloudfront.net (CloudFront)' ) ) );
		$this->assertSame( array(), CdnAccess::hints( array( 'server' => 'Apache' ) ) );
	}

	public function test_the_eight_fetching_crawlers_are_probed_with_the_shape_an_edge_rule_matches(): void {
		$this->assertCount( 8, CdnAccess::USER_AGENTS );
		$this->assertStringContainsString( 'compatible; GPTBot/', CdnAccess::USER_AGENTS['GPTBot'] );
		$this->assertStringNotContainsString( 'Google-Extended', implode( ' ', array_keys( CdnAccess::USER_AGENTS ) ), 'a directive-only token issues no requests' );
	}

	public function test_the_share_of_a_pages_own_words_that_is_in_the_raw_html(): void {
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		$own = JsOnly::words( '<p>We plant, prune &amp; design gardens.</p>' );
		$this->assertSame( 'we plant prune design gardens', $own );
		$this->assertSame( 1.0, JsOnly::share( $own, JsOnly::words( '<main>We plant prune design gardens</main>' ) ) );
		$this->assertSame( 0.0, JsOnly::share( $own, JsOnly::words( '<div id="root"></div>' ) ) );
		$this->assertSame( 1.0, JsOnly::share( '', 'anything' ) );
	}

	public function test_causes_are_named_from_the_markup(): void {
		$this->assertSame( array( 'app_shell' ), JsOnly::causes( '<div id="root"></div>' ) );
		$this->assertContains( 'framework', JsOnly::causes( '<script id="__NEXT_DATA__">{}</script>' ) );
		$this->assertContains( 'noscript_fallback', JsOnly::causes( '<noscript>' . str_repeat( 'x', 300 ) . '</noscript>' ) );
		$this->assertSame( array(), JsOnly::causes( '<main><p>Plain page</p></main>' ) );
	}

	public function test_a_result_survives_storage(): void {
		$result          = new Result( 'cdn' );
		$result->clean   = false;
		$result->lines[] = 'A line';
		$result->items[] = 'An item';
		$back            = Result::from_array( $result->to_array() );
		$this->assertSame( $result->to_array(), $back->to_array() );
		$this->assertFalse( $back->clean );
	}

	public function test_robots_meta_is_read_from_the_head_and_the_two_markups_are_told_apart(): void {
		$meta = RobotsMeta::meta( "<head><meta name='robots' content='noarchive, max-image-preview:large' /><meta name=\"robots\" content=\"nosnippet\"></head><body><meta name='robots' content='noindex'></body>" );
		$this->assertSame( array( 'noarchive', 'max-image-preview:large' ), $meta['core'] );
		$this->assertSame( array( 'nosnippet' ), $meta['other'] );
		$this->assertSame( array( 'noarchive', 'max-snippet:0' ), RobotsMeta::header_tokens( 'bot: noarchive, max-snippet:0' ) );
	}

	public function test_nothing_is_hooked_with_nothing_applied(): void {
		\Brain\Monkey\Actions\expectAdded( 'init' )->never();
		\Brain\Monkey\Filters\expectAdded( 'robots_txt' )->never();
		Hooks::register();
		$this->addToAssertionCount( 1 );
	}

	public function test_the_llms_answer_is_a_404_without_a_stored_file_and_a_304_for_a_matching_etag(): void {
		$stored = array(
			'text'  => "# Site\n",
			'hash'  => 'abc',
			'built' => 1700000000,
		);
		$this->assertSame( 404, LlmsTxt::response( null, '', '' )['status'] );
		$this->assertSame( 404, LlmsTxt::response( array( 'text' => '' ), '', '' )['status'] );
		$this->assertSame( 200, LlmsTxt::response( $stored, '', '' )['status'] );
		$this->assertSame( 304, LlmsTxt::response( $stored, '"abc"', '' )['status'] );
		$this->assertSame( 304, LlmsTxt::response( $stored, '"x", "abc"', '' )['status'] );
		$this->assertSame( 200, LlmsTxt::response( $stored, '"x"', 'Tue, 14 Nov 2023 22:13:20 GMT' )['status'], 'a validator that does not match is not rescued by a date' );
	}
}
