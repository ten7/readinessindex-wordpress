<?php
/**
 * The linked entity graph, and what is handed to an SEO tool instead.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Fixers\Logic;

use ReadinessIndex\Strings;

/**
 * AIR-1.11, 1.12, 1.13, 3.2 and 3.7: one JSON-LD `@graph` whose nodes (the organization, the
 * site, the page and, below the home page, its breadcrumb) are linked by `@id`.
 *
 * Every fact comes from the owner's settings or from the site's own pages. Nothing is guessed
 * or scraped. With an SEO tool active the logic says what to hand over and prints nothing, so
 * a page never carries two graphs that disagree about who the organization is.
 */
final class EntityGraph {

	const SAME_AS_MAX = 20;

	/**
	 * Evaluates the fixer.
	 *
	 * @param array<string,mixed> $given Site facts.
	 * @return array<string,mixed> Evaluation.
	 */
	public static function evaluate( array $given ): array {
		$site                       = rtrim( (string) $given['site']['url'], '/' );
		$name                       = (string) $given['site']['name'];
		$org                        = (array) $given['choices']['organization'];
		$template                   = $given['choices']['search_template'] ?? null;
		list( $search, $search_ok ) = self::search_action( $template );
		$language                   = $given['site']['language'] ?? null;
		$pages_meta                 = array(
			'about_url'   => $given['site']['about_url'] ?? null,
			'contact_url' => $given['site']['contact_url'] ?? null,
		);
		$tooling                    = null;
		foreach ( array( 'yoast', 'rank_math', 'schema_org_metatag' ) as $tool ) {
			if ( in_array( $tool, (array) $given['tooling'], true ) ) {
				$tooling = $tool;
				break;
			}
		}
		$findings = array(
			'mode'            => null === $tooling ? 'own_graph' : 'through_tooling',
			'tooling'         => $tooling,
			'search_template' => self::has( $template ) ? ( $search_ok ? 'valid' : 'invalid' ) : 'none',
		);

		if ( null !== $tooling ) {
			// The tool prints the graph. The module hands it the facts and prints nothing.
			$organization = self::organization( $site, $name, $org, $pages_meta['contact_url'] );
			unset( $organization['@type'], $organization['@id'] );
			$pages = array();
			foreach ( $given['pages'] as $page ) {
				$pages[] = array(
					'url'        => $page['url'],
					'kind'       => $page['kind'],
					'breadcrumb' => 'home' === $page['kind'] ? array() : self::trail( $site, $page ),
					'dates'      => $page['dates'] ?? null,
				);
			}
			$output = array(
				'prints_graph' => false,
				'tooling'      => $tooling,
				'provides'     => array(
					'organization' => $organization,
					'website'      => array( 'search_template' => $search_ok ? $template : null ),
					'pages'        => $pages,
				),
			);
		} else {
			$pages = array();
			foreach ( $given['pages'] as $page ) {
				$pages[] = array(
					'url'   => $page['url'],
					'graph' => self::page_graph( $site, $name, $org, $pages_meta, $page, $search, $language ),
				);
			}
			$output = array(
				'prints_graph' => true,
				'pages'        => $pages,
			);
		}
		return array(
			'status'   => 'ready',
			'reason'   => null,
			'findings' => $findings,
			'changes'  => array(
				array(
					'target' => 'structured data',
					'before' => 'none',
					'after'  => null === $tooling ? 'one linked graph' : 'through ' . $tooling,
				),
			),
			'output'   => $output,
			'baseline' => array( 'prints_graph' => false ),
			'paste'    => null,
		);
	}

	/**
	 * Whether a value is present the way the reference reads it: not null, blank, empty or zero.
	 *
	 * @param mixed $value A value.
	 */
	public static function has( $value ): bool {
		return null !== $value && '' !== $value && array() !== $value && false !== $value && 0 !== $value;
	}

