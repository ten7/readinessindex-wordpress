<?php
namespace ReadinessIndex\Tests;

use ReadinessIndex\Connect\Preflight;
use ReadinessIndex\Tests\Support\WpTestCase;

final class PreflightTest extends WpTestCase {

	private function notices( array $over = array() ): array {
		$args = array_merge(
			array(
				'home'        => 'https://example.com',
				'server'      => array(),
				'blog_public' => true,
				'maintenance' => false,
				'robots'      => null,
			),
			$over
		);
		return Preflight::notices( $args['home'], $args['server'], $args['blog_public'], $args['maintenance'], $args['robots'] );
	}

	public function test_a_clean_public_site_has_nothing_to_explain(): void {
		$this->assertSame( array(), $this->notices() );
	}

	public function test_private_insecure_and_local_hosts_are_explained(): void {
		foreach ( array( 'http://example.com', 'https://localhost', 'https://127.0.0.1', 'https://192.168.1.5', 'https://10.0.0.2', 'https://[::1]', 'https://mysite.test', 'https://shop.local', 'https://web', 'https://x.localhost' ) as $home ) {
			$this->assertSame( array( 'connect.site_unreachable' ), $this->notices( array( 'home' => $home ) ), $home );
		}
		$this->assertSame( array(), $this->notices( array( 'home' => 'https://8.8.8.8' ) ) );
		$this->assertSame( array(), $this->notices( array( 'home' => 'https://site.example.org' ) ) );
	}

	public function test_basic_auth_is_detected_from_either_server_variable(): void {
		$this->assertSame( array( 'unreachable.staging_password' ), $this->notices( array( 'server' => array( 'PHP_AUTH_USER' => 'pantheon' ) ) ) );
		$this->assertSame( array( 'unreachable.staging_password' ), $this->notices( array( 'server' => array( 'HTTP_AUTHORIZATION' => 'Basic abc' ) ) ) );
		$this->assertSame( array(), $this->notices( array( 'server' => array( 'HTTP_AUTHORIZATION' => 'Bearer abc' ) ) ) );
	}

	public function test_maintenance_mode_and_discouraged_search_engines_are_explained(): void {
		$this->assertSame( array( 'unreachable.maintenance_mode' ), $this->notices( array( 'maintenance' => true ) ) );
		$this->assertSame( array( 'unreachable.robots_blocked' ), $this->notices( array( 'blog_public' => false ) ) );
	}

	public function test_a_blanket_robots_disallow_is_found_and_a_narrow_one_is_not(): void {
		$this->assertTrue( Preflight::robots_blocks_everyone( "User-agent: *\nDisallow: /\n" ) );
		$this->assertTrue( Preflight::robots_blocks_everyone( "# c\nUser-agent: Googlebot\nUser-agent: *\r\nDisallow: /  # all\r\n" ) );
		$this->assertFalse( Preflight::robots_blocks_everyone( "User-agent: *\nDisallow: /wp-admin/\n" ) );
		$this->assertFalse( Preflight::robots_blocks_everyone( "User-agent: *\nDisallow: /\nAllow: /\n" ) );
		$this->assertFalse( Preflight::robots_blocks_everyone( "User-agent: GPTBot\nDisallow: /\n\nUser-agent: *\nDisallow:\n" ) );
		$this->assertFalse( Preflight::robots_blocks_everyone( '' ) );
		$this->assertSame( array( 'unreachable.robots_blocked' ), $this->notices( array( 'robots' => "User-agent: *\nDisallow: /\n" ) ) );
	}
}
