<?php
/**
 * Yoast SEO.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Fixers\Integration;

use ReadinessIndex\Fixers\Logic\EntityGraph;
use ReadinessIndex\Fixers\Runtime\Facts;

/**
 * Hands Yoast the organization's facts. Yoast prints an Organization only when the site
 * represents a company that has a name and a logo set in its own options, so those are written
 * there (with the owner's confirmation, and recorded for Undo); everything Yoast has no field for
 * goes through its schema filters.
 */
final class Yoast implements Tool {

	const TITLES = 'wpseo_titles';
	const SOCIAL = 'wpseo_social';

	/**
	 * The key `SeoTools` uses.
	 */
	public function key(): string {
		return 'yoast';
	}

	/**
	 * Whether Yoast is active and its settings are where this code expects them.
	 */
	public function supported(): bool {
		if ( ! defined( 'WPSEO_VERSION' ) || ! class_exists( 'WPSEO_Options' ) || ! method_exists( 'WPSEO_Options', 'get' ) || ! method_exists( 'WPSEO_Options', 'set' ) ) {
			return false;
		}
		$titles = get_option( self::TITLES );
		$social = get_option( self::SOCIAL );
		return is_array( $titles ) && is_array( $social )
			&& array_key_exists( 'company_name', $titles ) && array_key_exists( 'company_logo_id', $titles )
			&& array_key_exists( 'company_or_person', $titles ) && array_key_exists( 'other_social_urls', $social );
	}

	/**
	 * The writes Yoast needs for these choices.
	 *
	 * @param array<string,mixed> $choices   The owner's choices.
	 * @param string              $site_name The site's name.
	 * @return array<int,array{option:string,key:string,before:mixed,after:mixed}>
	 */
	public function plan( array $choices, string $site_name ): array {
		$plan = array();
		$org  = (array) ( $choices['organization'] ?? array() );
		$add  = function ( string $option, string $key, $after ) use ( &$plan ): void {
			$before = \WPSEO_Options::get( $key, null, array( $option ) );
			if ( $before !== $after ) {
				$plan[] = array(
					'option' => $option,
					'key'    => $key,
					'before' => $before,
					'after'  => $after,
				);
			}
		};
		$add( self::TITLES, 'company_or_person', 'company' );
		$add( self::TITLES, 'company_name', $site_name );
		if ( ! empty( $org['logo'] ) ) {
			$id = (int) attachment_url_to_postid( (string) $org['logo'] );
			if ( $id > 0 ) {
				$add( self::TITLES, 'company_logo', (string) $org['logo'] );
				$add( self::TITLES, 'company_logo_id', $id );
			}
		}
		$same_as = EntityGraph::organization( 'https://x', 'x', $org, null )['sameAs'] ?? array();
		if ( array() !== $same_as ) {
			$have = (array) \WPSEO_Options::get( 'other_social_urls', array(), array( self::SOCIAL ) );
			$add( self::SOCIAL, 'other_social_urls', array_values( array_unique( array_merge( array_map( 'strval', $have ), $same_as ) ) ) );
		}
		return $plan;
	}

	/**
	 * Makes the writes and returns what they replaced.
	 *
	 * The whole option is read before and after, and every key that differs is recorded, not
	 * only the ones written: Yoast derives some keys itself (it stores the logo's size and type
	 * when it is given a logo's id), and Undo has to give those back too or the option is not
	 * what it was.
	 *
	 * @param array<int,array{option:string,key:string,before:mixed,after:mixed}> $plan A plan.
	 * @return array<string,array<string,mixed>>
	 */
	public function write( array $plan ): array {
		$options = array_values(
			array_unique(
				array_map(
					static function ( array $write ): string {
						return $write['option'];
					},
					$plan
				)
			)
		);
		$before  = array();
		foreach ( $options as $option ) {
			$before[ $option ] = (array) get_option( $option, array() );
		}
		foreach ( $plan as $write ) {
			\WPSEO_Options::set( $write['key'], $write['after'], $write['option'] );
		}
		$prior = array();
		foreach ( $options as $option ) {
			$after = (array) get_option( $option, array() );
			foreach ( array_unique( array_merge( array_keys( $before[ $option ] ), array_keys( $after ) ) ) as $key ) {
				if ( ( $before[ $option ][ $key ] ?? null ) !== ( $after[ $key ] ?? null ) ) {
					$prior[ $option ][ $key ] = $before[ $option ][ $key ] ?? null;
				}
			}
		}
		// Yoast fills the logo's size and type in lazily, the first time it draws a graph after it
		// is given a logo, so the diff above cannot see it. It is derived from the logo's id and is
		// recorded with it: without this Undo leaves the old logo's metadata behind.
		if ( isset( $prior[ self::TITLES ]['company_logo_id'] ) && ! array_key_exists( 'company_logo_meta', $prior[ self::TITLES ] ) ) {
			$prior[ self::TITLES ]['company_logo_meta'] = $before[ self::TITLES ]['company_logo_meta'] ?? false;
		}
		return $prior;
	}

