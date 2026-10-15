<?php
/**
 * Drawing the Score screen.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Admin;

use ReadinessIndex\Api\Model\Allowance;
use ReadinessIndex\Api\Model\Score;
use ReadinessIndex\Api\Model\SiteResponse;
use ReadinessIndex\Connect\Connector;
use ReadinessIndex\Settings;
use ReadinessIndex\Strings;

/**
 * Prints one screen per state of `ViewModel`, in native wp-admin markup.
 *
 * Every sentence is looked up in the shared strings, and every value from the API is escaped
 * at the point it is printed, with the function that matches its context. Nothing the API or
 * the scanned site wrote is ever interpreted: not as HTML, not as a URL without an allowed
 * protocol, not as a callback.
 *
 * Nothing here knows a price, a plan name or what an account may buy. The upgrade surfaces
 * come from `Upgrade`, which reads the server's allowance.
 */
final class ScoreView {

	const SCREEN_SLUG = 'readiness-index';

	/**
	 * Prints the whole screen.
	 *
	 * @param ViewModel $model The model.
	 */
	public static function render( ViewModel $model ): void {
		self::shell_start( $model, Urls::SCORE );
		if ( $model->confirming_disconnect && Settings::is_connected() ) {
			self::confirm_disconnect();
		} else {
			self::body( $model );
		}
		self::shell_end();
	}

	/**
	 * Opens the page every tab shares: heading, tabs, the last action's result and the notices
	 * that sit above whatever else is shown.
	 *
	 * @param ViewModel $model The model.
	 * @param string    $tab   The tab being drawn, a slug from `Urls`.
	 */
	public static function shell_start( ViewModel $model, string $tab ): void {
		$poll = $model->polling && Urls::SCORE === $tab ? ' data-ri-poll="1"' : '';
		echo '<div class="wrap readiness-index"' . $poll . '>'; // phpcs:ignore WordPress.Security.EscapeOutput -- Fixed markup, no variable data.
		// The empty hr is where WordPress moves its own admin notices to, below the heading.
		echo '<h1>' . esc_html( Strings::get( 'app.name' ) ) . '</h1><hr class="wp-header-end" />';
		Tabs::render( $tab );

		self::flash( $model );
		if ( null !== $model->site && $model->site->update_required() ) {
			self::notice( 'warning', Strings::get( 'state.update_required.title' ), Strings::get( 'state.update_required.body' ) );
		}
		if ( $model->returned ) {
			self::notice( 'success', '', Strings::get( 'upgrade.returned' ) );
		}
	}

	/**
	 * Closes the page `shell_start` opened.
	 */
	public static function shell_end(): void {
		echo '</div>';
	}

	/**
	 * The screen's body for the model's state.
	 *
	 * @param ViewModel $model The model.
	 */
	private static function body( ViewModel $model ): void {
		if ( self::without_site( $model ) ) {
			return;
		}

		// A site answer, fresh or the last one: the state of its newest scan.
		$site = $model->site;
		if ( null === $site ) {
			return;
		}

		self::quota_notice( $model, $site );
		if ( null !== $site->score ) {
			// A score on screen: the five sections, with the state of the newest scan in the
			// status line above the heading and the actions beside the rescan count.
			echo '<div class="ri-sections">';
			self::score_section( $model, $site, $site->score, true );
			self::breakdown( $site->score );
			self::details( $site, $site->score );
			self::watch( $model, $site );
			self::connection( $model, $site );
			echo '</div>';
			return;
		}

		// No score yet: the state's own panel, the scan button and what remains of the account.
		self::scan_state( $model, $site );
		echo '<div class="ri-sections">';
		self::rescans_line( $site->allowance );
		self::pack_link( $site );
		self::watch( $model, $site );
		self::connection( $model, $site );
		echo '</div>';
	}

