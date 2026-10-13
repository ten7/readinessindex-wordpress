<?php
namespace ReadinessIndex\Tests;

use ReadinessIndex\Api\ApiError;
use ReadinessIndex\Api\Client;
use ReadinessIndex\Api\SiteCache;
use ReadinessIndex\Tests\Support\Fixtures;
use ReadinessIndex\Tests\Support\WpTestCase;

final class ApiClientTest extends WpTestCase {

	const KEY = 'air_0a1b2c3d_abcdefghijklmnopqrstuvwxyz012345';

	private function client(): Client {
		return new Client( 'https://api.test', self::KEY );
	}

	public function test_site_reads_every_site_fixture(): void {
		foreach ( Fixtures::index() as $entry ) {
			if ( 'SiteResponse' !== $entry['shape'] ) {
				continue;
			}
			$this->options = array();
			$this->respond( Fixtures::response( preg_replace( '/\.json$/', '', $entry['path'] ) ) );
			$site = $this->client()->site();
			$this->assertSame( 'example.com', $site->site->host, $entry['path'] );
		}
	}

	public function test_a_done_site_exposes_whole_integer_points_and_the_change(): void {
		$this->respond( Fixtures::response( 'site/done-with-change' ) );
		$site = $this->client()->site();
		$this->assertSame( 95, $site->score->value );
		$this->assertSame( 4, $site->score->change->score_change );
		$this->assertTrue( $site->score->change->comparable );
		$this->assertCount( 7, $site->score->dimensions );
		$this->assertSame( 12, $site->score->dimensions[1]->earned );
		$this->assertSame( 3, $site->fixes[0]->points );
		$this->assertSame( 'monitoring_basic', $site->allowance->next_step->key );
	}

	public function test_the_request_is_built_the_way_the_fixture_says(): void {
		$fixture = Fixtures::load( 'site/never-scanned' );
		$this->respond( Fixtures::response( 'site/never-scanned' ) );
		$this->client()->site();

		$call = $this->requests[0];
		$this->assertSame( 'https://api.test' . $fixture['request']['path'], $call['url'] );
		$this->assertSame( $fixture['request']['method'], $call['args']['method'] );
		$this->assertSame( 'Bearer ' . self::KEY, $call['args']['headers']['Authorization'] );
		$this->assertSame( 'ReadinessIndex-WordPress/' . READINESS_INDEX_VERSION . ' WordPress/6.6; https://example.com', $call['args']['headers']['User-Agent'] );
		$this->assertTrue( $call['args']['sslverify'] );
		$this->assertSame( 0, $call['args']['redirection'] );
	}

	public function test_the_user_agent_matches_the_documented_shape(): void {
		$fixture = Fixtures::load( 'site/never-scanned' );
		$agent   = $fixture['request']['headers']['User-Agent'];
		$this->assertMatchesRegularExpression( '#^ReadinessIndex-WordPress/\S+ WordPress/\S+; https://\S+$#', $agent );
		$this->respond( Fixtures::response( 'site/never-scanned' ) );
		$this->client()->site();
		$this->assertMatchesRegularExpression( '#^ReadinessIndex-WordPress/\S+ WordPress/\S+; https://\S+$#', $this->requests[0]['args']['headers']['User-Agent'] );
	}

	public function test_etag_is_stored_sent_and_a_304_returns_the_cached_body(): void {
		$first            = Fixtures::response( 'site/done-first-scan' );
		$first['headers'] = array( 'etag' => '"abc123"' );
		$this->respond( $first, Fixtures::response( 'site/not-modified' ) );

		$one = $this->client()->site();
		$two = $this->client()->site();

		$this->assertArrayNotHasKey( 'If-None-Match', $this->requests[0]['args']['headers'] );
		$this->assertSame( '"abc123"', $this->requests[1]['args']['headers']['If-None-Match'] );
		$this->assertEquals( $one, $two );
		$this->assertFalse( $this->autoload[ \ReadinessIndex\Settings::OPTION_SITE_CACHE ] );
	}

	public function test_a_304_with_nothing_cached_is_a_contract_error(): void {
		$this->respond( Fixtures::response( 'site/not-modified' ) );
		$this->expectException( ApiError::class );
		$this->client()->site();
	}

	public function test_clearing_the_cache_stops_the_validator_being_sent(): void {
		$first            = Fixtures::response( 'site/never-scanned' );
		$first['headers'] = array( 'etag' => '"x"' );
		$this->respond( $first );
		$this->client()->site();
		SiteCache::clear();
		$this->client()->site();
		$this->assertArrayNotHasKey( 'If-None-Match', $this->requests[1]['args']['headers'] );
	}

