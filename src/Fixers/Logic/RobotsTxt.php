<?php
/**
 * What the robots.txt fixer would serve.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Fixers\Logic;

use ReadinessIndex\Fixers\Robots\Parser;
use ReadinessIndex\Strings;

/**
 * `docs/contract/../fixers/robots-txt.md` as code: given what the site serves with the fixer
 * off (the baseline), the owner's choices and the site's facts, say what would be served
 * with it on. Nothing here reads WordPress, a clock or the network, which is what lets the
 * golden vectors hold it to the character.
 */
final class RobotsTxt {

	const BEGIN = '# BEGIN Readiness Index';
	const END   = '# END Readiness Index';

	/** The ten crawlers the Index evaluates, in the spec's order. */
	const AGENTS = array(
		'OAI-SearchBot',
		'ChatGPT-User',
		'PerplexityBot',
		'Claude-User',
		'GPTBot',
		'ClaudeBot',
		'CCBot',
		'Bytespider',
		'Google-Extended',
		'Applebot-Extended',
	);

	const SIGNAL_KEYS = array( 'search', 'ai-input', 'ai-train' );

	/** Whole words that make a comment a policy statement: AIR-1.5's own detector. */
	const POLICY_TERMS     = array( 'ai', 'training', 'crawl', 'license', 'licence', 'permission' );
	const POLICY_MIN_CHARS = 120;
	const POLICY_MIN_TERMS = 2;

	/**
	 * Evaluates the fixer.
	 *
	 * @param array<string,mixed> $given Site facts and the owner's choices.
	 * @return array<string,mixed> Evaluation: status, reason, findings, changes, output, baseline, paste.
	 * @throws \InvalidArgumentException When the choices are not ones the fixer offers.
	 */
	public static function evaluate( array $given ): array {
		$site     = $given['site'];
		$choices  = $given['choices'];
		$baseline = (string) $given['baseline']['robots.txt'];
		$allow    = array_values( (array) ( $choices['allow_agents'] ?? array() ) );
		$signals  = $choices['content_signals'] ?? null;
		$policy   = ! empty( $choices['policy'] );

		foreach ( $allow as $agent ) {
			if ( ! in_array( $agent, self::AGENTS, true ) ) {
				throw new \InvalidArgumentException( 'not one of the ten agents' );
			}
		}
		if ( null !== $signals ) {
			$keys = array_keys( (array) $signals );
			sort( $keys );
			$want = self::SIGNAL_KEYS;
			sort( $want );
			if ( $keys !== $want ) {
				throw new \InvalidArgumentException( 'content_signals names all three signals or none' );
			}
			foreach ( $signals as $value ) {
				if ( 'yes' !== $value && 'no' !== $value ) {
					throw new \InvalidArgumentException( 'a signal is yes or no' );
				}
			}
		}
		if ( $policy && null === $signals ) {
			throw new \InvalidArgumentException( 'the policy comment is written from the signals' );
		}

		$lines  = Parser::lines_of( $baseline );
		$groups = Parser::groups( $lines );

		$blocked = array();
		foreach ( self::AGENTS as $agent ) {
			if ( Parser::blanket_blocked( $agent, $groups ) ) {
				$blocked[] = $agent;
			}
		}
		$declared = false;
		foreach ( $lines as $line ) {
			$parsed = Parser::directive( $line );
			if ( null !== $parsed && 'content-signal' === $parsed[0] ) {
				$declared = true;
				break;
			}
		}
		$findings = array(
			'blocked_agents'  => $blocked,
			'content_signals' => $declared ? 'declared' : 'none',
			'policy_comment'  => self::has_policy_comment( $lines ) ? 'present' : 'none',
		);

		$already = false;
		foreach ( $lines as $line ) {
			if ( trim( $line ) === self::BEGIN ) {
				$already = true;
				break;
			}
		}
		$ev_lines = $lines;
		if ( ! $already ) {
			foreach ( $allow as $agent ) {
				$ev_lines = self::allow_agent( $ev_lines, $agent );
			}
		}
		$groups_after = Parser::groups( $ev_lines );

		$block = array();
		// The comment says what the signals say, so it is only written beside signals this fixer
		// wrote: a comment about a position the file does not state, or states differently, is the
		// contradiction AIR-1.5 penalizes.
		$wrote_signals = null !== $signals && 'none' === $findings['content_signals'] && ! $already;
		$wrote_policy  = $policy && $wrote_signals && 'none' === $findings['policy_comment'];
		if ( $wrote_policy ) {
			$block = array_merge( $block, self::policy_lines( (string) $site['name'], $signals ) );
		}
		// Each group the fixer adds is set off by a blank line, as a person would write them.
		if ( $wrote_signals ) {
			$parts = array();
			foreach ( self::SIGNAL_KEYS as $key ) {
				$parts[] = $key . '=' . $signals[ $key ];
			}
			array_push( $block, '', 'User-agent: *', 'Content-Signal: ' . implode( ', ', $parts ) );
		}
		foreach ( $allow as $agent ) {
			if ( ! $already && Parser::blanket_blocked( $agent, $groups_after ) ) {
				array_push( $block, '', 'User-agent: ' . $agent, 'Allow: /' );
			}
		}
		while ( array() !== $block && '' === $block[0] ) {
			array_shift( $block );
		}

		if ( $already || ( array() === $block && $ev_lines === $lines ) ) {
			$output  = $baseline;
			$status  = 'unchanged';
			$reason  = $already ? 'already_present' : 'nothing_to_change';
			$changes = array();
		} else {
			$kept  = rtrim( implode( "\n", $ev_lines ) );
			$parts = array();
			if ( '' !== $kept ) {
				$parts[] = $kept;
			}
			if ( array() !== $block ) {
				$parts[] = implode( "\n", array_merge( array( self::BEGIN ), $block, array( self::END ) ) );
			}
			$output  = implode( "\n\n", $parts ) . "\n";
			$status  = 'ready';
			$reason  = null;
			$changes = array(
				array(
					'target' => 'robots.txt',
					'before' => $baseline,
					'after'  => $output,
				),
			);
		}

		$paste = null;
		if ( 'ready' === $status && ! empty( $given['files']['robots.txt'] ) ) {
			// A real file wins over anything the CMS generates, so the change would never be served.
			// It is previewed all the same, so the owner can paste exactly that text into the file.
			$status = 'conflict';
			$reason = 'physical_file';
			$paste  = $output;
		}
		return array(
			'status'   => $status,
			'reason'   => $reason,
			'findings' => $findings,
			'changes'  => $changes,
			'output'   => array( 'robots.txt' => $output ),
			'baseline' => array( 'robots.txt' => $baseline ),
			'paste'    => $paste,
		);
	}

