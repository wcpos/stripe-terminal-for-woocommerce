<?php
/**
 * Tests for the Gateway class.
 */

namespace {
	if ( ! defined( 'STWC_PLUGIN_URL' ) ) {
		define( 'STWC_PLUGIN_URL', 'https://example.test/wp-content/plugins/stripe-terminal-for-woocommerce/' );
	}

	if ( ! defined( 'STWC_VERSION' ) ) {
		define( 'STWC_VERSION', '0.0.20' );
	}

	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		class WC_Payment_Gateway {
			public function get_option( $key ) {
				return 'enable_moto' === $key ? 'no' : null;
			}
		}
	}

	if ( ! class_exists( 'WC_Abstract_Order' ) ) {
		class WC_Abstract_Order {
			public function get_order_key() {
				return 'wc_order_key';
			}

			public function get_id() {
				return 42;
			}

			public function get_meta( $key, $single = true ) {
				return '';
			}
		}
	}

	if ( ! class_exists( 'WC_Order' ) ) {
		class WC_Order extends WC_Abstract_Order {}
	}

}

namespace WCPOS\WooCommercePOS\StripeTerminal\Tests {

	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use PHPUnit\Framework\TestCase;
	use WCPOS\WooCommercePOS\StripeTerminal\Gateway;

	class GatewayValidationTestDouble extends Gateway {
		public $options = array();
		public $webhook_called = false;

		public function get_option( $key ) {
			return $this->options[ $key ] ?? null;
		}

		public function validate_and_set_webhook( $api_key, $mode = 'live' ) {
			$this->webhook_called = true;

			return 'webhook-called';
		}
	}

	/**
	 * @covers \WCPOS\WooCommercePOS\StripeTerminal\Gateway
	 */
	class GatewayTest extends TestCase {
		protected function setUp(): void {
			parent::setUp();
			Monkey\setUp();
		}

		protected function tearDown(): void {
			\Mockery::close();
			unset( $GLOBALS['wp'] );
			$_POST = array();
			$_GET  = array();
			Monkey\tearDown();
			parent::tearDown();
		}

		public function test_enqueue_payment_scripts_uses_pos_cashier_nonce_for_pos_orders(): void {
			$gateway = ( new \ReflectionClass( Gateway::class ) )->newInstanceWithoutConstructor();

			$GLOBALS['wp'] = (object) array(
				'query_vars' => array( 'order-pay' => 42 ),
			);

			$order = \Mockery::mock( \WC_Abstract_Order::class );
			$order->shouldReceive( 'get_order_key' )->andReturn( 'wc_order_key' );
			$order->shouldReceive( 'get_id' )->andReturn( 42 );
			$order->shouldReceive( 'get_meta' )->with( '_pos_user', true )->andReturn( '2' );

			$current_user_id = 99;
			$switched_users  = array();
			$localized_data  = null;

			Functions\stubs(
				array(
					'is_checkout'              => false,
					'is_checkout_pay_page'     => true,
					'woocommerce_pos_request'  => true,
					'wp_enqueue_style'         => true,
					'wp_enqueue_script'        => true,
					'admin_url'                => 'https://example.test/wp-admin/admin-ajax.php',
					'__'                       => function ( $text ) {
						return $text;
					},
					'absint'                   => function ( $value ) {
						return abs( (int) $value );
					},
				)
			);

			Functions\when( 'wc_get_order' )->justReturn( $order );
			Functions\when( 'get_current_user_id' )->alias(
				function () use ( &$current_user_id ) {
					return $current_user_id;
				}
			);
			Functions\when( 'wp_set_current_user' )->alias(
				function ( $user_id ) use ( &$current_user_id, &$switched_users ) {
					$switched_users[] = $user_id;
					$current_user_id = $user_id;
				}
			);
			Functions\when( 'wp_salt' )->justReturn( 'unit-test-salt' );
			Functions\when( 'wp_create_nonce' )->alias(
				function ( $action ) use ( &$current_user_id ) {
					return 'nonce-for-' . $action . '-user-' . $current_user_id;
				}
			);
			Functions\when( 'wp_localize_script' )->alias(
				function ( $handle, $object_name, $data ) use ( &$localized_data ) {
					$localized_data = $data;

					return true;
				}
			);

			$gateway->enqueue_payment_scripts();

			$this->assertSame( 'nonce-for-stripe_terminal_nonce-user-2', $localized_data['nonce'] );
			$this->assertSame( array( 2, 99 ), $switched_users );
		}

