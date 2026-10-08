<?php
/**
 * Extension-owned fixture for Pro's provider conformance suite: the real gateway, adapter and
 * service, with only Stripe's HTTP transport faked.
 *
 * @package WCPOS\WooCommercePOS\StripeTerminal\Tests\Conformance
 */

namespace WCPOS\WooCommercePOS\StripeTerminal\Tests\Conformance;

use WCPOS\WooCommercePOS\StripeTerminal\Gateway;
use WCPOS\WooCommercePOS\StripeTerminal\Settings;
use WCPOS\WooCommercePOSPro\API\V2\Payments_Webhook_Controller;
use WCPOS\WooCommercePOSPro\Payments\Device\Device_Providers;
use WCPOS\WooCommercePOSPro\Payments\Server\Reader_Curation;
use WCPOS\WooCommercePOSPro\Payments\Server\Server_Providers;
use WCPOS\WooCommercePOSPro\Tests\Conformance\Conformance_Fixture;

require_once __DIR__ . '/Fake_Stripe_Transport.php';
require_once __DIR__ . '/Recording_Stripe_Provider.php';

/**
 * Capabilities not claimed, and why: `expiry` (Stripe has no provider-side expiry; Pro's deadline
 * void is the only one), `cancel_unsupported` (cancel exists), `prompt` (smart readers take no
 * cashier prompts through the API), `test_live_isolation` (the adapter's credentials are the
 * gateway's current mode, so an action created in test mode would be fetched with live keys after
 * a mode switch; Stripe answers 404 rather than the wrong money, and the webhook refuses a mode
 * mismatch, but the lesson as written is not met).
 */
final class Stripe_Conformance_Fixture implements Conformance_Fixture {
	public const SECRET = 'whsec_conformance';
	/**
	 * Scripted Stripe.
	 *
	 * @var Fake_Stripe_Transport
	 */
	public $transport;
	private $registry_property;
	private $old_registry;
	private $device_registry_property;
	private $old_device_registry;
	private $old_gateways;
	private $old_options;
	private $old_currency;
	private $calls = array();
	private $aliases = array();

	public function gateway_id(): string {
		return Settings::GATEWAY_ID;
	}

	public function install(): void {
		$this->old_options  = get_option( 'woocommerce_' . Settings::GATEWAY_ID . '_settings', array() );
		$this->old_currency = get_option( 'woocommerce_currency' );
		update_option( 'woocommerce_currency', 'EUR' );
		update_option( 'woocommerce_' . Settings::GATEWAY_ID . '_settings', array( 'enabled' => 'yes', 'test_mode' => 'yes', 'test_secret_key' => 'sk_test_conformance', 'secret_key' => 'sk_live_conformance', 'test_pos_webhook_secret' => self::SECRET, 'wcpos_connection' => 'server' ) );
		$this->transport                   = new Fake_Stripe_Transport();
		Recording_Stripe_Provider::$fixture = $this;
		$this->registry_property = new \ReflectionProperty( Server_Providers::class, 'instance' );
		$this->registry_property->setAccessible( true );
		$this->old_registry = $this->registry_property->getValue();
		$this->registry_property->setValue( null, null );
		wcpos_pro_register_server_provider( Settings::GATEWAY_ID, Recording_Stripe_Provider::class );
		// The plugin registered its device provider at boot (no connection mode was saved yet), and
		// Pro routes a gateway with one to device mode; the server configuration under test has none.
		$this->device_registry_property = new \ReflectionProperty( Device_Providers::class, 'instance' );
		$this->device_registry_property->setAccessible( true );
		$this->old_device_registry = $this->device_registry_property->getValue();
		$this->device_registry_property->setValue( null, null );
		$this->old_gateways = WC()->payment_gateways;
		add_filter( 'woocommerce_payment_gateways', array( Gateway::class, 'register_gateway' ) );
		WC()->payment_gateways = new \WC_Payment_Gateways();
		Reader_Curation::forget( Settings::GATEWAY_ID );
		delete_option( 'wcpos_pro_readers_lkg_' . Settings::GATEWAY_ID );
	}

	public function uninstall(): void {
		\Stripe\ApiRequestor::setHttpClient( \Stripe\HttpClient\CurlClient::instance() );
		Recording_Stripe_Provider::$fixture = null;
		Reader_Curation::forget( Settings::GATEWAY_ID );
		delete_option( 'wcpos_pro_readers_lkg_' . Settings::GATEWAY_ID );
		$this->registry_property->setValue( null, $this->old_registry );
		$this->device_registry_property->setValue( null, $this->old_device_registry );
		WC()->payment_gateways = $this->old_gateways;
		update_option( 'woocommerce_' . Settings::GATEWAY_ID . '_settings', $this->old_options );
		update_option( 'woocommerce_currency', $this->old_currency );
	}