	public function test_scan_calls_read_their_fixtures(): void {
		$this->respond( Fixtures::response( 'scan/started' ) );
		$started = $this->client()->start_scan();
		$this->assertTrue( $started->started );
		$this->assertSame( 4, $started->allowance->rescans_left );
		$this->assertSame( 'POST', $this->requests[0]['args']['method'] );

		$this->respond( Fixtures::response( 'scan/already-running' ) );
		$this->requests = array();
		$this->assertFalse( $this->client()->start_scan()->started );

		$this->respond( Fixtures::response( 'scan-status/running' ) );
		$status = $this->client()->scan_status( 'scan-token-4' );
		$this->assertSame( 4, $status->pages_read );
		$this->assertSame( 10, $status->pages_total );
		$this->assertFalse( $status->is_finished() );

		foreach ( array( 'done', 'failed', 'pending' ) as $state ) {
			$this->respond( Fixtures::response( 'scan-status/' . $state ) );
			$this->assertSame( $state, $this->client()->scan_status( 'scan-token-4' )->state );
		}
	}

	public function test_fix_prompt_and_verify_read_their_fixtures(): void {
		$this->respond( Fixtures::response( 'fix-prompt/outstanding-check' ) );
		$prompt = $this->client()->fix_prompt( 'AIR-1.1' );
		$this->assertSame( 'AIR-1.1', $prompt->check );
		$this->assertSame( 'https://api.test/cms/v1/fixes/AIR-1.1', $this->requests[0]['url'] );

		$this->respond( Fixtures::response( 'verify/verified' ) );
		$this->assertTrue( $this->client()->verify()->verified );
		$this->respond( Fixtures::response( 'verify/already-verified' ) );
		$this->assertTrue( $this->client()->verify()->verified );
	}

	public function test_a_path_segment_that_is_not_a_token_is_never_sent(): void {
		$this->respond( Fixtures::response( 'scan-status/done' ) );
		try {
			$this->client()->scan_status( '../../x?y=1' );
			$this->fail( 'Expected a not_found.' );
		} catch ( ApiError $e ) {
			$this->assertSame( 'not_found', $e->key );
		}
		$this->assertCount( 0, $this->requests );
	}

	/**
	 * Every error fixture maps to one ApiError with the server's key and sentence.
	 */
	public function test_every_error_fixture_becomes_one_api_error(): void {
		foreach ( Fixtures::index() as $entry ) {
			if ( 'ErrorBody' !== $entry['shape'] ) {
				continue;
			}
			$name = preg_replace( '/\.json$/', '', $entry['path'] );
			$body = Fixtures::load( $name )['response']['body'];
			$this->respond( Fixtures::response( $name ) );
			$this->requests = array();
			try {
				// Hit the route the fixture names: any call can carry any error.
				$this->client()->start_scan();
				$this->fail( 'No error for ' . $name );
			} catch ( ApiError $e ) {
				$this->assertSame( $body['error'], $e->key, $name );
				$this->assertSame( $body['message'], $e->getMessage(), $name );
				$this->assertSame( $entry['status'], $e->status, $name );
			}
		}
	}

	public function test_unauthenticated_asks_for_reconnect_and_others_do_not(): void {
		$this->respond( Fixtures::response( 'errors/unauthenticated' ) );
		try {
			$this->client()->site();
			$this->fail();
		} catch ( ApiError $e ) {
			$this->assertTrue( $e->show_reconnect() );
		}
		$this->respond( Fixtures::response( 'errors/account-disabled' ) );
		try {
			$this->client()->site();
			$this->fail();
		} catch ( ApiError $e ) {
			$this->assertFalse( $e->show_reconnect() );
		}
	}

	public function test_unreachable_carries_its_reason_and_quota_its_allowance(): void {
		$this->respond( Fixtures::response( 'errors/unreachable-staging-password' ) );
		try {
			$this->client()->start_scan();
			$this->fail();
		} catch ( ApiError $e ) {
			$this->assertSame( 'staging_password', $e->reason() );
		}
		$this->respond( Fixtures::response( 'errors/quota' ) );
		try {
			$this->client()->start_scan();
			$this->fail();
		} catch ( ApiError $e ) {
			$this->assertSame( 0, $e->extra['allowance']->rescans_left );
			$this->assertSame( 'upgrade_basic', $e->extra['allowance']->next_step->key );
		}
	}

	public function test_rate_limited_reads_retry_after_from_the_body(): void {
		$this->respond( Fixtures::response( 'errors/rate-limited' ) );
		try {
			$this->client()->site();
			$this->fail();
		} catch ( ApiError $e ) {
			$this->assertSame( 60, $e->retry_after() );
		}
	}

