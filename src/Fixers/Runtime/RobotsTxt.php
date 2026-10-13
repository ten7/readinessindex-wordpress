<?php
/**
 * Serving the robots.txt the fixer made.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Fixers\Runtime;

use ReadinessIndex\Fixers\Logic;

/**
 * A filter over the `robots_txt` body WordPress builds, never a file. It runs late, so what other
 * plugins (including SEO plugins' robots.txt editors) produced is the baseline it works on.
 */
final class RobotsTxt {

	const PRIORITY = 9999;

	/**
	 * Adds the filter when the fixer is on.
	 *
	 * @param array<string,mixed> $choices The stored choices.
	 */
	public static function register( array $choices ): void {
		add_filter( 'robots_txt', array( self::class, 'filter' ), self::PRIORITY, 2 );
		unset( $choices );
	}

	/**
	 * The filter.
	 *
	 * @param string     $output The robots.txt body so far.
	 * @param string|int $is_public The `blog_public` setting.
	 */
	public static function filter( $output, $is_public ) {
		if ( ! is_string( $output ) || '0' === (string) $is_public ) {
			// A site that asks to be left out of search gets WordPress's own answer, whatever was chosen.
			return $output;
		}
		$choices = \ReadinessIndex\Fixers\State::get( 'robots-txt' );
		if ( null === $choices ) {
			return $output;
		}
		try {
			$ev = Logic\RobotsTxt::evaluate(
				array(
					'site'     => array(
						'url'  => untrailingslashit( home_url() ),
						'name' => (string) get_bloginfo( 'name' ),
					),
					'files'    => array( 'robots.txt' => false ),
					'baseline' => array( 'robots.txt' => $output ),
					'choices'  => $choices,
				)
			);
		} catch ( \InvalidArgumentException $e ) {
			return $output;
		}
		return 'ready' === $ev['status'] ? $ev['output']['robots.txt'] : $output;
	}

	/**
	 * What WordPress serves with this fixer off: core's body through every other plugin's
	 * filter, with this filter taken out for the moment.
	 *
	 * The fixer never reads what is being served to find the baseline, because that would
	 * include its own change.
	 */
	public static function baseline(): string {
		$public = get_option( 'blog_public' );
		$output = "User-agent: *\n";
		if ( '0' === (string) $public ) {
			$output .= "Disallow: /\n";
		} else {
			$site_url = wp_parse_url( site_url() );
			$path     = ! empty( $site_url['path'] ) ? $site_url['path'] : '';
			$output  .= 'Disallow: ' . $path . "/wp-admin/\n";
			$output  .= 'Allow: ' . $path . "/wp-admin/admin-ajax.php\n";
		}
		$had = has_filter( 'robots_txt', array( self::class, 'filter' ) );
		if ( false !== $had ) {
			remove_filter( 'robots_txt', array( self::class, 'filter' ), self::PRIORITY );
		}
		/** This filter is documented in wp-includes/functions.php */
		$body = apply_filters( 'robots_txt', $output, $public ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's own hook.
		if ( false !== $had ) {
			add_filter( 'robots_txt', array( self::class, 'filter' ), self::PRIORITY, 2 );
		}
		return (string) $body;
	}
}