	/**
	 * Draws the states that have no site answer to show (unconnected, offline, disabled,
	 * revoked, rate limited with nothing remembered, an error), for every tab alike.
	 *
	 * @param ViewModel $model The model.
	 * @return bool Whether one was drawn, in which case the tab has nothing more to show.
	 */
	public static function without_site( ViewModel $model ): bool {
		switch ( $model->state ) {
			case ViewModel::UNCONNECTED:
				self::unconnected( $model );
				return true;
			case ViewModel::OFFLINE:
				self::panel( Strings::get( 'state.offline.title' ), Strings::get( 'state.offline.body' ) );
				self::form( 'readiness_index_refresh', Strings::get( 'common.retry' ), 'button' );
				return true;
			case ViewModel::DISABLED:
				self::panel( Strings::get( 'state.disabled.title' ), Strings::get( 'state.disabled.body' ) );
				return true;
			case ViewModel::REVOKED:
				self::revoked( $model );
				return true;
			case ViewModel::RATE_LIMITED:
				self::panel( '', Strings::get( 'state.rate_limited.body' ) );
				return true;
			case ViewModel::ERROR:
				self::panel( '', null !== $model->error ? $model->error->getMessage() : Strings::get( 'error.internal_error' ) );
				self::form( 'readiness_index_refresh', Strings::get( 'common.retry' ), 'button' );
				return true;
		}

		return false;
	}

	/**
	 * Unconnected: two sentences, a button, and what the pre-flight check found.
	 *
	 * @param ViewModel $model The model.
	 */
	private static function unconnected( ViewModel $model ): void {
		echo '<div class="card ri-card">';
		echo '<h2>' . esc_html( Strings::get( 'state.unconnected.title' ) ) . '</h2>';
		echo '<p>' . esc_html( Strings::get( 'state.unconnected.body' ) ) . '</p>';
		echo '<p>' . esc_html( Strings::get( 'connect.intro' ) ) . '</p>';
		if ( $model->waiting ) {
			echo '<p class="ri-waiting" role="status">' . esc_html( Strings::get( 'connect.waiting' ) ) . '</p>';
		}
		foreach ( $model->preflight as $key ) {
			echo '<p class="ri-preflight">' . esc_html( Strings::get( $key ) ) . '</p>';
		}
		self::form( Connector::ACTION_START, Strings::get( 'connect.button' ), 'button button-primary' );
		echo '</div>';
	}

	/**
	 * Revoked: said once, with Connect again, and the last score beneath.
	 *
	 * @param ViewModel $model The model.
	 */
	private static function revoked( ViewModel $model ): void {
		echo '<div class="card ri-card">';
		echo '<h2>' . esc_html( Strings::get( 'state.revoked.title' ) ) . '</h2>';
		echo '<p>' . esc_html( Strings::get( 'state.revoked.body' ) ) . '</p>';
		echo '<h3>' . esc_html( Strings::get( 'state.reconnect.title' ) ) . '</h3>';
		echo '<p>' . esc_html( Strings::get( 'state.reconnect.body' ) ) . '</p>';
		if ( $model->waiting ) {
			echo '<p class="ri-waiting" role="status">' . esc_html( Strings::get( 'connect.waiting' ) ) . '</p>';
		}
		self::form( Connector::ACTION_START, Strings::get( 'common.connect' ), 'button button-primary' );
		echo '</div>';
		if ( null !== $model->site && null !== $model->site->score ) {
			// The last answer, read only: the key is gone, so there is nothing to scan with and
			// the sections that act on the connection are left out.
			echo '<div class="ri-sections">';
			self::score_section( $model, $model->site, $model->site->score, false );
			self::breakdown( $model->site->score );
			self::details( $model->site, $model->site->score );
			echo '</div>';
		}
	}

	/**
	 * The state of the newest scan: never scanned, pending, running, done or failed.
	 *
	 * @param ViewModel    $model The model.
	 * @param SiteResponse $site  The answer.
	 */
	private static function scan_state( ViewModel $model, SiteResponse $site ): void {
		$spent = $site->allowance->is_spent();
		switch ( $model->state ) {
			case ViewModel::NEVER_SCANNED:
				if ( ! $spent ) {
					self::panel( Strings::get( 'state.never_scanned.title' ), Strings::get( 'state.never_scanned.body' ) );
					self::scan_button( $site, false );
				}
				break;
			case ViewModel::PENDING:
				self::panel( Strings::get( 'state.pending.title' ), Strings::get( 'state.pending.body' ), true );
				self::scan_button( $site, true );
				break;
			case ViewModel::RUNNING:
				self::panel( Strings::get( 'state.running.title' ), Strings::get( 'state.running.body_unknown' ), true );
				self::scan_button( $site, true );
				break;
			case ViewModel::FAILED:
				$problem = null !== $site->scan->problem ? $site->scan->problem : Strings::get( 'error.internal_error' );
				self::panel( Strings::get( 'state.failed.title' ), $problem );
				echo '<p>' . esc_html( Strings::get( 'state.failed.hint' ) ) . '</p>';
				if ( ! $spent ) {
					self::scan_button( $site, false );
				}
				break;
			case ViewModel::DONE:
				// A finished scan with no score is a contract oddity; say so as it always was.
				self::panel( Strings::get( 'state.done.title' ), '' );
				if ( ! $spent ) {
					self::scan_button( $site, false );
				}
				break;
		}
	}

