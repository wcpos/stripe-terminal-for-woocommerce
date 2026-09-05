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
			$order->shouldReceive( 'needs_payment' )->andReturn( false );
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
		 * @dataProvider webhook_transaction_provider
		 */
		public function test_succeeded_webhook_completes_unpaid_order( ?string $charge_id, string $transaction_id ): void {
			$order = $this->mock_order();
			$order->shouldReceive( 'needs_payment' )->once()->andReturn( true );
			$order->shouldReceive( 'set_transaction_id' )->once()->with( $transaction_id );
			$order->shouldReceive( 'payment_complete' )->once()->with( $transaction_id );
			$order->shouldReceive( 'add_order_note' )->once()->with( 'Stripe Terminal: Order completed from the payment_intent.succeeded webhook.' );
			$this->invoke_payment_intent_webhook( $order, 'succeeded', $charge_id );
		}

		public function webhook_transaction_provider(): array {
			return array(
				'charge ID' => array( 'ch_webhook', 'ch_webhook' ),
				'intent ID when charge absent' => array( null, 'pi_webhook' ),
			);
		}

		/**
		 * @dataProvider webhook_no_completion_provider
		 */
		public function test_webhook_does_not_complete_paid_or_unsucceeded_order( string $status, bool $needs_payment ): void {
			$order = $this->mock_order();
			$order->shouldReceive( 'needs_payment' )->andReturn( $needs_payment );
			$order->shouldNotReceive( 'set_transaction_id' );
			$order->shouldNotReceive( 'payment_complete' );
			$this->invoke_payment_intent_webhook( $order, $status, 'ch_webhook' );
		}

		public function webhook_no_completion_provider(): array {
			return array(
				'already paid' => array( 'succeeded', false ),
				'processing' => array( 'processing', true ),
				'uncaptured' => array( 'requires_capture', true ),
				'canceled' => array( 'canceled', true ),
			);
		}

		/**
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_charge_webhook_ignores_charge_for_intent_that_is_not_the_recorded_attempt(): void {
			Functions\when( 'get_option' )->justReturn( array( 'secret_key' => 'sk_test_x', 'test_mode' => 'no' ) );
			$intent_class = \Mockery::mock( 'alias:Stripe\PaymentIntent' );
			$intent_class->shouldReceive( 'retrieve' )->once()->with( 'pi_old' )->andReturn(
				(object) array( 'id' => 'pi_old', 'metadata' => (object) array( 'order_id' => 42 ), 'livemode' => false )
			);
			$order = \Mockery::mock();
			$order->shouldReceive( 'get_meta' )->with( '_stripe_terminal_payment_intent_id' )->andReturn( 'pi_current_attempt' );
			$order->shouldNotReceive( 'update_meta_data' );
			$order->shouldNotReceive( 'save' );
			$order->shouldNotReceive( 'add_order_note' );
			Functions\when( 'wc_get_order' )->justReturn( $order );
			$charge = (object) array( 'id' => 'ch_old', 'payment_intent' => 'pi_old', 'amount' => 1000, 'currency' => 'usd' );
			$api    = ( new \ReflectionClass( API::class ) )->newInstanceWithoutConstructor();
			$method = new \ReflectionMethod( API::class, 'update_order_with_charge' );
			if ( 80100 > PHP_VERSION_ID ) {
				$method->setAccessible( true );
			}
			$method->invoke( $api, $charge );
			$this->addToAssertionCount( \Mockery::getContainer()->mockery_getExpectationCount() );
		}

		public function test_webhook_ignores_intent_that_is_not_the_recorded_attempt(): void {
			$order = \Mockery::mock();
			$order->shouldReceive( 'get_meta' )->with( '_stripe_terminal_payment_intent_id' )->andReturn( 'pi_current_attempt' );
			$order->shouldNotReceive( 'update_meta_data' );
			$order->shouldNotReceive( 'save' );
			$order->shouldNotReceive( 'payment_complete' );
			$order->shouldNotReceive( 'add_order_note' );
			Functions\when( 'wc_get_order' )->justReturn( $order );
			Functions\when( '__' )->returnArg();
			$payment_intent = (object) array(
				'id'       => 'pi_stale',
				'status'   => 'succeeded',
				'metadata' => (object) array( 'order_id' => 42 ),
				'amount'   => 1000,
				'currency' => 'usd',
			);
			$api    = ( new \ReflectionClass( API::class ) )->newInstanceWithoutConstructor();
			$method = new \ReflectionMethod( API::class, 'update_order_with_payment_intent' );
			if ( 80100 > PHP_VERSION_ID ) {
				$method->setAccessible( true );
			}
			$method->invoke( $api, $payment_intent );
			$this->addToAssertionCount( \Mockery::getContainer()->mockery_getExpectationCount() );
		}

		/**
		 * @dataProvider webhook_amount_mismatch_provider
		 */
		public function test_webhook_does_not_complete_when_amount_or_currency_differs( string $total, string $currency ): void {
			$order = $this->mock_order( '', $total, $currency );
			$order->shouldReceive( 'needs_payment' )->andReturn( true );
			$order->shouldNotReceive( 'set_transaction_id' );
			$order->shouldNotReceive( 'payment_complete' );
			$order->shouldReceive( 'add_order_note' )->once()->with( \Mockery::pattern( '/amount does not match the order total/' ) );
			$this->invoke_payment_intent_webhook( $order, 'succeeded', 'ch_webhook' );
		}

		public function webhook_amount_mismatch_provider(): array {
			return array(
				'total differs'    => array( '12.50', 'usd' ),
				'currency differs' => array( '10.00', 'eur' ),
			);
		}

		private function invoke_payment_intent_webhook( $order, string $status, ?string $charge_id ): void {
			$order->shouldReceive( 'delete_meta_data' )->with( '_stripe_terminal_moto' )->once();
			$order->shouldReceive( 'add_order_note' )->once()->with( \Mockery::pattern( '/^Stripe Terminal: Payment Intent succeeded/' ) );
			Functions\when( 'wc_get_order' )->justReturn( $order );
			Functions\when( '__' )->returnArg();
			$payment_intent = (object) array(
				'id'                   => 'pi_webhook',
				'latest_charge'        => $charge_id,
				'livemode'             => false,
				'metadata'             => (object) array( 'order_id' => 42 ),
				'amount'               => 1000,
				'currency'             => 'usd',
				'status'               => $status,
				'payment_method_types' => array( 'card_present' ),
			);
			$api    = ( new \ReflectionClass( API::class ) )->newInstanceWithoutConstructor();
			$method = new \ReflectionMethod( API::class, 'update_order_with_payment_intent' );
			if ( 80100 > PHP_VERSION_ID ) {
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

		private function mock_order( string $recorded_intent = '', string $total = '10.00', string $currency = 'usd' ) {
			$order = \Mockery::mock();
			$order->shouldReceive( 'update_meta_data' )->byDefault();
			$order->shouldReceive( 'save' )->once();
			$order->shouldReceive( 'get_meta' )->with( '_stripe_terminal_payment_intent_id' )->andReturn( $recorded_intent )->byDefault();
			$order->shouldReceive( 'get_total' )->andReturn( $total )->byDefault();
			$order->shouldReceive( 'get_currency' )->andReturn( $currency )->byDefault();

			return $order;
		}
	}
}
