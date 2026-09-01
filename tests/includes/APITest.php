<?php
/**
 * Tests for the API class.
 */

namespace {
	if ( ! class_exists( 'WP_REST_Controller' ) ) {
		class WP_REST_Controller {}
	}

	if ( ! class_exists( 'WP_REST_Request' ) ) {
		class WP_REST_Request {}
	}

	if ( ! class_exists( 'WC_Order_Item_Fee' ) ) {
		class WC_Order_Item_Fee {
			public $name;
			public $total;
			public $tax_status;

			public function set_name( $name ): void {
				$this->name = $name;
			}

			public function set_total( $total ): void {
				$this->total = $total;
			}

			public function set_tax_status( $tax_status ): void {
				$this->tax_status = $tax_status;
			}
		}
	}
}

namespace WCPOS\WooCommercePOS\StripeTerminal\Tests {

	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use PHPUnit\Framework\TestCase;
	use WCPOS\WooCommercePOS\StripeTerminal\API;

	class ApiCaptureTestDouble extends API {
		/**
		 * @var \Stripe\PaymentIntent
		 */
		public $retrieved_payment_intent;

		protected function retrieve_payment_intent( string $payment_intent_id ) {
			return $this->retrieved_payment_intent;
		}
	}

	/**
	 * @covers \WCPOS\WooCommercePOS\StripeTerminal\API
	 */
	class APITest extends TestCase {
		protected function setUp(): void {
			parent::setUp();
			Monkey\setUp();
		}

		protected function tearDown(): void {
			\Mockery::close();
			Monkey\tearDown();
			parent::tearDown();
		}

		public function test_capture_payment_intent_records_metadata_from_server_retrieved_intent(): void {
			$order = $this->mock_order();
			$order->shouldReceive( 'update_meta_data' )->with( '_stripe_terminal_livemode', 'yes' )->once();
			$order->shouldReceive( 'update_meta_data' )->with( '_transaction_id', 'ch_server' )->once();
			$request = \Mockery::mock( \WP_REST_Request::class );
			$request->shouldReceive( 'get_json_params' )->once()->andReturn(
				array(
					'order_id'       => 42,
					// Client-supplied payment data beyond the ID must be ignored.
					'payment_intent' => array(
						'id'             => 'pi_capture',
						'livemode'       => false,
						'amount_details' => array( 'tip' => array( 'amount' => 9999 ) ),
						'charges'        => array(
							'data' => array( array( 'id' => 'ch_client_forged' ) ),
						),
					),
				)
			);
			Functions\when( 'wc_get_order' )->justReturn( $order );
			Functions\when( 'rest_ensure_response' )->returnArg();

			$api = ( new \ReflectionClass( ApiCaptureTestDouble::class ) )->newInstanceWithoutConstructor();
			$api->retrieved_payment_intent = \Stripe\PaymentIntent::constructFrom(
				array(
					'id'       => 'pi_capture',
					'livemode' => true,
					'metadata' => array( 'order_id' => '42' ),
					'charges'  => array(
						'data' => array(
							array(
								'id'                     => 'ch_server',
								'currency'               => 'usd',
								'captured'               => true,
								'payment_method_details' => array( 'card' => array( 'brand' => 'visa' ) ),
							),
						),
					),
				)
			);

			$this->assertTrue( $api->capture_payment_intent( $request )['success'] );
		}

		public function test_capture_payment_intent_rejects_intent_for_other_order(): void {
			$order = \Mockery::mock();
			$order->shouldNotReceive( 'update_meta_data' );
			$order->shouldNotReceive( 'save' );
			$request = \Mockery::mock( \WP_REST_Request::class );
			$request->shouldReceive( 'get_json_params' )->once()->andReturn(
				array(
					'order_id'       => 42,
					'payment_intent' => array( 'id' => 'pi_other' ),
				)
			);
			Functions\when( 'wc_get_order' )->justReturn( $order );

			$api = ( new \ReflectionClass( ApiCaptureTestDouble::class ) )->newInstanceWithoutConstructor();
			$api->retrieved_payment_intent = \Stripe\PaymentIntent::constructFrom(
				array(
					'id'       => 'pi_other',
					'livemode' => true,
					'metadata' => array( 'order_id' => '99' ),
				)
			);

			$result = $api->capture_payment_intent( $request );

			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'order_mismatch', $result->get_error_code() );
		}

		public function test_payment_intent_webhook_records_livemode(): void {
			$order = $this->mock_order();
			$order->shouldReceive( 'update_meta_data' )->with( '_stripe_terminal_livemode', 'no' )->once();
			$order->shouldReceive( 'delete_meta_data' )->with( '_stripe_terminal_moto' )->once();
			$order->shouldReceive( 'add_order_note' )->once();
			Functions\when( 'wc_get_order' )->justReturn( $order );
			Functions\when( '__' )->returnArg();

			$payment_intent = (object) array(
				'id'                   => 'pi_webhook',
				'livemode'             => false,
				'metadata'             => (object) array( 'order_id' => 42 ),
				'amount'               => 1000,
				'currency'             => 'usd',
				'status'               => 'succeeded',
				'payment_method_types' => array( 'card_present' ),
			);
			$api            = ( new \ReflectionClass( API::class ) )->newInstanceWithoutConstructor();
			$method         = new \ReflectionMethod( API::class, 'update_order_with_payment_intent' );
			if ( PHP_VERSION_ID < 80100 ) {
				$method->setAccessible( true );
			}

			$method->invoke( $api, $payment_intent );
			$this->addToAssertionCount( \Mockery::getContainer()->mockery_getExpectationCount() );
		}