	/**
	 * Quota: no rescans left. The refusal's own words when a scan was just refused.
	 *
	 * @param ViewModel    $model The model.
	 * @param SiteResponse $site  The answer.
	 */
	private static function quota_notice( ViewModel $model, SiteResponse $site ): void {
		$refused = null !== $model->flash && 'scan' === $model->flash['kind'] && 'quota' === $model->flash['code'];
		if ( ! $site->allowance->is_spent() && ! $refused ) {
			return;
		}
		$message = ( $refused && '' !== $model->flash['message'] ) ? $model->flash['message'] : Strings::get( 'error.quota' );
		self::notice( 'warning', Strings::get( 'state.quota.title' ), Strings::get( 'state.quota.body', array( 'message' => $message ) ) );
	}

	/**
	 * Section 1, the score: the state of the newest scan as a plain status line, the heading, the
	 * number, the grade with its sentences, the actions row and the link to the full report.
	 *
	 * @param ViewModel    $model   The model.
	 * @param SiteResponse $site    The answer.
	 * @param Score        $score   The score.
	 * @param bool         $actions Whether the answer is live, so the scan form and the count belong here.
	 */
	private static function score_section( ViewModel $model, SiteResponse $site, Score $score, bool $actions ): void {
		echo '<section class="card ri-card ri-score" aria-labelledby="ri-score-title">';
		self::status_line( $model, $site, $score );
		echo '<h2 id="ri-score-title">' . esc_html( Strings::get( 'score.title' ) ) . '</h2>';

		$value = Strings::around( 'score.value', 'score' );
		echo '<p class="ri-score-line">' . esc_html( $value[0] ) . '<strong class="ri-score-value">' . esc_html( (string) $score->value ) . '</strong><span class="ri-score-of">' . esc_html( $value[1] ) . '</span></p>';

		echo '<p class="ri-grade-line"><strong>' . esc_html( Strings::get( 'score.grade_line', array( 'grade' => $score->grade ) ) ) . '</strong> ' . esc_html( $score->verdict ) . ' ' . esc_html( self::change_sentence( $score ) ) . '</p>';
		if ( $score->gated ) {
			echo '<p class="ri-gated">' . esc_html( Strings::get( 'score.gated' ) ) . '</p>';
		}

		if ( $actions ) {
			echo '<div class="ri-actions">';
			self::scan_button( $site, $site->scan->is_active() || $site->allowance->is_spent() );
			self::rescans_text( $site->allowance );
			echo '</div>';
		}
		self::report_link( $score );
		if ( $actions ) {
			self::pack_link( $site );
		}
		echo '</section>';
	}

