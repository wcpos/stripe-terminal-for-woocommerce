<?php
/**
 * Tests for the StripeTerminalService class.
 */

namespace WCPOS\WooCommercePOS\StripeTerminal\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\StripeTerminal\StripeTerminalService;
use WP_Error;

/**
 * @covers \WCPOS\WooCommercePOS\StripeTerminal\StripeTerminalService
 */
class StripeTerminalServiceTest extends TestCase {

	/**
	 * Set up Brain\Monkey before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Stub common WP functions used by the error handler.
		Functions\stubs(
			array(
				'esc_html' => function ( $text ) {
					return $text;
				},
				'__' => function ( $text, $domain = 'default' ) {
					return $text;
				},
			)
		);
	}

	/**
	 * Tear down Brain\Monkey after each test.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// -----------------------------------------------------------------------
	// Constructor tests
	// -----------------------------------------------------------------------

	/**
	 * Test constructor creates an instance without errors.
	 */
	public function test_constructor_creates_instance(): void {
		$service = new StripeTerminalService( 'sk_test_fake_key_123' );

		$this->assertInstanceOf( StripeTerminalService::class, $service );
	}

	/**
	 * Test constructor accepts an empty string API key without errors.
	 */
	public function test_constructor_accepts_empty_api_key(): void {
		$service = new StripeTerminalService( '' );

		$this->assertInstanceOf( StripeTerminalService::class, $service );
	}

	// -----------------------------------------------------------------------
	// get_stripe_client tests
	// -----------------------------------------------------------------------

	/**
	 * Test get_stripe_client returns a StripeClient instance.
	 */
	public function test_get_stripe_client_returns_stripe_client(): void {
		$service = new StripeTerminalService( 'sk_test_fake_key_123' );

		$client = $service->get_stripe_client();

		$this->assertInstanceOf( \Stripe\StripeClient::class, $client );
	}

	/**
	 * Test get_stripe_client returns the same instance on subsequent calls (lazy init).
	 */
	public function test_get_stripe_client_returns_same_instance(): void {
		$service = new StripeTerminalService( 'sk_test_fake_key_123' );

		$first  = $service->get_stripe_client();
		$second = $service->get_stripe_client();

		$this->assertSame( $first, $second );
	}

	// -----------------------------------------------------------------------
	// set_stripe_client tests
	// -----------------------------------------------------------------------

	/**
	 * Test set_stripe_client overrides the lazy-init client.
	 */
	public function test_set_stripe_client_overrides_lazy_init(): void {
		$service = new StripeTerminalService( 'sk_test_fake_key_123' );

		$mock_client = new \Stripe\StripeClient( 'sk_test_override_key' );
		$service->set_stripe_client( $mock_client );

		$this->assertSame( $mock_client, $service->get_stripe_client() );
	}

	/**
	 * Test set_stripe_client replaces a previously initialized client.
	 */
	public function test_set_stripe_client_replaces_existing_client(): void {
		$service = new StripeTerminalService( 'sk_test_fake_key_123' );

		// Trigger lazy init first.
		$original = $service->get_stripe_client();

		// Now replace it.
		$replacement = new \Stripe\StripeClient( 'sk_test_replacement_key' );
		$service->set_stripe_client( $replacement );

		$result = $service->get_stripe_client();

		$this->assertSame( $replacement, $result );
		$this->assertNotSame( $original, $result );
	}

	/**
	 * Test that set_stripe_client returns void.
	 */
	public function test_set_stripe_client_returns_void(): void {
		$service = new StripeTerminalService( 'sk_test_fake_key_123' );
		$client  = new \Stripe\StripeClient( 'sk_test_key' );

		$result = $service->set_stripe_client( $client );

		$this->assertNull( $result );
	}

	// -----------------------------------------------------------------------
	// create_payment_intent — currency validation tests
	// -----------------------------------------------------------------------