	public function supports( string $capability ): bool {
		return in_array( $capability, array( 'cancel', 'cancel_final', 'cancel_requested_then_completed', 'webhook', 'refund', 'partial_refund', 'manual_capture', 'legacy_adoption', 'historical_webview_refund' ), true );
	}

	public function script( string $scenario ): void {
		// Scenario => intent states successive fetches observe, reader answer to cancel_action, refund status.
		$scripts = array(
			'create_ok'                       => array( array( 'created' ) ),
			'create_indeterminate'            => array( array( 'created' ) ),
			'webhook_replay'                  => array( array( 'created' ) ),
			'webhook_out_of_order'            => array( array( 'created' ) ),
			'pending_then_completed'          => array( array( 'created', 'succeeded' ) ),
			'declined'                        => array( array( 'declined' ) ),
			'cancel_requested_then_cancelled' => array( array( 'canceled' ), 'busy' ),
			'cancel_requested_then_completed' => array( array( 'succeeded' ), 'busy' ),
			'cancel_final'                    => array( array( 'created' ), 'idle' ),
			'amount_mismatch'                 => array( array( 'short' ) ),
			'currency_mismatch'               => array( array( 'usd' ) ),
			'manual_capture'                  => array( array( 'requires_capture' ) ),
			'refund_ok'                       => array( array( 'succeeded' ), 'idle', 'succeeded' ),
			'refund_pending'                  => array( array( 'succeeded' ), 'idle', 'pending' ),
			'refund_failed'                   => array( array( 'succeeded' ), 'idle', 'failed' ),
		);
		if ( ! isset( $scripts[ $scenario ] ) ) {
			throw new \OutOfBoundsException( 'Unknown conformance scenario: ' . $scenario );
		}
		$this->transport->script( $scenario, ...$scripts[ $scenario ] );
	}

	public function webhook_request( string $event ): \WP_REST_Request {
		$tampered = 'tampered' === $event;
		$event    = $tampered ? 'completed' : $event;
		$types    = array( 'completed' => array( 'payment_intent.succeeded', 'succeeded' ), 'failed' => array( 'payment_intent.payment_failed', 'declined' ), 'cancelled' => array( 'payment_intent.canceled', 'canceled' ) );
		if ( ! isset( $types[ $event ] ) ) {
			throw new \OutOfBoundsException( 'Unknown webhook event: ' . $event );
		}
		$ref = (string) $this->transport->current;
		if ( wcpos_pro_payment_id_for_action( 'stripe', $ref ) ) {
			// Model an actual 0.x intent: the old panel wrote only order_id into the metadata.
			$this->transport->strip_payment_metadata( $ref );
		}
		$intent = $this->transport->observe( $ref, $types[ $event ][1] );
		if ( is_array( $intent['latest_charge'] ?? null ) ) {
			$intent['latest_charge'] = $intent['latest_charge']['id']; // Events carry the charge id, never an expansion.
		}
		$body      = wp_json_encode( array( 'id' => 'evt_' . $ref . '_' . $event, 'object' => 'event', 'type' => $types[ $event ][0], 'livemode' => false, 'data' => array( 'object' => $intent ) ) );
		$timestamp = time();
		$request   = new \WP_REST_Request( 'POST', Payments_Webhook_Controller::ROUTE );
		$request->set_query_params( array( 'provider' => 'stripe' ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( $body );
		$request->set_header( 'stripe-signature', 't=' . $timestamp . ',v1=' . hash_hmac( 'sha256', $timestamp . '.' . $body, $tampered ? 'whsec_wrong' : self::SECRET ) );
		return $request;
	}

	/** One stable alias per provider action; a charge resolves to its intent. */
	public function alias( string $ref ): string {
		$ref = $this->transport->intent_id_for( $ref );
		if ( ! isset( $this->aliases[ $ref ] ) ) {
			$this->aliases[ $ref ] = 'action_' . ( count( $this->aliases ) + 1 );
		}
		return $this->aliases[ $ref ];
	}

	/** Append an adapter operation to the transcript. */
	public function record( string $op, string $ref, string $details ): void {
		$this->calls[] = array( 'op' => $op, 'request' => 'action=' . $this->alias( $ref ) . ' ' . $details );
	}

	public function transcript(): array {
		return $this->calls;
	}

	public function reset_transcript(): void {
		$this->calls   = array();
		$this->aliases = array();
	}

	public function transcript_dir(): ?string {
		return __DIR__ . '/transcripts';
	}
}
