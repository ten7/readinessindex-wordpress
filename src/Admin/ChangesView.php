<?php
/**
 * Drawing the Changes tab.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Admin;

use ReadinessIndex\Changes\Log;
use ReadinessIndex\Fixers\Registry;
use ReadinessIndex\Settings;
use ReadinessIndex\Strings;

/**
 * Every change the plugin made, newest first, from its own table: no call to readinessindex.io,
 * so it works unconnected, offline and after the key is revoked. Undo appears on an applied row
 * that nothing has replaced. An undo is a row of its own.
 */
final class ChangesView {

	const PER_PAGE = 50;

	/**
	 * Prints the tab.
	 *
	 * @param ViewModel                $model  A model with only the flash filled in.
	 * @param array<string,mixed>|null $result The last apply or undo.
	 * @param int                      $paged  The page of results, from one.
	 */
	public static function render( ViewModel $model, ?array $result = null, int $paged = 1 ): void {
		ScoreView::shell_start( $model, Urls::CHANGES );
		FixesView::result( $result );

		echo '<h2>' . esc_html( Strings::get( 'changes.title' ) ) . '</h2>';
		$total = Log::count();
		if ( 0 === $total ) {
			echo '<p>' . esc_html( Strings::get( 'changes.empty' ) ) . '</p>';
		} else {
			$paged = max( 1, $paged );
			self::table( Log::recent( self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE ) );
			self::pager( $total, $paged );
		}
		self::settings();
		ScoreView::shell_end();
	}

	/**
	 * The table.
	 *
	 * @param array<int,array<string,mixed>> $rows Log rows, newest first.
	 */
	private static function table( array $rows ): void {
		echo '<table class="widefat striped ri-changes"><thead><tr>';
		foreach ( array( 'what', 'when', 'who', 'action' ) as $column ) {
			echo '<th scope="col">' . esc_html( Strings::get( 'changes.col.' . $column ) ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr class="ri-change ri-change-' . esc_attr( $row['action'] . '-' . $row['status'] ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput -- Escaped.
			echo '<td>' . esc_html( self::what( $row ) ) . '</td>';
			echo '<td>' . esc_html( self::when( $row['created_gmt'] ) ) . '</td>';
			echo '<td>' . esc_html( self::who( (int) $row['user_id'] ) ) . '</td>';
			echo '<td>';
			if ( Log::APPLY === $row['action'] && Log::APPLIED === $row['status'] ) {
				FixesView::undo_form( (int) $row['id'] );
			} elseif ( Log::SUPERSEDED === $row['status'] ) {
				echo esc_html( Strings::get( 'changes.superseded' ) );
			} elseif ( Log::FAILED === $row['status'] ) {
				echo esc_html( Strings::get( 'changes.failed' ) );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * The "what" cell: an apply or an undo, naming the fixer, and whether it was live.
	 *
	 * @param array<string,mixed> $row A log row.
	 */
	private static function what( array $row ): string {
		$fixer = Registry::get( $row['fixer'] );
		$name  = null === $fixer ? $row['fixer'] : $fixer->title();
		$key   = Log::UNDO === $row['action'] ? 'changes.undone' : 'changes.applied';
		$text  = Strings::get( $key, array( 'fixer' => $name ) );
		if ( Log::APPLY === $row['action'] && Log::APPLIED === $row['status'] && null !== $row['verified'] ) {
			$text .= ' ' . ( $row['verified'] ? Strings::get( 'fixes.live.yes' ) : Strings::get( 'fixes.live.no' ) );
		}
		return $text;
	}

	/**
	 * A GMT time from the table in the site's own date and time format.
	 *
	 * @param string $gmt `Y-m-d H:i:s` in GMT.
	 */
	private static function when( string $gmt ): string {
		$time = strtotime( $gmt . ' UTC' );
		if ( false === $time ) {
			return Strings::get( 'common.never' );
		}
		return wp_date( get_option( 'date_format', 'F j, Y' ) . ' ' . get_option( 'time_format', 'g:i a' ), $time );
	}

	/**
	 * Who made the change: the user's display name now, or nothing when the account is gone.
	 *
	 * @param int $user_id User id.
	 */
	private static function who( int $user_id ): string {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		return false === $user ? '' : (string) $user->display_name;
	}

	/**
	 * Previous and next links, when there is more than one page.
	 *
	 * @param int $total Rows in all.
	 * @param int $paged This page.
	 */
	private static function pager( int $total, int $paged ): void {
		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages < 2 ) {
			return;
		}
		echo '<p class="ri-pager">';
		if ( $paged > 1 ) {
			echo '<a class="button" href="' . esc_url( Urls::to( Urls::CHANGES, array( 'paged' => $paged - 1 ) ) ) . '">&larr;</a> ';
		}
		if ( $paged < $pages ) {
			echo '<a class="button" href="' . esc_url( Urls::to( Urls::CHANGES, array( 'paged' => $paged + 1 ) ) ) . '">&rarr;</a>';
		}
		echo '</p>';
	}

	/**
	 * The one setting: whether deleting the plugin also removes what it changed.
	 */
	private static function settings(): void {
		echo '<form method="post" class="ri-settings-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( Actions::SAVE_SETTINGS ) . '" />';
		wp_nonce_field( Actions::SAVE_SETTINGS );
		echo '<p><label><input type="checkbox" name="remove_on_uninstall" value="1"' . checked( Settings::remove_on_uninstall(), true, false ) . ' /> ' . esc_html( Strings::get( 'changes.remove_on_delete' ) ) . '</label></p>';
		echo '<p class="description">' . esc_html( Strings::get( 'changes.deactivation_note' ) ) . '</p>';
		echo '<p><button type="submit" class="button">' . esc_html( Strings::get( 'changes.save' ) ) . '</button></p>';
		echo '</form>';
	}
}
