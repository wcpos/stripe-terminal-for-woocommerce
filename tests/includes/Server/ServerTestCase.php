<?php
namespace WCPOS\WooCommercePOS\StripeTerminal\Tests\Server;

use Brain\Monkey;
use Brain\Monkey\Functions;
use WCPOS\WooCommercePOS\StripeTerminal\Tests\StripeHttpClientFake;
use WCPOS\WooCommercePOS\StripeTerminal\StripeTerminalService;
use WCPOS\WooCommercePOS\StripeTerminal\Server\Stripe_Server_Provider;

require_once dirname( __DIR__ ) . '/StripeTerminalServiceTest.php';

abstract class ServerTestCase extends \PHPUnit\Framework\TestCase {
	protected const PAYMENT_ID = 'A1234567-1234-4123-8123-123456789ABC';
	protected $http;
	protected $options;
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options = array(
			'woocommerce_stripe_terminal_for_woocommerce_settings' => array(
				'test_mode'               => 'yes',
				'test_pos_webhook_secret' => 'whsec_test',
			),
		);
		Functions\stubs(
			array(
				'apply_filters'    => function ( $hook, $value ) {
					return $value;
				},
				'__'               => function ( $value ) {
					return $value;
				},
				'esc_html'         => function ( $value ) {
					return $value;
				},
				'wp_json_encode'   => function ( $value ) {
					return json_encode( $value );
				},
				'get_transient'    => 'US',
				'delete_transient' => true,
				'set_transient'    => true,
				'get_option'       => function ( $key, $fallback = false ) {
					return $this->options[ $key ] ?? $fallback;
				},
				'update_option'    => function ( $key, $value, $autoload = null ) {
					$this->options[ $key ] = $value;
					return true;
				},
				'rest_url'         => function ( $path ) {
					return 'https://store.test/wp-json/' . $path;
				},
				'add_query_arg'    => function ( $key, $value, $url ) {
					return $url . '?' . $key . '=' . $value;
				},
			)
		);
	}

	protected function tearDown(): void {
		\Stripe\ApiRequestor::setHttpClient( \Stripe\HttpClient\CurlClient::instance() );
		Monkey\tearDown();
		parent::tearDown();
	}

	protected function provider( array $responses = array() ): Stripe_Server_Provider {
		$service = new StripeTerminalService( 'sk_test_fake' );
		$service->set_stripe_client( new \Stripe\StripeClient( 'sk_test_fake' ) );
		$this->http = new StripeHttpClientFake( $responses );
		\Stripe\ApiRequestor::setHttpClient( $this->http );
		return new Stripe_Server_Provider( $service );
	}

	protected function ok( array $body ): array {
		return array(
			'body'   => $body,
			'status' => 200,
		);
	}

	protected function error( string $code = 'card_declined' ): array {
		return array(
			'body'   => array(
				'error' => array(
					'type'    => 'invalid_request_error',
					'code'    => $code,
					'message' => 'Stripe refused',
				),
			),
			'status' => 400,
		);
	}

	protected function intent( array $overrides = array() ): array {
		return array_replace(
			array(
				'id'              => 'pi_test',
				'object'          => 'payment_intent',
				'status'          => 'requires_payment_method',
				'amount'          => 1250,
				'amount_received' => 0,
				'currency'        => 'usd',
				'livemode'        => false,
				'metadata'        => array(
					'wcpos_payment_id' => self::PAYMENT_ID,
					'wcpos_reader'     => 'tmr_test',
				),
			),
			$overrides
		);
	}

	protected function row( string $currency = 'USD' ): array {
		return array(
			'id'            => self::PAYMENT_ID,
			'order_id'      => 42,
			'amount'        => '12.50',
			'currency'      => $currency,
			'provider_refs' => array( 'action' => 'pi_test' ),
		);
	}

	protected function charge(): array {
		return array(
			'id'                     => 'ch_test',
			'object'                 => 'charge',
			'payment_method_details' => array(
				'type'         => 'card_present',
				'card_present' => array(
					'brand'       => 'visa',
					'last4'       => '4242',
					'funding'     => 'credit',
					'read_method' => 'contactless_emv',
					'receipt'     => array(
						'authorization_code'             => '1234',
						'application_preferred_name'     => 'VISA',
						'dedicated_file_name'            => 'A000',
						'cardholder_verification_method' => 'pin',
					),
				),
			),
		);
	}

	protected function assert_provider_error( $error, string $code ): void {
		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'wcpos_provider_error', $error->get_error_code() );
		$this->assertSame(
			array(
				'status' => 502,
				'detail' => array(
					'code'    => $code,
					'message' => $error->get_error_message(),
				),
			),
			$error->get_error_data()
		);
	}

	protected function assert_idempotency( int $request, string $key ): void {
		$this->assertContains( 'Idempotency-Key: ' . $key, $this->http->requests[ $request ]['headers'] );
	}
}
