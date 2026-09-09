<?php
/**
 * Stripe transport for Pro's shared server handler; Free owns settlement.
 *
 * @package WCPOS\WooCommercePOS\StripeTerminal
 */

namespace WCPOS\WooCommercePOS\StripeTerminal\Server;

use WCPOS\WooCommercePOS\StripeTerminal\Settings;
use WCPOS\WooCommercePOS\StripeTerminal\StripeTerminalService;
use WCPOS\WooCommercePOS\StripeTerminal\Utils\CurrencyConverter;

/** Smart-reader provider, independent of the legacy order-pay flow. */
class Stripe_Server_Provider extends \WCPOS\WooCommercePOSPro\Payments\Server\Abstract_Provider_Adapter {
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
		// Dashboard-configured reader tips are included in amount_received; Free adds the fee.
		return array(
			'capabilities'  => array(
				'tips' => 'on_reader',
				'refunds' => array(
					'via' => 'provider',
					'partial' => true,
				),
				'void' => true,
			),
			'provider_data' => array( 'mode' => Settings::is_test_mode() ? 'test' : 'live' ),
		);
	}

	/** {@inheritDoc} */
	public function list_readers() {
		try {
			$response = $this->service->list_all_readers();
			if ( is_wp_error( $response ) ) {
				return self::error( $response );
			}
			$readers = array();
			foreach ( $response as $reader ) {
				$type = $reader['device_type'] ?? '';
				if ( ! in_array( $type, array( 'bbpos_wisepos_e', 'stripe_s700', 'stripe_s710', 'verifone_P400' ), true ) && 0 !== strpos( $type, 'simulated_' ) ) {
					continue;
				}
				$readers[] = array(
					'id'     => $reader['id'],
					'label'  => ! empty( $reader['label'] ) ? $reader['label'] : ( ! empty( $reader['serial_number'] ) ? $reader['serial_number'] : $reader['id'] ),
					'status' => $reader['status'],
				);
			}
			return $readers;
		} catch ( \Throwable $e ) {
			return self::error( $e );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array  $row Ledger row.
	 * @param string $reader_id Selected reader.
	 */
	public function create_reader_action( array $row, string $reader_id ) {
		try {
			$order = wc_get_order( (int) $row['order_id'] );
			if ( ! $order ) {
				return new \WP_Error( 'wcpos_order_not_found', __( 'Order not found.', 'stripe-terminal-for-woocommerce' ), array( 'status' => 404 ) );
			}
			$intent = $this->service->create_server_payment_intent(
				CurrencyConverter::convert_to_stripe_amount( $row['amount'], $row['currency'] ),
				strtolower( $row['currency'] ),
				sprintf( 'Order #%s', $order->get_order_number() ),
				array(
					'order_id' => (string) $order->get_id(),
					'wcpos_payment_id' => $row['id'],
					'wcpos_reader' => $reader_id,
				),
				$row['id'],
				( 'cad' === strtolower( $row['currency'] ) )
			);
			if ( is_wp_error( $intent ) ) {
				return self::error( $intent );
			}
			// Written BEFORE the dispatch: a warm scheduled during this request must not replace the action.
			update_option( 'stwc_payment_dispatch_at', time(), false );
			$result = $this->service->process_payment_intent( $reader_id, $intent['id'], array( 'enable_customer_cancellation' => true ) );
			if ( is_wp_error( $result ) ) {
				$reader = $this->service->get_reader( $reader_id );
				if ( is_wp_error( $reader ) ) {
					return self::error( $result );
				}
				// Dispatch may have succeeded despite the error; let polling resolve this intent.
				$action = $reader['action'] ?? array();
				if ( ( $action['process_payment_intent']['payment_intent'] ?? null ) !== $intent['id']
					|| ! in_array( $action['status'] ?? '', array( 'in_progress', 'succeeded' ), true ) ) {
					$this->cancel_best_effort( $intent['id'] );
					return self::error( $result );
				}
			}
			return array(
				'ref' => $intent['id'],
				'expires_at' => null,
			);
		} catch ( \Throwable $e ) {
			if ( isset( $intent['id'] ) ) {
				$this->cancel_best_effort( $intent['id'] );
			}
			return self::error( $e );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $ref Intent ID.
	 */
	public function fetch( string $ref ) {
		try {
			$intent = $this->service->retrieve_payment_intent( $ref );
			if ( is_wp_error( $intent ) ) {
				return self::error( $intent );
			}
			$reader = array();
			if ( 'requires_payment_method' === $intent['status'] && ! empty( $intent['metadata']['wcpos_reader'] ) ) {
				$reader = $this->service->get_reader( $intent['metadata']['wcpos_reader'] );
				if ( is_wp_error( $reader ) ) {
					return self::error( $reader );
				}
			}
			$result = self::normalize( $intent, $reader['action'] ?? null );
			if ( 'failed' === $result['status'] && 'superseded' === ( $result['failure_reason'] ?? '' ) ) {
				// The intent and reader reads are not atomic: the card may have been approved between them.
				$again = $this->service->retrieve_payment_intent( $ref );
				if ( is_wp_error( $again ) ) {
					return self::error( $again );
				}
				if ( 'requires_payment_method' !== $again['status'] ) {
					$result = self::normalize( $again, null );
				}
			}
			if ( in_array( $result['status'], array( 'pending', 'in_progress' ), true ) ) {
				update_option( 'stwc_payment_dispatch_at', time(), false );
			}
			// A declined or superseded intent can never be charged again by us; retire it so nothing else can.
			if ( 'failed' === $result['status'] ) {
				$this->cancel_best_effort( $ref );
			}
			return $result;
		} catch ( \Throwable $e ) {
			return self::error( $e );
		}
	}

	/**
	 * Project Stripe observations without writing the ledger or order.
	 *
	 * @param array      $intent        Stripe intent.
	 * @param array|null $reader_action Current reader action.
	 * @return array Normalized provider observation.
	 */
	public static function normalize( array $intent, ?array $reader_action = null ): array {
		$states = array(
			'succeeded' => 'completed',
			'requires_capture' => 'completed',
			'canceled' => 'cancelled',
			'processing' => 'in_progress',
			'requires_confirmation' => 'in_progress',
			'requires_action' => 'in_progress',
		);
		$state  = $intent['status'];
		$result = array(
			'status'        => $states[ $state ] ?? 'pending',
			'amount'        => self::amount( $intent ),
			'currency'      => strtoupper( $intent['currency'] ),
			'provider_refs' => array(
				'stripe_payment_intent' => $intent['id'],
				'stripe_charge' => $intent['latest_charge']['id'] ?? null,
				'stripe_mode' => ! empty( $intent['livemode'] ) ? 'live' : 'test',
			),
			'receipt'       => self::receipt( $intent ),
		);
		if ( 'requires_capture' === $state ) {
			$result['authorized'] = true;
		}
		if ( 'requires_payment_method' === $state ) {
			$action_ref = $reader_action['process_payment_intent']['payment_intent'] ?? null;
			if ( ! empty( $intent['last_payment_error'] ) ) {
				$result['status']         = 'failed';
				$result['failure_reason'] = $intent['last_payment_error']['decline_code'] ?? $intent['last_payment_error']['code'] ?? 'provider_error';
			} elseif ( $action_ref === $intent['id'] ) {
				if ( 'failed' === ( $reader_action['status'] ?? '' ) ) {
					$code             = $reader_action['failure_code'] ?? 'provider_error';
					$result['status'] = 'customer_canceled' === $code ? 'cancelled' : 'failed';
					if ( 'failed' === $result['status'] ) {
						$result['failure_reason'] = $code;
					}
				} elseif ( in_array( $reader_action['status'] ?? '', array( 'in_progress', 'succeeded' ), true ) ) {
					$result['status'] = 'in_progress';
				}
			} elseif ( $action_ref ) {
				$result['status']         = 'failed';
				$result['failure_reason'] = 'superseded';
			}
		}
		return $result;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $ref Intent ID.
	 */
	public function cancel( string $ref ) {
		try {
			$intent = $this->service->retrieve_payment_intent( $ref );
			if ( is_wp_error( $intent ) ) {
				return self::error( $intent );
			}
			if ( in_array( $intent['status'], array( 'succeeded', 'requires_capture' ), true ) ) {
				return 'requested';
			}
			if ( 'canceled' === $intent['status'] ) {
				return 'final';
			}
			if ( ! empty( $intent['metadata']['wcpos_reader'] ) ) {
				// cancel_action resets whatever the reader is doing; only reset it while it is still
				// on THIS intent, never a later leg's action on the same reader.
				$reader = $this->service->get_reader( $intent['metadata']['wcpos_reader'] );
				if ( is_wp_error( $reader ) ) {
					return self::error( $reader );
				}
				$active = $reader['action']['process_payment_intent']['payment_intent'] ?? null;
				if ( $active === $ref && 'in_progress' === ( $reader['action']['status'] ?? '' ) ) {
					$reader = $this->service->cancel_reader_action( $intent['metadata']['wcpos_reader'] );
					if ( is_wp_error( $reader ) ) {
						return self::error( $reader );
					}
					if ( 'busy' === ( $reader['status'] ?? '' ) ) {
						return 'requested';
					}
				}
			}
			try {
				$cancelled = $this->service->cancel_payment_intent_by_id( $ref );
				if ( ! is_wp_error( $cancelled ) ) {
					return 'final';
				}
				if ( ! in_array( $cancelled->get_error_data()['stripe_code'] ?? '', array( 'intent_invalid_state', 'payment_intent_unexpected_state' ), true ) ) {
					return self::error( $cancelled );
				}
			} catch ( \Throwable $e ) { // A completion race needs a fresh money observation.
				$cancelled = self::error( $e );
			}
			$intent = $this->service->retrieve_payment_intent( $ref );
			if ( is_wp_error( $intent ) ) {
				return self::error( $intent );
			}
			return 'canceled' === $intent['status'] ? 'final' : 'requested';
		} catch ( \Throwable $e ) {
			return self::error( $e );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $ref Intent ID.
	 */
	public function capture( string $ref ) {
		try {
			$result = $this->service->capture_payment_intent( $ref );
			return is_wp_error( $result ) ? self::error( $result ) : self::normalize( $result, null );
		} catch ( \Throwable $e ) {
			return self::error( $e );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array  $row Ledger row.
	 * @param int    $refund_id WooCommerce refund ID.
	 * @param string $amount Decimal major units.
	 */
	public function refund( array $row, int $refund_id, string $amount ) {
		try {
			// Pro stores the intent under `action`; the fetch/webhook refs carry it too, for a row restored without one.
			$ref = $row['provider_refs']['action'] ?? $row['provider_refs']['stripe_payment_intent'] ?? '';
			if ( '' === $ref ) {
				return self::error( __( 'No Stripe payment found for refund.', 'stripe-terminal-for-woocommerce' ), 'missing_payment_ref' );
			}
			if ( 'CAD' === strtoupper( $row['currency'] ) ) {
				$intent = $this->service->retrieve_payment_intent( $ref );
				if ( is_wp_error( $intent ) ) {
					return self::error( $intent );
				}
				if ( 'interac_present' === ( $intent['latest_charge']['payment_method_details']['type'] ?? '' ) ) {
					return self::error( __( 'Interac refunds must be run on the reader', 'stripe-terminal-for-woocommerce' ), 'interac_refund_on_reader' );
				}
			}
			$result = $this->service->refund_payment(
				$ref,
				CurrencyConverter::convert_to_stripe_amount( $amount, $row['currency'] ),
				array(
					'metadata'        => array(
						'wcpos_payment_id' => $row['id'],
						'wcpos_refund_id' => (string) $refund_id,
					),
					'request_options' => array( 'idempotency_key' => 'wcpos_refund_' . $row['id'] . '_' . $refund_id ),
				)
			);
			if ( is_wp_error( $result ) ) {
				return self::error( $result );
			}
			$states = array(
				'succeeded' => 'succeeded',
				'pending' => 'pending',
				'requires_action' => 'pending',
				'failed' => 'failed',
				'canceled' => 'failed',
			);
			return array(
				'status' => $states[ $result['status'] ] ?? 'pending',
				'provider_ref' => $result['id'],
			);
		} catch ( \Throwable $e ) {
			return self::error( $e );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param \WP_REST_Request $request Signed Stripe request.
	 */
	public function verify_webhook( \WP_REST_Request $request ) {
		try {
			$secret = Settings::get_pos_webhook_secret();
			if ( '' === $secret ) {
				return new \WP_Error( 'stripe_pos_webhook_unconfigured', __( 'POS webhook secret not configured.', 'stripe-terminal-for-woocommerce' ), array( 'status' => 500 ) );
			}
			try {
				$event = \Stripe\Webhook::constructEvent( $request->get_body(), (string) $request->get_header( 'stripe-signature' ), $secret );
			} catch ( \Stripe\Exception\SignatureVerificationException $e ) {
				return new \WP_Error( 'stripe_webhook_bad_signature', $e->getMessage(), array( 'status' => 401 ) );
			} catch ( \Throwable $e ) {
				return new \WP_Error( 'stripe_webhook_invalid', $e->getMessage(), array( 'status' => 400 ) );
			}
			$intent = $event->data->object->toArray();
			if ( 0 === strpos( $event->type, 'terminal.reader.action_' ) ) {
				$ref = $intent['action']['process_payment_intent']['payment_intent'] ?? '';
				if ( '' === $ref ) {
					return new \WP_Error( 'stripe_webhook_no_intent', __( 'No payment intent in reader event.', 'stripe-terminal-for-woocommerce' ), array( 'status' => 404 ) );
				}
				$intent = $this->service->retrieve_payment_intent( $ref );
				if ( is_wp_error( $intent ) ) {
					return self::error( $intent );
				}
			} elseif ( 0 !== strpos( $event->type, 'payment_intent.' ) ) {
				return new \WP_Error( 'stripe_webhook_ignored', __( 'Event type not handled.', 'stripe-terminal-for-woocommerce' ), array( 'status' => 200 ) );
			}
			$id = $intent['metadata']['wcpos_payment_id'] ?? '';
			if ( ! is_string( $id ) || ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id ) ) {
				// Not ours (a legacy or online intent on the same account): a 2xx so Stripe stops redelivering it.
				return new \WP_Error( 'stripe_webhook_unknown_payment', __( 'Unknown POS payment.', 'stripe-terminal-for-woocommerce' ), array( 'status' => 200 ) );
			}
			if ( ( $intent['livemode'] ?? null ) !== ! Settings::is_test_mode() ) {
				return new \WP_Error( 'stripe_webhook_mode_mismatch', __( 'Stripe payment mode mismatch.', 'stripe-terminal-for-woocommerce' ), array( 'status' => 200 ) );
			}
			return array(
				'payment_id' => strtolower( $id ),
				'patch' => self::webhook_patch( $intent, $event->id ),
			);
		} catch ( \Throwable $e ) {
			return self::error( $e );
		}
	}

	/**
	 * Polling owns non-money outcomes; do not race a legitimate void.
	 *
	 * @param array  $intent   Verified intent.
	 * @param string $event_id Stripe event ID, not the intent ID.
	 * @return array Settlement patch without Pro-owned references.
	 */
	public static function webhook_patch( array $intent, string $event_id ): array {
		$patch = array( 'event_id' => $event_id );
		if ( 'succeeded' === $intent['status'] ) {
			$patch += array(
				'status' => 'captured',
				'amount' => self::amount( $intent ),
				'currency' => strtoupper( $intent['currency'] ),
				'receipt' => self::receipt( $intent ),
			);
		}
		return $patch;
	}

	/** Return the Pro webhook URL, separate from legacy checkout. */
	public static function webhook_url(): string {
		return add_query_arg( 'provider', 'stripe', rest_url( 'wcpos/v2/payments/webhook' ) );
	}

	/**
	 * Format confirmed money including on-reader tips.
	 *
	 * @param array $intent Stripe intent.
	 * @return string Decimal major units.
	 */
	private static function amount( array $intent ): string {
		$value = CurrencyConverter::convert_from_stripe_amount( ! empty( $intent['amount_received'] ) ? $intent['amount_received'] : $intent['amount'], $intent['currency'] );
		return number_format( $value, CurrencyConverter::get_decimal_places( $intent['currency'] ), '.', '' );
	}

	/**
	 * Extract flat, non-empty receipt strings from an expanded charge.
	 *
	 * @param array $intent Stripe intent.
	 * @return array Receipt fields.
	 */
	private static function receipt( array $intent ): array {
		$charge  = is_array( $intent['latest_charge'] ?? null ) ? $intent['latest_charge'] : array();
		$details = $charge['payment_method_details']['card_present'] ?? $charge['payment_method_details']['interac_present'] ?? array();
		return array_filter(
			array(
				'card_brand'   => $details['brand'] ?? null,
				'card_last4'   => $details['last4'] ?? null,
				'card_funding' => $details['funding'] ?? null,
				'auth_code'    => $details['receipt']['authorization_code'] ?? null,
				'application'  => $details['receipt']['application_preferred_name'] ?? null,
				'aid'          => $details['receipt']['dedicated_file_name'] ?? null,
				'verification' => $details['receipt']['cardholder_verification_method'] ?? null,
				'read_method'  => $details['read_method'] ?? null,
				'stripe_charge' => $charge['id'] ?? null,
			),
			static function ( $value ) {
				return is_string( $value ) && '' !== $value;
			}
		);
	}

	/**
	 * Retire failed attempts so a fresh retry cannot also charge them.
	 *
	 * @param string $ref Intent ID.
	 */
	private function cancel_best_effort( string $ref ): void {
		try {
			$this->service->cancel_payment_intent_by_id( $ref );
		} catch ( \Throwable $e ) { // Cleanup must not replace the original observation.
			return;
		}
	}

	/**
	 * Preserve Stripe detail while using Pro's provider error envelope.
	 *
	 * @param \WP_Error|\Throwable|string $error Service error, exception or message.
	 * @param string                      $code  Explicit local error code.
	 * @return \WP_Error Provider error.
	 */
	private static function error( $error, string $code = 'stripe_api_error' ): \WP_Error {
		if ( $error instanceof \WP_Error ) {
			$code    = $error->get_error_data()['stripe_code'] ?? $code;
			$message = $error->get_error_message();
		} elseif ( $error instanceof \Throwable ) {
			$code    = $error instanceof \Stripe\Exception\ApiErrorException ? ( $error->getStripeCode() ? $error->getStripeCode() : $code ) : $code;
			$message = $error->getMessage();
		} else {
			$message = $error;
		}
		return new \WP_Error(
			'wcpos_provider_error',
			$message,
			array(
				'status' => 502,
				'detail' => array(
					'code' => $code,
					'message' => $message,
				),
			)
		);
	}
}
