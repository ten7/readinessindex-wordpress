<?php
namespace ReadinessIndex\Tests;

use PHPUnit\Framework\TestCase;
use ReadinessIndex\Fixers\Robots\Parser;

/**
 * The robots.txt parser against the examples in RFC 9309, which is what AIR-1.1 and the verify
 * step both depend on: groups, the longest-match rule, Allow winning a tie, and the two special
 * characters.
 */
final class RobotsParserTest extends TestCase {

	/**
	 * Whether a path is blocked for `*` by one Disallow rule.
	 *
	 * @param string $rule A path pattern.
	 * @param string $path A request path.
	 */
	private function blocked( string $rule, string $path ): bool {
		return ! Parser::allows( "User-agent: *\nDisallow: {$rule}\n", 'anybot', $path );
	}

	/**
	 * The path-matching table of RFC 9309 section 2.2.2.
	 *
	 * @return array<string,array{0:string,1:string[],2:string[]}>
	 */
	public function rfc_table(): array {
		return array(
			'/fish'      => array(
				'/fish',
				array( '/fish', '/fish.html', '/fish/salmon.html', '/fishheads', '/fishheads/yummy.html', '/fish.php?id=anything' ),
				array( '/Fish.asp', '/catfish', '/?id=fish', '/desert/fish' ),
			),
			'/fish*'     => array(
				'/fish*',
				array( '/fish', '/fish.html', '/fish/salmon.html', '/fishheads', '/fishheads/yummy.html', '/fish.php?id=anything' ),
				array( '/Fish.asp', '/catfish', '/?id=fish', '/desert/fish' ),
			),
			'/fish/'     => array(
				'/fish/',
				array( '/fish/', '/fish/?id=anything', '/fish/salmon.htm' ),
				array( '/fish', '/fish.html', '/Fish/Salmon.asp' ),
			),
			'/*.php'     => array(
				'/*.php',
				array( '/filename.php', '/folder/filename.php', '/folder/filename.php?parameters', '/folder/any.php.file.html', '/filename.php/' ),
				array( '/', '/windows.PHP' ),
			),
			'/*.php$'    => array(
				'/*.php$',
				array( '/filename.php', '/folder/filename.php' ),
				array( '/filename.php?parameters', '/filename.php/', '/filename.php5', '/windows.PHP' ),
			),
			'/fish*.php' => array(
				'/fish*.php',
				array( '/fish.php', '/fishheads/catfish.php?parameters' ),
				array( '/Fish.PHP' ),
			),
		);
	}

	/**
	 * Every row of the table, both columns.
	 *
	 * @dataProvider rfc_table
	 * @param string   $rule    The pattern.
	 * @param string[] $matches Paths it matches.
	 * @param string[] $misses  Paths it does not.
	 */
	public function test_the_rfc_path_matching_table( string $rule, array $matches, array $misses ): void {
		foreach ( $matches as $path ) {
			$this->assertTrue( $this->blocked( $rule, $path ), "{$rule} should match {$path}" );
		}
		foreach ( $misses as $path ) {
			$this->assertFalse( $this->blocked( $rule, $path ), "{$rule} should not match {$path}" );
		}
	}

	/**
	 * The file of RFC 9309 section 5.1.
	 */
	private function example(): string {
		return "User-Agent: *\nDisallow: *.gif$\nDisallow: /example/\nAllow: /publications/\n\nUser-Agent: foobot\nDisallow:/\nAllow:/example/page.html\nAllow:/example/allowed.gif\n\nUser-Agent: barbot\nUser-Agent: bazbot\nDisallow: /example/page.html\n\nUser-Agent: quxbot\n";
	}

	public function test_the_rfc_example_file_for_each_named_crawler(): void {
		$file = $this->example();
		// The specific group replaces the `*` group entirely.
		$this->assertFalse( Parser::allows( $file, 'foobot', '/' ) );
		$this->assertFalse( Parser::allows( $file, 'foobot', '/anything/else.html' ) );
		$this->assertTrue( Parser::allows( $file, 'foobot', '/example/page.html' ) );
		$this->assertTrue( Parser::allows( $file, 'foobot', '/example/allowed.gif' ) );
		// Two names share a group.
		foreach ( array( 'barbot', 'bazbot' ) as $bot ) {
			$this->assertFalse( Parser::allows( $file, $bot, '/example/page.html' ) );
			$this->assertTrue( Parser::allows( $file, $bot, '/example/allowed.gif' ), 'the `*` group does not apply to a crawler with a group of its own' );
			$this->assertTrue( Parser::allows( $file, $bot, '/' ) );
		}
		// A group with no rules allows everything and still replaces `*`.
		$this->assertTrue( Parser::allows( $file, 'quxbot', '/example/page.html' ) );
		// Any other crawler falls back to `*`.
		$this->assertFalse( Parser::allows( $file, 'otherbot', '/image.gif' ) );
		$this->assertFalse( Parser::allows( $file, 'otherbot', '/example/page.html' ) );
		$this->assertTrue( Parser::allows( $file, 'otherbot', '/publications/' ) );
	}