	/**
	 * Home, each ancestor, then the page itself.
	 *
	 * @param string              $site Site URL without a trailing slash.
	 * @param array<string,mixed> $page The page.
	 * @return array<int,array<string,string>>
	 */
	private static function trail( string $site, array $page ): array {
		$trail = array(
			array(
				'name' => Strings::get( 'fixer.entity_graph.breadcrumb_home' ),
				'url'  => $site . '/',
			),
		);
		foreach ( (array) ( $page['breadcrumb'] ?? array() ) as $crumb ) {
			$trail[] = $crumb;
		}
		$trail[] = array(
			'name' => $page['title'],
			'url'  => $page['url'],
		);
		return $trail;
	}

	/**
	 * The organization node.
	 *
	 * @param string              $site        Site URL without a trailing slash.
	 * @param string              $name        Site name.
	 * @param array<string,mixed> $org         The owner's facts.
	 * @param string|null         $contact_url The Contact page's address.
	 * @return array<string,mixed>
	 */
	public static function organization( string $site, string $name, array $org, ?string $contact_url ): array {
		$node = array(
			'@type' => 'Organization',
			'@id'   => $site . '/#organization',
			'name'  => $name,
			'url'   => $site . '/',
		);
		if ( self::has( $org['legal_name'] ?? null ) ) {
			$node['legalName'] = $org['legal_name'];
		}
		if ( self::has( $org['logo'] ?? null ) ) {
			$node['logo'] = $org['logo'];
		}
		if ( self::has( $org['description'] ?? null ) ) {
			$node['description'] = Text::one_line( (string) $org['description'] );
		}
		if ( self::has( $org['founding_date'] ?? null ) ) {
			$node['foundingDate'] = $org['founding_date'];
		}
		if ( self::has( $org['address'] ?? null ) ) {
			$renamed = array(
				'street'      => 'streetAddress',
				'locality'    => 'addressLocality',
				'region'      => 'addressRegion',
				'postal_code' => 'postalCode',
				'country'     => 'addressCountry',
			);
			$address = array( '@type' => 'PostalAddress' );
			foreach ( $renamed as $from => $to ) {
				if ( self::has( $org['address'][ $from ] ?? null ) ) {
					$address[ $to ] = $org['address'][ $from ];
				}
			}
			$node['address'] = $address;
		}
		if ( self::has( $org['area_served'] ?? null ) ) {
			$node['areaServed'] = array_values( (array) $org['area_served'] );
		}
		if ( self::has( $org['contact_points'] ?? null ) ) {
			$points = array();
			foreach ( $org['contact_points'] as $point ) {
				$entry = array(
					'@type'       => 'ContactPoint',
					'contactType' => $point['type'],
				);
				foreach ( array( 'email', 'telephone' ) as $key ) {
					if ( self::has( $point[ $key ] ?? null ) ) {
						$entry[ $key ] = $point[ $key ];
					}
				}
				if ( self::has( $contact_url ) ) {
					$entry['url'] = $contact_url;
				}
				$points[] = $entry;
			}
			$node['contactPoint'] = $points;
		}
		if ( self::has( $org['tax_id'] ?? null ) ) {
			$node['taxID'] = $org['tax_id'];
		}
		if ( self::has( $org['leaders'] ?? null ) ) {
			// Names, not Person nodes: the Index counts every distinct Person @id as another
			// definition of the same kind of entity (AIR-1.11), so two people with nodes of their
			// own would cost more than the leadership detail gains.
			$names = array();
			foreach ( $org['leaders'] as $leader ) {
				$leader = trim( (string) $leader );
				if ( '' !== $leader ) {
					$names[] = $leader;
				}
			}
			$node['founder'] = $names;
		}
		$same_as = array();
		foreach ( (array) ( $org['same_as'] ?? array() ) as $url ) {
			$cleaned = trim( (string) $url );
			if ( 0 === strpos( $cleaned, 'https://' ) && ! in_array( $cleaned, $same_as, true ) ) {
				$same_as[] = $cleaned;
			}
		}
		if ( array() !== $same_as ) {
			$node['sameAs'] = array_slice( $same_as, 0, self::SAME_AS_MAX );
		}
		return $node;
	}