	/**
	 * The status line above the heading. It is the one place the state of the newest scan is
	 * said once there is a score, and the polling script rewrites its two spans.
	 *
	 * @param ViewModel    $model The model.
	 * @param SiteResponse $site  The answer.
	 * @param Score        $score The score on screen.
	 */
	private static function status_line( ViewModel $model, SiteResponse $site, Score $score ): void {
		$live = ' id="ri-state" aria-live="polite"';
		switch ( $model->state ) {
			case ViewModel::PENDING:
				$title = Strings::get( 'state.pending.title' );
				$body  = Strings::get( 'state.pending.body' );
				break;
			case ViewModel::RUNNING:
				$title = Strings::get( 'state.running.title' );
				$body  = Strings::get( 'state.running.body_unknown' );
				break;
			case ViewModel::FAILED:
				echo '<p class="ri-status" role="status">' . esc_html( Strings::get( 'score.status.failed' ) ) . '</p>';
				$problem = null !== $site->scan->problem ? $site->scan->problem : '';
				if ( '' !== $problem ) {
					echo '<p class="ri-problem">' . esc_html( Strings::get( 'state.failed.body', array( 'problem' => $problem ) ) ) . '</p>';
				}
				return;
			default:
				echo '<p class="ri-status" role="status">' . esc_html( Strings::get( 'score.status.finished', array( 'date' => self::datetime( $score->finished_at ) ) ) ) . '</p>';
				return;
		}
		echo '<p class="ri-status" role="status"' . $live . '><span id="ri-state-title">' . esc_html( $title ) . '</span> <span id="ri-state-body">' . esc_html( $body ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- $live is fixed markup, the rest is escaped above.
	}

	/**
	 * Section 2, the breakdown: a real table in the server's order, the weakest row or rows in
	 * bold with the word for it, so the finding never rests on a colour or on the bar.
	 *
	 * @param Score $score The score.
	 */
	private static function breakdown( Score $score ): void {
		$weakest = self::weakest( $score->dimensions );
		echo '<section class="card ri-card ri-breakdown" aria-labelledby="ri-breakdown-title">';
		echo '<h2 id="ri-breakdown-title">' . esc_html( Strings::get( 'score.dimensions' ) ) . '</h2>';
		echo '<table class="widefat striped ri-dimensions"><thead><tr>';
		echo '<th scope="col">' . esc_html( Strings::get( 'score.breakdown.category' ) ) . '</th>';
		echo '<th scope="col" class="ri-num">' . esc_html( Strings::get( 'tab.score' ) ) . '</th>';
		echo '<th scope="col" class="ri-meter"><span class="screen-reader-text">' . esc_html( Strings::get( 'score.breakdown.progress' ) ) . '</span></th>';
		echo '</tr></thead><tbody>';
		foreach ( $score->dimensions as $i => $dimension ) {
			$name = self::sentence_case( $dimension->name );
			echo '<tr><th scope="row">';
			if ( isset( $weakest[ $i ] ) ) {
				echo '<strong>' . esc_html( $name ) . '</strong> <span class="ri-weakest">' . esc_html( Strings::get( 'score.breakdown.weakest' ) ) . '</span>';
			} else {
				echo esc_html( $name );
			}
			echo '</th><td class="ri-num"><strong>' . esc_html( (string) $dimension->earned ) . '</strong> / ' . esc_html( (string) $dimension->possible ) . '</td>';
			echo '<td class="ri-meter"><meter min="0" max="' . esc_attr( (string) $dimension->possible ) . '" value="' . esc_attr( (string) $dimension->earned ) . '" aria-label="' . esc_attr( $name ) . '"></meter></td></tr>';
		}
		echo '</tbody></table></section>';
	}

	/**
	 * The rows with the lowest share of their points, by index. Compared by cross-multiplying, so
	 * no fraction is ever rounded into a tie. When every row has the same share, or a row has no
	 * points to earn, nothing is flagged: "weakest" would be noise.
	 *
	 * @param array<int,\ReadinessIndex\Api\Model\Dimension> $dimensions The rows, in the server's order.
	 * @return array<int,true>
	 */
	public static function weakest( array $dimensions ): array {
		$low  = null;
		$high = null;
		foreach ( $dimensions as $d ) {
			if ( $d->possible <= 0 ) {
				continue;
			}
			if ( null === $low || $d->earned * $low->possible < $low->earned * $d->possible ) {
				$low = $d;
			}
			if ( null === $high || $d->earned * $high->possible > $high->earned * $d->possible ) {
				$high = $d;
			}
		}
		$out = array();
		if ( null === $low || null === $high || $low->earned * $high->possible === $high->earned * $low->possible ) {
			return $out;
		}
		foreach ( $dimensions as $i => $d ) {
			if ( $d->possible > 0 && $d->earned * $low->possible === $low->earned * $d->possible ) {
				$out[ $i ] = true;
			}
		}
		return $out;
	}

	/**
	 * Sentence case for a category's name: the first word as it came, every later word that is
	 * Capitalized and nothing else made lowercase. A word with a second capital (an acronym such
	 * as JSON-LD or AI) is left alone.
	 *
	 * @param string $name The name the server gave.
	 */
	public static function sentence_case( string $name ): string {
		$first = true;
		$out   = preg_replace_callback(
			'/\p{L}[\p{L}\p{M}]*/u',
			static function ( array $m ) use ( &$first ): string {
				if ( $first ) {
					$first = false;
					return $m[0];
				}
				return 1 === preg_match( '/^\p{Lu}\p{Ll}*$/u', $m[0] ) ? mb_strtolower( $m[0] ) : $m[0];
			},
			$name
		);
		return null === $out ? $name : $out;
	}

	/**
	 * Section 3, the scan details: a description list. The sampling sentence belongs to Pages
	 * read and is said on a free plan only.
	 *
	 * @param SiteResponse $site  The answer.
	 * @param Score        $score The score.
	 */
	private static function details( SiteResponse $site, Score $score ): void {
		echo '<section class="card ri-card ri-details" aria-labelledby="ri-details-title">';
		echo '<h2 id="ri-details-title">' . esc_html( Strings::get( 'score.details.title' ) ) . '</h2><dl class="ri-dl">';
		echo '<dt>' . esc_html( Strings::get( 'score.details.scanned' ) ) . '</dt><dd>' . esc_html( self::datetime( $score->finished_at ) ) . '</dd>';
		if ( null !== $site->scan->pages_read ) {
			$pages = Strings::get( 'score.details.pages', array( 'pages' => $site->scan->pages_read ) );
			if ( $site->allowance->lifetime ) {
				$pages .= ' ' . Strings::get( 'score.sample_note' );
			}
			echo '<dt>' . esc_html( Strings::get( 'score.details.pages_read' ) ) . '</dt><dd>' . esc_html( $pages ) . '</dd>';
		}
		if ( null !== $site->index ) {
			echo '<dt>' . esc_html( Strings::get( 'score.details.index_version' ) ) . '</dt><dd>' . esc_html( $site->index->version ) . '</dd>';
		}
		echo '</dl></section>';
	}

	/**
	 * The link to the full report on readinessindex.io, for the finished scan the numbers above
	 * come from. It opens in a new tab with `noopener`, so the report page cannot reach back
	 * into wp-admin through `window.opener`. The token is the API's, so it is encoded as a path
	 * segment and an empty one draws nothing rather than a link to the report index.
	 *
	 * @param Score $score The score.
	 */
	private static function report_link( Score $score ): void {
		$url = self::report_url( $score );
		if ( '' === $url ) {
			return;
		}
		echo '<p class="ri-report"><a href="' . esc_url( $url, self::protocols( Settings::origin() ) ) . '" target="_blank" rel="noopener">' . esc_html( Strings::get( 'score.view_report' ) ) . '</a></p>';
	}

	/**
	 * The address of the full report of a finished scan: the configured API origin, the same one
	 * the client uses, then `/report/` and the scan's token. Empty when there is no token.
	 *
	 * @param Score $score The score.
	 */
	public static function report_url( Score $score ): string {
		if ( '' === trim( $score->scan ) ) {
			return '';
		}
		return Settings::origin() . '/report/' . rawurlencode( $score->scan );
	}

	/**
	 * The change line: up, down, same, not comparable, or first.
	 *
	 * @param Score $score The score.
	 */
	private static function change_sentence( Score $score ): string {
		$change = $score->change;
		if ( null === $change || null === $change->comparable ) {
			return Strings::get( 'score.change.first' );
		}
		if ( false === $change->comparable || null === $change->score_change ) {
			return Strings::get( 'score.change.not_comparable' );
		}
		if ( $change->score_change > 0 ) {
			return Strings::get( 'score.since.up', array( 'delta' => $change->score_change ) );
		}
		if ( $change->score_change < 0 ) {
			return Strings::get( 'score.since.down', array( 'delta' => abs( $change->score_change ) ) );
		}
		return Strings::get( 'score.since.same' );
	}

	/**
	 * The rescan count as one element for the actions row: the numbers in bold and a proper
	 * sentence after them, which for a free account is followed by what the allowance means.
	 * With nothing left it says so in one sentence instead. A paid plan with metering reads the
	 * server's numbers and never the free-account sentence, which only `allowance.lifetime` earns.
	 *
	 * @param Allowance $allowance The server's allowance.
	 */
	private static function rescans_text( Allowance $allowance ): void {
		if ( null === $allowance->rescans_left || null === $allowance->rescans_total ) {
			return;
		}
		echo '<span class="ri-rescans">';
		if ( $allowance->is_spent() ) {
			echo esc_html( Strings::get( $allowance->lifetime ? 'score.rescans.none' : 'score.rescans.none_monthly' ) );
		} else {
			$count = Strings::get(
				'score.rescans.count',
				array(
					'left'  => $allowance->rescans_left,
					'total' => $allowance->rescans_total,
				)
			);
			$parts = Strings::around( $allowance->lifetime ? 'score.rescans.line' : 'score.rescans.line_monthly', 'count' );
			echo esc_html( $parts[0] ) . '<strong>' . esc_html( $count ) . '</strong>' . esc_html( $parts[1] );
			if ( $allowance->lifetime ) {
				echo ' ' . esc_html( Strings::get( 'score.rescans.lifetime', array( 'total' => $allowance->rescans_total ) ) );
			}
		}
		echo '</span>';
	}

	/**
	 * The rescan count on its own line, for a screen with no score and so no actions row.
	 *
	 * @param Allowance $allowance The server's allowance.
	 */
	private static function rescans_line( Allowance $allowance ): void {
		if ( null === $allowance->rescans_left || null === $allowance->rescans_total ) {
			return;
		}
		echo '<p class="ri-actions">';
		self::rescans_text( $allowance );
		echo '</p>';
	}

	/**
	 * Section 4, the weekly watch, as an aside below the data. What it offers, and whether it
	 * is offered at all, is the server's `next_step`: nothing when there is none or the person
	 * is already subscribed, and nothing once this user chose Not now. The button is a link to
	 * the server's address and secondary, because the one primary button on the screen is Scan again.
	 *
	 * @param ViewModel    $model The model.
	 * @param SiteResponse $site  The answer.
	 */
	private static function watch( ViewModel $model, SiteResponse $site ): void {
		$origin = Settings::origin();
		$offer  = Upgrade::offer( $site->allowance, null !== $site->score, $model->monitoring_dismissed, self::screen_url(), $origin );
		if ( null === $offer ) {
			return;
		}
		echo '<aside class="card ri-card ri-watch ri-upgrade-' . esc_attr( $offer['key'] ) . '" aria-labelledby="ri-watch-title">';
		echo '<h2 id="ri-watch-title">' . esc_html( $offer['label'] ) . '</h2>';
		echo '<p>' . esc_html( $offer['body'] ) . '</p>';
		echo '<div class="ri-actions"><a class="button" href="' . esc_url( $offer['url'], self::protocols( $origin ) ) . '">' . esc_html( $offer['button'] ) . '</a>';
		if ( $offer['dismissible'] ) {
			self::form( 'readiness_index_dismiss', Strings::get( 'watch.not_now' ), 'button-link', false, true );
		}
		echo '</div></aside>';
	}

	/**
	 * The pack link, only when the server says a pack can be bought right now.
	 *
	 * @param SiteResponse $site The answer.
	 */
	private static function pack_link( SiteResponse $site ): void {
		$origin = Settings::origin();
		$pack   = Upgrade::pack_url( $site->allowance, $origin );
		if ( '' !== $pack ) {
			echo '<p class="ri-pack"><a href="' . esc_url( $pack, self::protocols( $origin ) ) . '">' . esc_html( Strings::get( 'upgrade.pack' ) ) . '</a></p>';
		}
	}

	/**
	 * Section 5, the connection: ownership and who this is connected as, then Disconnect apart
	 * from the data. "Connected as" is the host the key is bound to, which the site answer
	 * carries; the plan label the Connect callback stored is a different thing and printed
	 * "Connected as Full report" before this.
	 *
	 * @param ViewModel    $model The model.
	 * @param SiteResponse $site  The answer.
	 */
	private static function connection( ViewModel $model, SiteResponse $site ): void {
		echo '<section class="card ri-card ri-connection" aria-labelledby="ri-connection-title">';
		echo '<h2 id="ri-connection-title">' . esc_html( Strings::get( 'connection.title' ) ) . '</h2><dl class="ri-dl">';
		echo '<dt>' . esc_html( Strings::get( 'connection.ownership' ) ) . '</dt><dd>';
		if ( $site->site->verified ) {
			echo esc_html( Strings::get( 'connection.verified' ) );
		} else {
			echo esc_html( Strings::get( 'connection.not_verified' ) ) . ' ' . esc_html( Strings::get( 'verify.serving' ) );
			self::form( 'readiness_index_verify', Strings::get( 'verify.check' ), 'button' );
		}
		echo '</dd>';
		if ( '' !== $site->site->host ) {
			echo '<dt>' . esc_html( Strings::get( 'connection.connected_as' ) ) . '</dt><dd>' . esc_html( $site->site->host ) . '</dd>';
		}
		echo '</dl>';
		if ( ! $model->key_is_constant ) {
			// A form that only asks for the confirm screen, so it is a button without a nonce: it
			// changes nothing, and the confirm step carries the nonce of the disconnect itself.
			echo '<form method="get" class="ri-form" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
			echo '<input type="hidden" name="page" value="' . esc_attr( self::SCREEN_SLUG ) . '" /><input type="hidden" name="ri_confirm" value="disconnect" />';
			echo '<button type="submit" class="button ri-danger">' . esc_html( Strings::get( 'common.disconnect' ) ) . '</button></form>';
		}
		echo '</section>';
	}

	/**
	 * The confirm step before Disconnect.
	 */
	private static function confirm_disconnect(): void {
		echo '<div class="card ri-card">';
		echo '<p>' . esc_html( Strings::get( 'settings.disconnect.confirm' ) ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="readiness_index_disconnect" />';
		wp_nonce_field( 'readiness_index_disconnect' );
		echo '<button type="submit" class="button button-primary">' . esc_html( Strings::get( 'common.disconnect' ) ) . '</button> ';
		echo '<a class="button" href="' . esc_url( self::screen_url() ) . '">' . esc_html( Strings::get( 'common.cancel' ) ) . '</a>';
		echo '</form></div>';
	}

	/**
	 * The Scan now button, disabled while a scan is pending or running.
	 *
	 * @param SiteResponse $site     The answer.
	 * @param bool         $disabled Whether a scan is already under way.
	 */
	private static function scan_button( SiteResponse $site, bool $disabled ): void {
		$label = null === $site->score ? Strings::get( 'score.scan_now' ) : Strings::get( 'score.scan_again' );
		self::form( 'readiness_index_scan', $label, 'button button-primary', $disabled );
	}

	/**
	 * A titled panel. A running panel carries the ids the polling script updates.
	 *
	 * @param string $title Title, or empty for none.
	 * @param string $body  Body, or empty for none.
	 * @param bool   $live  Whether the polling script rewrites it.
	 */
	public static function panel( string $title, string $body, bool $live = false ): void {
		echo '<div class="card ri-card" id="ri-state"' . ( $live ? ' aria-live="polite"' : '' ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput -- Fixed markup.
		if ( '' !== $title ) {
			echo '<h2 id="ri-state-title">' . esc_html( $title ) . '</h2>';
		}
		if ( '' !== $body ) {
			echo '<p id="ri-state-body">' . esc_html( $body ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * The result of the last action, as a wp-admin notice.
	 *
	 * @param ViewModel $model The model.
	 */
	public static function flash( ViewModel $model ): void {
		$flash = $model->flash;
		if ( null === $flash ) {
			return;
		}
		$lines = self::flash_lines( $flash );
		if ( null === $lines ) {
			return;
		}
		self::notice( $lines['type'], $lines['title'], $lines['body'] );
	}

	/**
	 * Maps a flash to a notice type and sentences.
	 *
	 * @param array{kind:string,code:string,message:string,reason:string} $flash The flash.
	 * @return array{type:string,title:string,body:string}|null Null for a message the screen shows elsewhere.
	 */
	public static function flash_lines( array $flash ): ?array {
		$kind = $flash['kind'];
		$code = $flash['code'];

		if ( 'connect' === $kind ) {
			$map = array(
				Connector::CONNECTED      => array( 'success', Strings::get( 'connect.success', array( 'account' => Settings::connection()['plan'] ) ) ),
				Connector::DENIED         => array( 'info', Strings::get( 'connect.denied' ) ),
				Connector::STATE_MISMATCH => array( 'error', Strings::get( 'connect.state_mismatch' ) ),
				Connector::INVALID        => array( 'error', Strings::get( 'error.internal_error' ) ),
			);
			if ( isset( $map[ $code ] ) ) {
				$text = $map[ $code ][1];
				if ( Connector::CONNECTED === $code ) {
					$text .= ' ' . Strings::get( 'connect.disconnect_hint' );
				}
				return array(
					'type'  => $map[ $code ][0],
					'title' => '',
					'body'  => $text,
				);
			}
			return null;
		}
		if ( 'disconnect' === $kind ) {
			return array(
				'type'  => 'success',
				'title' => '',
				'body'  => Strings::get( 'settings.disconnect.done' ),
			);
		}
		if ( 'verify' === $kind ) {
			$verified = 'verified' === $code;
			$key      = 'verify.' . ( 'not_found' === $code ? 'not_found' : 'error' );
			return array(
				'type'  => $verified ? 'success' : 'warning',
				'title' => '',
				'body'  => $verified ? Strings::get( 'verify.verified' ) : Strings::get( $key ),
			);
		}
		if ( 'scan' === $kind ) {
			if ( 'quota' === $code ) {
				return null; // Drawn as the quota panel.
			}
			if ( 'unreachable' === $code ) {
				$sentence = 'unreachable.' . $flash['reason'];
				return array(
					'type'  => 'warning',
					'title' => Strings::get( 'state.unreachable.title' ),
					'body'  => Strings::has( $sentence ) ? Strings::get( $sentence ) : $flash['message'],
				);
			}
			return array(
				'type'  => 'error',
				'title' => '',
				'body'  => '' !== $flash['message'] ? $flash['message'] : Strings::get( 'error.internal_error' ),
			);
		}
		return null;
	}

	/**
	 * A wp-admin notice.
	 *
	 * @param string $type  success, info, warning or error.
	 * @param string $title Bold lead, or empty.
	 * @param string $body  Sentence.
	 */
	public static function notice( string $type, string $title, string $body ): void {
		$class = in_array( $type, array( 'success', 'info', 'warning', 'error' ), true ) ? $type : 'info';
		echo '<div class="notice notice-' . esc_attr( $class ) . '" role="status">';
		if ( '' !== $title ) {
			echo '<p><strong>' . esc_html( $title ) . '</strong></p>';
		}
		echo '<p>' . esc_html( $body ) . '</p></div>';
	}

	/**
	 * A one-button POST form to an `admin-post` action, with its nonce.
	 *
	 * @param string $action   The `admin-post` action, which is also the nonce action.
	 * @param string $label    Button text.
	 * @param string $classes  Button classes.
	 * @param bool   $disabled Whether the button is disabled.
	 * @param bool   $inline   Whether the form is displayed inline.
	 */
	public static function form( string $action, string $label, string $classes, bool $disabled = false, bool $inline = false ): void {
		echo '<form method="post" class="ri-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( $inline ? ' style="display:inline"' : '' ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput -- Fixed markup.
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
		wp_nonce_field( $action );
		echo '<button type="submit" class="' . esc_attr( $classes ) . '"' . ( $disabled ? ' disabled="disabled"' : '' ) . '>' . esc_html( $label ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput -- Fixed markup.
		echo '</form>';
	}

	/**
	 * A date in the site's own format, or `common.never`.
	 *
	 * @param string|null $iso An ISO 8601 time.
	 */
	public static function date( ?string $iso ): string {
		$time = null === $iso ? false : strtotime( $iso );
		if ( false === $time ) {
			return Strings::get( 'common.never' );
		}
		return wp_date( (string) get_option( 'date_format', 'F j, Y' ), $time );
	}

	/**
	 * A date and time in the site's own formats, or `common.never`. Every date on the Score
	 * screen goes through this one function, so the status line and Scan details cannot differ.
	 *
	 * @param string|null $iso An ISO 8601 time.
	 */
	public static function datetime( ?string $iso ): string {
		$time = null === $iso ? false : strtotime( $iso );
		if ( false === $time ) {
			return Strings::get( 'common.never' );
		}
		return wp_date( (string) get_option( 'date_format', 'F j, Y' ) . ' ' . (string) get_option( 'time_format', 'g:i a' ), $time );
	}

	/**
	 * The protocols a link from the server may use: https, and http for a local origin only.
	 *
	 * @param string $origin The API origin.
	 * @return array<int,string>
	 */
	public static function protocols( string $origin ): array {
		return 0 === strpos( $origin, 'http://' ) ? array( 'https', 'http' ) : array( 'https' );
	}

	/**
	 * This screen's address.
	 *
	 * @param array<string,string> $args Extra query arguments.
	 */
	public static function screen_url( array $args = array() ): string {
		$url = admin_url( 'admin.php?page=' . self::SCREEN_SLUG );
		foreach ( $args as $name => $value ) {
			$url .= '&' . rawurlencode( $name ) . '=' . rawurlencode( $value );
		}
		return $url;
	}
}
