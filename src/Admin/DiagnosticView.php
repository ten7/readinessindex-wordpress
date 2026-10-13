<?php
/**
 * Drawing a diagnostic inside a hand-it-on row.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Admin;

use ReadinessIndex\Strings;

/**
 * A diagnostic says what the plugin can see and why it does not fix it.
 */
final class DiagnosticView {

	/**
	 * Prints one diagnostic's title and explanation.
	 *
	 * @param string $key `alt_text`, `cdn` or `js_only`.
	 */
	public static function render( string $key ): void {
		echo '<div class="ri-diagnostic ri-diagnostic-' . esc_attr( $key ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput -- Escaped.
		echo '<h4>' . esc_html( Strings::get( 'diagnostic.' . $key . '.title' ) ) . '</h4>';
		echo '</div>';
	}
}
