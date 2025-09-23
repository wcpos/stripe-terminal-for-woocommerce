<?php

namespace WCPOS\WooCommercePOS\StripeTerminal;

use Exception;
use WC_Order;
use WCPOS\WooCommercePOS\StripeTerminal\Abstracts\StripeErrorHandler;
use WCPOS\WooCommercePOS\StripeTerminal\Utils\CurrencyConverter;
use WP_Error;

/**
 * Stripe Terminal Service
 * Core service for handling Stripe Terminal operations.
 */
class StripeTerminalService {
	use StripeErrorHandler;

	/**
	 * Stripe API Key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Stripe client instance.
	 *
	 * @var null|\Stripe\StripeClient
	 */
	private $stripe_client;

	/**
	 * Constructor.
	 *
	 * @param string $api_key The Stripe API key to use.
	 */
	public function __construct( string $api_key ) {
		$this->api_key = $api_key;
	}

	/**
	 * Get the Stripe client instance.
	 *
	 * @return \Stripe\StripeClient
	 */
	public function get_stripe_client(): \Stripe\StripeClient {
		if ( ! $this->stripe_client ) {
			$this->stripe_client = new \Stripe\StripeClient( $this->api_key );
		}

		return $this->stripe_client;
	}

	/**
	 * Create a payment intent for an order.
	 *
	 * @param WC_Order $order  The WooCommerce order.
	 * @param null|int $amount Optional amount override (in cents).
	 *
	 * @return array|WP_Error The payment intent data or error.
	 */
	public function create_payment_intent( WC_Order $order, ?int $amount = null ) {
		try {
			\Stripe\Stripe::setApiKey( $this->api_key );

			$order_id             = $order->get_id();
			$amount               = $amount ?? CurrencyConverter::convert_to_stripe_amount( $order->get_total(), $order->get_currency() );
			$currency             = strtolower( $order->get_currency() );
			$description          = \sprintf( 'Order #%s', $order_id );
			
			// Check if currency is supported by Stripe Terminal
			$supported_currencies = $this->get_supported_currencies();
			if ( ! \in_array( $currency, $supported_currencies, true ) ) {
				return new WP_Error(
					'unsupported_currency',
					\sprintf( 'Currency %s is not supported by Stripe Terminal. Supported currencies: %s',
						strtoupper( $currency ),
						implode( ', ', array_map( 'strtoupper', $supported_currencies ) )
					),
					array( 'status' => 400 )
				);
			}
			
			$payment_method_types = 'cad' === $currency ? array( 'card_present', 'interac_present' ) : array( 'card_present' );

			if ( empty( $amount ) || empty( $currency ) ) {
				return new WP_Error(
					'missing_params',
					'Both amount and currency are required.',
					array( 'status' => 400 )
				);
			}

			$payment_intent = \Stripe\PaymentIntent::create(
				array(
					'amount'               => $amount,
					'currency'             => $currency,
					'payment_method_types' => $payment_method_types,
					'description'          => $description,
					'metadata'             => array( 'order_id' => $order_id ),
				)
			);

			return $payment_intent->toArray();
		} catch ( Exception $e ) {
			return $this->handle_stripe_exception( $e, 'create_payment_intent_error' );
		}
	}

	/**
	 * Process a payment intent on a reader.
	 *
	 * @param string $reader_id         The reader ID.
	 * @param string $payment_intent_id The payment intent ID.
	 * @param array  $process_config    Optional process configuration.
	 *
	 * @return array|WP_Error The updated reader data or error.
	 */
	public function process_payment_intent( string $reader_id, string $payment_intent_id, array $process_config = array() ) {
		try {
			\Stripe\Stripe::setApiKey( $this->api_key );

			// Default process config
			$default_config = array(
				'enable_customer_cancellation' => true,
			);
			$process_config = array_merge( $default_config, $process_config );

			$stripe = new \Stripe\StripeClient( $this->api_key );
			$reader = $stripe->terminal->readers->processPaymentIntent(
				$reader_id,
				array(
					'payment_intent' => $payment_intent_id,
					'process_config' => $process_config,
				)
			);

			return $reader->toArray();
		} catch ( Exception $e ) {
			return $this->handle_stripe_exception( $e, 'process_payment_intent_error' );
		}
	}

