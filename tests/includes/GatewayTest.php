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
}

namespace WCPOS\WooCommercePOS\StripeTerminal\Tests {

	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use PHPUnit\Framework\TestCase;
	use WCPOS\WooCommercePOS\StripeTerminal\Gateway;

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
			$order->shouldReceive( 'get_meta' )->with( '_pos_user', true )->andReturn( '2' );

			$current_user_id = 99;
			$switched_users  = array();
			$localized_data  = null;

			Functions\stubs(
				array(
					'is_checkout'          => false,
					'is_checkout_pay_page' => true,
					'wp_enqueue_style'     => true,
					'wp_enqueue_script'    => true,
					'admin_url'            => 'https://example.test/wp-admin/admin-ajax.php',
					'__'                   => function ( $text ) {
						return $text;
					},
					'absint'               => function ( $value ) {
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

		public function test_order_received_url_returns_default_when_pos_helper_is_unavailable(): void {
			$gateway = ( new \ReflectionClass( Gateway::class ) )->newInstanceWithoutConstructor();

			$GLOBALS['wp'] = (object) array(
				'query_vars' => array( 'order-pay' => 42 ),
			);

			$this->assertSame(
				'http://example.test/order-received/42',
				$gateway->order_received_url( 'http://example.test/order-received/42', new \WC_Abstract_Order() )
			);
		}
	}
}
