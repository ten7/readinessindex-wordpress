<?php
/**
 * Whether a CDN or firewall seems to turn AI crawlers away.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Diagnostics;

use ReadinessIndex\Http\SiteFetcher;
use ReadinessIndex\Strings;

/**
 * AIR-1.2. Asks the site's own home page as a browser would, then as each AI crawler's published
 * User-Agent, and compares the answers. It says plainly what it cannot know: a request made by
 * the site's own server is not a real crawler's request, and an edge that keys on the crawler's
 * address range answers the real one differently. It never fixes anything and asks no host but
 * this site's own.
 */
final class CdnAccess {

	/**
	 * The fetching crawlers' User-Agent strings, as the scanner probes them. Vendors that publish
	 * theirs are quoted; the others are the forms seen in server logs.
	 */
	const USER_AGENTS = array(
		'OAI-SearchBot' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36; compatible; OAI-SearchBot/1.4; +https://openai.com/searchbot',
		'ChatGPT-User'  => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; ChatGPT-User/1.0; +https://openai.com/bot',
		'GPTBot'        => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.4; +https://openai.com/gptbot',
		'PerplexityBot' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; PerplexityBot/1.0; +https://perplexity.ai/perplexitybot)',
		'Claude-User'   => 'Mozilla/5.0 (compatible; Claude-User/1.0; +Claude-User@anthropic.com)',
		'ClaudeBot'     => 'Mozilla/5.0 (compatible; ClaudeBot/1.0; +claudebot@anthropic.com)',
		'CCBot'         => 'CCBot/2.0 (https://commoncrawl.org/faq/)',
		'Bytespider'    => 'Mozilla/5.0 (Linux; Android 5.0) AppleWebKit/537.36 (KHTML, like Gecko) Mobile Safari/537.36 (compatible; Bytespider; spider-feedback@bytedance.com)',
	);

	/**
	 * Runs the comparison.
	 *
	 * @param string|null $url The page to ask, a URL on this site. Null for the home page.
	 */
	public static function run( ?string $url = null ): Result {
		$url    = $url ?? home_url( '/' );
		$result = new Result( 'cdn' );
		$base   = SiteFetcher::get( $url );
		if ( null === $base ) {
			$result->clean   = false;
			$result->lines[] = Strings::get( 'diagnostic.cdn.unreachable' );
			return $result;
		}
		$blocked = array();
		$hints   = self::hints( $base['headers'] );
		foreach ( self::USER_AGENTS as $agent => $user_agent ) {
			$got = SiteFetcher::get( $url, $user_agent );
			if ( null === $got ) {
				continue;
			}
			$hints = array_merge( $hints, self::hints( $got['headers'] ) );
			if ( self::differs( $base['status'], $got['status'] ) ) {
				$blocked[]       = $agent;
				$result->items[] = Strings::get(
					'diagnostic.cdn.row',
					array(
						'agent'  => $agent,
						'status' => $got['status'],
						'normal' => $base['status'],
					)
				);
			}
		}
		$result->clean = array() === $blocked;
		if ( array() === $blocked ) {
			$result->lines[] = Strings::get( 'diagnostic.cdn.result.clean' );
		} else {
			$result->lines[] = Strings::get( 'diagnostic.cdn.result.blocked', array( 'agents' => implode( ', ', $blocked ) ) );
		}
		$hints = array_values( array_unique( $hints ) );
		if ( array() !== $hints ) {
			$result->lines[] = Strings::get( 'diagnostic.cdn.result.hint', array( 'sources' => implode( ', ', $hints ) ) );
		}
		$result->lines[] = Strings::get( 'diagnostic.cdn.caveat' );
		return $result;
	}

	/**
	 * Whether an answer to a crawler differs from the answer to a browser in a way that
	 * looks like a refusal.
	 *
	 * @param int $browser Status for a browser.
	 * @param int $crawler Status for a crawler.
	 */
	public static function differs( int $browser, int $crawler ): bool {
		if ( $browser === $crawler ) {
			return false;
		}
		return ( $crawler >= 400 && $browser < 400 ) || in_array( $crawler, array( 401, 403, 406, 429, 503 ), true );
	}

	/**
	 * Which network in front of the site its headers suggest. Names of products, not sentences.
	 *
	 * @param array<string,string> $headers Lower-cased response headers.
	 * @return string[]
	 */
	public static function hints( array $headers ): array {
		$out    = array();
		$server = strtolower( $headers['server'] ?? '' );
		if ( isset( $headers['cf-ray'] ) || false !== strpos( $server, 'cloudflare' ) ) {
			$out[] = 'Cloudflare';
		}
		if ( isset( $headers['x-sucuri-id'] ) || isset( $headers['x-sucuri-cache'] ) ) {
			$out[] = 'Sucuri';
		}
		if ( isset( $headers['x-amz-cf-id'] ) || false !== strpos( $headers['via'] ?? '', 'CloudFront' ) ) {
			$out[] = 'Amazon CloudFront';
		}
		if ( false !== strpos( $server, 'akamai' ) || isset( $headers['x-akamai-transformed'] ) ) {
			$out[] = 'Akamai';
		}
		if ( isset( $headers['x-iinfo'] ) || ( isset( $headers['x-cdn'] ) && false !== stripos( $headers['x-cdn'], 'imperva' ) ) ) {
			$out[] = 'Imperva';
		}
		if ( isset( $headers['x-fastly-request-id'] ) || false !== strpos( $headers['x-served-by'] ?? '', 'cache-' ) ) {
			$out[] = 'Fastly';
		}
		if ( isset( $headers['x-pantheon-styx-hostname'] ) || isset( $headers['x-styx-req-id'] ) ) {
			$out[] = 'Pantheon';
		}
		return $out;
	}
}
