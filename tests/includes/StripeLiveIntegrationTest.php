<?php
/**
 * Stripe and request-contract smoke tests.
 *
 * Stripe tests intentionally hit Stripe's real test-mode API when
 * STWC_STRIPE_TEST_SECRET_KEY is available. They skip otherwise.
 */

namespace WCPOS\WooCommercePOS\StripeTerminal\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\StripeTerminal\AjaxHandler;

/**
 * @coversNothing
 */
class StripeLiveIntegrationTest extends TestCase {
	/**
	 * Stripe test secret key loaded from environment or local ignored env file.
	 *
	 * @var string
	 */
	private $secret_key;

	/**
	 * @var string|false
	 */
	private $previous_secret_key_env = false;

	/**
	 * @var bool
	 */
	private $previous_secret_key_superglobal_exists = false;

	/**
	 * @var string|null
	 */
	private $previous_secret_key_superglobal;

	protected function setUp(): void {
		parent::setUp();

		$this->previous_secret_key_env                = getenv( 'STWC_STRIPE_TEST_SECRET_KEY' );
		$this->previous_secret_key_superglobal_exists = array_key_exists( 'STWC_STRIPE_TEST_SECRET_KEY', $_ENV );
		$this->previous_secret_key_superglobal        = $this->previous_secret_key_superglobal_exists ? $_ENV['STWC_STRIPE_TEST_SECRET_KEY'] : null;
	}

	public function test_invalid_ajax_nonce_returns_invalid_request_before_payment_validation(): void {
		$original_post = $_POST;

		Monkey\setUp();
		try {
			$_POST = array(
				'nonce'     => 'invalid_nonce',
				'order_id'  => '42',
				'amount'    => '1234',
				'reader_id' => 'tmr_test_reader',
				'order_key' => 'wc_order_test',
			);

			Functions\stubs(
				array(
					'add_action'          => true,
					'get_option'          => array(),
					'absint'              => function ( $value ) {
						return abs( (int) $value );
					},
					'sanitize_text_field' => function ( $value ) {
						return $value;
					},
					'wp_unslash'          => function ( $value ) {
						return $value;
					},
				)
			);
			$order = \Mockery::mock( 'WC_Order' );
			$order->shouldReceive( 'get_order_key' )->andReturn( 'wc_order_test' );
			Functions\when( 'wc_get_order' )->justReturn( $order );
			Functions\when( 'check_ajax_referer' )->justReturn( false );
			Functions\when( 'wp_send_json_error' )->alias(
				function ( $data = null ) {
					throw new StripeLiveJsonErrorSentinel( $data );
				}
			);

			$handler = new AjaxHandler();

			try {
				$handler->create_payment_intent();
				$this->fail( 'Expected invalid nonce to return a JSON error.' );
			} catch ( StripeLiveJsonErrorSentinel $error ) {
				$this->assertSame( 'Security token expired or invalid. Please refresh or reopen the POS checkout and try again.', $error->data );
			}
		} finally {
			$_POST = $original_post;
			\Mockery::close();
			Monkey\tearDown();
		}
	}

	public function test_creates_terminal_connection_token_against_stripe_test_mode(): void {
		$this->require_stripe_test_key();

		$token = \Stripe\Terminal\ConnectionToken::create();

		$this->assertIsString( $token->secret );
		$this->assertStringStartsWith( 'pst_test_', $token->secret );
	}

	public function test_creates_card_present_payment_intent_against_stripe_test_mode(): void {
		$this->require_stripe_test_key();

		$payment_intent = \Stripe\PaymentIntent::create(
			array(
				'amount'               => 1234,
				'currency'             => 'usd',
				'payment_method_types' => array( 'card_present' ),
				'capture_method'       => 'manual',
				'description'          => 'STWC live integration smoke test',
				'metadata'             => array(
					'stwc_test' => 'stripe_live_integration',
				),
			)
		);

		$this->assertStringStartsWith( 'pi_', $payment_intent->id );
		$this->assertSame( 1234, $payment_intent->amount );
		$this->assertSame( 'usd', $payment_intent->currency );
		$this->assertSame( array( 'card_present' ), $payment_intent->payment_method_types );
		$this->assertIsString( $payment_intent->client_secret );
		$this->assertStringContainsString( '_secret_', $payment_intent->client_secret );
	}

	private function require_stripe_test_key(): void {
		$this->load_local_env_file();

		$this->secret_key = (string) getenv( 'STWC_STRIPE_TEST_SECRET_KEY' );
		if ( '' === $this->secret_key ) {
			$this->markTestSkipped( 'STWC_STRIPE_TEST_SECRET_KEY is not set.' );
		}

		$this->assertStringStartsWith( 'sk_test_', $this->secret_key, 'Stripe integration tests must use a test-mode secret key.' );
		\Stripe\Stripe::setApiKey( $this->secret_key );
	}

	protected function tearDown(): void {
		\Stripe\Stripe::setApiKey( '' );

		if ( false === $this->previous_secret_key_env ) {
			putenv( 'STWC_STRIPE_TEST_SECRET_KEY' );
		} else {
			putenv( 'STWC_STRIPE_TEST_SECRET_KEY=' . $this->previous_secret_key_env );
		}

		if ( $this->previous_secret_key_superglobal_exists ) {
			$_ENV['STWC_STRIPE_TEST_SECRET_KEY'] = $this->previous_secret_key_superglobal;
		} else {
			unset( $_ENV['STWC_STRIPE_TEST_SECRET_KEY'] );
		}

		parent::tearDown();
	}

	private function load_local_env_file(): void {
		$env_file = dirname( __DIR__, 2 ) . '/.env.local';
		if ( ! is_readable( $env_file ) ) {
			return;
		}

		$lines = file( $env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		if ( false === $lines ) {
			return;
		}

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === $line[0] || false === strpos( $line, '=' ) ) {
				continue;
			}

			list( $name, $value ) = array_map( 'trim', explode( '=', $line, 2 ) );
			if ( 'STWC_STRIPE_TEST_SECRET_KEY' !== $name || false !== getenv( $name ) ) {
				continue;
			}

			$value = trim( $value, "\"'" );
			putenv( $name . '=' . $value );
			$_ENV[ $name ] = $value;
		}
	}
}

class StripeLiveJsonErrorSentinel extends \Error {
	/**
	 * @var mixed
	 */
	public $data;

	public function __construct( $data = null ) {
		$this->data = $data;
		parent::__construct( 'wp_send_json_error' );
	}
}