		public function test_enqueue_payment_scripts_uses_default_nonce_when_order_is_missing(): void {
			$gateway = ( new \ReflectionClass( Gateway::class ) )->newInstanceWithoutConstructor();

			$GLOBALS['wp'] = (object) array(
				'query_vars' => array( 'order-pay' => 42 ),
			);

			$localized_data = null;

			Functions\stubs(
				array(
					'is_checkout'              => false,
					'is_checkout_pay_page'     => true,
					'woocommerce_pos_request'  => true,
					'wp_enqueue_style'         => true,
					'wp_enqueue_script'        => true,
					'admin_url'                => 'https://example.test/wp-admin/admin-ajax.php',
					'wp_create_nonce'          => 'default-nonce',
					'wp_salt'                  => 'unit-test-salt',
					'__'                       => function ( $text ) {
						return $text;
					},
					'absint'                   => function ( $value ) {
						return abs( (int) $value );
					},
				)
			);

			Functions\when( 'wc_get_order' )->justReturn( false );
			Functions\when( 'wp_localize_script' )->alias(
				function ( $handle, $object_name, $data ) use ( &$localized_data ) {
					$localized_data = $data;

					return true;
				}
			);

			$gateway->enqueue_payment_scripts();

			$this->assertSame( 'default-nonce', $localized_data['nonce'] );
			$this->assertNull( $localized_data['orderKey'] );
		}

		public function test_enqueue_payment_scripts_uses_default_nonce_outside_pos_requests(): void {
			$gateway = ( new \ReflectionClass( Gateway::class ) )->newInstanceWithoutConstructor();

			$GLOBALS['wp'] = (object) array(
				'query_vars' => array( 'order-pay' => 42 ),
			);

			$order = \Mockery::mock( \WC_Abstract_Order::class );
			$order->shouldReceive( 'get_order_key' )->andReturn( 'wc_order_key' );
			$order->shouldReceive( 'get_id' )->andReturn( 42 );

			$localized_data = null;

			Functions\stubs(
				array(
					'is_checkout'              => false,
					'is_checkout_pay_page'     => true,
					'woocommerce_pos_request'  => false,
					'wp_enqueue_style'         => true,
					'wp_enqueue_script'        => true,
					'admin_url'                => 'https://example.test/wp-admin/admin-ajax.php',
					'wp_create_nonce'          => 'default-nonce',
					'wp_salt'                  => 'unit-test-salt',
					'__'                       => function ( $text ) {
						return $text;
					},
					'absint'                   => function ( $value ) {
						return abs( (int) $value );
					},
				)
			);

			Functions\when( 'wc_get_order' )->justReturn( $order );
			Functions\when( 'wp_set_current_user' )->alias(
				function () {
					TestCase::fail( 'Default nonce generation should not switch users outside POS requests.' );
				}
			);
			Functions\when( 'wp_localize_script' )->alias(
				function ( $handle, $object_name, $data ) use ( &$localized_data ) {
					$localized_data = $data;

					return true;
				}
			);

			$gateway->enqueue_payment_scripts();

			$this->assertSame( 'default-nonce', $localized_data['nonce'] );
		}

		public function test_order_received_url_returns_default_outside_pos_requests(): void {
			$gateway = ( new \ReflectionClass( Gateway::class ) )->newInstanceWithoutConstructor();

			$GLOBALS['wp'] = (object) array(
				'query_vars' => array( 'order-pay' => 42 ),
			);

			Functions\stubs(
				array(
					'woocommerce_pos_request' => false,
				)
			);

			$this->assertSame(
				'http://example.test/order-received/42',
				$gateway->order_received_url( 'http://example.test/order-received/42', new \WC_Abstract_Order() )
			);
		}

