<?php
/**
 * Tests for stateless order payment request tokens.
 */

namespace WCPOS\WooCommercePOS\StripeTerminal\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\StripeTerminal\PaymentRequestToken;

/**
 * @covers \WCPOS\WooCommercePOS\StripeTerminal\PaymentRequestToken
 */
class PaymentRequestTokenTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_salt' )->justReturn( 'unit-test-salt' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_token_validates_for_matching_order_key_and_expiry(): void {
		$token_data = PaymentRequestToken::create( 42, 'wc_order_abc', time() + 3600 );

		$this->assertTrue(
			PaymentRequestToken::validate( 42, 'wc_order_abc', $token_data['token'], $token_data['expires'] )
		);
	}

	public function test_token_rejects_different_order_key(): void {
		$token_data = PaymentRequestToken::create( 42, 'wc_order_abc', time() + 3600 );

		$this->assertFalse(
			PaymentRequestToken::validate( 42, 'wc_order_other', $token_data['token'], $token_data['expires'] )
		);
	}

	public function test_token_rejects_expired_timestamp(): void {
		$token_data = PaymentRequestToken::create( 42, 'wc_order_abc', time() - 10 );

		$this->assertFalse(
			PaymentRequestToken::validate( 42, 'wc_order_abc', $token_data['token'], $token_data['expires'] )
		);
	}
}