	/**
	 * `lines` without the indexes in `$delete`, and without the double blank line that deleting
	 * a whole group between two blank lines would leave.
	 *
	 * @param string[] $lines  Lines.
	 * @param int[]    $delete Indexes to remove.
	 * @return string[]
	 */
	private static function drop( array $lines, array $delete ): array {
		$out  = array();
		$gap  = false;
		$gone = array_flip( $delete );
		foreach ( $lines as $index => $line ) {
			if ( isset( $gone[ $index ] ) ) {
				$gap = true;
				continue;
			}
			$blank = '' === trim( $line );
			if ( $blank && $gap && ( array() === $out || '' === trim( $out[ count( $out ) - 1 ] ) ) ) {
				continue;
			}
			if ( ! $blank ) {
				$gap = false;
			}
			$out[] = $line;
		}
		return $out;
	}

	/**
	 * Takes a blanket block off one agent without touching a line that is not about it.
	 *
	 * @param string[] $lines Lines.
	 * @param string   $agent Product token.
	 * @return string[]
	 */
	private static function allow_agent( array $lines, string $agent ): array {
		$delete = array();
		foreach ( Parser::groups( $lines ) as $group ) {
			if ( ! $group->names_agent( $agent ) ) {
				continue;
			}
			$blocks = array();
			foreach ( $group->rules as $rule ) {
				if ( 'disallow' === $rule[1] && '/' === $rule[2] ) {
					$blocks[] = $rule[0];
				}
			}
			if ( array() === $blocks ) {
				continue;
			}
			if ( $group->only_names( $agent ) ) {
				$delete = array_merge( $delete, $blocks );
				if ( count( $group->rules ) === count( $blocks ) ) {
					$delete = array_merge( $delete, $group->ua );
				}
			} else {
				// A group shared with other agents keeps blocking them. This one leaves it.
				foreach ( $group->ua as $i => $index ) {
					if ( 0 === strcasecmp( $group->names[ $i ], $agent ) ) {
						$delete[] = $index;
					}
				}
			}
		}
		return self::drop( $lines, $delete );
	}

	/**
	 * AIR-1.5's reading: the longest run of comment lines (a bare `#` ends a run), joined with
	 * single spaces, long enough and naming enough policy terms.
	 *
	 * @param string[] $lines Lines.
	 */
	private static function has_policy_comment( array $lines ): bool {
		$runs    = array();
		$current = array();
		foreach ( $lines as $line ) {
			$stripped = trim( $line );
			if ( '' === $stripped || '#' !== $stripped[0] ) {
				continue;
			}
			$text = trim( ltrim( $stripped, '#' ) );
			if ( '' !== $text ) {
				$current[] = $text;
			} elseif ( array() !== $current ) {
				$runs[]  = $current;
				$current = array();
			}
		}
		if ( array() !== $current ) {
			$runs[] = $current;
		}
		if ( array() === $runs ) {
			return false;
		}
		$best = '';
		foreach ( $runs as $run ) {
			$joined = implode( ' ', $run );
			// The first longest run wins a tie, as the scanner's own max() does.
			if ( mb_strlen( $joined, 'UTF-8' ) > mb_strlen( $best, 'UTF-8' ) ) {
				$best = $joined;
			}
		}
		$lowered = mb_strtolower( $best, 'UTF-8' );
		$terms   = 0;
		foreach ( self::POLICY_TERMS as $term ) {
			if ( 1 === preg_match( '/\b' . preg_quote( $term, '/' ) . '\b/u', $lowered ) ) {
				++$terms;
			}
		}
		return mb_strlen( $best, 'UTF-8' ) >= self::POLICY_MIN_CHARS && $terms >= self::POLICY_MIN_TERMS;
	}

	/**
	 * The policy comment, one `# ` line per sentence, from the shared sentences.
	 *
	 * @param string               $site_name The site's name.
	 * @param array<string,string> $signals   Signal values.
	 * @return string[]
	 */
	private static function policy_lines( string $site_name, array $signals ): array {
		$vars  = array( 'site_name' => $site_name );
		$lines = array( Strings::get( 'fixer.robots_txt.policy.intro', $vars ) );
		foreach ( self::SIGNAL_KEYS as $key ) {
			$lines[] = Strings::get( 'fixer.robots_txt.policy.' . $key . '.' . $signals[ $key ], $vars );
		}
		$lines[] = Strings::get( 'fixer.robots_txt.policy.outro', $vars );
		return array_map(
			static function ( string $line ): string {
				return '# ' . $line;
			},
			$lines
		);
	}
}
