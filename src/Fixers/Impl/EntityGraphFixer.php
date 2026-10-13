<?php
/**
 * Who you are, in structured data.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Fixers\Impl;

use ReadinessIndex\Changes\Log;
use ReadinessIndex\Fixers\Content\GraphPages;
use ReadinessIndex\Fixers\Integration;
use ReadinessIndex\Fixers\Logic\EntityGraph;
use ReadinessIndex\Fixers\Logic\GraphValidator;
use ReadinessIndex\Fixers\Permit;
use ReadinessIndex\Fixers\Runtime;
use ReadinessIndex\Fixers\SeoTools;
use ReadinessIndex\Http\SiteFetcher;

/**
 * AIR-1.11, 1.12, 1.13, 3.2 and 3.7. On a site with no SEO plugin, one linked JSON-LD graph per
 * page from facts the owner supplied. On a site with Yoast SEO or Rank Math, the same facts
 * go to the plugin and this plugin prints nothing. With any other plugin that prints a graph
 * it refuses and points at the setting to change. Nothing is guessed or scraped.
 */
final class EntityGraphFixer extends AbstractFixer {

	/**
	 * The id the contract names.
	 */
	public function id(): string {
		return 'entity-graph';
	}

	/**
	 * The tests it moves.
	 *
	 * @return string[]
	 */
	public function checks(): array {
		return array( 'AIR-1.11', 'AIR-1.12', 'AIR-1.13', 'AIR-3.2', 'AIR-3.7' );
	}

	/**
	 * The integration for the one integrated SEO plugin that is active, or null.
	 */
	private function tool(): ?Integration\Tool {
		$active = SeoTools::active();
		if ( SeoTools::both_integrated( $active ) ) {
			return null;
		}
		$key = SeoTools::integrated( $active );
		if ( 'yoast' === $key ) {
			return new Integration\Yoast();
		}
		if ( 'rank_math' === $key ) {
			return new Integration\RankMath();
		}
		return null;
	}

	/**
	 * Facts: the site and the tools. The pages are added once the owner's choices are known.
	 *
	 * @return array<string,mixed>
	 */
	public function detect(): array {
		return array(
			'site'    => $this->site() + array( 'language' => (string) get_bloginfo( 'language' ) ),
			'tooling' => SeoTools::active(),
			'files'   => array(),
			'pages'   => array(),
		);
	}

	/**
	 * Adds the About and Contact addresses and a few pages, which depend on the choices.
	 *
	 * @param array<string,mixed> $given Facts, state and choices.
	 * @return array<string,mixed>
	 */
	protected function with_choices( array $given ): array {
		$choices = (array) $given['choices'];
		$ids     = array( (int) ( $choices['about_page'] ?? 0 ), (int) ( $choices['contact_page'] ?? 0 ) );
		foreach ( array(
			'about_page'   => 'about_url',
			'contact_page' => 'contact_url',
		) as $choice => $fact ) {
			$id = (int) ( $choices[ $choice ] ?? 0 );
			if ( $id > 0 && 'publish' === get_post_status( $id ) ) {
				$given['site'][ $fact ] = (string) get_permalink( $id );
			}
		}
		$given['pages'] = GraphPages::sample( $ids );
		return $given;
	}

	/**
	 * The pure logic. Facts WordPress adds to the choices (the pages named) are not the
	 * logic's business and are left in place.
	 *
	 * @param array<string,mixed> $given Facts, state and choices.
	 * @return array<string,mixed>
	 */
	public function evaluate( array $given ): array {
		return EntityGraph::evaluate( $given );
	}

	/**
	 * Conflicts: two integrated tools at once (neither is guessed at), a tool that prints its
	 * own graph and that the plugin only has instructions for, and an integrated tool whose
	 * settings are not where this code expects them.
	 *
	 * @param array<string,mixed> $given Facts, state and choices.
	 * @return string[]
	 */
	protected function site_conflicts( array $given ): array {
		unset( $given );
		$active = SeoTools::active();
		if ( SeoTools::both_integrated( $active ) ) {
			return array( 'both_seo_tools' );
		}
		if ( null !== SeoTools::instructed( $active ) ) {
			return array( 'other_tool' );
		}
		$tool = $this->tool();
		if ( null !== $tool && ! $tool->supported() ) {
			return array( 'other_tool' );
		}
		return array();
	}

	/**
	 * Whether a change can be made here.
	 */
	public function reachable(): bool {
		return array() === $this->site_conflicts( array() );
	}

