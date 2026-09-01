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

		private function mock_order() {
			$order = \Mockery::mock();
			$order->shouldReceive( 'update_meta_data' )->byDefault();
			$order->shouldReceive( 'save' )->once();

			return $order;
		}
	}
}
