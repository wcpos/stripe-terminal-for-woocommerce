<?php
/**
 * Device transport contract tests.
 *
 * @package WCPOS\WooCommercePOS\StripeTerminal
 */

namespace WCPOS\WooCommercePOS\StripeTerminal\Tests\Server;

use WCPOS\WooCommercePOS\StripeTerminal\Server\Stripe_Device_Provider;
use WCPOS\WooCommercePOS\StripeTerminal\StripeTerminalService;

require_once __DIR__ . '/ServerTestCase.php';
require_once dirname( __DIR__ ) . '/GatewayTest.php';

/** Verify Stripe observations and SDK-only handoffs. */
class StripeDeviceProviderTest extends ServerTestCase {
	/**
	 * Mocked transport.
	 *
	 * @var StripeTerminalService
	 */
	private $service;
	/**
	 * Device adapter.
	 *
	 * @var Stripe_Device_Provider
	 */
	private $device;

	/** Set up a transport without network access. */
	protected function setUp(): void {
		parent::setUp();
		$this->service = \Mockery::mock( StripeTerminalService::class );
		$this->device  = new Stripe_Device_Provider( $this->service );
	}

	/** Advertise per-transport hardware limits and selected account configuration. */
	public function test_describe(): void {
		$this->assertSame( 'stripe', $this->device->provider() );
		$this->assertSame(
			array(
				'hardware'      => array(
					'discovery'  => 'sdk',
					'transports' => array(
						array(
							'transport' => 'bluetooth',
							'offline' => 'queue',
							'tips' => 'on_reader',
						),
						array(
							'transport' => 'tap_to_pay',
							'offline' => 'none',
							'tips' => 'none',
						),
					),
				),
				'capabilities'  => array(
					'tips'    => 'on_reader',
					'offline' => 'queue',
					'refunds' => array(
						'via' => 'provider',
						'partial' => true,
					),
				),
				'provider_data' => array(
					'location_id' => null,
					'test_mode' => true,
				),
			),
			$this->device->describe( new \WC_Payment_Gateway() )
		);
		$this->options['woocommerce_stripe_terminal_for_woocommerce_settings'] = array(
			'wcpos_location_test' => 'tml_test',
			'wcpos_location_live' => 'tml_live',
			'test_mode' => 'no',
		);
		$this->assertSame(
			array(
				'location_id' => 'tml_live',
				'test_mode' => false,
			),
			$this->device->describe( new \WC_Payment_Gateway() )['provider_data']
		);
	}

