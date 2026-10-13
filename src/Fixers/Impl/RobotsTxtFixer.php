<?php
/**
 * AI crawler access in robots.txt.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Fixers\Impl;

use ReadinessIndex\Fixers\Files;
use ReadinessIndex\Fixers\Logic\RobotsTxt;
use ReadinessIndex\Fixers\Runtime;
use ReadinessIndex\Fixers\SeoTools;
use ReadinessIndex\Http\SiteFetcher;

/**
 * AIR-1.1, 1.4 and 1.5, through the `robots_txt` filter and never a file: lift a blanket block
 * from the crawlers the owner chooses, state what AI may do with the content as a
 * `Content-Signal` line the owner chose for each of its three signals, and write the policy in
 * plain words. One delimited, labeled block, added once.
 */
final class RobotsTxtFixer extends AbstractFixer {

	/**
	 * The id the contract names.
	 */
	public function id(): string {
		return 'robots-txt';
	}

	/**
	 * The tests it moves.
	 *
	 * @return string[]
	 */
	public function checks(): array {
		return array( 'AIR-1.1', 'AIR-1.4', 'AIR-1.5' );
	}

	/**
	 * Facts: the site, what is served now (never read from the live file, which includes this
	 * fixer's own change), and whether a real file would win.
	 *
	 * @return array<string,mixed>
	 */
	public function detect(): array {
		$physical = Files::physical( 'robots.txt' );
		return array(
			'site'     => $this->site(),
			'tooling'  => SeoTools::active(),
			'files'    => array( 'robots.txt' => $physical ),
			'baseline' => array( 'robots.txt' => $physical ? $this->file_body() : Runtime\RobotsTxt::baseline() ),
		);
	}

	/**
	 * The body of the real file, which is what is served while it exists.
	 */
	private function file_body(): string {
		foreach ( Files::roots() as $root ) {
			if ( is_file( $root . 'robots.txt' ) ) {
				$body = file_get_contents( $root . 'robots.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file in the web root, not a URL.
				return false === $body ? '' : $body;
			}
		}
		return '';
	}

	/**
	 * The pure logic.
	 *
	 * @param array<string,mixed> $given Facts, state and choices.
	 * @return array<string,mixed>
	 */
	public function evaluate( array $given ): array {
		return RobotsTxt::evaluate( $given );
	}

	/**
	 * Conflicts that are facts about this install: a site that asks to be left out of search
	 * (WordPress then serves a robots.txt that shuts every crawler out), and a subdirectory
	 * site in a network (one robots.txt is served for the whole network).
	 *
	 * @param array<string,mixed> $given Facts, state and choices.
	 * @return string[]
	 */
	protected function site_conflicts( array $given ): array {
		unset( $given );
		$out = array();
		if ( '0' === (string) get_option( 'blog_public', '1' ) ) {
			$out[] = 'search_engines_discouraged';
		}
		if ( is_multisite() && ! is_subdomain_install() && ! is_main_site() ) {
			$out[] = 'subdirectory_site';
		}
		return $out;
	}

	/**
	 * Whether a change can be made here: nothing in the way, and no real file.
	 */
	public function reachable(): bool {
		return array() === $this->site_conflicts( array() ) && ! Files::physical( 'robots.txt' );
	}

	/**
	 * The owner's choices, from a form: the crawlers to allow, a value for each signal, and the policy.
	 *
	 * @param array<string,mixed> $raw Unslashed input.
	 * @return array<string,mixed>
	 */
	public function sanitize_choices( array $raw ): array {
		$allow = array();
		foreach ( (array) ( $raw['allow_agents'] ?? array() ) as $agent ) {
			$agent = is_string( $agent ) ? sanitize_text_field( $agent ) : '';
			if ( in_array( $agent, RobotsTxt::AGENTS, true ) && ! in_array( $agent, $allow, true ) ) {
				$allow[] = $agent;
			}
		}
		$signals = array();
		if ( isset( $raw['content_signals'] ) && is_array( $raw['content_signals'] ) ) {
			foreach ( RobotsTxt::SIGNAL_KEYS as $key ) {
				$value = isset( $raw['content_signals'][ $key ] ) && is_string( $raw['content_signals'][ $key ] ) ? strtolower( sanitize_text_field( $raw['content_signals'][ $key ] ) ) : '';
				if ( 'yes' === $value || 'no' === $value ) {
					$signals[ $key ] = $value;
				}
			}
		}
		$policy = isset( $raw['policy'] ) && in_array( strtolower( (string) $raw['policy'] ), array( '1', 'true', 'yes', 'on' ), true );
		return array(
			'allow_agents'    => $allow,
			'content_signals' => array() === $signals ? null : $signals,
			'policy'          => $policy,
		);
	}

	/**
	 * Fetches the site's own /robots.txt and compares it with what the preview said it would serve.
	 *
	 * @param array<string,mixed> $ev The applied evaluation.
	 * @return array{live:bool|null,reason:string}
	 */
	public function verify( array $ev ): array {
		$got = SiteFetcher::get( home_url( '/robots.txt' ) );
		if ( null === $got ) {
			return array(
				'live'   => null,
				'reason' => 'unreachable',
			);
		}
		$want = str_replace( "\r\n", "\n", (string) $ev['output']['robots.txt'] );
		$live = str_replace( "\r\n", "\n", $got['body'] ) === $want;
		return array(
			'live'   => $live,
			'reason' => $live ? '' : 'different',
		);
	}
}