	/**
	 * The search action, and whether the owner's address was usable.
	 *
	 * @param string|null $template The owner's search address.
	 * @return array{0:array<int,array<string,mixed>>|null,1:bool}
	 */
	private static function search_action( ?string $template ): array {
		if ( ! self::has( $template ) ) {
			return array( null, true );
		}
		if ( false === strpos( (string) $template, '{search_term_string}' ) || 0 !== strpos( (string) $template, 'https://' ) ) {
			return array( null, false );
		}
		return array(
			array(
				array(
					'@type'       => 'SearchAction',
					'target'      => array(
						'@type'       => 'EntryPoint',
						'urlTemplate' => $template,
					),
					'query-input' => 'required name=search_term_string',
				),
			),
			true,
		);
	}

	/**
	 * One page's graph document.
	 *
	 * @param string                              $site     Site URL without a trailing slash.
	 * @param string                              $name     Site name.
	 * @param array<string,mixed>                 $org      The owner's facts.
	 * @param array<string,mixed>                 $meta     About and Contact addresses.
	 * @param array<string,mixed>                 $page     The page.
	 * @param array<int,array<string,mixed>>|null $search The search action.
	 * @param string|null                         $language Language tag.
	 * @return array<string,mixed>
	 */
	private static function page_graph( string $site, string $name, array $org, array $meta, array $page, ?array $search, ?string $language ): array {
		$home    = 'home' === $page['kind'];
		$url     = $page['url'];
		$website = array(
			'@type'     => 'WebSite',
			'@id'       => $site . '/#website',
			'url'       => $site . '/',
			'name'      => $name,
			'publisher' => array( '@id' => $site . '/#organization' ),
		);
		if ( self::has( $language ) ) {
			$website['inLanguage'] = $language;
		}
		if ( null !== $search ) {
			$website['potentialAction'] = $search;
		}
		// An About or Contact page is still a WebPage: the chain AIR-1.11 follows runs through it.
		if ( $url === $meta['about_url'] ) {
			$kind = array( 'WebPage', 'AboutPage' );
		} elseif ( $url === $meta['contact_url'] ) {
			$kind = array( 'WebPage', 'ContactPage' );
		} else {
			$kind = 'WebPage';
		}
		$webpage = array(
			'@type'    => $kind,
			'@id'      => $url . '#webpage',
			'url'      => $url,
			'name'     => $page['title'],
			'isPartOf' => array( '@id' => $site . '/#website' ),
		);
		if ( $home ) {
			$webpage['about'] = array( '@id' => $site . '/#organization' );
		}
		if ( self::has( $language ) ) {
			$webpage['inLanguage'] = $language;
		}
		if ( self::has( $page['dates'] ?? null ) ) {
			$webpage['datePublished'] = $page['dates']['datePublished'];
			$webpage['dateModified']  = $page['dates']['dateModified'];
		}
		$extra = array();
		if ( ! $home ) {
			$webpage['breadcrumb'] = array( '@id' => $url . '#breadcrumb' );
			$items                 = array();
			foreach ( self::trail( $site, $page ) as $n => $crumb ) {
				$items[] = array(
					'@type'    => 'ListItem',
					'position' => $n + 1,
					'name'     => $crumb['name'],
					'item'     => $crumb['url'],
				);
			}
			$extra[] = array(
				'@type'           => 'BreadcrumbList',
				'@id'             => $url . '#breadcrumb',
				'itemListElement' => $items,
			);
		}
		return array(
			'@context' => 'https://schema.org',
			'@graph'   => array_merge( array( self::organization( $site, $name, $org, $meta['contact_url'] ), $website, $webpage ), $extra ),
		);
	}
}
