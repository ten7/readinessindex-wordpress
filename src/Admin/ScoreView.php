<?php
/**
 * Drawing the Score screen.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Admin;

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
		$poll = $model->polling ? ' data-ri-poll="1"' : '';
		echo '<div class="wrap readiness-index"' . $poll . '>'; // phpcs:ignore WordPress.Security.EscapeOutput -- Fixed markup, no variable data.
		// The empty hr is where WordPress moves its own admin notices to, below the heading.
		echo '<h1>' . esc_html( Strings::get( 'app.name' ) ) . '</h1><hr class="wp-header-end" />';

		self::flash( $model );
		if ( null !== $model->site && $model->site->update_required() ) {
			self::notice( 'warning', Strings::get( 'state.update_required.title' ), Strings::get( 'state.update_required.body' ) );
		}
		if ( $model->returned ) {
			self::notice( 'success', '', Strings::get( 'upgrade.returned' ) );
		}

		if ( $model->confirming_disconnect && Settings::is_connected() ) {
			self::confirm_disconnect();
		} else {
			self::body( $model );
		}
		echo '</div>';
	}

	/**
	 * The screen's body for the model's state.
	 *
	 * @param ViewModel $model The model.
	 */
	private static function body( ViewModel $model ): void {
		switch ( $model->state ) {
			case ViewModel::UNCONNECTED:
				self::unconnected( $model );
				return;
			case ViewModel::OFFLINE:
				self::panel( Strings::get( 'state.offline.title' ), Strings::get( 'state.offline.body' ) );
				self::form( 'readiness_index_refresh', Strings::get( 'common.retry' ), 'button' );
				return;
			case ViewModel::DISABLED:
				self::panel( Strings::get( 'state.disabled.title' ), Strings::get( 'state.disabled.body' ) );
				return;
			case ViewModel::REVOKED:
				self::revoked( $model );
				return;
			case ViewModel::RATE_LIMITED:
				self::panel( '', Strings::get( 'state.rate_limited.body' ) );
				return;
			case ViewModel::ERROR:
				self::panel( '', null !== $model->error ? $model->error->getMessage() : Strings::get( 'error.internal_error' ) );
				self::form( 'readiness_index_refresh', Strings::get( 'common.retry' ), 'button' );
				return;
		}

		// A site answer, fresh or the last one: the state of its newest scan.
		$site = $model->site;
		if ( null === $site ) {
			return;
		}

		self::scan_state( $model, $site );
		self::quota_notice( $model, $site );
		if ( null !== $site->score ) {
			self::score( $site, $site->score );
		}
		self::rescans( $model, $site );
		self::upgrade( $model, $site );
		self::ownership( $site );
		self::settings( $model );
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
			self::score( $model->site, $model->site->score );
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
		self::panel( Strings::get( 'state.quota.title' ), Strings::get( 'state.quota.body', array( 'message' => $message ) ) );
	}

	/**
	 * The score, grade, verdict, dimensions table, change and the facts beneath.
	 *
	 * @param SiteResponse $site  The answer.
	 * @param Score        $score The score.
	 */
	private static function score( SiteResponse $site, Score $score ): void {
		echo '<div class="card ri-card ri-score">';
		echo '<h2>' . esc_html( Strings::get( 'score.title' ) ) . '</h2>';
		echo '<p class="ri-score-line"><strong class="ri-score-value">' . esc_html( Strings::get( 'score.value', array( 'score' => $score->value ) ) ) . '</strong> ';
		echo '<span class="ri-grade">' . esc_html( Strings::get( 'score.grade', array( 'grade' => $score->grade ) ) ) . '</span></p>';
		echo '<p class="ri-verdict">' . esc_html( $score->verdict ) . '</p>';
		if ( $score->gated ) {
			echo '<p class="ri-gated">' . esc_html( Strings::get( 'score.gated' ) ) . '</p>';
		}
		echo '<p class="ri-change">' . esc_html( self::change_sentence( $score ) ) . '</p>';

		echo '<table class="widefat striped ri-dimensions"><caption>' . esc_html( Strings::get( 'score.dimensions' ) ) . '</caption><tbody>';
		foreach ( $score->dimensions as $dimension ) {
			$row = Strings::get(
				'score.dimension_row',
				array(
					'name'     => $dimension->name,
					'earned'   => $dimension->earned,
					'possible' => $dimension->possible,
				)
			);
			echo '<tr><td>' . esc_html( $row ) . '</td><td class="ri-meter" aria-hidden="true"><meter min="0" max="' . esc_attr( (string) $dimension->possible ) . '" value="' . esc_attr( (string) $dimension->earned ) . '"></meter></td></tr>';
		}
		echo '</tbody></table>';

		echo '<p class="ri-facts">';
		echo esc_html( Strings::get( 'score.scanned_on', array( 'date' => self::date( $score->finished_at ) ) ) );
		if ( null !== $site->scan->pages_read ) {
			echo ' &middot; ' . esc_html( Strings::get( 'score.pages_read', array( 'pages' => $site->scan->pages_read ) ) );
		}
		if ( null !== $site->index ) {
			echo ' &middot; ' . esc_html( Strings::get( 'score.index', array( 'version' => $site->index->version ) ) );
		}
		echo '</p>';
		if ( $site->allowance->lifetime ) {
			echo '<p class="ri-sample">' . esc_html( Strings::get( 'score.sample_note' ) ) . '</p>';
		}
		echo '</div>';
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
			return Strings::get( 'score.change.up', array( 'delta' => $change->score_change ) );
		}
		if ( $change->score_change < 0 ) {
			return Strings::get( 'score.change.down', array( 'delta' => abs( $change->score_change ) ) );
		}
		return Strings::get( 'score.change.same' );
	}

	/**
	 * The rescans left, in the sentence that fits a free or a paid account.
	 *
	 * @param ViewModel    $model The model.
	 * @param SiteResponse $site  The answer.
	 */
	private static function rescans( ViewModel $model, SiteResponse $site ): void {
		$allowance = $site->allowance;
		if ( null === $allowance->rescans_left || null === $allowance->rescans_total ) {
			return;
		}
		$vars = array(
			'left'  => $allowance->rescans_left,
			'total' => $allowance->rescans_total,
		);
		echo '<p class="ri-rescans">';
		if ( $allowance->lifetime ) {
			echo esc_html( Strings::get( 'score.rescans.left', $vars ) ) . ' ' . esc_html( Strings::get( 'score.rescans.lifetime', $vars ) );
		} else {
			echo esc_html( Strings::get( 'score.rescans.monthly', $vars ) );
		}
		echo '</p>';
	}

	/**
	 * The upgrade offer and the pack link, from the allowance alone.
	 *
	 * @param ViewModel    $model The model.
	 * @param SiteResponse $site  The answer.
	 */
	private static function upgrade( ViewModel $model, SiteResponse $site ): void {
		$origin = Settings::origin();
		$offer  = Upgrade::offer( $site->allowance, null !== $site->score, $model->monitoring_dismissed, self::screen_url(), $origin );
		if ( null !== $offer ) {
			echo '<div class="card ri-card ri-upgrade ri-upgrade-' . esc_attr( $offer['key'] ) . '">';
			echo '<h2>' . esc_html( $offer['label'] ) . '</h2>';
			echo '<p>' . esc_html( $offer['body'] ) . '</p>';
			echo '<p><a class="button button-primary" href="' . esc_url( $offer['url'], self::protocols( $origin ) ) . '">' . esc_html( $offer['button'] ) . '</a>';
			if ( $offer['dismissible'] ) {
				echo ' ';
				self::form( 'readiness_index_dismiss', Strings::get( 'common.close' ), 'button-link', false, true );
			}
			echo '</p></div>';
		}
		$pack = Upgrade::pack_url( $site->allowance, $origin );
		if ( '' !== $pack ) {
			echo '<p class="ri-pack"><a href="' . esc_url( $pack, self::protocols( $origin ) ) . '">' . esc_html( Strings::get( 'upgrade.pack' ) ) . '</a></p>';
		}
	}

	/**
	 * Ownership: verified, or not yet with the button to check.
	 *
	 * @param SiteResponse $site The answer.
	 */
	private static function ownership( SiteResponse $site ): void {
		echo '<div class="ri-ownership">';
		if ( $site->site->verified ) {
			echo '<p>' . esc_html( Strings::get( 'score.verified' ) ) . '</p>';
		} else {
			echo '<p>' . esc_html( Strings::get( 'score.not_verified' ) ) . '</p>';
			echo '<p class="description">' . esc_html( Strings::get( 'verify.serving' ) ) . '</p>';
			self::form( 'readiness_index_verify', Strings::get( 'verify.check' ), 'button' );
		}
		echo '</div>';
	}

	/**
	 * The small settings section: who this is connected as, and Disconnect.
	 *
	 * @param ViewModel $model The model.
	 */
	private static function settings( ViewModel $model ): void {
		echo '<div class="ri-settings">';
		if ( '' !== $model->account_label ) {
			echo '<p>' . esc_html( Strings::get( 'settings.account', array( 'account' => $model->account_label ) ) ) . '</p>';
		}
		if ( ! $model->key_is_constant ) {
			echo '<p><a class="button" href="' . esc_url( self::screen_url( array( 'ri_confirm' => 'disconnect' ) ) ) . '">' . esc_html( Strings::get( 'common.disconnect' ) ) . '</a></p>';
		}
		echo '</div>';
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
	private static function panel( string $title, string $body, bool $live = false ): void {
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
	private static function flash( ViewModel $model ): void {
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
	private static function notice( string $type, string $title, string $body ): void {
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
	private static function form( string $action, string $label, string $classes, bool $disabled = false, bool $inline = false ): void {
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
	private static function date( ?string $iso ): string {
		$time = null === $iso ? false : strtotime( $iso );
		if ( false === $time ) {
			return Strings::get( 'common.never' );
		}
		return wp_date( (string) get_option( 'date_format', 'F j, Y' ), $time );
	}

	/**
	 * The protocols a link from the server may use: https, and http for a local origin only.
	 *
	 * @param string $origin The API origin.
	 * @return array<int,string>
	 */
	private static function protocols( string $origin ): array {
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
