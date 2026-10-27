<?php
/**
 * The one notice outside the Score screen's own content.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Admin;

use ReadinessIndex\Strings;

/**
 * On a network-activated install, that each site connects on its own. It is printed on this
 * plugin's screen only, and an administrator can dismiss it for good: a notice that cannot be
 * closed and reappears on every load teaches people to ignore the notices that matter.
 */
final class Notices {

	const DISMISS_ACTION = 'readiness_index_dismiss_network';
	const DISMISS_META   = 'readiness_index_dismissed_network';

	/**
	 * Hooks the notice.
	 */
	public static function register(): void {
		add_action( 'admin_notices', array( self::class, 'network' ) );
	}

	/**
	 * Whether the notice is for this person on this screen.
	 *
	 * @param bool   $multisite       Whether this is a multisite install.
	 * @param bool   $network_active  Whether the plugin is active for the whole network.
	 * @param string $screen_id       The current admin screen's id.
	 * @param bool   $dismissed       Whether this user closed it.
	 */
	public static function should_show( bool $multisite, bool $network_active, string $screen_id, bool $dismissed ): bool {
		return $multisite && $network_active && ! $dismissed && false !== strpos( $screen_id, ScoreView::SCREEN_SLUG );
	}

	/**
	 * Prints the notice with its Dismiss button.
	 */
	public static function network(): void {
		if ( ! is_multisite() || ! current_user_can( Actions::CAPABILITY ) ) {
			return;
		}
		$screen    = get_current_screen();
		$screen_id = null === $screen ? '' : (string) $screen->id;
		$dismissed = '1' === (string) get_user_meta( get_current_user_id(), self::DISMISS_META, true );
		$show      = self::should_show( true, is_plugin_active_for_network( plugin_basename( READINESS_INDEX_FILE ) ), $screen_id, $dismissed );
		if ( ! $show ) {
			return;
		}
		echo '<div class="notice notice-info"><p>' . esc_html( Strings::get( 'network.notice' ) ) . '</p>';
		ScoreView::form( self::DISMISS_ACTION, Strings::get( 'network.dismiss' ), 'button-link', false, true );
		echo '</div>';
	}

	/**
	 * Closes the notice for this user. Capability, then nonce, then back to the screen.
	 */
	public static function handle_dismiss(): void {
		Actions::guard( self::DISMISS_ACTION );
		update_user_meta( get_current_user_id(), self::DISMISS_META, '1' );
		wp_safe_redirect( ScoreView::screen_url() );
		exit;
	}
}
