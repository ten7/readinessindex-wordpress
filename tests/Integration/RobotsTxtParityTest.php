<?php
namespace ReadinessIndex\Tests\Integration;

use ReadinessIndex\Changes\Log;
use ReadinessIndex\Fixers\Canon;
use ReadinessIndex\Fixers\Impl\RobotsTxtFixer;
use ReadinessIndex\Fixers\Registry;
use ReadinessIndex\Fixers\Runner;
use ReadinessIndex\Fixers\State;
use ReadinessIndex\Tests\Integration\Support\IntegrationCase;

/**
 * The robots.txt fixer's WordPress adapter against every golden vector, through the real
 * `robots_txt` filter, the real options table, the real changes table and a real file where the
 * vector says one exists. The pure logic is held to the vectors in the unit suite; this holds the
 * parts around it: how the baseline is found, how a real file is seen, what the filter serves,
 * what is stored and what Undo gives back.
 */
final class RobotsTxtParityTest extends IntegrationCase {

	/**
	 * A real robots.txt created for a vector, to remove afterwards.
	 *
	 * @var string|null
	 */
	private $file;

	public function tear_down() {
		if ( null !== $this->file && is_file( $this->file ) ) {
			unlink( $this->file );
		}
		parent::tear_down();
	}

	/**
	 * Every robots.txt vector.
	 *
	 * @return array<string,array{0:array<string,mixed>}>
	 */
	public function vectors(): array {
		$out = array();
		foreach ( glob( dirname( __DIR__ ) . '/fixtures/parity/fixers/robots-txt/*.json' ) as $file ) {
			$out[ basename( $file, '.json' ) ] = array( json_decode( (string) file_get_contents( $file ), true ) );
		}
		return $out;
	}

	/**
	 * Sets WordPress up as the vector says the site is.
	 *
	 * @param array<string,mixed> $given The vector's given.
	 */
	private function build_site( array $given ): void {
		update_option( 'blogname', $given['site']['name'] );
		update_option( 'blog_public', '1' );
		// What the site serves with the fixer off is the vector's baseline, whatever core and the
		// other plugins would have built: an earlier filter replaces the body.
		$baseline = $given['baseline']['robots.txt'];
		add_filter(
			'robots_txt',
			static function () use ( $baseline ) {
				return $baseline;
			},
			0
		);
		if ( ! empty( $given['files']['robots.txt'] ) ) {
			$this->file = ABSPATH . 'robots.txt';
			file_put_contents( $this->file, $baseline );
		}
	}

	/**
	 * What the site serves now, through the real filter chain.
	 */
	private function served(): string {
		$this->next_request();
		return (string) apply_filters( 'robots_txt', 'ignored by the first filter', 1 );
	}

	/**
	 * Preview, apply, serve, undo and apply again, as the vector says.
	 *
	 * @dataProvider vectors
	 * @param array<string,mixed> $vector The vector.
	 */
	public function test_the_adapter_reproduces_the_vector( array $vector ): void {
		$given = $vector['given'];
		$this->build_site( $given );
		$fixer = new RobotsTxtFixer();
		Registry::use_fixers( array( $fixer ) );
		$choices = $given['choices'];
		$label   = $vector['case'];

		$preview = Runner::preview( $fixer, $choices );
		foreach ( array( 'status', 'reason', 'findings', 'changes', 'paste', 'output' ) as $key ) {
			$this->assertTrue( Canon::same( $vector['preview'][ $key ], $preview[ $key ] ), $label . ': preview ' . $key );
		}

		$result = Runner::apply( $fixer, $choices, $preview['hash'], 1 );
		$this->assertSame( $vector['apply']['status'], $result['outcome'], $label . ': apply status' );
		$this->assertSame( $vector['apply']['output']['robots.txt'], 'refused' === $vector['apply']['status'] && ! empty( $given['files']['robots.txt'] ) ? $given['baseline']['robots.txt'] : $this->served(), $label . ': what is served after apply' );

		if ( 'applied' !== $vector['apply']['status'] ) {
			$this->assertNull( State::get( 'robots-txt' ), $label . ': nothing stored' );
			$this->assertSame( 0, Log::count(), $label . ': nothing recorded' );
			return;
		}

		$this->assertTrue( Canon::same( $vector['apply']['recorded']['new'], State::get( 'robots-txt' ) ), $label . ': stored choices' );
		$this->assertTrue( Canon::same( $vector['apply']['recorded']['new'], Log::find( $result['change'] )['new'] ), $label . ': recorded new' );

		$again = Runner::apply( $fixer, $choices, Runner::preview( $fixer, $choices )['hash'], 1 );
		$this->assertSame( 'unchanged', $again['outcome'], $label . ': apply twice' );
		$this->assertSame( $vector['reapply']['output']['robots.txt'], $this->served(), $label . ': apply twice serves the same' );
		$this->assertSame( 1, Log::count() );

		$undone = Runner::undo( $result['change'], 1 );
		$this->assertSame( 'undone', $undone['outcome'], $label );
		$this->assertSame( $vector['undo']['output']['robots.txt'], $this->served(), $label . ': undo serves the baseline byte for byte' );
		$this->assertNull( State::get( 'robots-txt' ), $label . ': the stored state is what it was' );
	}