	/**
	 * Puts back the values `write` replaced, and only those: anything else the owner changed in
	 * Yoast since is left as it is.
	 *
	 * @param array<string,array<string,mixed>> $prior Prior values by option and key.
	 */
	public function restore( array $prior ): void {
		foreach ( $prior as $option => $values ) {
			$current = (array) get_option( (string) $option, array() );
			foreach ( $values as $key => $value ) {
				$current[ $key ] = $value;
			}
			update_option( (string) $option, $current );
		}
	}

	/**
	 * Yoast needs a logo from the media library, which it reads as an image.
	 *
	 * @param array<string,mixed> $choices The owner's choices.
	 */
	public function missing( array $choices ): ?string {
		$logo = (string) ( $choices['organization']['logo'] ?? '' );
		if ( '' !== $logo && (int) attachment_url_to_postid( $logo ) > 0 ) {
			return null;
		}
		$has = (int) \WPSEO_Options::get( 'company_logo_id', 0, array( self::TITLES ) );
		return $has > 0 ? null : 'logo_needed';
	}

	/**
	 * Adds the facts through Yoast's schema filters.
	 *
	 * @param array<string,mixed> $choices The stored choices.
	 */
	public function register( array $choices ): void {
		unset( $choices );
		add_filter( 'wpseo_schema_organization', array( self::class, 'organization' ), 11 );
		add_filter( 'wpseo_schema_website', array( self::class, 'website' ), 11 );
		add_filter( 'wpseo_schema_webpage', array( self::class, 'webpage' ), 11, 2 );
	}

	/**
	 * Yoast's Organization piece: the facts it has no field for.
	 *
	 * @param array<string,mixed> $piece The piece.
	 * @return array<string,mixed>
	 */
	public static function organization( $piece ) {
		if ( ! is_array( $piece ) ) {
			return $piece;
		}
		$facts = Facts::organization();
		if ( null === $facts ) {
			return $piece;
		}
		foreach ( $facts as $key => $value ) {
			if ( in_array( $key, array( 'name', 'url', 'logo', 'sameAs' ), true ) ) {
				// Yoast's own name and logo, and the profiles written to its options, are the truth.
				continue;
			}
			$piece[ $key ] = $value;
		}
		return $piece;
	}

	/**
	 * Yoast's WebSite piece: one SearchAction, the owner's.
	 *
	 * @param array<string,mixed> $piece The piece.
	 * @return array<string,mixed>
	 */
	public static function website( $piece ) {
		if ( ! is_array( $piece ) ) {
			return $piece;
		}
		$search = Facts::search_action();
		if ( null !== $search ) {
			$piece['potentialAction'] = $search;
		}
		return $piece;
	}

	/**
	 * Yoast's WebPage piece: the home page is about the organization.
	 *
	 * @param array<string,mixed> $piece   The piece.
	 * @param mixed               $context Yoast's context.
	 * @return array<string,mixed>
	 */
	public static function webpage( $piece, $context = null ) {
		unset( $context );
		if ( is_array( $piece ) && is_front_page() && ! isset( $piece['about'] ) ) {
			$piece['about'] = array( '@id' => untrailingslashit( home_url() ) . '/#organization' );
		}
		return $piece;
	}
}
