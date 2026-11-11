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
use WCPOS\WooCommercePOS\StripeTerminal\StripeTerminalService;

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
				'amount'                       => 1234,
				'currency'                     => 'usd',
				'allowed_payment_method_types' => array( 'card_present' ),
				'capture_method'               => 'manual',
				'description'                  => 'STWC live integration smoke test',
				'metadata'                     => array(
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

	public function test_refunds_confirmed_payment_intent_against_stripe_test_mode(): void {
		$this->require_stripe_test_key();

		Monkey\setUp();
		try {
			$payment_intent = \Stripe\PaymentIntent::create(
				array(
					'amount'                       => 1234,
					'currency'                     => 'usd',
					'allowed_payment_method_types' => array( 'card' ),
					'payment_method'               => 'pm_card_visa',
					'confirm'                      => true,
					'description'                  => 'STWC live integration refund smoke test',
					'metadata'                     => array(
						'stwc_test' => 'stripe_live_integration',
					),
				)
			);
			$this->assertSame( 'succeeded', $payment_intent->status );

			$service = new StripeTerminalService( $this->secret_key );
			$refund  = $service->refund_payment(
				$payment_intent->id,
				null,
				array(
					'request_options' => array(
						'idempotency_key' => 'stwc-smoke-refund-' . $payment_intent->id,
					),
				)
			);

			if ( is_wp_error( $refund ) ) {
				$this->fail( 'refund_payment returned WP_Error: ' . $refund->get_error_message() );
			}

			$this->assertStringStartsWith( 're_', $refund['id'] );
			$this->assertSame( 1234, $refund['amount'] );
			$this->assertContains( $refund['status'], array( 'succeeded', 'pending' ) );
		} finally {
			Monkey\tearDown();
		}
	}

	/**
	 * POS-31F reproduction: on API 2026-09-30.endive `allowed_payment_method_types` is a filter, so a
	 * currency the account cannot take in person drops `card_present` and the create succeeds; the
	 * reader dispatch is then refused.
	 */
	public function test_endive_drops_card_present_for_a_currency_the_account_cannot_take_in_person(): void {
		$this->require_stripe_test_key();

		Monkey\setUp();
		$intent  = null;
		$created = array();
		try {
			$this->stub_service_functions();
			$currency = $this->currency_the_account_cannot_take_in_person();

			// Before endive this create failed; it must now succeed with card_present filtered out.
			$intent = \Stripe\PaymentIntent::create(
				array(
					'amount'                       => 1234,
					'currency'                     => $currency,
					'allowed_payment_method_types' => array( 'card_present' ),
					'description'                  => 'STWC live POS-31F reproduction',
					'metadata'                     => array( 'stwc_test' => 'stripe_live_integration' ),
				)
			);
			$this->assertNotContains( 'card_present', $intent->payment_method_types );

			$reader_id = $this->simulated_reader( $created );
			$result    = ( new StripeTerminalService( $this->secret_key ) )->process_payment_intent( $reader_id, $intent->id );
			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertStringContainsStringIgnoringCase( 'in-person', $result->get_error_message() );
		} finally {
			if ( null !== $intent ) {
				$this->cancel_quietly( $intent->id );
			}
			$this->delete_created( $created );
			Monkey\tearDown();
		}
	}

	/** POS-31F: the service refuses and cancels the intent Stripe created without `card_present`. */
	public function test_server_intent_without_card_present_is_refused_and_cancelled(): void {
		$this->require_stripe_test_key();

		Monkey\setUp();
		$intent_id = null;
		$api_base  = \Stripe\Stripe::$apiBase; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Stripe SDK property.
		try {
			$this->stub_service_functions();
			$currency = $this->currency_the_account_cannot_take_in_person();
			$service  = new StripeTerminalService( $this->secret_key );

			// The country is unknown, as when the key lacks the Account read: no cached country, and
			// the static Account::retrieve() goes to a closed port. The service's StripeClient keeps
			// its own api_base, so the create still reaches Stripe.
			\Stripe\Stripe::$apiBase = 'https://127.0.0.1:9'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Stripe SDK property.
			$error                   = $service->create_server_payment_intent( 1234, $currency, 'STWC live POS-31F', array( 'stwc_test' => 'stripe_live_integration' ), 'stwc-live-31f-' . bin2hex( random_bytes( 6 ) ), false );
			\Stripe\Stripe::$apiBase = $api_base; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Stripe SDK property.

			$this->assertInstanceOf( \WP_Error::class, $error, 'the create must be refused, not returned' );
			$this->assertSame( 'card_present_unavailable', $error->get_error_code(), $error->get_error_message() );
			$intent_id = $error->get_error_data()['payment_intent'];
			$this->assertSame( 'canceled', \Stripe\PaymentIntent::retrieve( $intent_id )->status );
		} finally {
			\Stripe\Stripe::$apiBase = $api_base; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Stripe SDK property.
			if ( null !== $intent_id ) {
				$this->cancel_quietly( $intent_id );
			}
			Monkey\tearDown();
		}
	}

	/** WordPress functions the service calls, with the account country never cached. */
	private function stub_service_functions(): void {
		Functions\stubTranslationFunctions();
		Functions\stubs(
			array(
				'get_transient'    => false,
				'set_transient'    => true,
				'delete_transient' => true,
			)
		);
	}

	/** The first of EUR, GBP, USD the service's own country map does not give the account country. */
	private function currency_the_account_cannot_take_in_person(): string {
		$country = (string) \Stripe\Account::retrieve()->country;
		$method  = new \ReflectionMethod( StripeTerminalService::class, 'currencies_for_country' );
		$method->setAccessible( true );
		$supported = $method->invoke( new StripeTerminalService( $this->secret_key ), $country );
		foreach ( array( 'eur', 'gbp', 'usd' ) as $currency ) {
			if ( ! \in_array( $currency, $supported, true ) ) {
				return $currency;
			}
		}
		$this->fail( 'No candidate currency is outside the in-person currencies of ' . $country );
	}

	/**
	 * An existing simulated reader, or one registered at a new US location.
	 *
	 * @param array $created Objects this test created, deleted by the caller.
	 */
	private function simulated_reader( array &$created ): string {
		foreach ( \Stripe\Terminal\Reader::all( array( 'limit' => 100 ) )->data as $reader ) {
			if ( 0 === strpos( (string) $reader->device_type, 'simulated_' ) ) {
				return $reader->id;
			}
		}
		$country = (string) \Stripe\Account::retrieve()->country;
		if ( 'US' !== $country ) {
			$this->markTestSkipped( 'No simulated reader, and no test address for a location in ' . $country );
		}
		$created[] = \Stripe\Terminal\Location::create(
			array(
				'display_name' => 'STWC live POS-31F',
				'address'      => array(
					'line1'       => '510 Townsend St',
					'city'        => 'San Francisco',
					'state'       => 'CA',
					'postal_code' => '94103',
					'country'     => 'US',
				),
			)
		);
		$reader    = \Stripe\Terminal\Reader::create(
			array(
				'registration_code' => 'simulated-wpe',
				'location'          => $created[0]->id,
				'label'             => 'STWC live POS-31F',
			)
		);
		array_unshift( $created, $reader );
		return $reader->id;
	}

	/**
	 * Delete what `simulated_reader()` created, reader first.
	 *
	 * @param array $created Stripe objects to delete.
	 */
	private function delete_created( array $created ): void {
		foreach ( $created as $object ) {
			try {
				$object->delete();
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
	}

	/**
	 * Cancel an intent unless already cancelled, ignoring errors.
	 *
	 * @param string $intent_id PaymentIntent ID.
	 */
	private function cancel_quietly( string $intent_id ): void {
		try {
			$intent = \Stripe\PaymentIntent::retrieve( $intent_id );
			if ( 'canceled' !== $intent->status ) {
				$intent->cancel();
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
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