	public function test_the_longest_match_wins_and_allow_wins_a_tie(): void {
		$file = "User-Agent: foobot\nAllow: /example/page/\nDisallow: /example/page/disallowed.gif\n";
		$this->assertTrue( Parser::allows( $file, 'foobot', '/example/page/' ) );
		$this->assertFalse( Parser::allows( $file, 'foobot', '/example/page/disallowed.gif' ) );
		$this->assertTrue( Parser::allows( $file, 'foobot', '/example/page/other.gif' ) );

		$tie = "User-agent: *\nDisallow: /p\nAllow: /p\n";
		$this->assertTrue( Parser::allows( $tie, 'x', '/page' ), 'equal length: the least restrictive rule' );
	}

	public function test_product_tokens_match_without_regard_to_case_and_paths_with_it(): void {
		$file = "User-agent: GPTBot\nDisallow: /Private\n";
		$this->assertFalse( Parser::allows( $file, 'gptbot', '/Private/x' ) );
		$this->assertTrue( Parser::allows( $file, 'gptbot', '/private/x' ), 'paths are case sensitive' );
	}

	public function test_an_empty_file_and_a_missing_group_allow_everything(): void {
		$this->assertTrue( Parser::allows( '', 'x', '/anything' ) );
		$this->assertTrue( Parser::allows( "User-agent: other\nDisallow: /\n", 'x', '/anything' ) );
		$this->assertTrue( Parser::allows( "User-agent: *\nDisallow:\n", 'x', '/anything' ), 'an empty Disallow allows everything' );
	}

	public function test_comments_blank_lines_line_endings_and_sitemaps_are_read_as_the_rfc_says(): void {
		$file = "# a comment\r\nUser-agent: *   # trailing\r\n\r\nDisallow: /x # why\r\nSitemap: https://example.com/s.xml\r\n";
		$this->assertFalse( Parser::allows( $file, 'a', '/x/y' ) );
		$this->assertTrue( Parser::allows( $file, 'a', '/y' ) );
		$this->assertSame( array( 'User-agent: *   # trailing' ), array_slice( Parser::lines_of( $file ), 1, 1 ) );
		$this->assertCount( 1, Parser::groups( Parser::lines_of( $file ) ), 'Sitemap belongs to no group' );
	}

	public function test_a_rule_before_any_user_agent_line_belongs_to_nothing(): void {
		$groups = Parser::groups( Parser::lines_of( "Disallow: /\nUser-agent: a\nDisallow: /b\n" ) );
		$this->assertCount( 1, $groups );
		$this->assertSame( array( 'a' ), $groups[0]->names );
	}

	public function test_a_blanket_block_is_exactly_disallow_slash_without_an_allow_slash(): void {
		$g = static function ( string $text ): array {
			return Parser::groups( Parser::lines_of( $text ) );
		};
		$this->assertTrue( Parser::blanket_blocked( 'GPTBot', $g( "User-agent: GPTBot\nDisallow: /\n" ) ) );
		$this->assertFalse( Parser::blanket_blocked( 'GPTBot', $g( "User-agent: GPTBot\nDisallow: /members/\n" ) ), 'a narrower rule is the owner\'s' );
		$this->assertFalse( Parser::blanket_blocked( 'GPTBot', $g( "User-agent: GPTBot\nDisallow: /\nAllow: /\n" ) ) );
		$this->assertTrue( Parser::blanket_blocked( 'ClaudeBot', $g( "User-agent: *\nDisallow: /\n" ) ), 'the star group governs a crawler with none of its own' );
		$this->assertFalse( Parser::blanket_blocked( 'ClaudeBot', $g( "User-agent: *\nDisallow: /\n\nUser-agent: ClaudeBot\nAllow: /\n" ) ) );
	}
}
