<?php
namespace ReadinessIndex\Tests\Integration;

use ReadinessIndex\Fixers\Impl\LlmsTxtFixer;
use ReadinessIndex\Fixers\Registry;
use ReadinessIndex\Fixers\Runner;
use ReadinessIndex\Fixers\Runtime\LlmsTxt;
use ReadinessIndex\Fixers\State;
use ReadinessIndex\Tests\Integration\Support\IntegrationCase;

/**
 * The llms.txt fixer in a real WordPress: what it lists, what it refuses to list, the route,
 * the stored file, the conditional responses and the conflicts.
 */
final class LlmsTxtTest extends IntegrationCase {

	public function set_up() {
		parent::set_up();
		update_option( 'blogname', 'Example Co' );
		update_option( 'blogdescription', 'A family-run garden center.' );
	}

	/**
	 * A page or post.
	 *
	 * @param array<string,mixed> $args Arguments for wp_insert_post.
	 */
	private function post( array $args ): int {
		return (int) self::factory()->post->create( $args + array( 'post_status' => 'publish' ) );
	}

	/**
	 * The text a preview says would be served.
	 */
	private function previewed(): string {
		return Runner::preview( new LlmsTxtFixer(), array() )['output']['llms.txt'];
	}

	public function test_it_lists_published_public_pages_and_recent_posts_with_their_own_excerpts(): void {
		$about = $this->post(
			array(
				'post_type'    => 'page',
				'post_title'   => 'About us',
				'post_excerpt' => 'Who we are.',
			)
		);
		$this->post(
			array(
				'post_title'   => 'Spring planting',
				'post_excerpt' => '',
				'post_content' => 'Tulips go in before the first frost.',
			)
		);
		$text = $this->previewed();

		$this->assertStringStartsWith( "# Example Co\n\n> A family-run garden center.\n\n## Pages\n\n", $text );
		$this->assertStringContainsString( '- [About us](' . get_permalink( $about ) . '): Who we are.', $text );
		$this->assertStringContainsString( ': Tulips go in before the first frost.', $text, 'a post with no excerpt gets the start of its own text' );
		$this->assertStringContainsString( "\n## Posts\n", $text );
		$this->assertStringEndsWith( "\n", $text );
	}

