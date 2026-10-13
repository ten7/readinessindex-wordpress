<?php
/**
 * Drawing a diagnostic inside a hand-it-on row.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Admin;

use ReadinessIndex\Diagnostics\AltText;
use ReadinessIndex\Diagnostics\Result;
use ReadinessIndex\Diagnostics\Runner;
use ReadinessIndex\Strings;

/**
 * A diagnostic says what the plugin can see and why it does not fix it. Alt text is counted
 * from the media library when the row is drawn. The other two make requests to the site itself, so
 * they run when the owner presses Check now, and the last result is kept and shown with a button
 * that copies it as Markdown.
 */
final class DiagnosticView {

	/**
	 * Prints one diagnostic's title, explanation and, where there is one, its last result.
	 *
	 * @param string $key `alt_text`, `cdn` or `js_only`.
	 */
	public static function render( string $key ): void {
		echo '<div class="ri-diagnostic ri-diagnostic-' . esc_attr( $key ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput -- Escaped.
		echo '<h4>' . esc_html( Strings::get( 'diagnostic.' . $key . '.title' ) ) . '</h4>';
		if ( 'alt_text' === $key ) {
			echo '<p>' . esc_html( Strings::get( 'diagnostic.alt_text.body', array( 'count' => AltText::count() ) ) ) . '</p>';
			echo '<p><a class="button" href="' . esc_url( AltText::url() ) . '">' . esc_html( Strings::get( 'diagnostic.alt_text.open' ) ) . '</a></p>';
		} else {
			echo '<p>' . esc_html( Strings::get( 'diagnostic.' . $key . '.body' ) ) . '</p>';
			self::last( $key );
			echo '<form method="post" class="ri-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="' . esc_attr( Actions::DIAGNOSE ) . '" />';
			echo '<input type="hidden" name="diagnostic" value="' . esc_attr( $key ) . '" />';
			wp_nonce_field( Actions::DIAGNOSE );
			echo '<button type="submit" class="button">' . esc_html( Strings::get( 'diagnostic.run' ) ) . '</button></form>';
		}
		echo '</div>';
	}

	/**
	 * The last result, with its Copy button, or a line saying there is none.
	 *
	 * @param string $key Diagnostic key.
	 */
	private static function last( string $key ): void {
		$result = Runner::last( $key );
		if ( ! $result instanceof Result ) {
			echo '<p class="description">' . esc_html( Strings::get( 'diagnostic.not_run' ) ) . '</p>';
			return;
		}
		echo '<p class="description">' . esc_html( Strings::get( 'diagnostic.last_run', array( 'date' => ScoreView::date( gmdate( 'c', $result->at ) ) ) ) ) . '</p>';
		foreach ( $result->lines as $line ) {
			echo '<p>' . esc_html( $line ) . '</p>';
		}
		if ( array() !== $result->items ) {
			echo '<ul>';
			foreach ( $result->items as $item ) {
				echo '<li>' . esc_html( $item ) . '</li>';
			}
			echo '</ul>';
		}
		$id = 'ri-md-' . $key;
		echo '<textarea readonly="readonly" class="large-text code" rows="6" id="' . esc_attr( $id ) . '">' . esc_textarea( $result->to_markdown( Strings::get( 'diagnostic.' . $key . '.title' ) ) ) . '</textarea>';
		echo '<p><button type="button" class="button ri-copy" data-ri-copy-from="#' . esc_attr( $id ) . '" data-ri-copied="' . esc_attr( Strings::get( 'common.copied' ) ) . '">' . esc_html( Strings::get( 'diagnostic.copy' ) ) . '</button></p>';
	}
}
