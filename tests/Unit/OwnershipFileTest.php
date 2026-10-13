<?php
namespace ReadinessIndex\Tests;

use ReadinessIndex\Connect\OwnershipFile;
use ReadinessIndex\Settings;
use ReadinessIndex\Tests\Support\WpTestCase;

final class OwnershipFileTest extends WpTestCase {

	const TOKEN = '0123456789abcdef0123456789abcdef';

	public function test_it_serves_exactly_the_token_at_the_well_known_path(): void {
		Settings::store_connection( 'Full report', self::TOKEN );
		$this->assertSame( self::TOKEN, OwnershipFile::body_for( '/.well-known/readinessindex-verify' ) );
		$this->assertStringNotContainsString( "\n", (string) OwnershipFile::body_for( '/.well-known/readinessindex-verify' ) );
	}

	public function test_it_answers_no_other_path(): void {
		Settings::store_connection( 'Full report', self::TOKEN );
		foreach ( array( '/', '/.well-known/readinessindex-verify/', '/x/.well-known/readinessindex-verify', '/.well-known/other', '/.WELL-KNOWN/readinessindex-verify' ) as $path ) {
			$this->assertNull( OwnershipFile::body_for( $path ), $path );
		}
	}

	public function test_it_serves_nothing_before_a_connection_and_nothing_after_disconnect(): void {
		$this->assertNull( OwnershipFile::body_for( OwnershipFile::PATH ) );
		Settings::store_connection( 'Full report', self::TOKEN );
		Settings::forget_connection();
		$this->assertNull( OwnershipFile::body_for( OwnershipFile::PATH ) );
	}

	public function test_a_stored_value_that_is_not_a_token_is_never_served(): void {
		$this->options[ Settings::OPTION_CONNECTION ] = array(
			'plan'         => 'x',
			'verify_token' => '<script>alert(1)</script>',
		);
		$this->assertNull( OwnershipFile::body_for( OwnershipFile::PATH ) );
	}
}
