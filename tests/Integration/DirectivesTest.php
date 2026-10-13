<?php
namespace ReadinessIndex\Tests\Integration;

use ReadinessIndex\Fixers\Content\RobotsMeta;
use ReadinessIndex\Fixers\Impl\RobotsDirectivesFixer;
use ReadinessIndex\Fixers\Registry;
use ReadinessIndex\Fixers\Runner;
use ReadinessIndex\Fixers\Runtime\Directives;
use ReadinessIndex\Fixers\SeoTools;
use ReadinessIndex\Tests\Integration\Support\IntegrationCase;

/**
 * The stray-directives fixer in a real WordPress: who emits each directive, what is removed,
 * what is reported and left alone, and the real `wp_robots` output.
 */
final class DirectivesTest extends IntegrationCase {

	/**
	 * Makes the site answer a page's request with a head.
	 *
	 * @param string               $needle  Part of the URL.
	 * @param string               $head    Markup for the head.
	 * @param array<string,string> $headers Response headers.
	 */
	private function answer( string $needle, string $head, array $headers = array() ): void {
		$this->answers[ $needle ] = array(
			'body'    => '<html><head>' . $head . '</head><body></body></html>',
			'headers' => $headers,
		);
	}

	public function test_a_directive_wordpress_emits_is_found_and_removed_and_the_others_are_kept_in_order(): void {
		$this->answer( home_url( '/' ), "<meta name='robots' content='noarchive, max-image-preview:large, max-snippet:0' />" );
		$preview = Runner::preview( new RobotsDirectivesFixer(), array() );

		$this->assertSame( 'ready', $preview['status'] );
		$this->assertSame(
			array(
				array(
					'path'    => '/',
					'removed' => array( 'noarchive', 'max-snippet:0' ),
				),
			),
			$preview['findings']['pages']
		);
		$this->assertSame( 'max-image-preview:large', $preview['changes'][0]['after'] );
		$this->assertSame( 'robots meta of /', $preview['changes'][0]['target'] );
	}

	public function test_an_x_robots_tag_header_is_reported_and_never_claimed_as_fixed(): void {
		$this->answer( home_url( '/' ), "<meta name='robots' content='max-image-preview:large' />", array( 'x-robots-tag' => 'googlebot: noarchive, nosnippet' ) );
		$preview = Runner::preview( new RobotsDirectivesFixer(), array() );

		$this->assertSame( 'unchanged', $preview['status'] );
		$this->assertSame(
			array(
				array(
					'path'       => '/',
					'directives' => array( 'noarchive', 'nosnippet' ),
				),
			),
			$preview['findings']['headers']
		);
		$this->assertSame( array(), $preview['conflicts'] );
	}

	public function test_a_hand_written_tag_in_a_theme_is_reported_and_not_fixed(): void {
		$this->answer( home_url( '/' ), '<meta name="robots" content="noarchive" />' );
		$preview = Runner::preview( new RobotsDirectivesFixer(), array() );

		$this->assertSame( 'unchanged', $preview['status'] );
		$this->assertSame(
			array(
				array(
					'path'       => '/',
					'directives' => array( 'noarchive' ),
				),
			),
			$preview['theme']
		);
	}

	public function test_a_directive_an_seo_plugin_owns_is_deferred_and_named(): void {
		SeoTools::use_active( array( 'yoast' ) );
		$this->answer( home_url( '/' ), "<meta name='robots' content='index, noarchive' />" );
		$fixer   = new RobotsDirectivesFixer();
		$preview = Runner::preview( $fixer, array() );

		$this->assertSame( 'defer', $preview['status'] );
		$this->assertSame( array( 'tooling_owns_it' ), $preview['conflicts'] );
		$this->assertSame( 'yoast', $preview['findings']['deferred'][0]['tooling'] );
		$this->assertFalse( $fixer->reachable() );

		Registry::use_fixers( array( $fixer ) );
		$result = Runner::apply( $fixer, array(), $preview['hash'], 1 );
		$this->assertSame( 'refused', $result['outcome'] );
		$this->assertSame( 'yoast', $result['tooling'] );
	}