	/**
	 * What WordPress adds to the preview: the structural check of each page's graph, and the
	 * settings of the SEO plugin that will be written.
	 *
	 * @param array<string,mixed> $ev    The evaluation.
	 * @param array<string,mixed> $given  The facts.
	 * @return array<string,mixed>
	 */
	protected function annotate( array $ev, array $given ): array {
		$ev['writes']     = array();
		$ev['validation'] = array();
		$ev['needs']      = null;
		$tool             = $this->tool();
		if ( null !== $tool && $tool->supported() && 'ready' === ( $ev['status'] ?? '' ) ) {
			$ev['writes'] = $tool->plan( (array) $given['choices'], (string) get_bloginfo( 'name' ) );
			$ev['needs']  = $tool->missing( (array) $given['choices'] );
			$ev['tool']   = $tool->key();
		}
		if ( isset( $ev['output']['pages'] ) && ! empty( $ev['output']['prints_graph'] ) ) {
			foreach ( $ev['output']['pages'] as $page ) {
				foreach ( GraphValidator::problems( $page['graph'] ) as $problem ) {
					$ev['validation'][] = $page['url'] . ': ' . $problem;
				}
			}
		}
		return $ev;
	}

	/**
	 * Turns the owner's input into choices.
	 *
	 * @param array<string,mixed> $raw Unslashed input.
	 * @return array<string,mixed>
	 */
	public function sanitize_choices( array $raw ): array {
		$in  = isset( $raw['organization'] ) && is_array( $raw['organization'] ) ? $raw['organization'] : array();
		$org = array();

		$text = static function ( $value ): string {
			return is_string( $value ) ? trim( sanitize_text_field( $value ) ) : '';
		};
		foreach ( array(
			'legal_name' => 'legal_name',
			'tax_id'     => 'tax_id',
		) as $key => $field ) {
			if ( '' !== $text( $in[ $key ] ?? null ) ) {
				$org[ $field ] = $text( $in[ $key ] );
			}
		}
		$logo = $text( $in['logo'] ?? null );
		if ( ctype_digit( $logo ) && (int) $logo > 0 ) {
			$resolved = wp_get_attachment_url( (int) $logo );
			$logo     = is_string( $resolved ) ? $resolved : '';
		}
		if ( '' !== $logo ) {
			$clean = esc_url_raw( $logo, array( 'https', 'http' ) );
			if ( '' !== $clean ) {
				$org['logo'] = $clean;
			}
		}
		if ( isset( $in['description'] ) && is_string( $in['description'] ) ) {
			$description = trim( preg_replace( '/\s+/', ' ', sanitize_textarea_field( $in['description'] ) ) ?? '' );
			if ( '' !== $description ) {
				$org['description'] = $description;
			}
		}
		$date = $text( $in['founding_date'] ?? null );
		if ( 1 === preg_match( '/^\d{4}(-\d{2}(-\d{2})?)?$/D', $date ) ) {
			$org['founding_date'] = $date;
		}
		$address = array();
		foreach ( array( 'street', 'locality', 'region', 'postal_code', 'country' ) as $part ) {
			$value = $text( $in['address'][ $part ] ?? null );
			if ( '' !== $value ) {
				$address[ $part ] = $value;
			}
		}
		if ( array() !== $address ) {
			$org['address'] = $address;
		}
		foreach ( array(
			'area_served' => 'area_served',
			'leaders'     => 'leaders',
		) as $key => $field ) {
			$lines = self::lines( $in[ $key ] ?? null );
			if ( array() !== $lines ) {
				$org[ $field ] = $lines;
			}
		}
		$points = array();
		foreach ( (array) ( $in['contact_points'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) || '' === $text( $row['type'] ?? null ) ) {
				continue;
			}
			$point = array( 'type' => $text( $row['type'] ) );
			$email = $text( $row['email'] ?? null );
			if ( '' !== $email && is_email( $email ) ) {
				$point['email'] = $email;
			}
			if ( '' !== $text( $row['telephone'] ?? null ) ) {
				$point['telephone'] = $text( $row['telephone'] );
			}
			$points[] = $point;
		}
		if ( array() !== $points ) {
			$org['contact_points'] = $points;
		}
		$same_as = array();
		foreach ( self::lines( $in['same_as'] ?? null ) as $url ) {
			$clean = esc_url_raw( $url, array( 'https' ) );
			if ( '' !== $clean && ! in_array( $clean, $same_as, true ) ) {
				$same_as[] = $clean;
			}
		}
		if ( array() !== $same_as ) {
			$org['same_as'] = $same_as;
		}

		$choices = array(
			'organization'    => $org,
			'search_template' => null,
		);
		$search  = isset( $raw['search_template'] ) && is_string( $raw['search_template'] ) ? trim( sanitize_text_field( $raw['search_template'] ) ) : '';
		if ( '' !== $search ) {
			$choices['search_template'] = substr( $search, 0, 500 );
		}
		foreach ( array( 'about_page', 'contact_page' ) as $page ) {
			$id = isset( $raw[ $page ] ) ? absint( $raw[ $page ] ) : 0;
			if ( $id > 0 && 'publish' === get_post_status( $id ) ) {
				$choices[ $page ] = $id;
			}
		}
		return $choices;
	}

