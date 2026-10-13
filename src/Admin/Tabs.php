<?php
/**
 * The three tabs: Score, Fixes, Changes.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Admin;

use ReadinessIndex\Strings;

/**
 * Plain links in WordPress's own tab markup. Each tab is a page of its own, so a tab is
 * reachable without scripts, linkable and in the browser's history. The preview page belongs to
 * the Fixes tab and keeps it highlighted.
 */
final class Tabs {

	/**
	 * Prints the tabs.
	 *
	 * @param string $current The slug of the tab being drawn.
	 */
	public static function render( string $current ): void {
		if ( Urls::PREVIEW === $current ) {
			$current = Urls::FIXES;
		}
		$tabs = array(
			Urls::SCORE   => array( Strings::get( 'tab.score' ), Urls::score() ),
			Urls::FIXES   => array( Strings::get( 'tab.fixes' ), Urls::fixes() ),
			Urls::CHANGES => array( Strings::get( 'tab.changes' ), Urls::changes() ),
		);
		echo '<nav class="nav-tab-wrapper ri-tabs" aria-label="' . esc_attr( Strings::get( 'menu.title' ) ) . '">';
		foreach ( $tabs as $slug => $tab ) {
			$active = $slug === $current;
			echo '<a class="nav-tab' . ( $active ? ' nav-tab-active' : '' ) . '" href="' . esc_url( $tab[1] ) . '"' . ( $active ? ' aria-current="page"' : '' ) . '>' . esc_html( $tab[0] ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput -- Fixed markup around escaped values.
		}
		echo '</nav>';
	}
}
