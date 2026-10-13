<?php
/**
 * Drawing the Fixes tab.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Admin;

use ReadinessIndex\Api\ApiError;
use ReadinessIndex\Api\Client;
use ReadinessIndex\Api\Model\Fix;
use ReadinessIndex\Api\Model\SiteResponse;
use ReadinessIndex\Changes\Log;
use ReadinessIndex\Fixers\Runner;
use ReadinessIndex\Strings;

/**
 * The server's ranked list, each row one of three kinds the local registry decides. Everything
 * the server sent is escaped where it is printed. A hand-it-on prompt quotes the scanned site, so
 * it is only ever put in a `<pre>` as text, is loaded when its row is opened and never before,
 * and is never rendered as HTML.
 */
final class FixesView {

	/**
	 * Prints the tab.
	 *
	 * @param ViewModel   $model   The screen model (the same `GET /site` as the Score tab).
	 * @param array       $query   Display-only query values: `ri_kind` and `ri_open`.
	 * @param array|null  $result  The last apply or undo, or null.
	 * @param Client|null $client  API client for a prompt, or null for the stored one.
	 */
	public static function render( ViewModel $model, array $query = array(), ?array $result = null, ?Client $client = null ): void {
		ScoreView::shell_start( $model, Urls::FIXES );
		self::result( $result );
		if ( ScoreView::without_site( $model ) ) {
			ScoreView::shell_end();
			return;
		}
		$site = $model->site;
		if ( null === $site ) {
			ScoreView::shell_end();
			return;
		}

		echo '<h2>' . esc_html( Strings::get( 'fixes.title' ) ) . '</h2>';
		if ( null === $site->score && 'none' === $site->scan->state ) {
			echo '<p>' . esc_html( Strings::get( 'fixes.no_scan' ) ) . '</p>';
			ScoreView::shell_end();
			return;
		}
		if ( array() === $site->fixes ) {
			echo '<p>' . esc_html( Strings::get( 'fixes.empty' ) ) . '</p>';
			ScoreView::shell_end();
			return;
		}

		$kind  = isset( $query['ri_kind'] ) ? (string) $query['ri_kind'] : 'all';
		$open  = isset( $query['ri_open'] ) ? (string) $query['ri_open'] : '';
		$fixes = FixesModel::build( $site, $kind );

		echo '<p class="ri-reachable">' . esc_html(
			Strings::get(
				'fixes.reachable',
				array(
					'reachable' => $fixes->reachable,
					'total'     => $fixes->total,
				)
			)
		) . '</p>';
		self::filters( $fixes );

		echo '<ol class="ri-fixes">';
		foreach ( $fixes->shown as $row ) {
			self::row( $row, $open, $client );
		}
		echo '</ol>';
		self::copy_all( $fixes );
		ScoreView::shell_end();
	}

	/**
	 * The result of the last apply or undo, as a notice.
	 *
	 * @param array<string,mixed>|null $result Runner result.
	 */
	public static function result( ?array $result ): void {
		if ( null === $result ) {
			return;
		}
		$note = Outcome::notice( $result );
		ScoreView::notice( $note['type'], $note['title'], $note['body'] );
	}

