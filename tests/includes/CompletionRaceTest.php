<?php
/**
 * Regression #129: the payment_intent.succeeded webhook and the order-pay
 * submit could each complete one order, reducing stock twice.
 *
 * Both requests load the unpaid order before either completes it, so the
 * second still holds an unpaid in-memory copy after the first has paid the
 * database row. Completion must take a per-order claim and decide from a
 * copy re-read with the post and HPOS order caches cleared.
 */

namespace {
	// Same guarded stubs as APITest and GatewayTest; whichever file loads first defines them.
	if ( ! class_exists( 'WP_REST_Controller' ) ) {
		class WP_REST_Controller {}
	}

	if ( ! class_exists( 'WP_REST_Request' ) ) {
		class WP_REST_Request {}
	}

	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		class WC_Payment_Gateway {
			public $id;
			public $method_title;
			public $method_description;
			public $title;
			public $description;
			public $supports = array( 'products' );
			public $form_fields = array();
			public $options = array();

			public function get_option( $key ) {
				return 'enable_moto' === $key ? 'no' : ( $this->options[ $key ] ?? null );
			}

			public function init_settings() {}

			public function supports( $feature ) {
				return in_array( $feature, $this->supports, true );
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
	use WCPOS\WooCommercePOS\StripeTerminal\API;
	use WCPOS\WooCommercePOS\StripeTerminal\Gateway;
	use WCPOS\WooCommercePOS\StripeTerminal\Tests\Support\FakeWpdb;

	/**
	 * One request's copy of the order row. Like WC_Data, save() writes only
	 * the fields this copy changed, so a stale copy does not undo the other
	 * request's writes, but its in-memory reads stay stale.
	 */
	class RaceOrder extends \WC_Order {
		/** @var CompletionRaceTest */
		private $db;
		private $row;
		private $changed_fields = array();
		private $changed_meta   = array();

		public function __construct( CompletionRaceTest $db, array $row ) {
			$this->db  = $db;
			$this->row = $row;
		}

		public function get_id() {
			return 42;
		}

		public function get_order_key() {
			return 'wc_order_key';
		}

		public function get_total() {
			return '10.00';
		}

		public function get_currency() {
			return 'USD';
		}

		public function get_meta( $key, $single = true ) {
			return $this->row['meta'][ $key ] ?? '';
		}

		public function get_transaction_id() {
			return $this->row['transaction_id'];
		}

		public function get_payment_method() {
			return $this->row['payment_method'];
		}

		public function get_payment_method_title() {
			return $this->row['payment_method_title'];
		}

		public function get_status() {
			return $this->row['status'];
		}

		public function is_paid() {
			return in_array( $this->row['status'], array( 'processing', 'completed' ), true );
		}

		public function needs_payment() {
			return in_array( $this->row['status'], array( 'pending', 'failed' ), true );
		}

		public function update_meta_data( $key, $value ) {
			if ( ! array_key_exists( $key, $this->row['meta'] ) || $this->row['meta'][ $key ] !== $value ) {
				$this->row['meta'][ $key ]  = $value;
				$this->changed_meta[ $key ] = true;
			}
		}

		public function delete_meta_data( $key ) {
			if ( array_key_exists( $key, $this->row['meta'] ) ) {
				unset( $this->row['meta'][ $key ] );
				$this->changed_meta[ $key ] = true;
			}
		}

		public function set_transaction_id( $id ) {
			$this->set_field( 'transaction_id', $id );
		}

		public function set_payment_method( $method ) {
			$this->set_field( 'payment_method', $method );
		}

		public function set_payment_method_title( $title ) {
			$this->set_field( 'payment_method_title', $title );
		}

		public function add_order_note( $note ) {
			$this->db->notes[] = $note;
		}

		public function save() {
			$this->db->write( $this->row, $this->changed_fields, $this->changed_meta );
			$this->changed_fields = array();
			$this->changed_meta   = array();

			return 42;
		}

		/**
		 * Stands in for WC_Order::payment_complete(): it trusts the in-memory
		 * status, and wc_maybe_reduce_stock_levels() on woocommerce_payment_complete
		 * trusts the in-memory _order_stock_reduced flag.
		 */
		public function payment_complete( $transaction_id = '' ) {
			$this->db->claim_held_during_completion[] = $this->db->claim_is_held();
			if ( $this->db->throw_on_completion ) {
				$this->db->throw_on_completion = false;
				throw new \RuntimeException( 'completion failed' );
			}
			if ( ! in_array( $this->row['status'], array( 'pending', 'failed', 'on-hold' ), true ) ) {
				return false;
			}
			++$this->db->payment_complete_calls;
			$this->set_field( 'status', 'processing' );
			$this->set_transaction_id( $transaction_id );
			if ( 'yes' !== $this->get_meta( '_order_stock_reduced' ) ) {
				++$this->db->stock_reductions;
				$this->update_meta_data( '_order_stock_reduced', 'yes' );
			}
			$this->save();

			return true;
		}

		private function set_field( string $field, $value ): void {
			if ( $this->row[ $field ] !== $value ) {
				$this->row[ $field ]              = $value;
				$this->changed_fields[ $field ] = true;
			}
		}
	}

	class RaceGateway extends Gateway {
		public function get_return_url( $order = null ) {
			return 'https://example.test/checkout/order-received/' . $order->get_id() . '/?key=' . $order->get_order_key();
		}
	}

	/**
	 * @covers \WCPOS\WooCommercePOS\StripeTerminal\API
	 * @covers \WCPOS\WooCommercePOS\StripeTerminal\Gateway
	 */
	class CompletionRaceTest extends TestCase {
		const CLAIM_KEY = 'stwc_lock_order_42_complete_payment';

		/** @var array The order's database row. */
		public $row;
		/** @var array<string,array> Per-request post cache: request => order id => row snapshot. */
		public $post_cache = array();
		/** @var array<string,array> Per-request HPOS OrderCache: request => order id => row snapshot. */
		public $hpos_cache = array();
		/** @var string The request currently running. */
		public $request = 'webhook';
		public $notes                        = array();
		public $payment_complete_calls       = 0;
		public $stock_reductions             = 0;
		public $claim_held_during_completion = array();
		public $throw_on_completion          = false;
		public $hpos                         = false;
		/** @var FakeWpdb */
		public $wpdb;
		private $previous_wpdb;

		protected function setUp(): void {
			parent::setUp();
			Monkey\setUp();
			$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
			$this->wpdb          = new FakeWpdb();
			$GLOBALS['wpdb']     = $this->wpdb;
			$this->row           = array(
				'status'               => 'pending',
				'transaction_id'       => '',
				'payment_method'       => 'stripe_terminal_for_woocommerce',
				'payment_method_title' => 'Stripe Terminal',
				// The capture/status poll has already recorded the Terminal payment.
				'meta'                 => array(
					'_stripe_terminal_payment_intent_id' => 'pi_race',
					'_stripe_terminal_charge_id'         => 'ch_race',
					'_stripe_terminal_payment_status'    => 'succeeded',
				),
			);

			$test = $this;
			Functions\when( '__' )->returnArg();
			Functions\when( 'get_option' )->justReturn( array() );
			Functions\when( 'wp_generate_uuid4' )->alias(
				function () {
					static $n = 0;

					return 'uuid-' . ++$n;
				}
			);
			Functions\when( 'sanitize_key' )->alias(
				function ( $key ) {
					return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
				}
			);
			Functions\when( 'wc_get_order' )->alias(
				function ( $id ) use ( $test ) {
					return $test->load( (int) $id );
				}
			);
			Functions\when( 'clean_post_cache' )->alias(
				function ( $id ) use ( $test ) {
					unset( $test->post_cache[ $test->request ][ (int) $id ] );
				}
			);
		}

		protected function tearDown(): void {
			$GLOBALS['wpdb'] = $this->previous_wpdb;
			$_POST           = array();
			\Mockery::close();
			Monkey\tearDown();
			parent::tearDown();
		}

		/** wc_get_order() for the current request: cached copies win over the row. */
		public function load( int $id ) {
			if ( 42 !== $id ) {
				return false;
			}
			if ( $this->hpos && isset( $this->hpos_cache[ $this->request ][ $id ] ) ) {
				return new RaceOrder( $this, $this->hpos_cache[ $this->request ][ $id ] );
			}
			if ( ! isset( $this->post_cache[ $this->request ][ $id ] ) ) {
				$this->post_cache[ $this->request ][ $id ] = $this->row;
			}
			if ( $this->hpos ) {
				$this->hpos_cache[ $this->request ][ $id ] = $this->post_cache[ $this->request ][ $id ];
			}

			return new RaceOrder( $this, $this->post_cache[ $this->request ][ $id ] );
		}

		public function write( array $copy, array $fields, array $meta ): void {
			foreach ( array_keys( $fields ) as $field ) {
				$this->row[ $field ] = $copy[ $field ];
			}
			foreach ( array_keys( $meta ) as $key ) {
				if ( array_key_exists( $key, $copy['meta'] ) ) {
					$this->row['meta'][ $key ] = $copy['meta'][ $key ];
				} else {
					unset( $this->row['meta'][ $key ] );
				}
			}
		}

		public function claim_is_held(): bool {
			return isset( $this->wpdb->rows[ self::CLAIM_KEY ] );
		}

		public function test_webhook_then_stale_order_pay_submit_completes_once(): void {
			// The order-pay request loads the order (WooCommerce's pay action) while it is unpaid.
			$this->in_request( 'order-pay' );
			wc_get_order( 42 );

			$this->in_request( 'webhook' );
			$this->run_webhook();

			$this->in_request( 'order-pay' );
			$result = $this->run_order_pay();

			$this->assert_completed_once();
			$this->assertSame( 'success', $result['result'] );
			$this->assertSame( 'https://example.test/checkout/order-received/42/?key=wc_order_key', $result['redirect'] );
		}

		public function test_order_pay_submit_then_stale_webhook_completes_once(): void {
			// The webhook request loads the order while it is unpaid.
			$this->in_request( 'webhook' );
			wc_get_order( 42 );

			$this->in_request( 'order-pay' );
			$this->run_order_pay();

			$this->in_request( 'webhook' );
			$this->run_webhook();

			$this->assert_completed_once();
		}

		public function test_webhook_then_stale_stripe_check_completes_once(): void {
			// No charge recorded locally: process_payment() asks Stripe instead.
			unset( $this->row['meta']['_stripe_terminal_charge_id'], $this->row['meta']['_stripe_terminal_payment_status'] );
			$this->in_request( 'order-pay' );
			wc_get_order( 42 );

			$this->in_request( 'webhook' );
			$this->run_webhook();

			$this->in_request( 'order-pay' );
			$result = $this->run_order_pay( $this->succeeded_stripe_service() );

			$this->assert_completed_once();
			$this->assertSame( 'success', $result['result'] );
		}

		public function test_completion_holds_the_claim_and_releases_it(): void {
			$this->run_webhook();

			$this->assertSame( array( true ), $this->claim_held_during_completion );
			$this->assertFalse( $this->claim_is_held() );
		}

		public function test_held_claim_skips_completion_and_still_redirects_order_pay(): void {
			$held                                 = json_encode( array( 'token' => 'other-request', 'expires_at' => time() + 60 ) );
			$this->wpdb->rows[ self::CLAIM_KEY ] = $held;
			$before                               = $this->row;

			$result = $this->run_order_pay();
			$this->run_webhook();

			$this->assertSame( 0, $this->payment_complete_calls );
			$this->assertSame( 0, $this->stock_reductions );
			$this->assertSame( 'pending', $this->row['status'] );
			$this->assertSame( $before['transaction_id'], $this->row['transaction_id'] );
			$this->assertSame( $held, $this->wpdb->rows[ self::CLAIM_KEY ], 'a request must not release a claim it does not hold' );
			$this->assertSame( 'success', $result['result'] );
			$this->assertSame( 'https://example.test/checkout/order-received/42/?key=wc_order_key', $result['redirect'] );
		}

		public function test_expired_claim_is_taken_over(): void {
			$this->wpdb->rows[ self::CLAIM_KEY ] = json_encode( array( 'token' => 'dead', 'expires_at' => time() - 60 ) );

			$this->run_webhook();

			$this->assert_completed_once();
			$this->assertFalse( $this->claim_is_held() );
		}

		public function test_failed_completion_releases_the_claim(): void {
			$this->throw_on_completion = true;
			try {
				$this->run_webhook();
				$this->fail( 'the completion exception must propagate' );
			} catch ( \RuntimeException $e ) {
				$this->assertSame( 'completion failed', $e->getMessage() );
			}
			$this->assertFalse( $this->claim_is_held() );

			$this->run_order_pay();

			$this->assert_completed_once();
		}

		/**
		 * HPOS keeps its own per-request OrderCache, which the reload must clear too.
		 *
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_hpos_order_cache_is_cleared_before_completion(): void {
			$test       = $this;
			$this->hpos = true;
			if ( ! class_exists( 'Automattic\WooCommerce\Caches\OrderCache' ) ) {
				eval( 'namespace Automattic\WooCommerce\Caches; class OrderCache { public static $remove; public function remove( $id ) { ( self::$remove )( $id ); } }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Separate-process test double for a WooCommerce class.
			}
			\Automattic\WooCommerce\Caches\OrderCache::$remove = function ( $id ) use ( $test ) {
				unset( $test->hpos_cache[ $test->request ][ (int) $id ] );
			};
			Functions\when( 'wc_get_container' )->alias(
				function () {
					return new class() {
						public function get( $id ) {
							TestCase::assertSame( 'Automattic\WooCommerce\Caches\OrderCache', $id );

							return new \Automattic\WooCommerce\Caches\OrderCache();
						}
					};
				}
			);

			$this->in_request( 'order-pay' );
			wc_get_order( 42 );

			$this->in_request( 'webhook' );
			$this->run_webhook();

			$this->in_request( 'order-pay' );
			$this->run_order_pay();

			$this->assert_completed_once();
		}

		private function in_request( string $request ): void {
			$this->request = $request;
		}

		private function run_webhook(): void {
			$payment_intent = (object) array(
				'id'                   => 'pi_race',
				'latest_charge'        => 'ch_race',
				'livemode'             => false,
				'metadata'             => (object) array( 'order_id' => 42 ),
				'amount'               => 1000,
				'currency'             => 'usd',
				'status'               => 'succeeded',
				'payment_method_types' => array( 'card_present' ),
			);
			$api            = ( new \ReflectionClass( API::class ) )->newInstanceWithoutConstructor();
			$method         = new \ReflectionMethod( API::class, 'update_order_with_payment_intent' );
			if ( 80100 > PHP_VERSION_ID ) {
				$method->setAccessible( true );
			}
			$method->invoke( $api, $payment_intent );
		}

		private function run_order_pay( $stripe_service = null ): array {
			$_POST['woocommerce_pay'] = '1';
			Functions\when( 'wc_add_notice' )->justReturn( null );
			$gateway  = ( new \ReflectionClass( RaceGateway::class ) )->newInstanceWithoutConstructor();
			$property = new \ReflectionProperty( Gateway::class, 'stripe_service' );
			if ( PHP_VERSION_ID < 80100 ) {
				$property->setAccessible( true );
			}
			$property->setValue( $gateway, $stripe_service );

			return $gateway->process_payment( 42 );
		}

		private function succeeded_stripe_service() {
			$service = \Mockery::mock( \WCPOS\WooCommercePOS\StripeTerminal\StripeTerminalService::class );
			$service->shouldReceive( 'check_payment_status_from_stripe' )->andReturn(
				array(
					'charge'         => array(
						'id'   => 'ch_race',
						'paid' => true,
					),
					'payment_intent' => array(
						'id'     => 'pi_race',
						'status' => 'succeeded',
					),
				)
			);

			return $service;
		}

		private function assert_completed_once(): void {
			// Stock first: it is the merchant-visible damage.
			$this->assertSame( 1, $this->stock_reductions, 'stock must be reduced once' );
			$this->assertSame( 1, $this->payment_complete_calls, 'payment_complete() must run once' );
			$this->assertSame( 'processing', $this->row['status'] );
			$this->assertSame( 'ch_race', $this->row['transaction_id'] );
		}
	}
}
