<?php
/**
 * Tests for the AjaxHandler class.
 *
 * Focuses on input validation — verifying that missing or invalid
 * parameters are rejected with the correct error messages before
 * any Stripe service interaction occurs.
 */

namespace WCPOS\WooCommercePOS\StripeTerminal\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\StripeTerminal\AjaxHandler;

/**
 * Sentinel thrown by the wp_send_json_error stub.
 *
 * Extends \Error instead of \Exception so the AjaxHandler's
 * catch (Exception $e) blocks do not swallow it.
 */
class JsonErrorSentinel extends \Error {
	/**
	 * @var mixed
	 */
	public $data;

	public function __construct( $data = null ) {
		$this->data = $data;
		parent::__construct( 'wp_send_json_error' );
	}
}

/**
 * @covers \WCPOS\WooCommercePOS\StripeTerminal\AjaxHandler
 */
class AjaxHandlerTest extends TestCase {

	/**
	 * The AjaxHandler instance under test.
	 *
	 * @var AjaxHandler
	 */
	private $handler;

	/**
	 * Set up Brain\Monkey and stub WordPress functions before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Stub core WordPress/WooCommerce functions used during construction
		// and inside every handler method.
		Functions\stubs(
			array(
				'add_action'          => true,
				'get_option'          => function () {
					// Return empty settings so init_stripe_service() never
					// finds an API key — the service stays null.
					return array();
				},
				'absint'              => function ( $val ) {
					return abs( (int) $val );
				},
				'sanitize_text_field' => function ( $str ) {
					return $str;
				},
				'__'                  => function ( $text ) {
					return $text;
				},
			)
		);

		// Throw a sentinel Error (not Exception) so we can capture the
		// argument and escape the handler method without the catch block
		// swallowing it.
		Functions\when( 'wp_send_json_error' )->alias(
			function ( $data = null ) {
				throw new JsonErrorSentinel( $data );
			}
		);

		// wp_send_json_success should not be reached in validation-failure
		// tests, but stub it to avoid "undefined function" noise.
		Functions\when( 'wp_send_json_success' )->justReturn( null );

		$this->handler = new AjaxHandler();
	}

	/**
	 * Tear down Brain\Monkey and reset global state after each test.
	 */
	protected function tearDown(): void {
		$_POST = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Helper: call a handler method and return the error data.
	 *
	 * @param string $method The AjaxHandler method to call.
	 * @return mixed The data argument passed to wp_send_json_error.
	 */
	private function call_and_capture_error( string $method ) {
		try {
			$this->handler->$method();
			$this->fail( 'Expected wp_send_json_error to be called' );
		} catch ( JsonErrorSentinel $e ) {
			return $e->data;
		}
	}

	// -------------------------------------------------------------------
	// create_payment_intent — missing parameters
	// -------------------------------------------------------------------

	public function test_create_payment_intent_rejects_missing_order_id(): void {
		$_POST = array(
			'amount'    => '1000',
			'reader_id' => 'tmr_abc123',
		);

		$error = $this->call_and_capture_error( 'create_payment_intent' );
		$this->assertSame( 'Missing order ID, amount, or reader ID', $error );
	}

	public function test_create_payment_intent_rejects_missing_amount(): void {
		$_POST = array(
			'order_id'  => '42',
			'reader_id' => 'tmr_abc123',
		);

		$error = $this->call_and_capture_error( 'create_payment_intent' );
		$this->assertSame( 'Missing order ID, amount, or reader ID', $error );
	}

	public function test_create_payment_intent_rejects_missing_reader_id(): void {
		$_POST = array(
			'order_id' => '42',
			'amount'   => '1000',
		);

		$error = $this->call_and_capture_error( 'create_payment_intent' );
		$this->assertSame( 'Missing order ID, amount, or reader ID', $error );
	}

	public function test_create_payment_intent_rejects_all_missing_params(): void {
		$_POST = array();

		$error = $this->call_and_capture_error( 'create_payment_intent' );
		$this->assertSame( 'Missing order ID, amount, or reader ID', $error );
	}

	// -------------------------------------------------------------------
	// create_payment_intent — invalid order
	// -------------------------------------------------------------------

	public function test_create_payment_intent_rejects_invalid_order(): void {
		$_POST = array(
			'order_id'  => '999',
			'amount'    => '1000',
			'reader_id' => 'tmr_abc123',
		);

		Functions\when( 'wc_get_order' )->justReturn( false );

		$error = $this->call_and_capture_error( 'create_payment_intent' );
		$this->assertSame( 'Order not found', $error );
	}

	// -------------------------------------------------------------------
	// confirm_payment — missing parameters
	// -------------------------------------------------------------------

	public function test_confirm_payment_rejects_missing_payment_intent_id(): void {
		$_POST = array(
			'order_id' => '42',
		);

		$error = $this->call_and_capture_error( 'confirm_payment' );
		$this->assertSame( 'Missing payment intent ID or order ID', $error );
	}

	public function test_confirm_payment_rejects_missing_order_id(): void {
		$_POST = array(
			'payment_intent_id' => 'pi_abc123',
		);

		$error = $this->call_and_capture_error( 'confirm_payment' );
		$this->assertSame( 'Missing payment intent ID or order ID', $error );
	}

	public function test_confirm_payment_rejects_all_missing_params(): void {
		$_POST = array();

		$error = $this->call_and_capture_error( 'confirm_payment' );
		$this->assertSame( 'Missing payment intent ID or order ID', $error );
	}

	// -------------------------------------------------------------------
	// cancel_payment — missing parameters
	// -------------------------------------------------------------------

	public function test_cancel_payment_rejects_missing_payment_intent_id(): void {
		$_POST = array(
			'order_id' => '42',
		);

		$error = $this->call_and_capture_error( 'cancel_payment' );
		$this->assertSame( 'Missing payment intent ID or order ID', $error );
	}

	public function test_cancel_payment_rejects_missing_order_id(): void {
		$_POST = array(
			'payment_intent_id' => 'pi_abc123',
		);

		$error = $this->call_and_capture_error( 'cancel_payment' );
		$this->assertSame( 'Missing payment intent ID or order ID', $error );
	}

	public function test_cancel_payment_rejects_all_missing_params(): void {
		$_POST = array();

		$error = $this->call_and_capture_error( 'cancel_payment' );
		$this->assertSame( 'Missing payment intent ID or order ID', $error );
	}

	// -------------------------------------------------------------------
	// check_payment_status — missing parameters
	// -------------------------------------------------------------------

	public function test_check_payment_status_rejects_missing_order_id(): void {
		$_POST = array();

		$error = $this->call_and_capture_error( 'check_payment_status' );
		$this->assertSame( 'Missing order ID', $error );
	}

	// -------------------------------------------------------------------
	// simulate_payment — missing parameters
	// -------------------------------------------------------------------

	public function test_simulate_payment_rejects_missing_reader_id(): void {
		$_POST = array(
			'order_id' => '42',
		);

		$error = $this->call_and_capture_error( 'simulate_payment' );
		$this->assertSame( 'Missing reader ID', $error );
	}

	public function test_simulate_payment_rejects_missing_order_id(): void {
		$_POST = array(
			'reader_id' => 'tmr_abc123',
		);

		$error = $this->call_and_capture_error( 'simulate_payment' );
		$this->assertSame( 'Missing order ID', $error );
	}

	// -------------------------------------------------------------------
	// Service-not-initialized checks
	// -------------------------------------------------------------------

	public function test_get_reader_status_fails_without_service(): void {
		$error = $this->call_and_capture_error( 'get_reader_status' );
		$this->assertSame(
			'Stripe service not initialized - check API key configuration',
			$error
		);
	}

	public function test_validate_service_fails_without_service(): void {
		$error = $this->call_and_capture_error( 'validate_service' );
		$this->assertSame(
			'Stripe service not initialized - check API key configuration',
			$error
		);
	}

	public function test_get_readers_fails_without_service(): void {
		$error = $this->call_and_capture_error( 'get_readers' );
		$this->assertSame(
			'Stripe service not initialized - check API key configuration',
			$error
		);
	}

	// -------------------------------------------------------------------
	// check_stripe_status — missing parameters
	// -------------------------------------------------------------------

	public function test_check_stripe_status_rejects_missing_order_id(): void {
		$_POST = array();

		$error = $this->call_and_capture_error( 'check_stripe_status' );
		$this->assertSame( 'Missing order ID', $error );
	}

	// -------------------------------------------------------------------
	// confirm_payment / cancel_payment — invalid order
	// -------------------------------------------------------------------

	public function test_confirm_payment_rejects_invalid_order(): void {
		$_POST = array(
			'payment_intent_id' => 'pi_abc123',
			'order_id'          => '999',
		);

		Functions\when( 'wc_get_order' )->justReturn( false );

		$error = $this->call_and_capture_error( 'confirm_payment' );
		$this->assertSame( 'Order not found', $error );
	}

	public function test_cancel_payment_rejects_invalid_order(): void {
		$_POST = array(
			'payment_intent_id' => 'pi_abc123',
			'order_id'          => '999',
		);

		Functions\when( 'wc_get_order' )->justReturn( false );

		$error = $this->call_and_capture_error( 'cancel_payment' );
		$this->assertSame( 'Order not found', $error );
	}
}