	/**
	 * Confirm a payment intent.
	 *
	 * @param string   $payment_intent_id The payment intent ID.
	 * @param WC_Order $order             The WooCommerce order.
	 *
	 * @return array|WP_Error The confirmed payment intent or error.
	 */
	public function confirm_payment_intent( string $payment_intent_id, WC_Order $order ) {
		try {
			\Stripe\Stripe::setApiKey( $this->api_key );

			$payment_intent = \Stripe\PaymentIntent::retrieve( $payment_intent_id );
			
			if ( 'succeeded' === $payment_intent->status ) {
				// Payment already succeeded, update the order
				$this->update_order_from_payment_intent( $order, $payment_intent );

				return $payment_intent->toArray();
			}

			// If payment intent is still in progress, return current status
			return $payment_intent->toArray();
		} catch ( Exception $e ) {
			return $this->handle_stripe_exception( $e, 'confirm_payment_intent_error' );
		}
	}

	/**
	 * Cancel a payment intent.
	 *
	 * @param string   $payment_intent_id The payment intent ID.
	 * @param WC_Order $order             The WooCommerce order.
	 *
	 * @return array|WP_Error The cancelled payment intent or error.
	 */
	public function cancel_payment_intent( string $payment_intent_id, WC_Order $order ) {
		try {
			\Stripe\Stripe::setApiKey( $this->api_key );

			$payment_intent = \Stripe\PaymentIntent::retrieve( $payment_intent_id );
			
			if ( 'requires_payment_method' === $payment_intent->status || 'requires_confirmation' === $payment_intent->status ) {
				$payment_intent->cancel();
			}

			return $payment_intent->toArray();
		} catch ( Exception $e ) {
			return $this->handle_stripe_exception( $e, 'cancel_payment_intent_error' );
		}
	}

	/**
	 * Update order from payment intent data.
	 *
	 * @param WC_Order              $order          The WooCommerce order.
	 * @param \Stripe\PaymentIntent $payment_intent The Stripe payment intent.
	 *
	 * @return void
	 */
	public function update_order_from_payment_intent( WC_Order $order, \Stripe\PaymentIntent $payment_intent ): void {
		// Extract charge data.
		$charge = $payment_intent->charges->data[0] ?? null;

		if ( $charge ) {
			// Save immediate metadata.
			$order->update_meta_data( '_transaction_id', $charge->id );
			$order->update_meta_data( '_stripe_currency', strtoupper( $charge->currency ) );
			$order->update_meta_data( '_stripe_charge_captured', $charge->captured ? 'yes' : 'no' );
			$order->update_meta_data( '_stripe_intent_id', $payment_intent->id );
			$order->update_meta_data( '_stripe_card_type', ucfirst( $charge->payment_method_details->card->brand ?? '' ) );

			// Save order.
			$order->save();
		}
	}

	/**
	 * Get connection token for Stripe Terminal.
	 *
	 * @return array|WP_Error The connection token or error.
	 */
	public function get_connection_token() {
		try {
			\Stripe\Stripe::setApiKey( $this->api_key );

			$connection_token = \Stripe\Terminal\ConnectionToken::create();

			return $connection_token->toArray();
		} catch ( Exception $e ) {
			return $this->handle_stripe_exception( $e, 'get_connection_token_error' );
		}
	}

	/**
	 * List Stripe Terminal locations.
	 *
	 * @return array|WP_Error The locations or error.
	 */
	public function list_locations() {
		try {
			\Stripe\Stripe::setApiKey( $this->api_key );

			$locations = \Stripe\Terminal\Location::all();

			return $locations->toArray();
		} catch ( Exception $e ) {
			return $this->handle_stripe_exception( $e, 'list_locations_error' );
		}
	}

