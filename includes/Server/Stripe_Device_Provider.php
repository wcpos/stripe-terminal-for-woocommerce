<?php
/**
 * Stripe SDK transport for Pro's device handler; Free owns settlement.
 *
 * @package WCPOS\WooCommercePOS\StripeTerminal
 */

namespace WCPOS\WooCommercePOS\StripeTerminal\Server;

use WCPOS\WooCommercePOS\StripeTerminal\Settings;
use WCPOS\WooCommercePOS\StripeTerminal\StripeTerminalService;
use WCPOS\WooCommercePOS\StripeTerminal\Utils\CurrencyConverter;

/** Bluetooth and Tap to Pay provider, independent of smart-reader dispatch. */
class Stripe_Device_Provider extends \WCPOS\WooCommercePOSPro\Payments\Device\Abstract_Device_Provider_Adapter {
	/**
	 * Stripe transport.
	 *
	 * @var StripeTerminalService
	 */
	private $service;

	/**
	 * Use the existing service, optionally injected for tests.
	 *
	 * @param StripeTerminalService|null $service Stripe transport.
	 */
	public function __construct( ?StripeTerminalService $service = null ) {
		$this->service = $service ?? new StripeTerminalService( Settings::get_api_key() );
	}

	/** {@inheritDoc} */
	public function provider(): string {
		return 'stripe';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param \WC_Payment_Gateway $gateway Gateway instance.
	 */
	public function describe( \WC_Payment_Gateway $gateway ): array {
		$location = Settings::get_wcpos_location();
		return array(
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
				'location_id' => '' !== $location ? $location : null,
				'test_mode'   => Settings::is_test_mode(),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param \WC_Payment_Gateway $gateway Gateway instance.
	 * @param array               $context Device context.
	 */
	public function bootstrap( \WC_Payment_Gateway $gateway, array $context ) {
		$location = Settings::get_wcpos_location();
		if ( '' === $location ) {
			return new \WP_Error( 'stripe_location_missing', __( 'Select a Terminal location in the Stripe Terminal settings before connecting a mobile reader.', 'stripe-terminal-for-woocommerce' ), array( 'status' => 409 ) );
		}
		$token = $this->service->get_connection_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		return array(
			'handoff'    => array(
				'connection_token' => $token['secret'],
				'location_id' => $location,
			),
			'expires_at' => null,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $row Ledger row.
	 * @param array $context Device context.
	 */
	public function create_intent( array $row, array $context ) {
		$intent = $this->service->create_server_payment_intent(
			CurrencyConverter::convert_to_stripe_amount( $row['amount'], $row['currency'] ),
			strtolower( $row['currency'] ),
			'WCPOS payment ' . $row['id'],
			array(
				'wcpos_payment_id' => $row['id'],
				'wcpos_transport'  => (string) ( $context['transport'] ?? '' ),
			),
			$row['id'],
			false
		);
		if ( is_wp_error( $intent ) ) {
			return $intent;
		}
		return array(
			'ref'     => $intent['id'],
			'handoff' => array(
				'client_secret' => $intent['client_secret'],
				'payment_intent' => $intent['id'],
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $ref Intent ID.
	 */
	public function fetch( string $ref ) {
		$intent = $this->service->retrieve_payment_intent( $ref );
		if ( is_wp_error( $intent ) ) {
			return $intent;
		}
		$result = Stripe_Server_Provider::normalize( $intent );
		// The SDK can retry a declined intent; an authorization is not an automatic capture.
		if ( in_array( $intent['status'], array( 'requires_payment_method', 'requires_capture' ), true ) ) {
			$result['status'] = 'in_progress';
		}
		$result['payment_id'] = $intent['metadata']['wcpos_payment_id'] ?? null;
		$result['handoff']    = array(
			'client_secret' => $intent['client_secret'] ?? null,
			'payment_intent' => $ref,
		);
		return $result;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $ref Intent ID.
	 */
	public function cancel( string $ref ) {
		$result = $this->service->cancel_payment_intent_by_id( $ref );
		return is_wp_error( $result ) ? $result : 'final';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array  $row Ledger row.
	 * @param int    $refund_id WooCommerce refund ID.
	 * @param string $amount Decimal major units.
	 */
	public function refund( array $row, int $refund_id, string $amount ) {
		$service = $this->service;
		$mode    = $row['provider_refs']['stripe_mode'] ?? null;
		if ( null !== $mode ) {
			$settings = Settings::get_gateway_settings();
			$service  = new StripeTerminalService( $settings[ 'test' === $mode ? 'test_secret_key' : 'secret_key' ] ?? '' );
		}
		return ( new Stripe_Server_Provider( $service ) )->refund( $row, $refund_id, $amount );
	}
}