	/**
	 * The kind filter, as links, so it is kept in the URL and works without scripts.
	 *
	 * @param FixesModel $fixes The model.
	 */
	private static function filters( FixesModel $fixes ): void {
		$labels = array(
			'all'                => Strings::get( 'fixes.filter.all' ),
			FixesModel::FIX_HERE => Strings::get( 'fixes.kind.fix_here' ),
			FixesModel::FIX_WITH => Strings::get( 'fixes.kind.fix_with', array( 'tooling' => '' ) ),
			FixesModel::HAND_ON  => Strings::get( 'fixes.kind.hand_on' ),
		);
		echo '<ul class="subsubsub ri-filters">';
		$last = array_key_last( $labels );
		foreach ( $labels as $kind => $label ) {
			$current = $kind === $fixes->filter;
			$url     = 'all' === $kind ? Urls::fixes() : Urls::fixes( array( 'ri_kind' => $kind ) );
			echo '<li><a href="' . esc_url( $url ) . '"' . ( $current ? ' class="current" aria-current="true"' : '' ) . '>' . esc_html( trim( $label ) ) . '</a>' . ( $kind === $last ? '' : ' |' ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput -- Fixed markup around escaped values.
		}
		echo '</ul><br class="clear" />';
	}

	/**
	 * One row.
	 *
	 * @param array<string,mixed> $row    A `FixesModel` row.
	 * @param string              $open   The check whose row is open, from the URL.
	 * @param Client|null         $client API client for a prompt.
	 */
	private static function row( array $row, string $open, ?Client $client ): void {
		$fix       = $row['fix'];
		$is_open   = $open === $fix->check;
		$roles     = implode(
			', ',
			array_map(
				static function ( $role ): string {
					return $role->role;
				},
				$fix->fixed_by
			)
		);
		$tool_name = null !== $row['tooling'] ? $row['tooling']['name'] : '';

		echo '<li class="ri-fix ri-kind-' . esc_attr( str_replace( '_', '-', $row['kind'] ) ) . '" id="fix-' . esc_attr( $fix->check ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput -- Escaped above.
		echo '<details' . ( $is_open ? ' open' : '' ) . ' data-ri-check="' . esc_attr( $fix->check ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput -- Escaped above.
		echo '<summary><span class="ri-rank">' . esc_html( Strings::get( 'fixes.rank', array( 'rank' => $fix->rank ) ) ) . '</span> ';
		echo '<strong class="ri-fix-title">' . esc_html( $fix->title ) . '</strong> ';
		echo '<span class="ri-points">' . esc_html( Strings::get( 'fixes.points', array( 'points' => $fix->points ) ) ) . '</span> ';
		echo '<span class="ri-kind-label">' . esc_html( trim( Strings::get( 'fixes.kind.' . $row['kind'], array( 'tooling' => $tool_name ) ) ) ) . '</span></summary>';

		echo '<div class="ri-fix-body">';
		if ( null !== $fix->why && '' !== $fix->why ) {
			echo '<p class="ri-why">' . esc_html( $fix->why ) . '</p>';
		}
		if ( '' !== $roles ) {
			echo '<p class="ri-fixed-by">' . esc_html( Strings::get( 'fixes.fixed_by', array( 'roles' => $roles ) ) ) . '</p>';
			echo '<ul class="ri-chips">';
			foreach ( $fix->fixed_by as $role ) {
				echo '<li class="ri-chip" title="' . esc_attr( $role->who ) . '">' . esc_html( $role->role ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput -- Escaped.
			}
			echo '</ul>';
		}

		switch ( $row['kind'] ) {
			case FixesModel::FIX_HERE:
				self::fix_here( $row );
				break;
			case FixesModel::FIX_WITH:
				self::fix_with( $row );
				break;
			default:
				self::hand_on( $row, $is_open, $client );
		}
		echo '</div></details></li>';
	}

	/**
	 * Fix it here: what the fixer does, Preview, and Undo when it is on.
	 *
	 * @param array<string,mixed> $row A row.
	 */
	private static function fix_here( array $row ): void {
		$fixer = $row['fixer'];
		echo '<p class="ri-fixer"><strong>' . esc_html( $fixer->title() ) . '</strong> ' . esc_html( $fixer->describe() ) . '</p>';
		echo '<p><a class="button button-primary" href="' . esc_url( Urls::preview( $fixer->id(), (array) ( $fixer->state() ?? array() ) ) ) . '">' . esc_html( Strings::get( 'fixes.preview' ) ) . '</a>';
		$applied = Log::latest_applied( $fixer->id() );
		if ( null !== $applied ) {
			echo ' ';
			self::undo_form( (int) $applied['id'] );
		}
		echo '</p>';
	}

	/**
	 * Fix it with a tool the site has: its name, the exact field and a link.
	 *
	 * @param array<string,mixed> $row A row.
	 */
	private static function fix_with( array $row ): void {
		$tool = $row['tooling'];
		echo '<p class="ri-fix-with">' . esc_html( Strings::get( 'fixes.fix_with.field', array( 'setting' => $tool['setting'] ) ) ) . '</p>';
		echo '<p><a class="button" href="' . esc_url( $tool['url'] ) . '">' . esc_html( Strings::get( 'fixes.fix_with.open', array( 'tooling' => $tool['name'] ) ) ) . '</a></p>';
	}

	/**
	 * Hand it on: the explanation, a diagnostic where there is one, and the prompt.
	 *
	 * @param array<string,mixed> $row     A row.
	 * @param bool                $is_open Whether the row is the open one, so the prompt is loaded now.
	 * @param Client|null         $client  API client.
	 */
	private static function hand_on( array $row, bool $is_open, ?Client $client ): void {
		$fix = $row['fix'];
		echo '<p class="ri-hand-on">' . esc_html( Strings::get( 'fixes.hand_on.explain' ) ) . '</p>';
		if ( null !== $row['diagnostic'] ) {
			DiagnosticView::render( $row['diagnostic'] );
		}

		echo '<div class="ri-prompt-box" data-ri-check="' . esc_attr( $fix->check ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput -- Escaped.
		if ( $is_open ) {
			self::prompt( $fix->check, $client );
		} else {
			// Loaded on demand: by the script when the row opens, and by this link without one.
			echo '<p class="ri-prompt-load"><a class="button" href="' . esc_url( Urls::fixes( array( 'ri_open' => $fix->check ) ) . '#fix-' . rawurlencode( $fix->check ) ) . '">' . esc_html( Strings::get( 'fixes.hand_on.copy' ) ) . '</a></p>';
		}
		echo '</div>';
	}

	/**
	 * Loads the fix prompt now and prints it as text, with its copy button.
	 *
	 * @param string      $check  Test id.
	 * @param Client|null $client API client.
	 */
	private static function prompt( string $check, ?Client $client ): void {
		try {
			$prompt = ( $client ?? new Client() )->fix_prompt( $check );
		} catch ( ApiError $e ) {
			echo '<p class="ri-prompt-error">' . esc_html( $e->getMessage() ) . '</p>';
			return;
		}
		$text = (string) $prompt->untrusted_prompt;
		echo '<p class="description">' . esc_html( Strings::get( 'fixes.hand_on.untrusted' ) ) . '</p>';
		// A `<pre>` with escaped text: the prompt quotes the scanned site, so it is never HTML.
		echo '<pre class="ri-prompt" tabindex="0">' . esc_html( $text ) . '</pre>';
		echo '<p><button type="button" class="button ri-copy" data-ri-copy-from=".ri-prompt" data-ri-copied="' . esc_attr( Strings::get( 'common.copied' ) ) . '">' . esc_html( Strings::get( 'fixes.hand_on.copy' ) ) . '</button></p>';
	}

	/**
	 * The Undo button: a POST form with its nonce.
	 *
	 * @param int $change The applied row's id.
	 */
	public static function undo_form( int $change ): void {
		echo '<form method="post" class="ri-form" style="display:inline" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput -- Fixed markup.
		echo '<input type="hidden" name="action" value="' . esc_attr( Runner::UNDO_ACTION ) . '" />';
		echo '<input type="hidden" name="change" value="' . esc_attr( (string) $change ) . '" />';
		wp_nonce_field( Runner::UNDO_ACTION );
		echo '<button type="submit" class="button">' . esc_html( Strings::get( 'fixes.undo' ) ) . '</button></form>';
	}

	/**
	 * The whole list as Markdown, in a text area, with a copy button when scripts run.
	 *
	 * @param FixesModel $fixes The model.
	 */
	private static function copy_all( FixesModel $fixes ): void {
		$lines   = array();
		$lines[] = '# ' . Strings::get( 'fixes.title' );
		$lines[] = '';
		foreach ( $fixes->rows as $row ) {
				$fix = $row['fix'];
			$lines[] = '- **' . Strings::get( 'fixes.rank', array( 'rank' => $fix->rank ) ) . '** ' . self::inline( $fix->title ) . ' (' . Strings::get( 'fixes.points', array( 'points' => $fix->points ) ) . ')';
		}
		echo '<details class="ri-copy-all"><summary>' . esc_html( Strings::get( 'fixes.copy_all' ) ) . '</summary>';
		echo '<textarea readonly="readonly" rows="' . esc_attr( (string) min( 20, count( $lines ) + 1 ) ) . '" class="large-text code" id="ri-markdown">' . esc_textarea( implode( "\n", $lines ) . "\n" ) . '</textarea>';
		echo '<p><button type="button" class="button ri-copy" data-ri-copy-from="#ri-markdown" data-ri-copied="' . esc_attr( Strings::get( 'common.copied' ) ) . '">' . esc_html( Strings::get( 'common.copy' ) ) . '</button></p>';
		echo '</details>';
	}

	/**
	 * A title on one line, with the characters that would make Markdown of it removed.
	 *
	 * @param string $text A title.
	 */
	private static function inline( string $text ): string {
		return trim( preg_replace( '/\s+/', ' ', preg_replace( '/[\r\n`*_\[\]<>]+/', ' ', $text ) ?? '' ) ?? '' );
	}
}