	/**
	 * Register a Stripe Terminal reader.
	 *
	 * @param string $location_id       The location ID.
	 * @param string $registration_code The registration code.
	 *
	 * @return array|WP_Error The registered reader or error.
	 */
	public function register_reader( string $location_id, string $registration_code ) {
		try {
			\Stripe\Stripe::setApiKey( $this->api_key );

			$reader = \Stripe\Terminal\Reader::create(
				array(
					'registration_code' => $registration_code,
					'location'          => $location_id,
				)
			);

			return $reader->toArray();
		} catch ( Exception $e ) {
			return $this->handle_stripe_exception( $e, 'register_reader_error' );
		}
	}

	/**
	 * Get reader status.
	 *
	 * @param null|string $reader_id Optional reader ID to get status for specific reader.
	 *
	 * @return array|WP_Error The reader status or error.
	 */
	public function get_reader_status( ?string $reader_id = null ) {
		try {
			\Stripe\Stripe::setApiKey( $this->api_key );

			if ( $reader_id ) {
				$reader = \Stripe\Terminal\Reader::retrieve( $reader_id );

				return $reader->toArray();
			}
			// Get all readers
			$readers = \Stripe\Terminal\Reader::all();

			return $readers->toArray();
		} catch ( Exception $e ) {
			return $this->handle_stripe_exception( $e, 'get_reader_status_error' );
		}
	}

	/**
	 * Handle Stripe webhook events.
	 *
	 * @param array  $payload   The webhook payload.
	 * @param string $signature The webhook signature.
	 *
	 * @return array|WP_Error The webhook processing result or error.
	 */
	public function handle_webhook( array $payload, string $signature ) {
		try {
			\Stripe\Stripe::setApiKey( $this->api_key );

			// Verify webhook signature
			$webhook_secret = $this->get_webhook_secret();
			if ( ! $webhook_secret ) {
				return new WP_Error(
					'webhook_secret_missing',
					'Webhook secret not configured.',
					array( 'status' => 500 )
				);
			}

			$event = \Stripe\Webhook::constructEvent(
				$payload,
				$signature,
				$webhook_secret
			);

			// Handle the event
			switch ( $event->type ) {
				case 'payment_intent.succeeded':
					return $this->handle_payment_intent_succeeded( $event->data->object );
				case 'payment_intent.payment_failed':
					return $this->handle_payment_intent_failed( $event->data->object );
				case 'charge.succeeded':
					return $this->handle_charge_succeeded( $event->data->object );
				default:
					return array(
						'success' => true,
						'message' => 'Event type not handled: ' . $event->type,
					);
			}
		} catch ( Exception $e ) {
			return $this->handle_stripe_exception( $e, 'webhook_processing_error' );
		}
	}