		public function test_tip_adds_fee_and_increases_order_total(): void {
			list( $order, $state ) = $this->tip_order( 10.00 );

			$this->invoke_tip_reconciliation( $order, $this->payment_intent_with_tip( 150, 'usd', 'pi_tip' ) );

			$this->assertCount( 1, $state->fees );
			$this->assertSame( 'Tip', $state->fees[0]->name );
			$this->assertSame( 1.5, $state->fees[0]->total );
			$this->assertSame( 'none', $state->fees[0]->tax_status );
			$this->assertSame( 11.5, $state->total );
			$this->assertSame( 1.5, $state->meta['_stripe_terminal_tip_amount'] );
			$this->assertSame( 'pi_tip', $state->meta['_stripe_terminal_tip_payment_intent_id'] );
			$this->assertStringContainsString( '1.50 USD', $state->notes[0] );
			$this->assertStringContainsString( 'pi_tip', $state->notes[0] );
		}

		/**
		 * @dataProvider no_tip_provider
		 */
		public function test_zero_missing_or_null_tip_is_a_no_op( $amount_details ): void {
			list( $order, $state ) = $this->tip_order( 10.00 );
			$payment_intent       = (object) array(
				'id'             => 'pi_no_tip',
				'currency'       => 'usd',
				'amount_details' => $amount_details,
			);

			$this->invoke_tip_reconciliation( $order, $payment_intent );

			$this->assertSame( array(), $state->fees );
			$this->assertSame( 10.0, $state->total );
			$this->assertSame( array(), $state->meta );
			$this->assertSame( 0, $state->save_count );
		}

		public function no_tip_provider(): array {
			return array(
				'zero'    => array( (object) array( 'tip' => (object) array( 'amount' => 0 ) ) ),
				'missing' => array( (object) array() ),
				'null'    => array( (object) array( 'tip' => null ) ),
			);
		}

		public function test_tip_is_added_only_once_for_the_same_payment_intent(): void {
			list( $order, $state ) = $this->tip_order( 10.00 );
			$payment_intent       = $this->payment_intent_with_tip( 150, 'usd', 'pi_duplicate' );

			$this->invoke_tip_reconciliation( $order, $payment_intent );
			$this->invoke_tip_reconciliation( $order, $payment_intent );

			$this->assertCount( 1, $state->fees );
			$this->assertSame( 11.5, $state->total );
			$this->assertSame( 1, $state->save_count );
		}

		public function test_tip_uses_zero_decimal_currency_conversion(): void {
			list( $order, $state ) = $this->tip_order( 5000.0 );

			$this->invoke_tip_reconciliation( $order, $this->payment_intent_with_tip( 1000, 'jpy', 'pi_jpy' ) );

			$this->assertSame( 1000.0, $state->fees[0]->total );
			$this->assertSame( 6000.0, $state->total );
		}

		private function invoke_tip_reconciliation( $order, $payment_intent ): void {
			Functions\when( '__' )->returnArg();
			$api    = ( new \ReflectionClass( API::class ) )->newInstanceWithoutConstructor();
			$method = new \ReflectionMethod( API::class, 'maybe_add_tip_to_order' );
			if ( PHP_VERSION_ID < 80100 ) {
				$method->setAccessible( true );
			}

			$method->invoke( $api, $order, $payment_intent );
		}

		private function payment_intent_with_tip( int $tip, string $currency, string $id ) {
			return (object) array(
				'id'             => $id,
				'currency'       => $currency,
				'amount_details' => (object) array( 'tip' => (object) array( 'amount' => $tip ) ),
			);
		}

		private function tip_order( float $base_total ): array {
			$state = (object) array(
				'base_total' => $base_total,
				'fees'       => array(),
				'meta'       => array(),
				'notes'      => array(),
				'total'      => $base_total,
				'save_count' => 0,
			);
			$order = \Mockery::mock();
			$order->shouldReceive( 'get_meta' )->andReturnUsing( function ( $key ) use ( $state ) {
				return $state->meta[ $key ] ?? '';
			} );
			$order->shouldReceive( 'add_item' )->andReturnUsing( function ( $fee ) use ( $state ) {
				$state->fees[] = $fee;
			} );
			$order->shouldReceive( 'update_meta_data' )->andReturnUsing( function ( $key, $value ) use ( $state ) {
				$state->meta[ $key ] = $value;
			} );
			$order->shouldReceive( 'calculate_totals' )->with( false )->andReturnUsing( function () use ( $state ) {
				$state->total = $state->base_total + array_sum( array_column( $state->fees, 'total' ) );
			} );
			$order->shouldReceive( 'save' )->andReturnUsing( function () use ( $state ) {
				++$state->save_count;
			} );
			$order->shouldReceive( 'add_order_note' )->andReturnUsing( function ( $note ) use ( $state ) {
				$state->notes[] = $note;
			} );

			return array( $order, $state );
		}

		private function mock_order() {
			$order = \Mockery::mock();
			$order->shouldReceive( 'update_meta_data' )->byDefault();
			$order->shouldReceive( 'save' )->once();

			return $order;
		}
	}
}
