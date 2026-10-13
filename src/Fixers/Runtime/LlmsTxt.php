<?php
/**
 * Serving /llms.txt as a route WordPress generates.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Fixers\Runtime;

use ReadinessIndex\Fixers\Content\LlmsContent;
use ReadinessIndex\Fixers\Logic;
use ReadinessIndex\Fixers\State;

/**
 * A rewrite rule and a `template_redirect` handler: never a file on disk. The text is built when
 * content is published or a menu changes and kept in one non-autoloaded option, so answering
 * a request costs one option read and no content query. A real `llms.txt` file in the web root
 * is answered by the web server first, which is why the fixer refuses to apply beside one.
 */
final class LlmsTxt {

	const OPTION    = 'readiness_index_llms';
	const QUERY_VAR = 'readiness_index_llms';
	const RULE      = '^llms\.txt$';

	/**
	 * Whether a rebuild is already queued for the end of this request.
	 *
	 * @var bool
	 */
	private static $queued = false;

	/**
	 * Adds the route, the handler and the rebuild triggers.
	 *
	 * @param array<string,mixed> $choices The stored choices.
	 */
	public static function register( array $choices ): void {
		unset( $choices );
		add_action( 'init', array( self::class, 'add_rule' ) );
		add_filter( 'query_vars', array( self::class, 'query_vars' ) );
		add_action( 'template_redirect', array( self::class, 'serve' ), 0 );
		foreach ( array( 'transition_post_status', 'deleted_post', 'wp_update_nav_menu', 'update_option_blogname', 'update_option_blogdescription', 'update_option_page_on_front', 'update_option_page_for_posts' ) as $hook ) {
			add_action( $hook, array( self::class, 'queue' ) );
		}
	}

	/**
	 * The rewrite rule.
	 */
	public static function add_rule(): void {
		add_rewrite_rule( self::RULE, 'index.php?' . self::QUERY_VAR . '=1', 'top' );
	}

	/**
	 * Lets the rule's query variable through.
	 *
	 * @param string[] $vars Public query variables.
	 * @return string[]
	 */
	public static function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Takes the rule out of WordPress's own list, so a rewrite flush right after the fixer is
	 * turned off does not write it back.
	 */
	public static function forget_rule(): void {
		global $wp_rewrite;
		if ( is_object( $wp_rewrite ) && isset( $wp_rewrite->extra_rules_top[ self::RULE ] ) ) {
			unset( $wp_rewrite->extra_rules_top[ self::RULE ] );
		}
	}

	/**
	 * Marks the file stale and rebuilds it once, at the end of the request, however many posts
	 * changed in it.
	 */
	public static function queue(): void {
		if ( self::$queued ) {
			return;
		}
		self::$queued = true;
		add_action( 'shutdown', array( self::class, 'rebuild' ) );
	}

	/**
	 * Builds the file from the site's content and stores it.
	 *
	 * @return string The text, or an empty string when there is nothing to list.
	 */
	public static function rebuild(): string {
		self::$queued = false;
		if ( null === State::get( 'llms-txt' ) ) {
			return '';
		}
		$ev   = Logic\LlmsTxt::evaluate(
			array(
				'site'     => array(
					'url'         => untrailingslashit( home_url() ),
					'name'        => (string) get_bloginfo( 'name' ),
					'description' => (string) get_bloginfo( 'description' ),
				),
				'tooling'  => array(),
				'files'    => array( 'llms.txt' => false ),
				'content'  => LlmsContent::collect(),
				'baseline' => array( 'llms.txt' => '' ),
			)
		);
		$text = (string) $ev['output']['llms.txt'];
		if ( 'no_content' === $ev['reason'] || '' === $text ) {
			delete_option( self::OPTION );
			return '';
		}
		update_option(
			self::OPTION,
			array(
				'text'  => $text,
				'hash'  => hash( 'sha256', $text ),
				'built' => time(),
			),
			false
		);
		return $text;
	}

	/**
	 * What to answer: status, headers and body for a request.
	 *
	 * Pure, so the 304 logic is tested without a web server. A request whose `If-None-Match`
	 * names the current hash, or whose `If-Modified-Since` is not older than the build, gets a
	 * 304 with no body.
	 *
	 * @param array<string,mixed>|null $stored  The stored file: text, hash and built.
	 * @param string                   $if_none Request `If-None-Match`.
	 * @param string                   $if_mod  Request `If-Modified-Since`.
	 * @return array{status:int,headers:array<string,string>,body:string}
	 */
	public static function response( ?array $stored, string $if_none, string $if_mod ): array {
		if ( null === $stored || empty( $stored['text'] ) ) {
			return array(
				'status'  => 404,
				'headers' => array(),
				'body'    => '',
			);
		}
		$etag    = '"' . $stored['hash'] . '"';
		$headers = array(
			'Content-Type'           => 'text/plain; charset=utf-8',
			'X-Content-Type-Options' => 'nosniff',
			'ETag'                   => $etag,
			'Last-Modified'          => gmdate( 'D, d M Y H:i:s', (int) $stored['built'] ) . ' GMT',
			'Cache-Control'          => 'public, max-age=60',
		);
		$same    = '' !== $if_none && in_array( $etag, array_map( 'trim', explode( ',', $if_none ) ), true );
		if ( ! $same && '' === $if_none && '' !== $if_mod ) {
			$since = strtotime( $if_mod );
			$same  = false !== $since && $since >= (int) $stored['built'];
		}
		return array(
			'status'  => $same ? 304 : 200,
			'headers' => $headers,
			'body'    => $same ? '' : (string) $stored['text'],
		);
	}

	/**
	 * Answers `/llms.txt` and ends the request; any other request is left alone.
	 */
	public static function serve(): void {
		if ( '1' !== (string) get_query_var( self::QUERY_VAR ) || null === State::get( 'llms-txt' ) ) {
			return;
		}
		$stored = get_option( self::OPTION, null );
		if ( ! is_array( $stored ) ) {
			// First request after applying, or after the option was lost: build it once.
			self::rebuild();
			$stored = get_option( self::OPTION, null );
		}
		$none = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) : '';
		$mod  = isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) : '';
		$out  = self::response( is_array( $stored ) ? $stored : null, $none, $mod );
		if ( 404 === $out['status'] ) {
			// Nothing to list: WordPress's own not-found page, as if the route did not exist.
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			return;
		}
		status_header( $out['status'] );
		foreach ( $out['headers'] as $name => $value ) {
			header( $name . ': ' . $value );
		}
		// Plain text with nosniff: escaping it would change the file's bytes, and it is never HTML.
		echo $out['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}
}