	public function test_it_never_lists_a_draft_private_protected_noindex_or_scheduled_page(): void {
		$this->post(
			array(
				'post_type'  => 'page',
				'post_title' => 'Visible page',
			)
		);
		$this->post(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Draft page',
				'post_status' => 'draft',
			)
		);
		$this->post(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Private page',
				'post_status' => 'private',
			)
		);
		$this->post(
			array(
				'post_type'     => 'page',
				'post_title'    => 'Protected page',
				'post_password' => 'secret',
			)
		);
		$this->post(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Scheduled page',
				'post_status' => 'future',
				'post_date'   => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			)
		);
		$yoast = $this->post(
			array(
				'post_type'  => 'page',
				'post_title' => 'Yoast noindex page',
			)
		);
		update_post_meta( $yoast, '_yoast_wpseo_meta-robots-noindex', '1' );
		$rank = $this->post(
			array(
				'post_type'  => 'page',
				'post_title' => 'Rank Math noindex page',
			)
		);
		update_post_meta( $rank, 'rank_math_robots', array( 'index' => 'noindex' ) );
		$excluded = $this->post(
			array(
				'post_type'  => 'page',
				'post_title' => 'Left out of the sitemap',
			)
		);
		add_filter(
			'wp_sitemaps_posts_query_args',
			static function ( $args ) use ( $excluded ) {
				$args['post__not_in'] = array( $excluded );
				return $args;
			}
		);

		$text = $this->previewed();

		$this->assertStringContainsString( 'Visible page', $text );
		foreach ( array( 'Draft page', 'Private page', 'Protected page', 'Scheduled page', 'Yoast noindex page', 'Rank Math noindex page', 'Left out of the sitemap' ) as $title ) {
			$this->assertStringNotContainsString( $title, $text, $title );
		}
	}

	public function test_nothing_to_list_makes_no_file(): void {
		wp_delete_post( 1, true );
		wp_delete_post( 2, true );
		$preview = Runner::preview( new LlmsTxtFixer(), array() );
		$this->assertSame( 'unchanged', $preview['status'] );
		$this->assertSame( 'no_content', $preview['reason'] );
	}

	public function test_apply_stores_one_non_autoloaded_option_and_the_route_is_added_and_removed(): void {
		$this->post(
			array(
				'post_type'  => 'page',
				'post_title' => 'About us',
			)
		);
		$this->set_permalink_structure( '/%postname%/' );
		$fixer = new LlmsTxtFixer();
		Registry::use_fixers( array( $fixer ) );

		$result = Runner::apply( $fixer, array(), Runner::preview( $fixer, array() )['hash'], 1 );

		$this->assertSame( 'applied', $result['outcome'] );
		$stored = get_option( LlmsTxt::OPTION );
		$this->assertStringContainsString( 'About us', $stored['text'] );
		$this->assertSame( hash( 'sha256', $stored['text'] ), $stored['hash'] );
		$autoload = $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( "SELECT autoload FROM {$GLOBALS['wpdb']->options} WHERE option_name = %s", LlmsTxt::OPTION ) );
		$this->assertContains( $autoload, array( 'no', 'off' ), 'the file is not loaded on every request' );

		$this->next_request();
		do_action( 'init' );
		$this->assertArrayHasKey( '^llms\.txt$', $GLOBALS['wp_rewrite']->wp_rewrite_rules() );

		$id = $result['change'];
		Runner::undo( $id, 1 );
		$this->assertFalse( get_option( LlmsTxt::OPTION ) );
		$this->assertArrayNotHasKey( '^llms\.txt$', $GLOBALS['wp_rewrite']->wp_rewrite_rules() );
		$this->assertNull( State::get( 'llms-txt' ) );
	}

	public function test_it_is_rebuilt_once_at_the_end_of_a_request_in_which_content_was_published(): void {
		$this->post(
			array(
				'post_type'  => 'page',
				'post_title' => 'About us',
			)
		);
		$fixer = new LlmsTxtFixer();
		Registry::use_fixers( array( $fixer ) );
		Runner::apply( $fixer, array(), Runner::preview( $fixer, array() )['hash'], 1 );
		$this->next_request();

		$this->post(
			array(
				'post_type'  => 'page',
				'post_title' => 'Services',
			)
		);
		$this->post(
			array(
				'post_type'  => 'page',
				'post_title' => 'Contact',
			)
		);
		$this->assertSame( 1, has_action( 'shutdown', array( LlmsTxt::class, 'rebuild' ) ) ? 1 : 0, 'queued once, however many posts changed' );
		LlmsTxt::rebuild();

		$text = get_option( LlmsTxt::OPTION )['text'];
		$this->assertStringContainsString( 'Services', $text );
		$this->assertStringContainsString( 'Contact', $text );
	}

	public function test_a_request_answers_304_for_a_matching_etag_and_for_an_unchanged_date(): void {
		$stored = array(
			'text'  => "# Example Co\n",
			'hash'  => hash( 'sha256', "# Example Co\n" ),
			'built' => 1700000000,
		);
		$fresh  = LlmsTxt::response( $stored, '', '' );
		$this->assertSame( 200, $fresh['status'] );
		$this->assertSame( "# Example Co\n", $fresh['body'] );
		$this->assertSame( 'text/plain; charset=utf-8', $fresh['headers']['Content-Type'] );
		$this->assertSame( 'nosniff', $fresh['headers']['X-Content-Type-Options'] );
		$this->assertSame( '"' . $stored['hash'] . '"', $fresh['headers']['ETag'] );
		$this->assertSame( 'Tue, 14 Nov 2023 22:13:20 GMT', $fresh['headers']['Last-Modified'] );

		$this->assertSame( 304, LlmsTxt::response( $stored, '"' . $stored['hash'] . '"', '' )['status'] );
		$this->assertSame( '', LlmsTxt::response( $stored, '"' . $stored['hash'] . '"', '' )['body'] );
		$this->assertSame( 200, LlmsTxt::response( $stored, '"another"', '' )['status'] );
		$this->assertSame( 304, LlmsTxt::response( $stored, '', 'Tue, 14 Nov 2023 22:13:20 GMT' )['status'] );
		$this->assertSame( 200, LlmsTxt::response( $stored, '', 'Mon, 13 Nov 2023 22:13:20 GMT' )['status'] );
		$this->assertSame( 404, LlmsTxt::response( null, '', '' )['status'] );
	}

	public function test_a_real_file_in_the_web_root_is_a_conflict_and_the_text_to_paste_is_offered(): void {
		$this->post(
			array(
				'post_type'  => 'page',
				'post_title' => 'About us',
			)
		);
		$file = ABSPATH . 'llms.txt';
		file_put_contents( $file, "# Hand written\n" );
		try {
			$preview = Runner::preview( new LlmsTxtFixer(), array() );
			$this->assertSame( 'conflict', $preview['status'] );
			$this->assertSame( 'physical_file', $preview['reason'] );
			$this->assertSame( array( 'physical_file' ), $preview['conflicts'] );
			$this->assertSame( "# Hand written\n", $preview['changes'][0]['before'], 'the file is what is served now' );
			$this->assertStringContainsString( 'About us', $preview['paste'] );
			$this->assertFalse( ( new LlmsTxtFixer() )->reachable() );
		} finally {
			unlink( $file );
		}
	}

	public function test_another_plugin_that_serves_the_route_is_a_conflict_and_is_named(): void {
		$this->post(
			array(
				'post_type'  => 'page',
				'post_title' => 'About us',
			)
		);
		update_option( 'wpseo', array( 'enable_llms_txt' => true ) );
		$preview = Runner::preview( new LlmsTxtFixer(), array() );

		$this->assertSame( 'conflict', $preview['status'] );
		$this->assertSame( 'other_tool', $preview['reason'] );
		$this->assertSame( 'Yoast SEO', $preview['findings']['tooling_name'] );
		$this->assertFalse( ( new LlmsTxtFixer() )->reachable() );
	}

	public function test_llms_full_txt_is_never_published(): void {
		$this->assertFalse( has_action( 'template_redirect', array( LlmsTxt::class, 'serve' ) ) );
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Fixers/Runtime/LlmsTxt.php' );
		$this->assertStringNotContainsString( 'llms-full', $source );
	}
}