		public function test_paid_pos_terminal_resubmission_redirects_to_order_received_url(): void {
			$order = $this->prepare_paid_submission_request();
			$order->shouldReceive( 'is_paid' )->once()->andReturn( true );
			$order->shouldReceive( 'get_payment_method' )->once()->andReturn( 'stripe_terminal_for_woocommerce' );
			$order->shouldReceive( 'get_order_key' )->once()->andReturn( 'wc_order_key' );
			$order->shouldReceive( 'get_checkout_order_received_url' )->once()->andReturn( 'https://example.test/wcpos-checkout/order-received/42?key=wc_order_key' );

			$redirect_url = null;
			Functions\when( 'wp_safe_redirect' )->alias(
				function ( $url ) use ( &$redirect_url ) {
					$redirect_url = $url;

					throw new \RuntimeException( 'redirected' );
				}
			);

			try {
				Gateway::maybe_redirect_paid_order_submission();
				$this->fail( 'Expected the paid Terminal submission to redirect.' );
			} catch ( \RuntimeException $e ) {
				$this->assertSame( 'redirected', $e->getMessage() );
			}

			$this->assertSame( 'https://example.test/wcpos-checkout/order-received/42?key=wc_order_key', $redirect_url );
		}

		public function test_unpaid_pos_terminal_submission_does_not_redirect(): void {
			$order = $this->prepare_paid_submission_request();
			$order->shouldReceive( 'get_order_key' )->once()->andReturn( 'wc_order_key' );
			$order->shouldReceive( 'get_payment_method' )->once()->andReturn( 'stripe_terminal_for_woocommerce' );
			$order->shouldReceive( 'is_paid' )->once()->andReturn( false );

			Functions\expect( 'wp_safe_redirect' )->never();

			Gateway::maybe_redirect_paid_order_submission();
		}

		public function test_paid_pos_terminal_submission_with_wrong_key_does_not_redirect(): void {
			$order = $this->prepare_paid_submission_request();
			$order->shouldReceive( 'get_order_key' )->once()->andReturn( 'wc_order_key' );
			$order->shouldReceive( 'get_payment_method' )->never();
			$order->shouldReceive( 'is_paid' )->never();
			$_GET['key'] = 'wrong_key';

			Functions\expect( 'wp_safe_redirect' )->never();

			Gateway::maybe_redirect_paid_order_submission();
		}

		public function test_paid_non_pos_terminal_submission_does_not_redirect(): void {
			$this->prepare_paid_submission_request( false );

			Functions\expect( 'wc_get_order' )->never();
			Functions\expect( 'wp_safe_redirect' )->never();

			Gateway::maybe_redirect_paid_order_submission();
		}

		public function test_paid_pos_non_terminal_submission_does_not_redirect(): void {
			$this->prepare_paid_submission_request();
			$_POST['payment_method'] = 'cod';

			Functions\expect( 'wc_get_order' )->never();
			Functions\expect( 'wp_safe_redirect' )->never();

			Gateway::maybe_redirect_paid_order_submission();
		}

		public function test_check_key_status_does_not_set_webhook_for_invalid_key(): void {
			$gateway = ( new \ReflectionClass( GatewayValidationTestDouble::class ) )->newInstanceWithoutConstructor();
			$gateway->options = array(
				'secret_key' => 'sk_test_wrong_mode',
			);

			Functions\stubs(
				array(
					'__' => function ( $text ) {
						return $text;
					},
				)
			);

			$method = new \ReflectionMethod( Gateway::class, 'check_key_status' );
			if ( PHP_VERSION_ID < 80100 ) {
				$method->setAccessible( true );
			}
			$status = $method->invoke( $gateway, 'live' );

			$this->assertStringContainsString( 'Invalid live API key', $status );
			$this->assertFalse( $gateway->webhook_called );
		}

		public function test_check_key_status_does_not_set_webhook_for_restricted_key(): void {
			$gateway = ( new \ReflectionClass( GatewayValidationTestDouble::class ) )->newInstanceWithoutConstructor();
			$gateway->options = array(
				'secret_key' => 'rk_live_restricted',
			);

			Functions\stubs(
				array(
					'__' => function ( $text ) {
						return $text;
					},
				)
			);

			$method = new \ReflectionMethod( Gateway::class, 'check_key_status' );
			if ( PHP_VERSION_ID < 80100 ) {
				$method->setAccessible( true );
			}
			$status = $method->invoke( $gateway, 'live' );

			$this->assertStringContainsString( 'Restricted Stripe API key format is valid', $status );
			$this->assertFalse( $gateway->webhook_called );
		}


