<?php
/**
 * Drawing a fixer's preview page.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Admin;

use ReadinessIndex\Fixers\Fixer;
use ReadinessIndex\Fixers\Runner;
use ReadinessIndex\Fixers\SeoTools;
use ReadinessIndex\Strings;

/**
 * A page of its own, so it works without scripts and can be linked to. Rendering it is a GET and
 * changes nothing: the only way to apply is the Apply form at the bottom, a POST with a nonce
 * that carries the hash of exactly the preview shown here. If the site changes between this
 * page and the click, the hash no longer matches and the preview is shown again.
 */
final class PreviewView {

	/**
	 * Prints the page.
	 *
	 * @param ViewModel           $model   A model with only the flash filled in.
	 * @param Fixer               $fixer   The fixer.
	 * @param array<string,mixed> $choices The choices being previewed.
	 * @param array|null          $result  The last result, shown when the preview was re-shown.
	 */
	public static function render( ViewModel $model, Fixer $fixer, array $choices, ?array $result = null ): void {
		ScoreView::shell_start( $model, Urls::PREVIEW );
		FixesView::result( $result );

		$preview = Runner::preview( $fixer, $choices );

		echo '<h2>' . esc_html( $fixer->title() ) . '</h2>';
		echo '<p>' . esc_html( $fixer->describe() ) . '</p>';
		self::scope( $fixer );
		ChoiceForms::render( $fixer, $choices );
		self::status( $preview );

		if ( 'ready' === $preview['status'] || 'conflict' === $preview['status'] ) {
			self::changes( $preview );
		}
		FindingsView::render( $fixer->id(), $preview );
		self::apply_form( $fixer, $choices, $preview );
		ScoreView::shell_end();
	}

	/**
	 * Who the change affects and what it will not do.
	 *
	 * @param Fixer $fixer The fixer.
	 */
	private static function scope( Fixer $fixer ): void {
		$key = str_replace( '-', '_', $fixer->id() );
		echo '<ul class="ri-scope">';
		echo '<li><strong>' . esc_html( Strings::get( 'preview.affects' ) ) . '</strong> ' . esc_html( Strings::get( 'fixer.' . $key . '.affects' ) ) . '</li>';
		echo '<li><strong>' . esc_html( Strings::get( 'preview.wont' ) ) . '</strong> ' . esc_html( Strings::get( 'fixer.' . $key . '.wont' ) ) . '</li>';
		echo '</ul>';
	}

	/**
	 * The sentence for the preview's status: what the screen can do.
	 *
	 * @param array<string,mixed> $preview The preview.
	 */
	private static function status( array $preview ): void {
		$tooling = '';
		if ( isset( $preview['findings']['deferred'][0]['tooling'] ) ) {
			$tooling = SeoTools::name( (string) $preview['findings']['deferred'][0]['tooling'] );
		}
		$lines = array();
		switch ( $preview['status'] ) {
			case 'unchanged':
				$lines[] = 'already_present' === $preview['reason'] ? Strings::get( 'fixes.already_present' ) : Strings::get( 'fixes.nothing_to_change' );
				break;
			case 'invalid':
				$lines[] = Outcome::reason( 'choices_incomplete' );
				break;
		}
		foreach ( $preview['conflicts'] as $reason ) {
			$lines[] = Outcome::reason( (string) $reason, $tooling );
		}
		if ( array() === $lines && isset( $preview['findings']['tooling'] ) && 'through_tooling' === ( $preview['findings']['mode'] ?? '' ) ) {
			$lines[] = Strings::get( 'fixes.through_tooling', array( 'tooling' => SeoTools::name( (string) $preview['findings']['tooling'] ) ) );
		}
		foreach ( $lines as $line ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html( $line ) . '</p></div>';
		}
	}