	/**
	 * Check payment status directly from Stripe for an order.
	 *
	 * @param WC_Order $order The WooCommerce order.
	 *
	 * @return array|WP_Error The payment status information or error.
	 */
	public function check_payment_status_from_stripe( WC_Order $order ) {
		try {
			\Stripe\Stripe::setApiKey( $this->api_key );

			$order_id = $order->get_id();
			
			// First, try to get payment intent from order transaction ID
			$transaction_id = $order->get_transaction_id();
			if ( $transaction_id ) {
				try {
					// Check if it's a payment intent ID
					if ( 0 === strpos( $transaction_id, 'pi_' ) ) {
						$payment_intent = \Stripe\PaymentIntent::retrieve( $transaction_id );
					} else {
						// It might be a charge ID, get the payment intent from the charge
						$charge         = \Stripe\Charge::retrieve( $transaction_id );
						$payment_intent = \Stripe\PaymentIntent::retrieve( $charge->payment_intent );
					}
				} catch ( \Stripe\Exception\InvalidRequestException $e ) {
					// Transaction ID not found in Stripe, continue with search
				}
			}

			// If we don't have a payment intent yet, search for it by order metadata
			if ( ! isset( $payment_intent ) ) {
				$payment_intents = \Stripe\PaymentIntent::all( array(
					'limit' => 100,
				) );

				foreach ( $payment_intents->data as $pi ) {
					if ( isset( $pi->metadata->order_id ) && $pi->metadata->order_id == $order_id ) {
						$payment_intent = $pi;

						break;
					}
				}
			}

			if ( ! isset( $payment_intent ) ) {
				return new WP_Error(
					'payment_intent_not_found',
					'No payment intent found for this order in Stripe.',
					array( 'status' => 404 )
				);
			}

			// Get the latest charge for this payment intent
			$charges = \Stripe\Charge::all( array(
				'payment_intent' => $payment_intent->id,
				'limit'          => 1,
			) );

			$latest_charge = null;
			if ( ! empty( $charges->data ) ) {
				$latest_charge = $charges->data[0];
			}

			// If we found a successful charge but the order isn't paid yet, save metadata
			$metadata_saved = false;
			if ( $latest_charge && $latest_charge->paid && ! $order->is_paid() ) {
				// Save payment metadata instead of completing the order
				$order->update_meta_data( '_stripe_terminal_payment_intent_id', $payment_intent->id );
				$order->update_meta_data( '_stripe_terminal_charge_id', $latest_charge->id );
				$order->update_meta_data( '_stripe_terminal_payment_status', 'succeeded' );
				$order->update_meta_data( '_stripe_terminal_payment_amount', $latest_charge->amount );
				$order->update_meta_data( '_stripe_terminal_payment_currency', $latest_charge->currency );
				$order->update_meta_data( '_stripe_terminal_payment_method', 'card_present' );
				$order->save();

				// Add order note
				$order->add_order_note(
					\sprintf(
						__( 'Stripe Terminal payment detected via manual check. Payment Intent: %s, Charge: %s. Order ready for processing.', 'stripe-terminal-for-woocommerce' ),
						$payment_intent->id,
						$latest_charge->id
					)
				);

				$metadata_saved = true;
			}

			// Get the return URL if payment is successful
			$return_url = null;
			if ( $order->is_paid() ) {
				// We need to get the gateway instance to call order_received_url
				$gateway = WC()->payment_gateways()->payment_gateways()['stripe_terminal_for_woocommerce'] ?? null;
				if ( $gateway ) {
					// Get the default return URL first
					$default_url = $gateway->get_return_url( $order );
					// Then apply our custom POS logic
					$return_url = $gateway->order_received_url( $default_url, $order );
				}
			}

			return array(
				'success'        => true,
				'payment_intent' => array(
					'id'       => $payment_intent->id,
					'status'   => $payment_intent->status,
					'amount'   => $payment_intent->amount,
					'currency' => $payment_intent->currency,
					'created'  => $payment_intent->created,
				),
				'charge' => $latest_charge ? array(
					'id'       => $latest_charge->id,
					'status'   => $latest_charge->status,
					'paid'     => $latest_charge->paid,
					'amount'   => $latest_charge->amount,
					'currency' => $latest_charge->currency,
					'created'  => $latest_charge->created,
				) : null,
				'order_status'    => $order->get_status(),
				'order_paid'      => $order->is_paid(),
				'metadata_saved'  => $metadata_saved,
				'return_url'      => $return_url,
			);
		} catch ( Exception $e ) {
			return $this->handle_stripe_exception( $e, 'check_payment_status_error' );
		}
	}

