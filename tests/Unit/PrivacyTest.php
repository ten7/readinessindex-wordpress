<?php
namespace ReadinessIndex\Tests;

use Brain\Monkey\Functions;
use ReadinessIndex\Admin\Privacy;
use ReadinessIndex\Strings;
use ReadinessIndex\Tests\Support\WpTestCase;

/**
 * The paragraph the plugin adds to the privacy policy guide says what it does and no more.
 */
final class PrivacyTest extends WpTestCase {

	public function test_the_policy_text_is_added_once_under_the_plugins_name_and_matches_the_readme_claims(): void {
		Functions\when( 'wpautop' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\expect( 'wp_add_privacy_policy_content' )->once()->with( 'Readiness Index', \Mockery::type( 'string' ) );
		Privacy::add_policy_content();

		$text = Strings::get( 'privacy.policy' );
		$this->assertStringContainsString( 'no information about visitors or users', $text );
		$this->assertStringContainsString( 'readinessindex.io', $text );
		$this->assertStringContainsString( "this site's own pages", $text );
	}
}
