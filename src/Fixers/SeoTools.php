<?php
/**
 * The SEO and structured-data tools a site may already run.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Fixers;

/**
 * Feature detection, never version sniffing: a tool is active when the constant, class or
 * function it defines exists. Yoast SEO and Rank Math are integrated (the plugin hands them
 * the same facts and prints nothing of its own); the others get instructions only, because a
 * wrong write into a tool the plugin has not been tested against is worse than a sentence.
 *
 * WooCommerce is listed because it prints its own product structured data, which is left alone.
 */
final class SeoTools {

	/**
	 * Display names. Product names, which are proper nouns and not sentences.
	 */
	const NAMES = array(
		'yoast'       => 'Yoast SEO',
		'rank_math'   => 'Rank Math',
		'aioseo'      => 'All in One SEO',
		'seopress'    => 'SEOPress',
		'tsf'         => 'The SEO Framework',
		'schema_pro'  => 'Schema Pro',
		'woocommerce' => 'WooCommerce',
	);

	/**
	 * Tools the plugin integrates with.
	 */
	const INTEGRATED = array( 'yoast', 'rank_math' );

	/**
	 * Tools that print their own JSON-LD graph, so this plugin prints none beside them.
	 */
	const GRAPH_OWNERS = array( 'yoast', 'rank_math', 'aioseo', 'seopress', 'tsf', 'schema_pro' );

	/**
	 * Tools a test says are active, or null to detect them.
	 *
	 * @var string[]|null
	 */
	private static $forced = null;

	/**
	 * Pins the active tools for a test, or detects them again with null.
	 *
	 * @param string[]|null $keys Tool keys.
	 */
	public static function use_active( ?array $keys ): void {
		self::$forced = $keys;
	}

	/**
	 * The keys of the tools active on this site, in a stable order.
	 *
	 * @return string[]
	 */
	public static function active(): array {
		if ( null !== self::$forced ) {
			return self::$forced;
		}
		$found = array();
		if ( defined( 'WPSEO_VERSION' ) ) {
			$found[] = 'yoast';
		}
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath', false ) ) {
			$found[] = 'rank_math';
		}
		if ( defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' ) ) {
			$found[] = 'aioseo';
		}
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			$found[] = 'seopress';
		}
		if ( defined( 'THE_SEO_FRAMEWORK_VERSION' ) ) {
			$found[] = 'tsf';
		}
		if ( defined( 'BSF_AIOSEO_PRO_VER' ) || class_exists( 'BSF_AIOSEO_Pro_Admin', false ) ) {
			$found[] = 'schema_pro';
		}
		if ( class_exists( 'WooCommerce', false ) ) {
			$found[] = 'woocommerce';
		}
		return $found;
	}

	/**
	 * The name of a tool.
	 *
	 * @param string $key Tool key.
	 */
	public static function name( string $key ): string {
		return self::NAMES[ $key ] ?? $key;
	}

	/**
	 * Whether Yoast SEO and Rank Math are both active, which this plugin refuses to guess at.
	 *
	 * @param string[] $active Active tool keys.
	 */
	public static function both_integrated( array $active ): bool {
		return in_array( 'yoast', $active, true ) && in_array( 'rank_math', $active, true );
	}

	/**
	 * The one integrated tool that owns the graph, or null.
	 *
	 * @param string[] $active Active tool keys.
	 */
	public static function integrated( array $active ): ?string {
		foreach ( self::INTEGRATED as $key ) {
			if ( in_array( $key, $active, true ) ) {
				return $key;
			}
		}
		return null;
	}

	/**
	 * A graph-printing tool the plugin only has instructions for, or null.
	 *
	 * @param string[] $active Active tool keys.
	 */
	public static function instructed( array $active ): ?string {
		foreach ( self::GRAPH_OWNERS as $key ) {
			if ( in_array( $key, $active, true ) && ! in_array( $key, self::INTEGRATED, true ) ) {
				return $key;
			}
		}
		return null;
	}

	/**
	 * Where to change organization details in a tool the plugin has only instructions for: the
	 * exact field and the screen it is on. Null when the plugin does not know.
	 *
	 * @param string $key Tool key.
	 * @return array{setting:string,page:string}|null
	 */
	public static function organization_field( string $key ): ?array {
		$map = array(
			'aioseo'     => array(
				'setting' => 'Search Appearance, Global Settings, Organization (name, logo and social profiles)',
				'page'    => 'admin.php?page=aioseo-search-appearance',
			),
			'seopress'   => array(
				'setting' => 'Titles & Metas, Knowledge Graph (organization name, logo and social profiles)',
				'page'    => 'admin.php?page=seopress-titles',
			),
			'tsf'        => array(
				'setting' => 'Schema.org Settings (organization name, logo and social profiles)',
				'page'    => 'admin.php?page=theseoframework-settings',
			),
			'schema_pro' => array(
				'setting' => 'Schema, Global Schemas (organization name, logo and social profiles)',
				'page'    => 'options-general.php?page=aiosrs-pro-settings',
			),
		);
		return $map[ $key ] ?? null;
	}
}