	/**
	 * The exact change: before and after, side by side, with the lines that differ marked, and
	 * the text to paste when the change cannot be applied here.
	 *
	 * @param array<string,mixed> $preview The preview.
	 */
	private static function changes( array $preview ): void {
		foreach ( $preview['changes'] as $change ) {
			$diff = Diff::lines( (string) $change['before'], (string) $change['after'] );
			echo '<div class="ri-change-box">';
			echo '<h3>' . esc_html( (string) $change['target'] ) . '</h3>';
			echo '<div class="ri-diff">';
			self::column( Strings::get( 'fixes.before' ), $diff['before'] );
			self::column( Strings::get( 'fixes.after' ), $diff['after'] );
			echo '</div></div>';
		}
		if ( null !== $preview['paste'] ) {
			echo '<div class="ri-paste"><h3>' . esc_html( Strings::get( 'fixes.paste' ) ) . '</h3>';
			echo '<textarea readonly="readonly" rows="12" class="large-text code" id="ri-paste">' . esc_textarea( (string) $preview['paste'] ) . '</textarea>';
			echo '<p><button type="button" class="button ri-copy" data-ri-copy-from="#ri-paste" data-ri-copied="' . esc_attr( Strings::get( 'common.copied' ) ) . '">' . esc_html( Strings::get( 'common.copy' ) ) . '</button></p></div>';
		}
	}

	/**
	 * One column of the diff. The mark is text as well as colour, so it reads without either.
	 *
	 * @param string                              $title Column heading.
	 * @param array<int,array{0:string,1:string}> $lines Marked lines.
	 */
	private static function column( string $title, array $lines ): void {
		echo '<div class="ri-diff-col"><h4>' . esc_html( $title ) . '</h4><pre class="ri-pre">';
		foreach ( $lines as $line ) {
			$class = '-' === $line[0] ? 'ri-del' : ( '+' === $line[0] ? 'ri-add' : 'ri-same' );
			$mark  = '=' === $line[0] ? ' ' : $line[0];
			echo '<span class="' . esc_attr( $class ) . '"><span aria-hidden="true">' . esc_html( $mark ) . ' </span>' . esc_html( $line[1] ) . "</span>\n";
		}
		echo '</pre></div>';
	}

	/**
	 * Apply and Cancel. Apply is disabled when there is nothing to apply, a conflict, or the
	 * choices are not yet complete; the form carries the preview's hash.
	 *
	 * @param Fixer               $fixer   The fixer.
	 * @param array<string,mixed> $choices The choices previewed.
	 * @param array<string,mixed> $preview The preview.
	 */
	private static function apply_form( Fixer $fixer, array $choices, array $preview ): void {
		$can = ! empty( $preview['can_apply'] );
		echo '<form method="post" class="ri-apply" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( Runner::APPLY_ACTION ) . '" />';
		echo '<input type="hidden" name="fixer" value="' . esc_attr( $fixer->id() ) . '" />';
		echo '<input type="hidden" name="ri_hash" value="' . esc_attr( $preview['hash'] ) . '" />';
		self::hidden_choices( $choices, 'choices' );
		wp_nonce_field( Runner::APPLY_ACTION );
		echo '<p><button type="submit" class="button button-primary"' . ( $can ? '' : ' disabled="disabled"' ) . '>' . esc_html( Strings::get( 'fixes.apply' ) ) . '</button> '; // phpcs:ignore WordPress.Security.EscapeOutput -- Fixed markup.
		echo '<a class="button" href="' . esc_url( Urls::fixes() ) . '">' . esc_html( Strings::get( 'common.cancel' ) ) . '</a></p>';
		echo '</form>';
	}

	/**
	 * The choices as hidden fields, nested the way the form that made them named them.
	 *
	 * @param mixed  $value  A choice or a map of them.
	 * @param string $prefix Field name so far.
	 */
	private static function hidden_choices( $value, string $prefix ): void {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $inner ) {
				self::hidden_choices( $inner, $prefix . '[' . $key . ']' );
			}
			return;
		}
		if ( null === $value ) {
			return;
		}
		$text = true === $value ? '1' : ( false === $value ? '0' : (string) $value );
		echo '<input type="hidden" name="' . esc_attr( $prefix ) . '" value="' . esc_attr( $text ) . '" />';
	}
}