	public function test_not_verified_carries_the_state(): void {
		$this->respond( Fixtures::response( 'errors/not-verified-not-found' ) );
		try {
			$this->client()->verify();
			$this->fail();
		} catch ( ApiError $e ) {
			$this->assertSame( 'not_found', $e->verify_state() );
		}
	}

	public function test_a_get_is_retried_once_and_a_post_never(): void {
		$this->respond( Fixtures::response( 'errors/internal-error' ), Fixtures::response( 'site/never-scanned' ) );
		$this->client()->site();
		$this->assertCount( 2, $this->requests );

		$this->requests = array();
		$this->respond( Fixtures::response( 'errors/internal-error' ), Fixtures::response( 'scan/started' ) );
		try {
			$this->client()->start_scan();
			$this->fail();
		} catch ( ApiError $e ) {
			$this->assertSame( 'internal_error', $e->key );
		}
		$this->assertCount( 1, $this->requests );
	}

	public function test_a_get_gives_up_after_one_retry(): void {
		$this->respond( Fixtures::response( 'errors/internal-error' ) );
		try {
			$this->client()->site();
			$this->fail();
		} catch ( ApiError $e ) {
			$this->assertSame( 'internal_error', $e->key );
		}
		$this->assertCount( 2, $this->requests );
	}

	public function test_no_answer_at_all_is_offline_and_not_an_error_key(): void {
		$this->respond( new WP_Error_Double() );
		try {
			$this->client()->site();
			$this->fail();
		} catch ( ApiError $e ) {
			$this->assertSame( ApiError::OFFLINE, $e->key );
		}
		$this->assertCount( 2, $this->requests );
	}

	public function test_a_post_that_gets_no_answer_is_not_repeated(): void {
		$this->respond( new WP_Error_Double() );
		try {
			$this->client()->start_scan();
			$this->fail();
		} catch ( ApiError $e ) {
			$this->assertSame( ApiError::OFFLINE, $e->key );
		}
		$this->assertCount( 1, $this->requests );
	}

	public function test_a_response_missing_a_required_field_is_a_contract_error(): void {
		$fixture = Fixtures::response( 'site/never-scanned' );
		$body    = json_decode( $fixture['body'], true );
		unset( $body['allowance']['rescans_total'] );
		$fixture['body'] = wp_json_encode( $body );
		$this->respond( $fixture );
		try {
			$this->client()->site();
			$this->fail();
		} catch ( ApiError $e ) {
			$this->assertSame( ApiError::CONTRACT, $e->key );
			$this->assertSame( 'allowance.rescans_total', $e->extra['path'] );
		}
		$this->assertNull( SiteCache::read(), 'A body that fails the contract is never cached.' );
	}

	public function test_a_float_where_points_are_expected_is_a_contract_error(): void {
		$fixture                = Fixtures::response( 'site/done-first-scan' );
		$body                   = json_decode( $fixture['body'], true );
		$body['score']['value'] = 90.5;
		$fixture['body']        = wp_json_encode( $body );
		$this->respond( $fixture );
		$this->expectException( ApiError::class );
		$this->client()->site();
	}

	public function test_a_body_that_is_not_json_is_a_contract_error(): void {
		$this->respond(
			array(
				'response' => array( 'code' => 200 ),
				'headers'  => array(),
				'body'     => '<html>',
			)
		);
		try {
			$this->client()->site();
			$this->fail();
		} catch ( ApiError $e ) {
			$this->assertSame( ApiError::CONTRACT, $e->key );
		}
	}

	public function test_a_redirect_is_never_followed_and_is_a_contract_error(): void {
		$this->respond(
			array(
				'response' => array( 'code' => 302 ),
				'headers'  => array( 'location' => 'https://evil.test/' ),
				'body'     => '',
			)
		);
		try {
			$this->client()->scan_status( 'scan-token-1' );
			$this->fail();
		} catch ( ApiError $e ) {
			$this->assertSame( ApiError::CONTRACT, $e->key );
		}
		$this->assertCount( 1, $this->requests );
	}

	public function test_without_a_key_no_request_is_made(): void {
		$client = new Client( 'https://api.test', '' );
		try {
			$client->site();
			$this->fail();
		} catch ( ApiError $e ) {
			$this->assertSame( 'unauthenticated', $e->key );
		}
		$this->assertCount( 0, $this->requests );
	}

	public function test_the_key_is_never_in_an_error_or_its_extras(): void {
		$this->respond( Fixtures::response( 'errors/unauthenticated' ) );
		try {
			$this->client()->site();
		} catch ( ApiError $e ) {
			$this->assertStringNotContainsString( self::KEY, serialize( $e->extra ) . $e->getMessage() . $e->getTraceAsString() );
		}
	}
}
