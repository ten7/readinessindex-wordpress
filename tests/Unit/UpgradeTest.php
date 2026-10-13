<?php
namespace ReadinessIndex\Tests;

use ReadinessIndex\Admin\Upgrade;
use ReadinessIndex\Api\Model\Allowance;
use ReadinessIndex\Tests\Support\Fixtures;
use ReadinessIndex\Tests\Support\WpTestCase;

final class UpgradeTest extends WpTestCase {

	const ORIGIN = 'https://readinessindex.io';
	const RETURN = 'https://example.com/wp-admin/admin.php?page=readiness-index';

	private function allowance( string $fixture, array $over = array() ): Allowance {
		$data = Fixtures::load( $fixture )['response']['body']['allowance'];
		foreach ( $over as $key => $value ) {
			$data[ $key ] = $value;
		}
		return Allowance::from_array( $data );
	}

	public function test_billing_off_has_no_offer_and_nothing_price_related(): void {
		$allowance = $this->allowance( 'site/never-scanned-billing-off' );
		$this->assertNull( $allowance->next_step );
		$this->assertNull( Upgrade::offer( $allowance, true, false, self::RETURN, self::ORIGIN ) );
		$this->assertSame( '', Upgrade::pack_url( $allowance, self::ORIGIN ) );
	}

	public function test_the_monitoring_line_waits_for_a_score_and_can_be_dismissed(): void {
		$allowance = $this->allowance( 'site/never-scanned' );
		$this->assertNull( Upgrade::offer( $allowance, false, false, self::RETURN, self::ORIGIN ) );
		$this->assertNull( Upgrade::offer( $allowance, true, true, self::RETURN, self::ORIGIN ) );
		$offer = Upgrade::offer( $allowance, true, false, self::RETURN, self::ORIGIN );
		$this->assertSame( 'monitoring_basic', $offer['key'] );
		$this->assertTrue( $offer['dismissible'] );
	}

	public function test_the_upgrade_when_rescans_are_spent_is_never_hidden_or_dismissible(): void {
		$allowance = $this->allowance( 'site/no-rescans-left' );
		$offer     = Upgrade::offer( $allowance, false, true, self::RETURN, self::ORIGIN );
		$this->assertSame( 'upgrade_basic', $offer['key'] );
		$this->assertFalse( $offer['dismissible'] );
	}

	public function test_the_button_opens_the_servers_address_with_the_return_appended(): void {
		$offer = Upgrade::offer( $this->allowance( 'site/no-rescans-left' ), true, false, self::RETURN, self::ORIGIN );
		$this->assertSame( 'https://readinessindex.io/billing/checkout/basic?return=' . rawurlencode( self::RETURN ), $offer['url'] );
	}

	public function test_an_address_that_already_has_a_query_gets_an_ampersand(): void {
		$this->assertSame( 'https://readinessindex.io/x?a=1&return=r', Upgrade::checkout_url( 'https://readinessindex.io/x?a=1', 'r', self::ORIGIN ) );
	}

	public function test_only_a_plain_https_address_is_accepted_and_http_only_for_a_local_origin(): void {
		$this->assertSame( '', Upgrade::safe_url( 'javascript:alert(1)', self::ORIGIN ) );
		$this->assertSame( '', Upgrade::safe_url( 'http://readinessindex.io/x', self::ORIGIN ) );
		$this->assertSame( '', Upgrade::safe_url( '//evil.test/x', self::ORIGIN ) );
		$this->assertSame( 'http://localhost:8099/x', Upgrade::safe_url( 'http://localhost:8099/x', 'http://localhost:8099' ) );
	}

	public function test_a_pack_is_offered_only_when_the_server_says_one_can_be_bought(): void {
		$this->assertSame( 'https://readinessindex.io/dashboard', Upgrade::pack_url( $this->allowance( 'site/basic-plan' ), self::ORIGIN ) );
		$this->assertSame( '', Upgrade::pack_url( $this->allowance( 'site/basic-plan', array( 'can_buy_pack' => false ) ), self::ORIGIN ) );
		$this->assertSame( '', Upgrade::pack_url( $this->allowance( 'site/basic-plan', array( 'pack_url' => null ) ), self::ORIGIN ) );
		$this->assertSame( '', Upgrade::pack_url( $this->allowance( 'site/no-rescans-left' ), self::ORIGIN ), 'There is no pack for a free account.' );
	}

	public function test_a_paid_account_at_zero_with_a_pack_gets_the_pack_and_no_checkout_button(): void {
		$allowance = $this->allowance( 'site/basic-plan', array( 'rescans_left' => 0 ) );
		$this->assertNull( Upgrade::offer( $allowance, true, false, self::RETURN, self::ORIGIN ) );
		$this->assertNotSame( '', Upgrade::pack_url( $allowance, self::ORIGIN ) );
	}
}