	/** Refuse before minting a token if mobile readers cannot register. */
	public function test_bootstrap_requires_location(): void {
		$this->service->shouldNotReceive( 'get_connection_token' );
		$error = $this->device->bootstrap( new \WC_Payment_Gateway(), array() );
		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'stripe_location_missing', $error->get_error_code() );
		$this->assertSame( array( 'status' => 409 ), $error->get_error_data() );
	}

	/** Return the ephemeral SDK token, never a made-up expiry. */
	public function test_bootstrap_handoff_and_error(): void {
		$this->options['woocommerce_stripe_terminal_for_woocommerce_settings']['wcpos_location_test'] = 'tml_test';
		$error = new \WP_Error( 'stripe_token_error', 'Unavailable' );
		$this->service->shouldReceive( 'get_connection_token' )->twice()->andReturn( array( 'secret' => 'pst_test' ), $error );
		$this->assertSame(
			array(
				'handoff' => array(
					'connection_token' => 'pst_test',
					'location_id' => 'tml_test',
				),
				'expires_at' => null,
			),
			$this->device->bootstrap( new \WC_Payment_Gateway(), array() )
		);
		$this->assertSame( $error, $this->device->bootstrap( new \WC_Payment_Gateway(), array() ) );
	}

	/**
	 * Keep units, row identity, and transport at the service boundary.
	 *
	 * @dataProvider amounts
	 * @param string $currency Currency code.
	 * @param string $amount Decimal amount.
	 * @param int    $minor Minor amount.
	 * @param array  $context Device context.
	 * @param string $transport Metadata transport.
	 */
	public function test_create_intent( string $currency, string $amount, int $minor, array $context, string $transport ): void {
		$row = $this->row( $currency );
		$row['amount'] = $amount;
		$this->service->shouldReceive( 'create_server_payment_intent' )->once()->with(
			$minor,
			strtolower( $currency ),
			'WCPOS payment ' . self::PAYMENT_ID,
			array(
				'wcpos_payment_id' => self::PAYMENT_ID,
				'wcpos_transport' => $transport,
			),
			self::PAYMENT_ID,
			false
		)->andReturn(
			array(
				'id' => 'pi_test',
				'client_secret' => 'pi_test_secret',
			)
		);
		$this->assertSame(
			array(
				'ref' => 'pi_test',
				'handoff' => array(
					'client_secret' => 'pi_test_secret',
					'payment_intent' => 'pi_test',
				),
			),
			$this->device->create_intent( $row, $context )
		);
	}

	/** Money conversion and both transports, including the absent context. */
	public function amounts(): array {
		return array(
			array( 'USD', '12.50', 1250, array( 'transport' => 'bluetooth' ), 'bluetooth' ),
			array( 'CAD', '12.50', 1250, array( 'transport' => 'tap_to_pay' ), 'tap_to_pay' ),
			array( 'JPY', '1250', 1250, array(), '' ),
		);
	}

	/** The reused service actually requests automatic capture and card_present only. */
	public function test_create_uses_automatic_capture(): void {
		$service = new StripeTerminalService( 'sk_test_fake' );
		$service->set_stripe_client( new \Stripe\StripeClient( 'sk_test_fake' ) );
		$this->http = new \WCPOS\WooCommercePOS\StripeTerminal\Tests\StripeHttpClientFake( array( $this->ok( $this->intent( array( 'client_secret' => 'pi_test_secret' ) ) ) ) );
		\Stripe\ApiRequestor::setHttpClient( $this->http );
		$result = ( new Stripe_Device_Provider( $service ) )->create_intent( $this->row(), array() );
		$this->assertSame( 'pi_test', $result['ref'] );
		$this->assertSame( 'automatic', $this->http->requests[0]['params']['capture_method'] );
		$this->assertSame( array( 'card_present' ), $this->http->requests[0]['params']['payment_method_types'] );
		$this->assert_idempotency( 0, self::PAYMENT_ID );
	}

	/**
	 * Project only observed Stripe state, with SDK secrets confined to handoff.
	 *
	 * @dataProvider states
	 * @param string $state Stripe status.
	 * @param string $expected Device status.
	 */
	public function test_fetch( string $state, string $expected ): void {
		$this->service->shouldReceive( 'retrieve_payment_intent' )->once()->with( 'pi_test' )->andReturn(
			$this->intent(
				array(
					'status' => $state,
					'client_secret' => 'pi_test_secret',
					'latest_charge' => $this->charge(),
					'amount_received' => 'succeeded' === $state ? 1500 : 0,
				)
			)
		);
		$result = $this->device->fetch( 'pi_test' );
		$this->assertSame( $expected, $result['status'] );
		$this->assertSame( 'requires_capture' === $state, $result['authorized'] ?? false );
		$this->assertSame( self::PAYMENT_ID, $result['payment_id'] );
		$this->assertSame(
			array(
				'client_secret' => 'pi_test_secret',
				'payment_intent' => 'pi_test',
			),
			$result['handoff']
		);
		$this->assertSame( 'succeeded' === $state ? '15.00' : '12.50', $result['amount'] );
		$this->assertSame( 'USD', $result['currency'] );
		$this->assertSame(
			array(
				'stripe_payment_intent' => 'pi_test',
				'stripe_charge' => 'ch_test',
				'stripe_mode' => 'test',
			),
			$result['provider_refs']
		);
		$this->assertSame( '4242', $result['receipt']['card_last4'] );
		$this->assertArrayNotHasKey( 'client_secret', $result );
	}

	/** Stripe's full status set plus an unknown observation. */
	public function states(): array {
		return array(
			array( 'requires_payment_method', 'in_progress' ),
			array( 'requires_confirmation', 'in_progress' ),
			array( 'requires_action', 'in_progress' ),
			array( 'processing', 'in_progress' ),
			array( 'requires_capture', 'in_progress' ),
			array( 'succeeded', 'completed' ),
			array( 'canceled', 'cancelled' ),
			array( 'unknown', 'pending' ),
		);
	}

	/** An SDK decline is retryable on the same intent; missing metadata stays unknown. */
	public function test_fetch_retryable_decline_and_missing_handoff(): void {
		$this->service->shouldReceive( 'retrieve_payment_intent' )->once()->with( 'pi_test' )->andReturn(
			$this->intent(
				array(
					'metadata' => array(),
					'last_payment_error' => array( 'code' => 'card_declined' ),
				)
			)
		);
		$result = $this->device->fetch( 'pi_test' );
		$this->assertSame( 'in_progress', $result['status'] );
		$this->assertSame( 'card_declined', $result['failure_reason'] );
		$this->assertNull( $result['payment_id'] );
		$this->assertSame(
			array(
				'client_secret' => null,
				'payment_intent' => 'pi_test',
			),
			$result['handoff']
		);
	}

	/** Service errors cannot become a successful handoff or money observation. */
	public function test_service_errors_pass_through(): void {
		$error = new \WP_Error( 'stripe_unavailable', 'Unavailable' );
		$this->service->shouldReceive( 'create_server_payment_intent' )->once()->andReturn( $error );
		$this->service->shouldReceive( 'retrieve_payment_intent' )->once()->with( 'pi_test' )->andReturn( $error );
		$this->assertSame( $error, $this->device->create_intent( $this->row(), array() ) );
		$this->assertSame( $error, $this->device->fetch( 'pi_test' ) );
	}

	/** Cancellation is final only when Stripe accepts it; Pro resolves completion races. */
	public function test_cancel_final_and_error(): void {
		$error = new \WP_Error( 'payment_intent_unexpected_state', 'Already succeeded', array( 'status' => 400 ) );
		$this->service->shouldReceive( 'cancel_payment_intent_by_id' )->twice()->with( 'pi_test' )->andReturn( $this->intent( array( 'status' => 'canceled' ) ), $error );
		$this->assertSame( 'final', $this->device->cancel( 'pi_test' ) );
		$this->assertSame( $error, $this->device->cancel( 'pi_test' ) );
	}

	/** Automatic capture keeps Pro's unsupported manual-capture response. */
	public function test_capture_is_unsupported(): void {
		$error = $this->device->capture( 'pi_test' );
		$this->assertSame( 'wcpos_capture_mode_unsupported', $error->get_error_code() );
		$this->assertSame( array( 'status' => 501 ), $error->get_error_data() );
	}

	/**
	 * Refund credentials follow the recorded mode, not the current setting.
	 *
	 * @dataProvider refund_modes
	 * @param string      $selected_mode Current test-mode setting.
	 * @param string|null $recorded_mode Payment mode, if recorded.
	 * @param string      $expected_key Expected request credential.
	 */
	public function test_refund_uses_payment_mode( string $selected_mode, ?string $recorded_mode, string $expected_key ): void {
		$this->options['woocommerce_stripe_terminal_for_woocommerce_settings'] = array(
			'test_mode' => $selected_mode,
			'test_secret_key' => 'sk_test_fake',
			'secret_key' => 'sk_live_fake',
		);
		$this->http = new \WCPOS\WooCommercePOS\StripeTerminal\Tests\StripeHttpClientFake(
			array(
				$this->ok(
					array(
						'id' => 're_test',
						'object' => 'refund',
						'status' => 'succeeded',
					)
				),
			)
		);
		// Service construction resets the HTTP client, so replace its singleton at the network boundary.
		$instance = new \ReflectionProperty( \Stripe\HttpClient\CurlClient::class, 'instance' );
		if ( PHP_VERSION_ID < 80100 ) {
			$instance->setAccessible( true );
		}
		$original = \Stripe\HttpClient\CurlClient::instance();
		$client = \Mockery::mock( \Stripe\HttpClient\CurlClient::class );
		$client->shouldReceive( 'setConnectTimeout', 'setTimeout' );
		$client->shouldReceive( 'getUserAgentInfo' )->andReturn( array() );
		$client->shouldReceive( 'request' )->once()->andReturnUsing( array( $this->http, 'request' ) );
		$instance->setValue( null, $client );
		try {
			$device = new Stripe_Device_Provider();
			$row = $this->row();
			if ( null !== $recorded_mode ) {
				$row['provider_refs']['stripe_mode'] = $recorded_mode;
			}
			$this->assertSame(
				array(
					'status' => 'succeeded',
					'provider_ref' => 're_test',
				),
				$device->refund( $row, 123, '2.50' )
			);
			$this->assertContains( 'Authorization: Bearer ' . $expected_key, $this->http->requests[0]['headers'] );
			$this->assertSame( 'pi_test', $this->http->requests[0]['params']['payment_intent'] );
			$this->assertSame( 250, $this->http->requests[0]['params']['amount'] );
		} finally {
			$instance->setValue( null, $original );
		}
	}

	/** Recorded modes win; rows without one use the current mode. */
	public function refund_modes(): array {
		return array(
			array( 'no', 'test', 'sk_test_fake' ),
			array( 'yes', 'live', 'sk_live_fake' ),
			array( 'yes', 'test', 'sk_test_fake' ),
			array( 'no', 'live', 'sk_live_fake' ),
			array( 'yes', null, 'sk_test_fake' ),
			array( 'no', null, 'sk_live_fake' ),
		);
	}

	/** Reuse server refund metadata, idempotency, amount conversion, and status mapping. */
	public function test_refund_delegation(): void {
		$this->service->shouldReceive( 'refund_payment' )->once()->with(
			'pi_test',
			250,
			array(
				'metadata' => array(
					'wcpos_payment_id' => self::PAYMENT_ID,
					'wcpos_refund_id' => '123',
				),
				'request_options' => array( 'idempotency_key' => 'wcpos_refund_' . self::PAYMENT_ID . '_123' ),
			)
		)->andReturn(
			array(
				'id' => 're_test',
				'status' => 'requires_action',
			)
		);
		$this->assertSame(
			array(
				'status' => 'pending',
				'provider_ref' => 're_test',
			),
			$this->device->refund( $this->row(), 123, '2.50' )
		);
		$row = $this->row();
		$row['provider_refs'] = array();
		$this->assert_provider_error( $this->device->refund( $row, 123, '2.50' ), 'missing_payment_ref' );
	}
}