	public function test_the_filter_removes_the_three_and_never_touches_noindex_or_nofollow(): void {
		$out = Directives::filter(
			array(
				'noindex'           => true,
				'nofollow'          => true,
				'noarchive'         => true,
				'nosnippet'         => true,
				'max-snippet'       => 0,
				'max-image-preview' => 'large',
			)
		);
		$this->assertSame(
			array(
				'noindex'           => true,
				'nofollow'          => true,
				'max-image-preview' => 'large',
			),
			$out
		);
		$this->assertSame( array( 'max-snippet' => 50 ), Directives::filter( array( 'max-snippet' => 50 ) ), 'a real limit stays' );
		$this->assertSame( array( 'max-snippet' => -1 ), Directives::filter( array( 'max-snippet' => -1 ) ) );
	}

	public function test_the_filter_does_nothing_where_an_seo_plugin_owns_the_tag_or_when_asked_for_the_baseline(): void {
		SeoTools::use_active( array( 'rank_math' ) );
		$this->assertArrayHasKey( 'noarchive', Directives::filter( array( 'noarchive' => true ) ) );
		SeoTools::use_active( array() );
		$_GET[ Directives::BASELINE_FLAG ] = '1';
		try {
			$this->assertArrayHasKey( 'noarchive', Directives::filter( array( 'noarchive' => true ) ) );
		} finally {
			unset( $_GET[ Directives::BASELINE_FLAG ] );
		}
	}

	public function test_the_real_robots_meta_loses_the_directives_after_apply(): void {
		add_filter(
			'wp_robots',
			static function ( $robots ) {
				$robots['noarchive'] = true;
				return $robots;
			}
		);
		$fixer = new RobotsDirectivesFixer();
		Registry::use_fixers( array( $fixer ) );
		$this->answer( home_url( '/' ), "<meta name='robots' content='max-image-preview:large, noarchive' />" );
		Runner::apply( $fixer, array(), Runner::preview( $fixer, array() )['hash'], 1 );
		$this->next_request();
		add_filter( 'wp_robots', array( Directives::class, 'filter' ), Directives::PRIORITY );

		ob_start();
		wp_robots();
		$tag = ob_get_clean();
		$this->assertStringContainsString( 'max-image-preview:large', $tag );
		$this->assertStringNotContainsString( 'noarchive', $tag );
	}

	public function test_the_meta_parser_reads_core_and_other_tags_header_prefixes_and_ignores_the_body(): void {
		$meta = RobotsMeta::meta( "<html><head><meta name='robots' content='a, b' /><meta name=\"robots\" content=\"c\"></head><body><meta name='robots' content='d' /></body></html>" );
		$this->assertSame( array( 'a', 'b' ), $meta['core'] );
		$this->assertSame( array( 'c' ), $meta['other'] );
		$this->assertSame( array( 'noarchive', 'max-snippet:0', 'nosnippet' ), RobotsMeta::header_tokens( 'googlebot: noarchive, max-snippet:0, bingbot: nosnippet' ) );
	}

	public function test_only_this_sites_own_pages_are_ever_requested(): void {
		$this->answer( home_url( '/' ), "<meta name='robots' content='noarchive' />" );
		Runner::preview( new RobotsDirectivesFixer(), array() );
		foreach ( $this->requests as $url ) {
			$this->assertSame( wp_parse_url( home_url() )['host'], wp_parse_url( $url )['host'], $url );
		}
	}

	public function test_when_no_page_could_be_read_the_preview_says_so_instead_of_claiming_there_is_nothing(): void {
		// No answers at all: every request fails.
		$preview = Runner::preview( new RobotsDirectivesFixer(), array() );
		$this->assertSame( array(), $preview['output']['pages'] );

		ob_start();
		\ReadinessIndex\Admin\FindingsView::render( 'robots-directives', $preview );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'could not read any of your pages', $html );
		$this->assertStringNotContainsString( 'No page tells search engines', $html );
	}
}
