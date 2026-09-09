<?php
namespace WCPOS\WooCommercePOS\StripeTerminal\Tests\Server;
use Brain\Monkey\Functions;
require_once __DIR__ . '/ServerTestCase.php';

class StripeServerProviderCreateActionTest extends ServerTestCase {
	private function order(): void {
		$order = \Mockery::mock();
		$order->shouldReceive( 'get_id' )->andReturn( 42 );
		$order->shouldReceive( 'get_order_number' )->andReturn( 'CUSTOM-42' );
		$order->shouldReceive( 'get_total' )->andReturn( '100.00' );
		$order->shouldNotReceive( 'update_meta_data' );
		$order->shouldNotReceive( 'save' );
		Functions\when( 'wc_get_order' )->justReturn( $order );
	}

	/** @dataProvider currencies */
	public function test_row_payload_and_idempotency( string $currency, string $country, array $methods ): void {
		$this->order();
		Functions\when( 'get_transient' )->justReturn( $country );
		$provider = $this->provider( array( $this->ok( $this->intent() ), $this->ok( array( 'id' => 'tmr_test' ) ) ) );
		$this->assertSame(
			array(
				'ref'        => 'pi_test',
				'expires_at' => null,
			),
			$provider->create_reader_action( $this->row( $currency ), 'tmr_test' )
		);
		$this->assertSame(
			array(
				'amount'               => 1250,
				'currency'             => strtolower( $currency ),
				'description'          => 'Order #CUSTOM-42',
				'metadata'             => array(
					'order_id'         => '42',
					'wcpos_payment_id' => self::PAYMENT_ID,
					'wcpos_reader'     => 'tmr_test',
				),
				'capture_method'       => 'automatic',
				'payment_method_types' => $methods,
			),
			$this->http->requests[0]['params']
		);
		$this->assert_idempotency( 0, self::PAYMENT_ID );
		$this->assertSame(
			array(
				'payment_intent' => 'pi_test',
				'process_config' => array( 'enable_customer_cancellation' => 'true' ),
			),
			$this->http->requests[1]['params']
		);
		$this->assertEqualsWithDelta( time(), $this->options['stwc_payment_dispatch_at'], 1 );
	}

	public function currencies(): array {
		return array( array( 'USD', 'US', array( 'card_present' ) ), array( 'CAD', 'CA', array( 'card_present', 'interac_present' ) ) );
	}

	public function test_dispatch_failure_cancels_intent(): void {
		$this->order();
		$provider = $this->provider( array( $this->ok( $this->intent() ), $this->error(), $this->ok( $this->intent( array( 'status' => 'canceled' ) ) ) ) );
		$this->assert_provider_error( $provider->create_reader_action( $this->row(), 'tmr_test' ), 'card_declined' );
		$this->assertStringEndsWith( '/payment_intents/pi_test/cancel', $this->http->requests[2]['url'] );
	}

	public function test_missing_order(): void {
		Functions\when( 'wc_get_order' )->justReturn( false );
		$provider = $this->provider();
		$error    = $provider->create_reader_action( $this->row(), 'tmr_test' );
		$this->assertSame( 'wcpos_order_not_found', $error->get_error_code() );
		$this->assertSame( 404, $error->get_error_data()['status'] );
		$this->assertCount( 0, $this->http->requests );
	}

	public function test_create_error_does_not_dispatch(): void {
		$this->order();
		$provider = $this->provider( array( $this->error( 'parameter_invalid_integer' ) ) );
		$this->assert_provider_error( $provider->create_reader_action( $this->row(), 'tmr_test' ), 'parameter_invalid_integer' );
		$this->assertCount( 1, $this->http->requests );
	}

	public function test_unsupported_currency_uses_the_shared_account_check(): void {
		$this->order();
		$provider = $this->provider(
			array(
				$this->ok(
					array(
						'id'      => 'acct_us',
						'object'  => 'account',
						'country' => 'US',
					)
				),
			)
		);
		$error    = $provider->create_reader_action( $this->row( 'CAD' ), 'tmr_test' );
		$this->assert_provider_error( $error, 'stripe_api_error' );
		$this->assertStringContainsString( 'Currency CAD is not supported', $error->get_error_message() );
		$this->assertCount( 1, $this->http->requests );
		$this->assertStringEndsWith( '/account', $this->http->requests[0]['url'] );
	}
}