	/**
	 * Handle successful payment intent.
	 *
	 * @param \Stripe\PaymentIntent $payment_intent The payment intent.
	 *
	 * @return array|WP_Error The result or error.
	 */
	private function handle_payment_intent_succeeded( \Stripe\PaymentIntent $payment_intent ) {
		$order_id = $payment_intent->metadata->order_id ?? null;
		
		if ( ! $order_id ) {
			return new WP_Error(
				'missing_order_id',
				'Order ID not found in payment intent metadata.',
				array( 'status' => 400 )
			);
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error(
				'invalid_order',
				'Order not found.',
				array( 'status' => 404 )
			);
		}

		// Update order from payment intent
		$this->update_order_from_payment_intent( $order, $payment_intent );

		// Complete the payment
		$order->payment_complete( $payment_intent->id );

		return array(
			'success'  => true,
			'message'  => 'Payment completed successfully.',
			'order_id' => $order_id,
		);
	}

	/**
	 * Handle successful charge.
	 *
	 * @param \Stripe\Charge $charge The charge object.
	 *
	 * @return array|WP_Error The result or error.
	 */
	private function handle_charge_succeeded( \Stripe\Charge $charge ) {
		// Get the payment intent from the charge
		$payment_intent_id = $charge->payment_intent;
		
		if ( ! $payment_intent_id ) {
			return new WP_Error(
				'missing_payment_intent',
				'Payment intent ID not found in charge.',
				array( 'status' => 400 )
			);
		}

		// Retrieve the payment intent to get order metadata
		$payment_intent = \Stripe\PaymentIntent::retrieve( $payment_intent_id );
		$order_id       = $payment_intent->metadata->order_id ?? null;
		
		if ( ! $order_id ) {
			return new WP_Error(
				'missing_order_id',
				'Order ID not found in payment intent metadata.',
				array( 'status' => 400 )
			);
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error(
				'invalid_order',
				'Order not found: ' . $order_id,
				array( 'status' => 404 )
			);
		}

		// Double-check that the order needs payment
		if ( $order->is_paid() ) {
			return array(
				'success'  => true,
				'message'  => 'Order already paid.',
				'order_id' => $order_id,
			);
		}

		// Save payment metadata instead of completing the order
		$order->update_meta_data( '_stripe_terminal_payment_intent_id', $payment_intent->id );
		$order->update_meta_data( '_stripe_terminal_charge_id', $charge->id );
		$order->update_meta_data( '_stripe_terminal_payment_status', 'succeeded' );
		$order->update_meta_data( '_stripe_terminal_payment_amount', $charge->amount );
		$order->update_meta_data( '_stripe_terminal_payment_currency', $charge->currency );
		$order->update_meta_data( '_stripe_terminal_payment_method', 'card_present' );
		$order->save();

		// Add order note
		$order->add_order_note(
			\sprintf(
				__( 'Stripe Terminal payment detected via webhook. Payment Intent: %s, Charge: %s. Order ready for processing.', 'stripe-terminal-for-woocommerce' ),
				$payment_intent->id,
				$charge->id
			)
		);

		return array(
			'success'   => true,
			'message'   => 'Payment metadata saved. Order ready for processing.',
			'order_id'  => $order_id,
			'charge_id' => $charge->id,
		);
	}

	/**
	 * Handle failed payment intent.
	 *
	 * @param \Stripe\PaymentIntent $payment_intent The payment intent.
	 *
	 * @return array|WP_Error The result or error.
	 */
	private function handle_payment_intent_failed( \Stripe\PaymentIntent $payment_intent ) {
		$order_id = $payment_intent->metadata->order_id ?? null;
		
		if ( ! $order_id ) {
			return new WP_Error(
				'missing_order_id',
				'Order ID not found in payment intent metadata.',
				array( 'status' => 400 )
			);
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error(
				'invalid_order',
				'Order not found.',
				array( 'status' => 404 )
			);
		}

		// Update order status to failed
		$order->update_status( 'failed', __( 'Payment failed via Stripe Terminal.', 'stripe-terminal-for-woocommerce' ) );

		return array(
			'success'  => true,
			'message'  => 'Payment failure handled.',
			'order_id' => $order_id,
		);
	}