		public function test_process_payment_requires_strict_paid_true_from_stripe_api_check(): void {
			$gateway = ( new \ReflectionClass( Gateway::class ) )->newInstanceWithoutConstructor();

			$_POST['woocommerce_pay'] = '1';

			$order = \Mockery::mock( \WC_Order::class );
			$order->shouldReceive( 'is_paid' )->andReturn( false );
			$order->shouldReceive( 'get_meta' )->with( '_stripe_terminal_payment_intent_id' )->andReturn( '' );
			$order->shouldReceive( 'get_meta' )->with( '_stripe_terminal_charge_id' )->andReturn( '' );
			$order->shouldReceive( 'get_meta' )->with( '_stripe_terminal_payment_status' )->andReturn( '' );
			$order->shouldReceive( 'set_transaction_id' )->never();
			$order->shouldReceive( 'payment_complete' )->never();

			$stripe_service = \Mockery::mock( \WCPOS\WooCommercePOS\StripeTerminal\StripeTerminalService::class );
			$stripe_service->shouldReceive( 'check_payment_status_from_stripe' )
				->with( $order )
				->once()
				->andReturn(
					array(
						'charge'         => array(
							'id'   => 'ch_test',
							'paid' => 1,
						),
						'payment_intent' => array(
							'id'     => 'pi_test',
							'status' => 'succeeded',
						),
					)
				);

			$property = new \ReflectionProperty( Gateway::class, 'stripe_service' );
			if ( PHP_VERSION_ID < 80100 ) {
				$property->setAccessible( true );
			}
			$property->setValue( $gateway, $stripe_service );

			Functions\stubs(
				array(
					'__'          => function ( $text ) {
						return $text;
					},
				)
			);
			Functions\when( 'wc_get_order' )->justReturn( $order );
			Functions\when( 'wc_add_notice' )->alias(
				function ( $message, $type ) {
					TestCase::assertSame( 'error', $type );
					TestCase::assertStringContainsString( 'No successful payment found', $message );
				}
			);

			$this->assertSame( array( 'result' => 'failure' ), $gateway->process_payment( 42 ) );
		}

		public function test_process_payment_redirects_to_order_pay_when_unpaid_from_checkout(): void {
			$gateway = ( new \ReflectionClass( Gateway::class ) )->newInstanceWithoutConstructor();

			$order = \Mockery::mock( \WC_Order::class );
			$order->shouldReceive( 'is_paid' )->andReturn( false );
			$order->shouldReceive( 'get_meta' )->with( '_stripe_terminal_payment_intent_id' )->andReturn( '' );
			$order->shouldReceive( 'get_meta' )->with( '_stripe_terminal_charge_id' )->andReturn( '' );
			$order->shouldReceive( 'get_meta' )->with( '_stripe_terminal_payment_status' )->andReturn( '' );
			$order->shouldReceive( 'get_checkout_payment_url' )->withNoArgs()->once()->andReturn( 'https://example.test/checkout/order-pay/42/?pay_for_order=true&key=wc_order_key' );
			$order->shouldReceive( 'set_transaction_id' )->never();
			$order->shouldReceive( 'payment_complete' )->never();

			$property = new \ReflectionProperty( Gateway::class, 'stripe_service' );
			if ( PHP_VERSION_ID < 80100 ) {
				$property->setAccessible( true );
			}
			$property->setValue( $gateway, null );

			Functions\when( 'wc_get_order' )->justReturn( $order );
			Functions\when( 'wc_add_notice' )->alias(
				function () {
					TestCase::fail( 'Checkout Place Order should redirect, not add an error notice.' );
				}
			);

			$this->assertSame(
				array(
					'result'   => 'success',
					'redirect' => 'https://example.test/checkout/order-pay/42/?pay_for_order=true&key=wc_order_key',
				),
				$gateway->process_payment( 42 )
			);
		}