	public function test_a_site_that_discourages_search_engines_is_refused_with_its_reason(): void {
		$vector = $this->vectors()['wordpress-default-add-signals-and-policy'][0];
		$this->build_site( $vector['given'] );
		update_option( 'blog_public', '0' );
		$fixer = new RobotsTxtFixer();

		$preview = Runner::preview( $fixer, $vector['given']['choices'] );
		$this->assertContains( 'search_engines_discouraged', $preview['conflicts'] );
		$result = Runner::apply( $fixer, $vector['given']['choices'], $preview['hash'], 1 );
		$this->assertSame( 'refused', $result['outcome'] );
		$this->assertNull( State::get( 'robots-txt' ) );
	}

	public function test_a_new_preview_never_contains_the_fixers_own_change(): void {
		$vector = $this->vectors()['wordpress-default-add-signals-and-policy'][0];
		$this->build_site( $vector['given'] );
		$fixer = new RobotsTxtFixer();
		Registry::use_fixers( array( $fixer ) );
		$choices = $vector['given']['choices'];
		Runner::apply( $fixer, $choices, Runner::preview( $fixer, $choices )['hash'], 1 );
		$this->next_request();

		// The site now serves the block, and the baseline of the next preview is still without it.
		$this->assertStringContainsString( '# BEGIN Readiness Index', $this->served() );
		$other           = $choices;
		$other['policy'] = false;
		$again           = Runner::preview( $fixer, $other );
		$this->assertSame( $vector['given']['baseline']['robots.txt'], $again['changes'][0]['before'] );
		$this->assertSame( 1, substr_count( $again['output']['robots.txt'], '# BEGIN Readiness Index' ) );
	}

	public function test_nothing_is_hooked_on_the_front_end_until_a_fixer_is_applied(): void {
		$this->next_request();
		$this->assertFalse( has_filter( 'robots_txt', array( \ReadinessIndex\Fixers\Runtime\RobotsTxt::class, 'filter' ) ) );
		$this->assertFalse( has_action( 'template_redirect', array( \ReadinessIndex\Fixers\Runtime\LlmsTxt::class, 'serve' ) ) );
		$this->assertFalse( has_filter( 'wp_robots', array( \ReadinessIndex\Fixers\Runtime\Directives::class, 'filter' ) ) );
		$this->assertFalse( has_action( 'wp_head', array( \ReadinessIndex\Fixers\Runtime\EntityGraph::class, 'print_graph' ) ) );
	}

	public function test_verify_reads_the_served_file_as_a_crawler_does_and_not_only_as_text(): void {
		$vector = $this->vectors()['blocks-on-answer-engines-removed'][0];
		$this->build_site( $vector['given'] );
		$fixer = new RobotsTxtFixer();
		Registry::use_fixers( array( $fixer ) );
		$choices = $vector['given']['choices'];
		Runner::apply( $fixer, $choices, Runner::preview( $fixer, $choices )['hash'], 1 );
		$agent = $choices['allow_agents'][0];

		$good                                       = $vector['apply']['output']['robots.txt'];
		$this->answers[ home_url( '/robots.txt' ) ] = array( 'body' => $good );
		$this->assertTrue( $fixer->verify( array( 'output' => array( 'robots.txt' => $good ) ) )['live'] );

		// The text matches what was previewed, but the file blocks the crawler the owner let in.
		$blocked                                    = "User-agent: {$agent}\nDisallow: /\n";
		$this->answers[ home_url( '/robots.txt' ) ] = array( 'body' => $blocked );
		$this->assertFalse( $fixer->verify( array( 'output' => array( 'robots.txt' => $blocked ) ) )['live'] );

		// A page cache serving the old file: not live, and the runner names the cache it found.
		$this->answers[ home_url( '/robots.txt' ) ] = array( 'body' => $vector['given']['baseline']['robots.txt'] );
		$this->assertFalse( $fixer->verify( array( 'output' => array( 'robots.txt' => $good ) ) )['live'] );
	}
}