	/**
	 * Get webhook secret from settings.
	 *
	 * @return null|string The webhook secret or null if not found.
	 */
	private function get_webhook_secret() {
		$settings  = get_option( 'woocommerce_stripe_terminal_for_woocommerce_settings', array() );
		$test_mode = $settings['test_mode'] ?? 'no';
		
		return 'yes' === $test_mode
			? $settings['test_webhook_secret'] ?? null
			: $settings['webhook_secret']      ?? null;
	}

	/**
	 * Get supported currencies for Stripe Terminal based on account region.
	 *
	 * @return array Array of supported currency codes.
	 */
	private function get_supported_currencies(): array {
		// Get account information to determine region
		try {
			\Stripe\Stripe::setApiKey( $this->api_key );
			$account = \Stripe\Account::retrieve();
			$country = $account->country ?? 'US'; // Default to US if not available
		} catch ( Exception $e ) {
			// If we can't get account info, default to US
			$country = 'US';
		}

		// Return supported currencies based on country
		switch ( $country ) {
			case 'US':
				return array( 'usd' );
			case 'CA':
				return array( 'cad' );
			case 'GB':
				return array( 'gbp' );
			case 'AU':
				return array( 'aud' );
			case 'NZ':
				return array( 'nzd' );
			case 'SG':
				return array( 'sgd' );
			case 'JP':
				return array( 'jpy' );
			case 'HK':
				return array( 'hkd' );
			case 'CH':
				return array( 'chf' );
			case 'NO':
				return array( 'nok' );
			case 'SE':
				return array( 'sek' );
			case 'DK':
				return array( 'dkk' );
			case 'PL':
				return array( 'pln' );
			case 'CZ':
				return array( 'czk' );
			case 'HU':
				return array( 'huf' );
			case 'RO':
				return array( 'ron' );
			case 'BG':
				return array( 'bgn' );
			case 'HR':
				return array( 'hrk' );
			case 'RS':
				return array( 'rsd' );
			case 'IS':
				return array( 'isk' );
			case 'MY':
				return array( 'myr' );
			case 'TH':
				return array( 'thb' );
			case 'IN':
				return array( 'inr' );
			case 'MX':
				return array( 'mxn' );
			case 'BR':
				return array( 'brl' );
			case 'AR':
				return array( 'ars' );
			case 'CL':
				return array( 'clp' );
			case 'CO':
				return array( 'cop' );
			case 'PE':
				return array( 'pen' );
			case 'UY':
				return array( 'uyu' );
			case 'PY':
				return array( 'pyg' );
			case 'BO':
				return array( 'bob' );
			case 'VE':
				return array( 'ves' );
			case 'GT':
				return array( 'gtq' );
			case 'HN':
				return array( 'hnl' );
			case 'NI':
				return array( 'nio' );
			case 'CR':
				return array( 'crc' );
			case 'PA':
				return array( 'pab' );
			case 'DO':
				return array( 'dop' );
			case 'TT':
				return array( 'ttd' );
			case 'JM':
				return array( 'jmd' );
			case 'BZ':
				return array( 'bzd' );
			case 'BB':
				return array( 'bbd' );
			case 'BS':
				return array( 'bsd' );
			case 'BMD':
				return array( 'bmd' );
			case 'KYD':
				return array( 'kyd' );
			case 'XCD':
				return array( 'xcd' );
			case 'AWG':
				return array( 'awg' );
			case 'ANG':
				return array( 'ang' );
			case 'SRD':
				return array( 'srd' );
			case 'GYD':
				return array( 'gyd' );
			case 'FKP':
				return array( 'fkp' );
			case 'SHP':
				return array( 'shp' );
			case 'EUR':
			default:
				// For European countries and others, support EUR and common currencies
				return array( 'eur', 'gbp', 'chf', 'nok', 'sek', 'dkk', 'pln', 'czk', 'huf', 'ron', 'bgn', 'hrk', 'rsd', 'isk' );
		}
	}
}