		public function test_process_payment_fails_on_order_pay_without_terminal_payment(): void {
			$gateway = ( new \ReflectionClass( Gateway::class ) )->newInstanceWithoutConstructor();

			$_POST['woocommerce_pay'] = '1';

			$order = \Mockery::mock( \WC_Order::class );
			$order->shouldReceive( 'is_paid' )->andReturn( false );
			$order->shouldReceive( 'get_meta' )->with( '_stripe_terminal_payment_intent_id' )->andReturn( '' );
			$order->shouldReceive( 'get_meta' )->with( '_stripe_terminal_charge_id' )->andReturn( '' );
			$order->shouldReceive( 'get_meta' )->with( '_stripe_terminal_payment_status' )->andReturn( '' );
			$order->shouldReceive( 'get_checkout_payment_url' )->never();
			$order->shouldReceive( 'set_transaction_id' )->never();
			$order->shouldReceive( 'payment_complete' )->never();

			$property = new \ReflectionProperty( Gateway::class, 'stripe_service' );
			if ( PHP_VERSION_ID < 80100 ) {
				$property->setAccessible( true );
			}
			$property->setValue( $gateway, null );

			Functions\stubs(
				array(
					'__' => function ( $text ) {
						return $text;
					},
				)
			);
			Functions\when( 'wc_get_order' )->justReturn( $order );
			Functions\when( 'wc_add_notice' )->alias(
				function ( $message, $type ) {
					TestCase::assertSame( 'error', $type );
					TestCase::assertStringContainsString( 'No successful payment found', $message );
				}
			);

			$this->assertSame( array( 'result' => 'failure' ), $gateway->process_payment( 42 ) );
		}

		public function test_process_payment_completes_order_when_terminal_meta_succeeded(): void {
			$gateway = new class() extends Gateway {
				public function __construct() {
					// Skip parent constructor; only exercise process_payment.
				}

				public function get_return_url( $order = null ) {
					return 'https://example.test/checkout/order-received/42/';
				}
			};

			$order = \Mockery::mock( \WC_Order::class );
			$order->shouldReceive( 'is_paid' )->andReturn( false );
			$order->shouldReceive( 'get_meta' )->with( '_stripe_terminal_payment_intent_id' )->andReturn( 'pi_123' );
			$order->shouldReceive( 'get_meta' )->with( '_stripe_terminal_charge_id' )->andReturn( 'ch_123' );
			$order->shouldReceive( 'get_meta' )->with( '_stripe_terminal_payment_status' )->andReturn( 'succeeded' );
			$order->shouldReceive( 'set_transaction_id' )->with( 'ch_123' )->once();
			$order->shouldReceive( 'payment_complete' )->with( 'ch_123' )->once();
			$order->shouldReceive( 'add_order_note' )->once();

			$property = new \ReflectionProperty( Gateway::class, 'stripe_service' );
			if ( PHP_VERSION_ID < 80100 ) {
				$property->setAccessible( true );
			}
			$property->setValue( $gateway, null );

			Functions\stubs(
				array(
					'__' => function ( $text ) {
						return $text;
					},
				)
			);
			Functions\when( 'wc_get_order' )->justReturn( $order );

			$this->assertSame(
				array(
					'result'   => 'success',
					'redirect' => 'https://example.test/checkout/order-received/42/',
				),
				$gateway->process_payment( 42 )
			);
		}

		/**
		 * Prepare a submitted POS Terminal order-pay request.
		 *
		 * @param bool $is_pos_request Whether this is a POS checkout request.
		 * @return \Mockery\MockInterface
		 */
		private function prepare_paid_submission_request( bool $is_pos_request = true ) {
			$this->assertTrue(
				is_callable( array( Gateway::class, 'maybe_redirect_paid_order_submission' ) ),
				'Gateway must expose paid Terminal submission recovery.'
			);

			$_POST = array(
				'woocommerce_pay' => '1',
				'payment_method'  => 'stripe_terminal_for_woocommerce',
			);
			$_GET  = array(
				'key' => 'wc_order_key',
			);
			$GLOBALS['wp'] = (object) array(
				'query_vars' => array( 'order-pay' => 42 ),
			);

			$order = \Mockery::mock( \WC_Order::class );

			Functions\stubs(
				array(
					'woocommerce_pos_request' => $is_pos_request,
					'wp_unslash'              => function ( $value ) {
						return $value;
					},
					'sanitize_text_field'     => function ( $value ) {
						return $value;
					},
					'absint'                  => function ( $value ) {
						return abs( (int) $value );
					},
					'wc_nocache_headers'      => true,
				)
			);
			Functions\when( 'wc_get_order' )->justReturn( $order );

			return $order;
		}
	}
}
