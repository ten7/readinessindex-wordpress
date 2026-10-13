<?php
namespace ReadinessIndex\Tests;

use ReadinessIndex\Strings;
use ReadinessIndex\Tests\Support\WpTestCase;

/**
 * The two layers of sentences: the contract's, and the few the contract does not have yet.
 */
final class StringsTest extends WpTestCase {

	public function test_the_plugins_own_sentences_never_shadow_the_contracts(): void {
		$contract = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/strings/en.json' ), true );
		$overlap  = array_intersect_key( Strings::local(), $contract );
		$this->assertSame( array(), array_keys( $overlap ), 'The kit now has these sentences. Delete them from strings/plugin.en.json.' );
	}

	public function test_a_contract_sentence_wins_over_a_local_one(): void {
		$this->assertSame( 'Fix it here', Strings::get( 'fixes.kind.fix_here' ) );
	}

	public function test_a_local_sentence_is_found_and_filled(): void {
		$this->assertStringContainsString( 'WP Rocket', Strings::get( 'fixes.live.named_cache', array( 'cache' => 'WP Rocket' ) ) );
	}

	public function test_a_missing_key_shows_itself_and_never_a_blank(): void {
		$this->assertSame( 'no.such.key', Strings::get( 'no.such.key' ) );
	}

	public function test_no_local_sentence_holds_a_price_a_placeholder_left_open_or_british_spelling(): void {
		foreach ( Strings::local() as $key => $text ) {
			$this->assertDoesNotMatchRegularExpression( '/[$€£]|\d\s*\/\s*(mo|month|year)|\bper (month|year)\b/i', $text, $key );
			$this->assertDoesNotMatchRegularExpression( '/\b(colour|organis|behaviour|licence|favour|centre|analys(e|ing)\b|catalogue)/i', $text, $key );
			$this->assertDoesNotMatchRegularExpression( '/(?<!\{)\{(?!\w+\})/', $text, $key . ' has an unclosed placeholder' );
		}
	}
}