	/**
	 * A textarea or list as trimmed, non-empty lines.
	 *
	 * @param mixed $value A string with line breaks, or a list.
	 * @return string[]
	 */
	private static function lines( $value ): array {
		$items = is_array( $value ) ? $value : ( is_string( $value ) ? preg_split( '/\r\n|\r|\n/', $value ) : array() );
		$out   = array();
		foreach ( (array) $items as $item ) {
			$item = is_string( $item ) ? trim( sanitize_text_field( $item ) ) : '';
			if ( '' !== $item ) {
				$out[] = $item;
			}
		}
		return $out;
	}

	/**
	 * Stores the choices and, where an integrated SEO plugin needs its own settings changed,
	 * changes them and returns the values they replaced for Undo.
	 *
	 * @param Permit              $permit  Issued by the runner.
	 * @param array<string,mixed> $choices The choices.
	 * @return array<string,mixed>
	 */
	public function apply( Permit $permit, array $choices ): array {
		parent::apply( $permit, $choices );
		$tool = $this->tool();
		if ( null === $tool || ! $tool->supported() ) {
			return array();
		}
		$prior = $tool->write( $tool->plan( $choices, (string) get_bloginfo( 'name' ) ) );
		return array() === $prior ? array() : array(
			'tool'   => $tool->key(),
			'writes' => $prior,
		);
	}

	/**
	 * Gives back the stored choices and every SEO plugin setting the change replaced.
	 *
	 * @param Permit              $permit Issued by the runner.
	 * @param array<string,mixed> $record The record.
	 */
	public function undo( Permit $permit, array $record ): void {
		parent::undo( $permit, $record );
		$this->restore( (array) ( $record['extra'] ?? array() ) );
	}

	/**
	 * Removes everything, including the SEO plugin settings of the change still on record.
	 *
	 * @param Permit $permit Issued by the runner.
	 */
	public function cleanup( Permit $permit ): void {
		parent::cleanup( $permit );
		$row = Log::latest_applied( $this->id() );
		if ( null !== $row ) {
			$this->restore( (array) ( $row['extra'] ?? array() ) );
		}
	}

	/**
	 * Restores recorded SEO plugin settings through the integration that wrote them.
	 *
	 * @param array<string,mixed> $extra The `extra` of a record.
	 */
	private function restore( array $extra ): void {
		if ( empty( $extra['writes'] ) ) {
			return;
		}
		$tool = 'yoast' === ( $extra['tool'] ?? '' ) ? new Integration\Yoast() : ( 'rank_math' === ( $extra['tool'] ?? '' ) ? new Integration\RankMath() : null );
		if ( null !== $tool && $tool->supported() ) {
			$tool->restore( (array) $extra['writes'] );
		}
	}

	/**
	 * Looks at the home page as a visitor: exactly one Organization, one WebSite with a search
	 * action when one was given, no repeated `@id`, and no second block from this plugin beside an
	 * SEO plugin's.
	 *
	 * @param array<string,mixed> $ev The applied evaluation.
	 * @return array{live:bool|null,reason:string}
	 */
	public function verify( array $ev ): array {
		$got = SiteFetcher::get( home_url( '/' ) );
		if ( null === $got || 200 !== $got['status'] ) {
			return array(
				'live'   => null,
				'reason' => 'unreachable',
			);
		}
		$docs = self::documents( $got['body'] );
		$own  = false !== strpos( $got['body'], 'readiness-index-graph' );
		$tool = ( $ev['findings']['mode'] ?? '' ) === 'through_tooling';
		if ( $tool && $own ) {
			return array(
				'live'   => false,
				'reason' => 'different',
			);
		}
		if ( ! $tool && ! $own ) {
			return array(
				'live'   => false,
				'reason' => 'different',
			);
		}
		$orgs     = 0;
		$searches = 0;
		$ids      = array();
		foreach ( $docs as $doc ) {
			foreach ( (array) ( $doc['@graph'] ?? array() ) as $node ) {
				$types = (array) ( $node['@type'] ?? array() );
				if ( in_array( 'Organization', $types, true ) ) {
					++$orgs;
				}
				if ( in_array( 'WebSite', $types, true ) && ! empty( $node['potentialAction'] ) ) {
					++$searches;
				}
				if ( isset( $node['@id'] ) ) {
					$ids[] = $node['@id'];
				}
			}
		}
		$wants_search = 'valid' === ( $ev['findings']['search_template'] ?? 'none' );
		$ok           = 1 === $orgs && count( $ids ) === count( array_unique( $ids ) ) && ( ! $wants_search || 1 === $searches );
		return array(
			'live'   => $ok,
			'reason' => $ok ? '' : 'different',
		);
	}

	/**
	 * The JSON-LD documents in a page.
	 *
	 * @param string $html A page.
	 * @return array<int,array<string,mixed>>
	 */
	private static function documents( string $html ): array {
		$docs = array();
		if ( preg_match_all( '#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $m ) ) {
			foreach ( $m[1] as $json ) {
				$doc = json_decode( trim( $json ), true );
				if ( is_array( $doc ) ) {
					$docs[] = $doc;
				}
			}
		}
		return $docs;
	}
}
