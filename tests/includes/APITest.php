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

		/**
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_charge_webhook_logs_missing_api_key_without_throwing(): void {
			Functions\when( 'get_option' )->justReturn( array() );
			$logger = \Mockery::mock( 'alias:WCPOS\WooCommercePOS\StripeTerminal\Logger' );
			$logger->shouldReceive( 'log' )->once()->with(
				\Mockery::pattern( '/^Charge webhook: Failed to retrieve payment intent/' ),
				'error'
			);
			$charge = (object) array( 'payment_intent' => 'pi_missing_key' );
			$api    = ( new \ReflectionClass( API::class ) )->newInstanceWithoutConstructor();
			$method = new \ReflectionMethod( API::class, 'update_order_with_charge' );
			if ( 80100 > PHP_VERSION_ID ) {
				$method->setAccessible( true );
			}

			$this->assertNull( $method->invoke( $api, $charge ) );
		}

		public function test_permission_callbacks_reject_users_without_capability(): void {
			Functions\when( 'current_user_can' )->justReturn( false );
			Functions\when( 'rest_authorization_required_code' )->justReturn( 401 );
			Functions\when( '__' )->returnArg();
			$api = ( new \ReflectionClass( API::class ) )->newInstanceWithoutConstructor();

			foreach ( array( 'can_manage_terminal', 'can_process_payments' ) as $callback ) {
				$method = new \ReflectionMethod( API::class, $callback );
				if ( 80100 > PHP_VERSION_ID ) {
					$method->setAccessible( true );
				}
				$result = $method->invoke( $api );

				$this->assertInstanceOf( \WP_Error::class, $result );
				$this->assertSame( 'rest_forbidden', $result->get_error_code() );
				$this->assertSame( 401, $result->get_error_data()['status'] );
			}
		}

		public function test_permission_callbacks_allow_users_with_capability(): void {
			Functions\when( 'current_user_can' )->justReturn( true );
			$api = ( new \ReflectionClass( API::class ) )->newInstanceWithoutConstructor();

			foreach ( array( 'can_manage_terminal', 'can_process_payments' ) as $callback ) {
				$method = new \ReflectionMethod( API::class, $callback );
				if ( 80100 > PHP_VERSION_ID ) {
					$method->setAccessible( true );
				}

				$this->assertTrue( $method->invoke( $api ) );
			}
		}

		public function test_routes_register_callable_permissions_for_the_required_capabilities(): void {
			$routes = array();
			Functions\when( 'register_rest_route' )->alias(
				function ( $namespace, $route, $args ) use ( &$routes ) {
					$routes[ $route ] = $args;
				}
			);
			$api = ( new \ReflectionClass( API::class ) )->newInstanceWithoutConstructor();
			$api->register_routes();
			$capabilities = array(
				'/connection-token'                  => 'manage_woocommerce',
				'/list-locations'                    => 'manage_woocommerce',
				'/register-reader'                   => 'manage_woocommerce',
				'/create-payment-intent'             => 'publish_shop_orders',
				'/capture-payment-intent'            => 'publish_shop_orders',
				'/attach-payment-method-to-customer' => 'manage_woocommerce',
			);
			foreach ( $capabilities as $route => $capability ) {
				Functions\expect( 'current_user_can' )->once()->with( $capability )->andReturn( true );
				$callback = $routes[ $route ]['permission_callback'];
				$this->assertTrue( is_callable( $callback ) );
				$this->assertTrue( call_user_func( $callback ) );
			}
			$this->assertSame( '__return_true', $routes['/webhook']['permission_callback'] );
		}

		private function mock_order() {
			$order = \Mockery::mock();
			$order->shouldReceive( 'update_meta_data' )->byDefault();
			$order->shouldReceive( 'save' )->once();

			return $order;
		}
	}
}
