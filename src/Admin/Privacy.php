<?php
/**
 * The plugin's paragraph for the site's privacy policy.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Admin;

use ReadinessIndex\Strings;

/**
 * Adds to Settings, Privacy, Policy Guide what this plugin does with data, so the site owner can
 * copy it into their policy. It is the readme's External services section in the owner's words.
 */
final class Privacy {

	/**
	 * Registers the suggested text.
	 */
	public static function add_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content( Strings::get( 'app.name' ), wp_kses_post( wpautop( Strings::get( 'privacy.policy' ) ) ) );
	}
}