	/**
	 * Test create_payment_intent rejects unsupported currency.
	 *
	 * With a fake API key, get_supported_currencies() will fail to reach
	 * Stripe and default to country 'US', which only supports 'usd'.
	 * Passing 'eur' should be rejected.
	 */
	public function test_create_payment_intent_rejects_unsupported_currency(): void {
		$service = new StripeTerminalService( 'sk_test_fake_key_123' );

		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 42 );
		$order->shouldReceive( 'get_total' )->andReturn( '25.00' );
		$order->shouldReceive( 'get_currency' )->andReturn( 'EUR' );

		$result = $service->create_payment_intent( $order );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'unsupported_currency', $result->get_error_code() );
		$this->assertStringContainsString( 'EUR', $result->get_error_message() );
	}

	/**
	 * Test create_payment_intent rejects various non-USD currencies when defaulting to US.
	 *
	 * @dataProvider unsupported_currency_provider
	 */
	public function test_create_payment_intent_rejects_various_unsupported_currencies( string $currency ): void {
		$service = new StripeTerminalService( 'sk_test_fake_key_123' );

		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 1 );
		$order->shouldReceive( 'get_total' )->andReturn( '10.00' );
		$order->shouldReceive( 'get_currency' )->andReturn( $currency );

		$result = $service->create_payment_intent( $order );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'unsupported_currency', $result->get_error_code() );
	}

	public function unsupported_currency_provider(): array {
		return array(
			'EUR' => array( 'EUR' ),
			'GBP' => array( 'GBP' ),
			'CAD' => array( 'CAD' ),
			'AUD' => array( 'AUD' ),
			'JPY' => array( 'JPY' ),
		);
	}

	/**
	 * Test create_payment_intent error message lists supported currencies.
	 */
	public function test_create_payment_intent_unsupported_currency_lists_supported(): void {
		$service = new StripeTerminalService( 'sk_test_fake_key_123' );

		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 42 );
		$order->shouldReceive( 'get_total' )->andReturn( '25.00' );
		$order->shouldReceive( 'get_currency' )->andReturn( 'GBP' );

		$result = $service->create_payment_intent( $order );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( 'USD', $result->get_error_message() );
	}

	/**
	 * Test create_payment_intent unsupported currency error has status 400.
	 */
	public function test_create_payment_intent_unsupported_currency_status_400(): void {
		$service = new StripeTerminalService( 'sk_test_fake_key_123' );

		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 42 );
		$order->shouldReceive( 'get_total' )->andReturn( '25.00' );
		$order->shouldReceive( 'get_currency' )->andReturn( 'EUR' );

		$result = $service->create_payment_intent( $order );

		$data = $result->get_error_data();
		$this->assertSame( 400, $data['status'] );
	}

	// -----------------------------------------------------------------------
	// create_payment_intent — amount validation tests
	// -----------------------------------------------------------------------

	/**
	 * Test create_payment_intent rejects zero amount with explicit override.
	 *
	 * When amount is explicitly passed as 0 and currency is 'usd' (supported),
	 * the empty(amount) check triggers a missing_params error.
	 */
	public function test_create_payment_intent_rejects_zero_amount(): void {
		$service = new StripeTerminalService( 'sk_test_fake_key_123' );

		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 42 );
		$order->shouldReceive( 'get_total' )->andReturn( '0.00' );
		$order->shouldReceive( 'get_currency' )->andReturn( 'USD' );

		$result = $service->create_payment_intent( $order, 0 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'missing_params', $result->get_error_code() );
		$this->assertStringContainsString( 'amount and currency are required', $result->get_error_message() );
	}

	/**
	 * Test create_payment_intent rejects zero amount derived from order total.
	 */
	public function test_create_payment_intent_rejects_zero_order_total(): void {
		$service = new StripeTerminalService( 'sk_test_fake_key_123' );

		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 42 );
		$order->shouldReceive( 'get_total' )->andReturn( '0.00' );
		$order->shouldReceive( 'get_currency' )->andReturn( 'USD' );

		$result = $service->create_payment_intent( $order );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'missing_params', $result->get_error_code() );
	}

	/**
	 * Test create_payment_intent missing_params error has status 400.
	 */
	public function test_create_payment_intent_missing_params_status_400(): void {
		$service = new StripeTerminalService( 'sk_test_fake_key_123' );

		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 42 );
		$order->shouldReceive( 'get_total' )->andReturn( '0.00' );
		$order->shouldReceive( 'get_currency' )->andReturn( 'USD' );

		$result = $service->create_payment_intent( $order, 0 );

		$data = $result->get_error_data();
		$this->assertSame( 400, $data['status'] );
	}

	// -----------------------------------------------------------------------
	// create_payment_intent — with valid USD currency hits Stripe API
	// -----------------------------------------------------------------------

	/**
	 * Test create_payment_intent with valid USD currency and positive amount
	 * attempts a Stripe API call (which fails with a fake key), returning a
	 * WP_Error from the error handler rather than a validation error.
	 *
	 * This confirms validation passes and the code reaches the API call.
	 */
	public function test_create_payment_intent_valid_usd_reaches_api_call(): void {
		$service = new StripeTerminalService( 'sk_test_fake_key_123' );

		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 42 );
		$order->shouldReceive( 'get_total' )->andReturn( '25.00' );
		$order->shouldReceive( 'get_currency' )->andReturn( 'USD' );

		$result = $service->create_payment_intent( $order );

		// The result will be a WP_Error because the Stripe API call fails with
		// a fake key, but the error code should be from the error handler
		// (stripe_error or general_error), NOT from our validation checks.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'unsupported_currency', $result->get_error_code() );
		$this->assertNotSame( 'missing_params', $result->get_error_code() );
	}

	/**
	 * Test create_payment_intent with explicit amount override passes validation.
	 */
	public function test_create_payment_intent_explicit_amount_passes_validation(): void {
		$service = new StripeTerminalService( 'sk_test_fake_key_123' );

		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 42 );
		$order->shouldReceive( 'get_total' )->andReturn( '25.00' );
		$order->shouldReceive( 'get_currency' )->andReturn( 'USD' );

		$result = $service->create_payment_intent( $order, 5000 );

		// Should reach the API call (not a validation error).
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'unsupported_currency', $result->get_error_code() );
		$this->assertNotSame( 'missing_params', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// create_payment_intent — currency case handling
	// -----------------------------------------------------------------------

	/**
	 * Test create_payment_intent normalizes currency to lowercase.
	 *
	 * 'usd' (lowercase) should pass currency validation since the
	 * supported currencies list uses lowercase codes.
	 */
	public function test_create_payment_intent_currency_case_insensitive(): void {
		$service = new StripeTerminalService( 'sk_test_fake_key_123' );

		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 42 );
		$order->shouldReceive( 'get_total' )->andReturn( '10.00' );
		$order->shouldReceive( 'get_currency' )->andReturn( 'usd' );

		$result = $service->create_payment_intent( $order );

		// Should pass validation and reach the API call.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'unsupported_currency', $result->get_error_code() );
		$this->assertNotSame( 'missing_params', $result->get_error_code() );
	}

	/**
	 * Test create_payment_intent with mixed-case USD passes validation.
	 */
	public function test_create_payment_intent_mixed_case_usd_passes_validation(): void {
		$service = new StripeTerminalService( 'sk_test_fake_key_123' );

		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 42 );
		$order->shouldReceive( 'get_total' )->andReturn( '10.00' );
		$order->shouldReceive( 'get_currency' )->andReturn( 'Usd' );

		$result = $service->create_payment_intent( $order );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'unsupported_currency', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// process_payment_intent — default config tests
	// -----------------------------------------------------------------------

	/**
	 * Test process_payment_intent merges custom config with defaults.
	 *
	 * We can't mock the Stripe API call easily, but we can verify it
	 * returns a WP_Error (because the fake key fails) rather than
	 * crashing or returning null.
	 */
	public function test_process_payment_intent_returns_wp_error_with_fake_key(): void {
		$service = new StripeTerminalService( 'sk_test_fake_key_123' );

		$result = $service->process_payment_intent( 'tmr_fake_reader', 'pi_fake_intent' );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/**
	 * Test process_payment_intent with custom config returns WP_Error.
	 */
	public function test_process_payment_intent_with_custom_config(): void {
		$service = new StripeTerminalService( 'sk_test_fake_key_123' );

		$result = $service->process_payment_intent(
			'tmr_fake_reader',
			'pi_fake_intent',
			array( 'skip_tipping' => true )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	// -----------------------------------------------------------------------
	// confirm_payment_intent tests
	// -----------------------------------------------------------------------

	/**
	 * Test confirm_payment_intent returns WP_Error with fake key.
	 */
	public function test_confirm_payment_intent_returns_wp_error_with_fake_key(): void {
		$service = new StripeTerminalService( 'sk_test_fake_key_123' );

		$order = Mockery::mock( 'WC_Order' );

		$result = $service->confirm_payment_intent( 'pi_fake_intent', $order );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	// -----------------------------------------------------------------------
	// cancel_payment_intent tests
	// -----------------------------------------------------------------------

	/**
	 * Test cancel_payment_intent returns WP_Error with fake key.
	 */
	public function test_cancel_payment_intent_returns_wp_error_with_fake_key(): void {
		$service = new StripeTerminalService( 'sk_test_fake_key_123' );

		$order = Mockery::mock( 'WC_Order' );

		$result = $service->cancel_payment_intent( 'pi_fake_intent', $order );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	// -----------------------------------------------------------------------
	// get_connection_token tests
	// -----------------------------------------------------------------------

	/**
	 * Test get_connection_token returns WP_Error with fake key.
	 */
	public function test_get_connection_token_returns_wp_error_with_fake_key(): void {
		$service = new StripeTerminalService( 'sk_test_fake_key_123' );

		$result = $service->get_connection_token();

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	// -----------------------------------------------------------------------
	// list_locations tests
	// -----------------------------------------------------------------------

	/**
	 * Test list_locations returns WP_Error with fake key.
	 */
	public function test_list_locations_returns_wp_error_with_fake_key(): void {
		$service = new StripeTerminalService( 'sk_test_fake_key_123' );

		$result = $service->list_locations();

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	// -----------------------------------------------------------------------
	// register_reader tests
	// -----------------------------------------------------------------------

	/**
	 * Test register_reader returns WP_Error with fake key.
	 */
	public function test_register_reader_returns_wp_error_with_fake_key(): void {
		$service = new StripeTerminalService( 'sk_test_fake_key_123' );

		$result = $service->register_reader( 'tml_fake_location', 'simulated-wpe' );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	// -----------------------------------------------------------------------
	// get_reader_status tests
	// -----------------------------------------------------------------------

	/**
	 * Test get_reader_status with specific reader returns WP_Error with fake key.
	 */
	public function test_get_reader_status_with_reader_id_returns_wp_error(): void {
		$service = new StripeTerminalService( 'sk_test_fake_key_123' );

		$result = $service->get_reader_status( 'tmr_fake_reader' );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/**
	 * Test get_reader_status without reader ID returns WP_Error with fake key.
	 */
	public function test_get_reader_status_without_reader_id_returns_wp_error(): void {
		$service = new StripeTerminalService( 'sk_test_fake_key_123' );

		$result = $service->get_reader_status();

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/**
	 * Test get_reader_status with null reader ID returns WP_Error with fake key.
	 */
	public function test_get_reader_status_with_null_reader_id_returns_wp_error(): void {
		$service = new StripeTerminalService( 'sk_test_fake_key_123' );

		$result = $service->get_reader_status( null );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	// -----------------------------------------------------------------------
	// handle_webhook tests
	// -----------------------------------------------------------------------

	/**
	 * Test handle_webhook returns WP_Error when webhook secret is not configured.
	 */
	public function test_handle_webhook_returns_error_when_secret_missing(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( 'woocommerce_stripe_terminal_for_woocommerce_settings', array() )
			->andReturn( array() );

		$service = new StripeTerminalService( 'sk_test_fake_key_123' );

		$result = $service->handle_webhook( array( 'type' => 'test' ), 'v1=sig123' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'webhook_secret_missing', $result->get_error_code() );
		$this->assertStringContainsString( 'Webhook secret not configured', $result->get_error_message() );
	}

	/**
	 * Test handle_webhook missing secret error has status 500.
	 */
	public function test_handle_webhook_missing_secret_status_500(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( 'woocommerce_stripe_terminal_for_woocommerce_settings', array() )
			->andReturn( array() );

		$service = new StripeTerminalService( 'sk_test_fake_key_123' );

		$result = $service->handle_webhook( array( 'type' => 'test' ), 'v1=sig123' );

		$data = $result->get_error_data();
		$this->assertSame( 500, $data['status'] );
	}

	/**
	 * Test handle_webhook uses test_webhook_secret when test_mode is yes.
	 */
	public function test_handle_webhook_uses_test_secret_in_test_mode(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( 'woocommerce_stripe_terminal_for_woocommerce_settings', array() )
			->andReturn(
				array(
					'test_mode'           => 'yes',
					'test_webhook_secret' => 'whsec_test_secret',
				)
			);

		$service = new StripeTerminalService( 'sk_test_fake_key_123' );

		// The webhook signature verification will fail, but we're testing
		// that it gets past the "secret missing" check.
		$result = $service->handle_webhook( array( 'type' => 'test' ), 'v1=sig123' );

		$this->assertInstanceOf( WP_Error::class, $result );
		// Should NOT be webhook_secret_missing since we provided a test secret.
		$this->assertNotSame( 'webhook_secret_missing', $result->get_error_code() );
	}

	/**
	 * Test handle_webhook uses live webhook_secret when test_mode is no.
	 */
	public function test_handle_webhook_uses_live_secret_in_live_mode(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( 'woocommerce_stripe_terminal_for_woocommerce_settings', array() )
			->andReturn(
				array(
					'test_mode'      => 'no',
					'webhook_secret' => 'whsec_live_secret',
				)
			);

		$service = new StripeTerminalService( 'sk_test_fake_key_123' );

		$result = $service->handle_webhook( array( 'type' => 'test' ), 'v1=sig123' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'webhook_secret_missing', $result->get_error_code() );
	}

	/**
	 * Test handle_webhook with missing test secret in test mode.
	 */
	public function test_handle_webhook_missing_test_secret_returns_error(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( 'woocommerce_stripe_terminal_for_woocommerce_settings', array() )
			->andReturn(
				array(
					'test_mode' => 'yes',
					// No test_webhook_secret provided.
				)
			);

		$service = new StripeTerminalService( 'sk_test_fake_key_123' );

		$result = $service->handle_webhook( array( 'type' => 'test' ), 'v1=sig123' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'webhook_secret_missing', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// update_order_from_payment_intent tests
	// -----------------------------------------------------------------------

	/**
	 * Test update_order_from_payment_intent saves charge metadata.
	 */
	public function test_update_order_saves_charge_metadata(): void {
		$service = new StripeTerminalService( 'sk_test_fake_key_123' );

		$payment_intent = \Stripe\PaymentIntent::constructFrom(
			array(
				'id'      => 'pi_test_123',
				'charges' => array(
					'data' => array(
						array(
							'id'                     => 'ch_test_123',
							'currency'               => 'usd',
							'captured'               => true,
							'payment_method_details'  => array(
								'card' => array( 'brand' => 'visa' ),
							),
						),
					),
				),
			)
		);

		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'update_meta_data' )
			->with( '_transaction_id', 'ch_test_123' )
			->once();
		$order->shouldReceive( 'update_meta_data' )
			->with( '_stripe_currency', 'USD' )
			->once();
		$order->shouldReceive( 'update_meta_data' )
			->with( '_stripe_charge_captured', 'yes' )
			->once();
		$order->shouldReceive( 'update_meta_data' )
			->with( '_stripe_intent_id', 'pi_test_123' )
			->once();
		$order->shouldReceive( 'update_meta_data' )
			->with( '_stripe_card_type', 'Visa' )
			->once();
		$order->shouldReceive( 'save' )->once();

		$service->update_order_from_payment_intent( $order, $payment_intent );

		// Count Mockery expectations as PHPUnit assertions.
		$this->addToAssertionCount( Mockery::getContainer()->mockery_getExpectationCount() );
	}

	/**
	 * Test update_order_from_payment_intent records captured=no for uncaptured charge.
	 */
	public function test_update_order_records_uncaptured_charge(): void {
		$service = new StripeTerminalService( 'sk_test_fake_key_123' );

		$payment_intent = \Stripe\PaymentIntent::constructFrom(
			array(
				'id'      => 'pi_test_456',
				'charges' => array(
					'data' => array(
						array(
							'id'                     => 'ch_test_456',
							'currency'               => 'gbp',
							'captured'               => false,
							'payment_method_details'  => array(
								'card' => array( 'brand' => 'mastercard' ),
							),
						),
					),
				),
			)
		);

		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'update_meta_data' )
			->with( '_stripe_charge_captured', 'no' )
			->once();
		$order->shouldReceive( 'update_meta_data' )->times( 4 ); // The other 4 calls.
		$order->shouldReceive( 'save' )->once();

		$service->update_order_from_payment_intent( $order, $payment_intent );

		$this->addToAssertionCount( Mockery::getContainer()->mockery_getExpectationCount() );
	}

	/**
	 * Test update_order_from_payment_intent does not save when no charge data.
	 */
	public function test_update_order_does_nothing_when_no_charges(): void {
		$service = new StripeTerminalService( 'sk_test_fake_key_123' );

		$payment_intent = \Stripe\PaymentIntent::constructFrom(
			array(
				'id'      => 'pi_test_789',
				'charges' => array(
					'data' => array(),
				),
			)
		);

		$order = Mockery::mock( 'WC_Order' );
		$order->shouldNotReceive( 'update_meta_data' );
		$order->shouldNotReceive( 'save' );

		$service->update_order_from_payment_intent( $order, $payment_intent );

		$this->addToAssertionCount( Mockery::getContainer()->mockery_getExpectationCount() );
	}

	/**
	 * Test update_order_from_payment_intent handles missing card brand gracefully.
	 */
	public function test_update_order_handles_missing_card_brand(): void {
		$service = new StripeTerminalService( 'sk_test_fake_key_123' );

		$payment_intent = \Stripe\PaymentIntent::constructFrom(
			array(
				'id'      => 'pi_test_no_brand',
				'charges' => array(
					'data' => array(
						array(
							'id'                     => 'ch_test_no_brand',
							'currency'               => 'usd',
							'captured'               => true,
							'payment_method_details'  => array(
								'card' => array(),
							),
						),
					),
				),
			)
		);

		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'update_meta_data' )
			->with( '_stripe_card_type', '' )
			->once();
		$order->shouldReceive( 'update_meta_data' )->times( 4 ); // Other calls.
		$order->shouldReceive( 'save' )->once();

		$service->update_order_from_payment_intent( $order, $payment_intent );

		$this->addToAssertionCount( Mockery::getContainer()->mockery_getExpectationCount() );
	}

	// -----------------------------------------------------------------------
	// Error handling delegation — methods catch exceptions and delegate
	// -----------------------------------------------------------------------

	/**
	 * Test that all API-calling methods properly catch exceptions and return
	 * WP_Error via the error handler (not uncaught exceptions).
	 *
	 * @dataProvider api_method_provider
	 */
	public function test_api_methods_return_wp_error_not_exceptions( string $method, array $args ): void {
		$service = new StripeTerminalService( 'sk_test_fake_key_123' );

		$result = \call_user_func_array( array( $service, $method ), $args );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function api_method_provider(): array {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 1 );
		$order->shouldReceive( 'get_total' )->andReturn( '10.00' );
		$order->shouldReceive( 'get_currency' )->andReturn( 'USD' );

		$order_for_confirm = Mockery::mock( 'WC_Order' );

		return array(
			'create_payment_intent'  => array( 'create_payment_intent', array( $order ) ),
			'confirm_payment_intent' => array( 'confirm_payment_intent', array( 'pi_fake', $order_for_confirm ) ),
			'cancel_payment_intent'  => array( 'cancel_payment_intent', array( 'pi_fake', $order_for_confirm ) ),
			'get_connection_token'   => array( 'get_connection_token', array() ),
			'list_locations'         => array( 'list_locations', array() ),
			'register_reader'       => array( 'register_reader', array( 'tml_fake', 'code123' ) ),
			'get_reader_status'      => array( 'get_reader_status', array( 'tmr_fake' ) ),
		);
	}
}
