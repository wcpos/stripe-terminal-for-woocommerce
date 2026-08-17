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
 * Queue-backed Stripe HTTP client for service integration tests.
 */
class StripeHttpClientFake implements \Stripe\HttpClient\ClientInterface {
	/**
	 * @var array<int, array{body:array,status:int}>
	 */
	private $responses;

	/**
	 * @var array<int, array{method:string,url:string,params:array}>
	 */
	public $requests = array();

	public function __construct( array $responses ) {
		$this->responses = $responses;
	}

	public function request( $method, $abs_url, $headers, $params, $has_file, $api_mode = 'v1', $max_network_retries = null ) {
		$this->requests[] = array(
			'method' => $method,
			'url'    => $abs_url,
			'params' => $params,
		);

		$response = array_shift( $this->responses );

		return array( wp_json_encode( $response['body'] ), $response['status'], array() );
	}
}

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
				'apply_filters' => function ( $hook, $value ) {
					return $value;
				},
				'esc_html' => function ( $text ) {
					return $text;
				},
				'get_transient' => false,
				'set_transient' => true,
				'wp_json_encode' => function ( $value ) {
					return json_encode( $value );
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

	/**
	 * Test constructor applies host-configurable timeouts to Stripe's shared client.
	 */
	public function test_constructor_configures_shared_stripe_http_timeouts(): void {
		$client = \Stripe\HttpClient\CurlClient::instance();
		$client->setConnectTimeout( 30 );
		$client->setTimeout( 30 );

		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value ) {
				return 'stwc_stripe_connect_timeout' === $hook ? 7 : 83;
			}
		);

		new StripeTerminalService( 'sk_test_fake_key_123' );

		$this->assertSame( 7, $client->getConnectTimeout() );
		$this->assertSame( 83, $client->getTimeout() );
		$this->assertSame( $client, \Stripe\ApiRequestor::httpClient() );
	}

	/**
	 * Test constructor uses the asymmetric timeout defaults.
	 */
	public function test_constructor_uses_asymmetric_timeout_defaults(): void {
		$client = \Stripe\HttpClient\CurlClient::instance();

		new StripeTerminalService( 'sk_test_fake_key_123' );

		$this->assertSame( 10, $client->getConnectTimeout() );
		$this->assertSame( 80, $client->getTimeout() );
	}

	/**
	 * Test reader retrieval uses the read bucket and restores command timeout.
	 */
	public function test_get_reader_temporarily_uses_read_timeout(): void {
		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value ) {
				return 'stwc_stripe_read_timeout' === $hook ? 17 : $value;
			}
		);

		$service     = new StripeTerminalService( 'sk_test_fake_key_123' );
		$http_client = \Stripe\HttpClient\CurlClient::instance();
		$during      = null;
		$reader      = Mockery::mock( \Stripe\Terminal\Reader::class );
		$reader->shouldReceive( 'toArray' )->andReturn( array( 'id' => 'tmr_test' ) );
		$readers = Mockery::mock();
		$readers->shouldReceive( 'retrieve' )->andReturnUsing(
			function () use ( &$during, $http_client, $reader ) {
				$during = $http_client->getTimeout();

				return $reader;
			}
		);
		$terminal          = Mockery::mock();
		$terminal->readers = $readers;
		$stripe            = Mockery::mock( \Stripe\StripeClient::class );
		$stripe->terminal  = $terminal;
		$service->set_stripe_client( $stripe );

		$this->assertSame( array( 'id' => 'tmr_test' ), $service->get_reader( 'tmr_test' ) );
		$this->assertSame( 17, $during );
		$this->assertSame( 80, $http_client->getTimeout() );
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
		$method = new \ReflectionMethod( StripeTerminalService::class, 'set_stripe_client' );

		$this->assertSame( 'void', (string) $method->getReturnType() );
	}

	/**
	 * Test a full charge refund omits amount and keeps the command timeout.
	 */
	public function test_refund_payment_creates_full_charge_refund(): void {
		$service = new StripeTerminalService( 'sk_test_fake_key_123' );
		$refund  = Mockery::mock( \Stripe\Refund::class );
		$refund->shouldReceive( 'toArray' )->andReturn( array( 'id' => 're_full' ) );
		$during_timeout = null;
		$refunds        = Mockery::mock();
		$refunds->shouldReceive( 'create' )
			->with( array( 'charge' => 'ch_123' ), array( 'idempotency_key' => 'refund-key' ) )
			->once()
			->andReturnUsing(
				function () use ( &$during_timeout, $refund ) {
					$during_timeout = \Stripe\HttpClient\CurlClient::instance()->getTimeout();

					return $refund;
				}
			);
		$stripe          = Mockery::mock( \Stripe\StripeClient::class );
		$stripe->refunds = $refunds;
		$service->set_stripe_client( $stripe );

		$result = $service->refund_payment(
			'ch_123',
			null,
			array( 'request_options' => array( 'idempotency_key' => 'refund-key' ) )
		);

		$this->assertSame( array( 'id' => 're_full' ), $result );
		$this->assertSame( 80, $during_timeout );
	}

	/**
	 * Test a partial payment-intent refund forwards amount and arguments.
	 */
	public function test_refund_payment_creates_partial_payment_intent_refund(): void {
		$service = new StripeTerminalService( 'sk_test_fake_key_123' );
		$refund  = Mockery::mock( \Stripe\Refund::class );
		$refund->shouldReceive( 'toArray' )->andReturn( array( 'id' => 're_partial' ) );
		$refunds = Mockery::mock();
		$refunds->shouldReceive( 'create' )
			->with(
				array(
					'payment_intent' => 'pi_123',
					'amount'         => 1050,
					'metadata'       => array( 'reason' => 'Damaged item' ),
				),
				array()
			)
			->once()
			->andReturn( $refund );
		$stripe          = Mockery::mock( \Stripe\StripeClient::class );
		$stripe->refunds = $refunds;
		$service->set_stripe_client( $stripe );

		$result = $service->refund_payment( 'pi_123', 1050, array( 'metadata' => array( 'reason' => 'Damaged item' ) ) );

		$this->assertSame( array( 'id' => 're_partial' ), $result );
	}

	/**
	 * Test py_ IDs are sent as charge IDs.
	 */
	public function test_refund_payment_treats_py_id_as_charge(): void {
		$service = new StripeTerminalService( 'sk_test_fake_key_123' );
		$refund  = Mockery::mock( \Stripe\Refund::class );
		$refund->shouldReceive( 'toArray' )->andReturn( array( 'id' => 're_py' ) );
		$refunds = Mockery::mock();
		$refunds->shouldReceive( 'create' )->with( array( 'charge' => 'py_123' ), array() )->once()->andReturn( $refund );
		$stripe          = Mockery::mock( \Stripe\StripeClient::class );
		$stripe->refunds = $refunds;
		$service->set_stripe_client( $stripe );

		$this->assertSame( array( 'id' => 're_py' ), $service->refund_payment( 'py_123' ) );
	}

	/**
	 * Test Stripe refund exceptions are converted to WP_Error.
	 */
	public function test_refund_payment_returns_wp_error_for_stripe_exception(): void {
		$service = new StripeTerminalService( 'sk_test_fake_key_123' );
		$refunds = Mockery::mock();
		$refunds->shouldReceive( 'create' )->andThrow(
			\Stripe\Exception\InvalidRequestException::factory( 'Refund failed.', 400 )
		);
		$stripe          = Mockery::mock( \Stripe\StripeClient::class );
		$stripe->refunds = $refunds;
		$service->set_stripe_client( $stripe );

		$result = $service->refund_payment( 'ch_error' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'refund_payment_error', $result->get_error_data()['context'] );
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

	/**
	 * Test set_reader_display sends the zero-total store placeholder.
	 */
	public function test_set_reader_display_sends_ambient_placeholder(): void {
		$this->assertTrue( method_exists( StripeTerminalService::class, 'set_reader_display' ) );
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Store' );
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'CAD' );

		$service = new StripeTerminalService( 'sk_test_fake_key_123' );
		$reader  = Mockery::mock( \Stripe\Terminal\Reader::class );
		$reader->shouldReceive( 'toArray' )->andReturn( array( 'id' => 'tmr_test' ) );
		$readers = Mockery::mock();
		$readers->shouldReceive( 'setReaderDisplay' )
			->with(
				'tmr_test',
				array(
					'type' => 'cart',
					'cart' => array(
						'currency'   => 'cad',
						'total'      => 0,
						'line_items' => array(
							array(
								'description' => 'Test Store',
								'amount'      => 0,
								'quantity'    => 1,
							),
						),
					),
				)
			)
			->once()
			->andReturn( $reader );
		$terminal          = Mockery::mock();
		$terminal->readers = $readers;
		$stripe            = Mockery::mock( \Stripe\StripeClient::class );
		$stripe->terminal  = $terminal;
		$service->set_stripe_client( $stripe );

		$this->assertSame( array( 'id' => 'tmr_test' ), $service->set_reader_display( 'tmr_test' ) );
	}

	/**
	 * Test a busy reader is a successful best-effort warm outcome.
	 */
	public function test_set_reader_display_returns_busy_status(): void {
		$this->assertTrue( method_exists( StripeTerminalService::class, 'set_reader_display' ) );
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Store' );
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		$service = new StripeTerminalService( 'sk_test_fake_key_123' );
		$readers = Mockery::mock();
		$readers->shouldReceive( 'setReaderDisplay' )->andThrow(
			\Stripe\Exception\InvalidRequestException::factory(
				'Reader busy.',
				400,
				null,
				null,
				null,
				'terminal_reader_busy'
			)
		);
		$terminal          = Mockery::mock();
		$terminal->readers = $readers;
		$stripe            = Mockery::mock( \Stripe\StripeClient::class );
		$stripe->terminal  = $terminal;
		$service->set_stripe_client( $stripe );

		$this->assertSame( array( 'status' => 'busy' ), $service->set_reader_display( 'tmr_test' ) );
	}

	/**
	 * Test status recovery retrieves the recorded payment intent without scanning 100 intents.
	 */
	public function test_check_payment_status_retrieves_recorded_payment_intent_directly(): void {
		$service = new StripeTerminalService( 'sk_test_status_key' );
		$client  = new StripeHttpClientFake(
			array(
				array(
					'body'   => array(
						'id'       => 'pi_recorded',
						'object'   => 'payment_intent',
						'status'   => 'processing',
						'amount'   => 2500,
						'currency' => 'usd',
						'created'  => 1700000000,
					),
					'status' => 200,
				),
				array(
					'body'   => array(
						'object' => 'list',
						'data'   => array(),
					),
					'status' => 200,
				),
			)
		);
		\Stripe\ApiRequestor::setHttpClient( $client );

		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 42 );
		$order->shouldReceive( 'get_transaction_id' )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_stripe_terminal_payment_intent_id' )->andReturn( 'pi_recorded' );
		$order->shouldReceive( 'is_paid' )->andReturn( false );
		$order->shouldReceive( 'get_status' )->andReturn( 'pending' );

		$result = $service->check_payment_status_from_stripe( $order );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'pi_recorded', $result['payment_intent']['id'] );
		$this->assertCount( 2, $client->requests );
		$this->assertStringEndsWith( '/v1/payment_intents/pi_recorded', $client->requests[0]['url'] );
		$this->assertStringNotContainsString( '/v1/payment_intents?', $client->requests[0]['url'] );
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
				'id'       => 'pi_test_123',
				'livemode' => true,
				'charges'  => array(
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
			->with( '_stripe_terminal_livemode', 'yes' )
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
				'id'       => 'pi_test_456',
				'livemode' => false,
				'charges'  => array(
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
		$order->shouldReceive( 'update_meta_data' )
			->with( '_stripe_terminal_livemode', 'no' )
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
				'id'       => 'pi_test_no_brand',
				'livemode' => true,
				'charges'  => array(
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
		$order->shouldReceive( 'update_meta_data' )
			->with( '_stripe_terminal_livemode', 'yes' )
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
			'cancel_reader_action'   => array( 'cancel_reader_action', array( 'tmr_fake' ) ),
			'get_reader'             => array( 'get_reader', array( 'tmr_fake' ) ),
		);
	}

	// -----------------------------------------------------------------------
	// get_reader tests
	// -----------------------------------------------------------------------

	/**
	 * Test get_reader returns WP_Error with fake key.
	 */
	public function test_get_reader_returns_wp_error_with_fake_key(): void {
		$service = new StripeTerminalService( 'sk_test_fake_key_123' );

		$result = $service->get_reader( 'tmr_fake_reader' );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	// -----------------------------------------------------------------------
	// cancel_reader_action tests
	// -----------------------------------------------------------------------

	/**
	 * Test cancel_reader_action returns WP_Error with fake key.
	 */
	public function test_cancel_reader_action_returns_wp_error_with_fake_key(): void {
		$service = new StripeTerminalService( 'sk_test_fake_key_123' );

		$result = $service->cancel_reader_action( 'tmr_fake_reader' );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/**
	 * Test cancel_reader_action returns idle status when reader has no action to cancel.
	 *
	 * When Stripe throws InvalidRequestException with stripeCode 'resource_missing',
	 * the method should gracefully return array('status' => 'idle') instead of an error.
	 */
	public function test_cancel_reader_action_returns_idle_for_resource_missing(): void {
		$service = new StripeTerminalService( 'sk_test_fake_key_123' );

		// Build the mock chain: $stripe->terminal->readers->cancelAction() throws.
		$readers_mock = Mockery::mock();
		$readers_mock->shouldReceive( 'cancelAction' )
			->with( 'tmr_fake_reader' )
			->andThrow(
				\Stripe\Exception\InvalidRequestException::factory(
					'This reader does not have an action to cancel.',
					400,
					null,
					null,
					null,
					'resource_missing'
				)
			);

		$terminal_mock          = Mockery::mock();
		$terminal_mock->readers = $readers_mock;

		$stripe_mock           = Mockery::mock( \Stripe\StripeClient::class );
		$stripe_mock->terminal = $terminal_mock;

		$service->set_stripe_client( $stripe_mock );

		$result = $service->cancel_reader_action( 'tmr_fake_reader' );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'status' => 'idle' ), $result );
	}

	// -----------------------------------------------------------------------
	// create_payment_intent — MOTO tests
	// -----------------------------------------------------------------------

	/**
	 * Test create_payment_intent with moto=true reaches the API call.
	 */
	public function test_create_payment_intent_with_moto_reaches_api_call(): void {
		$service = new StripeTerminalService( 'sk_test_fake_key_123' );

		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 42 );
		$order->shouldReceive( 'get_total' )->andReturn( '25.00' );
		$order->shouldReceive( 'get_currency' )->andReturn( 'USD' );

		$result = $service->create_payment_intent( $order, null, true );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'unsupported_currency', $result->get_error_code() );
		$this->assertNotSame( 'missing_params', $result->get_error_code() );
	}

	/**
	 * Test create_payment_intent with moto=false behaves normally.
	 */
	public function test_create_payment_intent_with_moto_false_reaches_api_call(): void {
		$service = new StripeTerminalService( 'sk_test_fake_key_123' );

		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 42 );
		$order->shouldReceive( 'get_total' )->andReturn( '25.00' );
		$order->shouldReceive( 'get_currency' )->andReturn( 'USD' );

		$result = $service->create_payment_intent( $order, null, false );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'unsupported_currency', $result->get_error_code() );
		$this->assertNotSame( 'missing_params', $result->get_error_code() );
	}

	/**
	 * Test cancel_reader_action returns busy status when reader is mid-authorization.
	 *
	 * When Stripe throws InvalidRequestException with stripeCode 'terminal_reader_busy',
	 * the method should gracefully return array('status' => 'busy') instead of an error.
	 */
	public function test_cancel_reader_action_returns_busy_for_terminal_reader_busy(): void {
		$service = new StripeTerminalService( 'sk_test_fake_key_123' );

		// Build the mock chain: $stripe->terminal->readers->cancelAction() throws.
		$readers_mock = Mockery::mock();
		$readers_mock->shouldReceive( 'cancelAction' )
			->with( 'tmr_fake_reader' )
			->andThrow(
				\Stripe\Exception\InvalidRequestException::factory(
					'The reader is busy and cannot cancel the action.',
					400,
					null,
					null,
					null,
					'terminal_reader_busy'
				)
			);

		$terminal_mock          = Mockery::mock();
		$terminal_mock->readers = $readers_mock;

		$stripe_mock           = Mockery::mock( \Stripe\StripeClient::class );
		$stripe_mock->terminal = $terminal_mock;

		$service->set_stripe_client( $stripe_mock );

		$result = $service->cancel_reader_action( 'tmr_fake_reader' );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'status' => 'busy' ), $result );
	}

	// -----------------------------------------------------------------------
	// Dispatch-first reader recovery tests
	// -----------------------------------------------------------------------

	/**
	 * Test the happy path dispatches without retrieving reader state first.
	 */
	public function test_process_payment_intent_dispatches_without_reader_preflight(): void {
		$service = new StripeTerminalService( 'sk_test_fake_key_123' );

		$reader_mock = Mockery::mock( \Stripe\Terminal\Reader::class );
		$reader_mock->shouldReceive( 'toArray' )
			->andReturn(
				array(
					'id'     => 'tmr_fake_reader',
					'action' => null,
				)
			);

		$retrieve_calls = 0;
		$readers_mock    = Mockery::mock();
		$readers_mock->shouldReceive( 'retrieve' )
			->andReturnUsing(
				function () use ( &$retrieve_calls, $reader_mock ) {
					++$retrieve_calls;

					return $reader_mock;
				}
			);
		$readers_mock->shouldReceive( 'processPaymentIntent' )
			->once()
			->andReturn( $reader_mock );

		$terminal_mock          = Mockery::mock();
		$terminal_mock->readers = $readers_mock;

		$stripe_mock           = Mockery::mock( \Stripe\StripeClient::class );
		$stripe_mock->terminal = $terminal_mock;

		$service->set_stripe_client( $stripe_mock );

		$result = $service->process_payment_intent( 'tmr_fake_reader', 'pi_fake_intent' );

		$this->assertSame( 0, $retrieve_calls );
		$this->assertSame(
			array(
				'id'     => 'tmr_fake_reader',
				'action' => null,
			),
			$result
		);
	}

	/**
	 * Test process_payment_intent preserves an in-progress action for a different intent.
	 */
	public function test_process_payment_intent_returns_busy_for_different_in_progress_action(): void {
		$service = new StripeTerminalService( 'sk_test_fake_key_123' );

		$reader_with_action = Mockery::mock( \Stripe\Terminal\Reader::class );
		$reader_with_action->shouldReceive( 'toArray' )
			->andReturn(
				array(
					'id'           => 'tmr_fake_reader',
					'last_seen_at' => time() - 30,
					'action'       => array(
						'status'                 => 'in_progress',
						'process_payment_intent' => array(
							'payment_intent' => 'pi_old_intent',
						),
					),
				)
			);

		$dispatch_calls = 0;
		$readers_mock   = Mockery::mock();
		$readers_mock->shouldReceive( 'processPaymentIntent' )
			->with( 'tmr_fake_reader', Mockery::type( 'array' ) )
			->andReturnUsing(
				function () use ( &$dispatch_calls ) {
					++$dispatch_calls;

					throw \Stripe\Exception\InvalidRequestException::factory(
						'Reader is busy.',
						409,
						null,
						null,
						null,
						'terminal_reader_busy'
					);
				}
			);
		$readers_mock->shouldReceive( 'retrieve' )->with( 'tmr_fake_reader' )->once()->andReturn( $reader_with_action );
		$readers_mock->shouldNotReceive( 'cancelAction' );

		$terminal_mock          = Mockery::mock();
		$terminal_mock->readers = $readers_mock;

		$stripe_mock           = Mockery::mock( \Stripe\StripeClient::class );
		$stripe_mock->terminal = $terminal_mock;

		$service->set_stripe_client( $stripe_mock );

		$result = $service->process_payment_intent( 'tmr_fake_reader', 'pi_new_intent' );

		$this->assertSame( 1, $dispatch_calls );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'reader_busy', $result->get_error_code() );
		$this->assertSame(
			array(
				'status'                    => 409,
				'reader_id'                 => 'tmr_fake_reader',
				'current_payment_intent_id' => 'pi_old_intent',
				'can_force_cancel'          => true,
			),
			$result->get_error_data()
		);
	}

	/**
	 * Test a cached account country avoids an account API request.
	 */
	public function test_supported_currencies_uses_cached_account_country(): void {
		$cache_key = 'stwc_account_country_' . substr( md5( 'sk_test_cache_key' ), 0, 8 );
		$requested_key = null;
		Functions\when( 'get_transient' )->alias(
			function ( $key ) use ( &$requested_key ) {
				$requested_key = $key;

				return 'CA';
			}
		);

		$service = new StripeTerminalService( 'sk_test_cache_key' );
		$method  = new \ReflectionMethod( StripeTerminalService::class, 'get_supported_currencies' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		$this->assertSame( array( 'cad' ), $method->invoke( $service ) );
		$this->assertSame( $cache_key, $requested_key );
	}

	/**
	 * Test an account-country cache miss stores Stripe's response for one week.
	 */
	public function test_supported_currencies_caches_retrieved_account_country(): void {
		$cache_key = 'stwc_account_country_' . substr( md5( 'sk_test_cache_miss' ), 0, 8 );
		$stored_transient = null;
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value, $ttl ) use ( &$stored_transient ) {
				$stored_transient = array( $key, $value, $ttl );

				return true;
			}
		);

		$service = new StripeTerminalService( 'sk_test_cache_miss' );
		$client  = new StripeHttpClientFake(
			array(
				array(
					'body'   => array(
						'id'      => 'acct_test',
						'object'  => 'account',
						'country' => 'GB',
					),
					'status' => 200,
				),
			)
		);
		\Stripe\ApiRequestor::setHttpClient( $client );

		$method = new \ReflectionMethod( StripeTerminalService::class, 'get_supported_currencies' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		$this->assertSame( array( 'gbp' ), $method->invoke( $service ) );
		$this->assertSame( array( $cache_key, 'GB', 604800 ), $stored_transient );
		$this->assertCount( 1, $client->requests );
		$this->assertStringEndsWith( '/v1/account', $client->requests[0]['url'] );
	}
}
