<?php
/**
 * A guide for AI assistants at /llms.txt.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Fixers\Impl;

use ReadinessIndex\Fixers\Content\LlmsContent;
use ReadinessIndex\Fixers\Files;
use ReadinessIndex\Fixers\Logic\LlmsTxt;
use ReadinessIndex\Fixers\Permit;
use ReadinessIndex\Fixers\Runtime;
use ReadinessIndex\Fixers\SeoTools;
use ReadinessIndex\Http\SiteFetcher;

/**
 * AIR-1.10: a short list of the site's own pages at `/llms.txt`, served as a route WordPress
 * generates and kept current as content is published. `/llms-full.txt` is not published. It defers
 * to a real file and to another plugin that serves the route, and says which.
 */
final class LlmsTxtFixer extends AbstractFixer {

	/**
	 * The id the contract names.
	 */
	public function id(): string {
		return 'llms-txt';
	}

	/**
	 * The tests it moves.
	 *
	 * @return string[]
	 */
	public function checks(): array {
		return array( 'AIR-1.10' );
	}

	/**
	 * Facts: the site, the content to list, a real file if there is one, and any other tool
	 * that serves the route.
	 *
	 * @return array<string,mixed>
	 */
	public function detect(): array {
		$physical = Files::physical( 'llms.txt' );
		$tooling  = array();
		if ( null !== self::other_tool() ) {
			$tooling[] = 'llms_txt_plugin';
		}
		return array(
			'site'     => $this->site() + array( 'description' => (string) get_bloginfo( 'description' ) ),
			'tooling'  => $tooling,
			'files'    => array( 'llms.txt' => $physical ),
			'content'  => LlmsContent::collect(),
			'baseline' => array( 'llms.txt' => $physical ? $this->file_body() : '' ),
		);
	}

	/**
	 * The body of the real file.
	 */
	private function file_body(): string {
		foreach ( Files::roots() as $root ) {
			if ( is_file( $root . 'llms.txt' ) ) {
				$body = file_get_contents( $root . 'llms.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file in the web root, not a URL.
				return false === $body ? '' : $body;
			}
		}
		return '';
	}

	/**
	 * The name of another plugin that already serves `/llms.txt`, or null.
	 *
	 * Found two ways, both feature detection: a rewrite rule that is not this plugin's and
	 * names the file, and Yoast SEO's own llms.txt setting. A tool this does not know serves
	 * the route unseen, which the verify step then reports as a file that is not this one.
	 */
	public static function other_tool(): ?string {
		$rules = get_option( 'rewrite_rules' );
		if ( is_array( $rules ) ) {
			foreach ( array_keys( $rules ) as $pattern ) {
				if ( is_string( $pattern ) && false !== stripos( $pattern, 'llms' ) && Runtime\LlmsTxt::RULE !== $pattern ) {
					return 'llms_txt_plugin';
				}
			}
		}
		$yoast = get_option( 'wpseo' );
		if ( is_array( $yoast ) && ! empty( $yoast['enable_llms_txt'] ) ) {
			return 'yoast';
		}
		return null;
	}

	/**
	 * The pure logic.
	 *
	 * @param array<string,mixed> $given Facts, state and choices.
	 * @return array<string,mixed>
	 */
	public function evaluate( array $given ): array {
		return LlmsTxt::evaluate( $given );
	}

	/**
	 * Whether a change can be made here: no real file and no other tool.
	 */
	public function reachable(): bool {
		return ! Files::physical( 'llms.txt' ) && null === self::other_tool();
	}

	/**
	 * Turns the route on: stores the choice, builds the file once, and makes the rule live.
	 *
	 * @param Permit              $permit  Issued by the runner.
	 * @param array<string,mixed> $choices The choices.
	 * @return array<string,mixed>
	 */
	public function apply( Permit $permit, array $choices ): array {
		$extra = parent::apply( $permit, $choices );
		Runtime\LlmsTxt::rebuild();
		Runtime\LlmsTxt::add_rule();
		flush_rewrite_rules( false );
		return $extra;
	}

	/**
	 * Turns the route off: gives back the prior state and takes the rule and the file away.
	 *
	 * @param Permit              $permit Issued by the runner.
	 * @param array<string,mixed> $record The record.
	 */
	public function undo( Permit $permit, array $record ): void {
		parent::undo( $permit, $record );
		$this->forget();
	}

	/**
	 * Removes everything the route left.
	 *
	 * @param Permit $permit Issued by the runner.
	 */
	public function cleanup( Permit $permit ): void {
		parent::cleanup( $permit );
		$this->forget();
	}

	/**
	 * Deletes the stored file and the rule.
	 */
	private function forget(): void {
		delete_option( Runtime\LlmsTxt::OPTION );
		Runtime\LlmsTxt::forget_rule();
		flush_rewrite_rules( false );
	}

	/**
	 * Fetches `/llms.txt` as a visitor and compares it with what the preview said.
	 *
	 * @param array<string,mixed> $ev The applied evaluation.
	 * @return array{live:bool|null,reason:string}
	 */
	public function verify( array $ev ): array {
		$got = SiteFetcher::get( home_url( '/llms.txt' ) );
		if ( null === $got ) {
			return array(
				'live'   => null,
				'reason' => 'unreachable',
			);
		}
		$live = 200 === $got['status'] && str_replace( "\r\n", "\n", $got['body'] ) === (string) $ev['output']['llms.txt'];
		return array(
			'live'   => $live,
			'reason' => $live ? '' : 'different',
		);
	}

	/**
	 * Naming the tool in a finding.
	 *
	 * @param array<string,mixed> $ev    The evaluation.
	 * @param array<string,mixed> $given The facts.
	 * @return array<string,mixed>
	 */
	protected function annotate( array $ev, array $given ): array {
		unset( $given );
		$tool = self::other_tool();
		if ( null !== $tool && isset( $ev['findings'] ) ) {
			$ev['findings']['tooling_name'] = 'llms_txt_plugin' === $tool ? '' : SeoTools::name( $tool );
		}
		return $ev;
	}
}
