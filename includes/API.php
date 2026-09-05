<?php
/**
 * Stripe Terminal API
 * Handles the API for Stripe Terminal.
 *
 * @package WCPOS\WooCommercePOS\StripeTerminal
 */

namespace WCPOS\WooCommercePOS\StripeTerminal;

use Exception;
use WCPOS\WooCommercePOS\StripeTerminal\Utils\CurrencyConverter;
use WCPOS\WooCommercePOS\StripeTerminal\Utils\TipReconciler;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Class API
 * Handles the API for Stripe Terminal.
 */
class API extends Abstracts\APIController {
	/**
	 * Stripe API Key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Constructor.
	 *
	 * Initializes the API and registers the REST routes.
	 */
	public function __construct() {
		try {
			$this->api_key = $this->get_stripe_api_key();
		} catch ( Exception $e ) {
			// Gracefully handle initialization errors for the API key.
			$this->api_key = null;
		}

		$this->register_routes();
	}

	/**
	 * Register the routes for the API.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/connection-token',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'get_connection_token' ),
				'permission_callback' => array( $this, 'can_manage_terminal' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/list-locations',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_locations' ),
				'permission_callback' => array( $this, 'can_manage_terminal' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/register-reader',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'register_reader' ),
				'permission_callback' => array( $this, 'can_manage_terminal' ),
			)
		);

		// Add endpoint for creating payment intents.
		register_rest_route(
			$this->namespace,
			'/create-payment-intent',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_payment_intent' ),
				'permission_callback' => array( $this, 'can_process_payments' ),
			)
		);

		// Add endpoint for capturing payment intents.
		register_rest_route(
			$this->namespace,
			'/capture-payment-intent',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'capture_payment_intent' ),
				'permission_callback' => array( $this, 'can_process_payments' ),
			)
		);

		// Add endpoint for attaching a payment method to a customer.
		register_rest_route(
			$this->namespace,
			'/attach-payment-method-to-customer',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'attach_payment_method_to_customer' ),
				'permission_callback' => array( $this, 'can_manage_terminal' ),
			)
		);

		// Add the webhook route. Public by design: the handler authenticates
		// every request with the Stripe signature check before acting on it.
		register_rest_route(
			$this->namespace,
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_webhook' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Permission callback for terminal management routes (connection tokens,
	 * locations, reader registration, saved payment methods).
	 *
	 * @return bool|WP_Error
	 */
	public function can_manage_terminal() {
		return $this->require_capability( 'manage_woocommerce' );
	}

	/**
	 * Permission callback for the payment-intent routes.
	 *
	 * @return bool|WP_Error
	 */
	public function can_process_payments() {
		return $this->require_capability( 'publish_shop_orders' );
	}

	/**
	 * Allow the request when the current user has the capability, otherwise
	 * answer with the standard REST forbidden error.
	 *
	 * @param string $capability Capability to require.
	 *
	 * @return bool|WP_Error
	 */
	private function require_capability( string $capability ) {
		if ( current_user_can( $capability ) ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'Sorry, you are not allowed to do that.', 'stripe-terminal-for-woocommerce' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Get a connection token for the Stripe Terminal.
	 *
	 * @return WP_Error|WP_REST_Response The connection token or an error response.
	 */
	public function get_connection_token() {
		try {
			\Stripe\Stripe::setApiKey( $this->api_key );
			$token = \Stripe\Terminal\ConnectionToken::create();

			return rest_ensure_response( array( 'secret' => $token->secret ) );
		} catch ( Exception $e ) {
			return $this->handle_stripe_exception( $e, 'connection_token_error' );
		}
	}

	/**
	 * List all locations associated with the Stripe account.
	 *
	 * @return WP_Error|WP_REST_Response A list of locations or an error response.
	 */
	public function list_locations() {
		try {
			\Stripe\Stripe::setApiKey( $this->api_key );
			$locations = \Stripe\Terminal\Location::all();

			return rest_ensure_response( $locations->data );
		} catch ( Exception $e ) {
			return $this->handle_stripe_exception( $e, 'list_locations_error' );
		}
	}

	/**
	 * Register a new reader with the Stripe account.
	 *
	 * @param WP_REST_Request $request The request object containing reader details.
	 *
	 * @return WP_Error|WP_REST_Response The registered reader object or an error response.
	 */
	public function register_reader( WP_REST_Request $request ) {
		try {
			\Stripe\Stripe::setApiKey( $this->api_key );
			$params            = $request->get_json_params();
			$label             = $params['label'] ?? null;
			$registration_code = $params['registrationCode'] ?? null;
			$location          = $params['location'] ?? null;

			if ( empty( $label ) || empty( $registration_code ) || empty( $location ) ) {
				return new WP_Error(
					'missing_params',
					'Each reader object must include label, registrationCode, and location.',
					array( 'status' => 400 )
				);
			}

			$reader = \Stripe\Terminal\Reader::create(
				array(
					'label'             => $label,
					'registration_code' => $registration_code,
					'location'          => $location,
				)
			);

			return rest_ensure_response( $reader );
		} catch ( Exception $e ) {
			return $this->handle_stripe_exception( $e, 'register_reader_error' );
		}
	}

	/**
	 * Create a payment intent.
	 *
	 * @param WP_REST_Request $request The request object containing payment intent details.
	 *
	 * @return WP_Error|WP_REST_Response The created payment intent or an error response.
	 */
	public function create_payment_intent( WP_REST_Request $request ) {
		try {
			\Stripe\Stripe::setApiKey( $this->api_key );

			$params   = $request->get_json_params();
			$order_id = $params['order_id'] ?? null;
			$settings = get_option( 'woocommerce_stripe_terminal_for_woocommerce_settings', array() );
			$moto     = isset( $params['moto'] ) && true === $params['moto'] && 'yes' === ( $settings['enable_moto'] ?? 'no' );

			if ( empty( $order_id ) ) {
				return new WP_Error(
					'missing_params',
					'Order ID is required.',
					array( 'status' => 400 )
				);
			}

			$order                = wc_get_order( $order_id );
			$amount               = CurrencyConverter::convert_to_stripe_amount( $order->get_total(), $order->get_currency() );
			$tax_amount           = CurrencyConverter::convert_to_stripe_amount( $order->get_total_tax(), $order->get_currency() );
			$currency             = strtolower( $order->get_currency() );
			$description          = \sprintf( 'Order #%s', $order_id );
			if ( $moto ) {
				$payment_method_types = array( 'card' );
			} else {
				$payment_method_types = 'cad' === $currency ? array( 'card_present', 'interac_present' ) : array( 'card_present' );
			}

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

			return rest_ensure_response( $payment_intent );
		} catch ( Exception $e ) {
			return $this->handle_stripe_exception( $e, 'create_payment_intent_error' );
		}
	}

	/**
	 * Attach a payment method to a customer.
	 *
	 * @param WP_REST_Request $request The request object containing payment method and customer IDs.
	 *
	 * @return WP_Error|WP_REST_Response The attached payment method or an error response.
	 */
	public function attach_payment_method_to_customer( WP_REST_Request $request ) {
		try {
			\Stripe\Stripe::setApiKey( $this->api_key );

			$params            = $request->get_json_params();
			$payment_method_id = $params['payment_method_id'] ?? null;
			$customer_id       = $params['customer_id'] ?? null;

			if ( empty( $payment_method_id ) || empty( $customer_id ) ) {
				return new WP_Error(
					'missing_params',
					'Both payment_method_id and customer_id are required.',
					array( 'status' => 400 )
				);
			}

			$payment_method = \Stripe\PaymentMethod::retrieve( $payment_method_id );
			$payment_method->attach( array( 'customer' => $customer_id ) );

			return rest_ensure_response( $payment_method );
		} catch ( Exception $e ) {
			return $this->handle_stripe_exception( $e, 'attach_payment_method_to_customer_error' );
		}
	}

	/**
	 * Capture a payment intent.
	 *
	 * @param WP_REST_Request $request The request object containing payment intent ID.
	 *
	 * @return WP_Error|WP_REST_Response The captured payment intent or an error response.
	 */
	public function capture_payment_intent( WP_REST_Request $request ) {
		try {
			$params            = $request->get_json_params();
			$order_id          = $params['order_id'] ?? null;
			$payment_intent_id = $params['payment_intent']['id'] ?? null;

			if ( empty( $order_id ) || empty( $payment_intent_id ) ) {
				return new WP_Error(
					'missing_params',
					'Both order_id and payment_intent are required.',
					array( 'status' => 400 )
				);
			}

			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				return new WP_Error(
					'invalid_order',
					'Invalid order ID.',
					array( 'status' => 404 )
				);
			}

			// This endpoint is unauthenticated, and refunds later act on the metadata
			// saved here. Never store client-supplied payment data: retrieve the
			// payment intent with the store's own key and derive everything from it.
			\Stripe\Stripe::setApiKey( $this->api_key );
			$payment_intent = $this->retrieve_payment_intent( $payment_intent_id );

			$intent_order_id = isset( $payment_intent->metadata->order_id ) ? (string) $payment_intent->metadata->order_id : '';
			if ( (string) $order_id !== $intent_order_id ) {
				return new WP_Error(
					'order_mismatch',
					'The payment intent does not belong to this order.',
					array( 'status' => 403 )
				);
			}

			TipReconciler::maybe_add_tip_to_order( $order, $payment_intent );

			$charge = $payment_intent->charges->data[0] ?? $this->retrieve_latest_charge( $payment_intent->id );

			// Save immediate metadata.
			$order->update_meta_data( '_transaction_id', $charge->id ?? null );
			$order->update_meta_data( '_stripe_currency', strtoupper( $charge->currency ?? '' ) );
			$order->update_meta_data( '_stripe_charge_captured', ! empty( $charge->captured ) ? 'yes' : 'no' );
			$order->update_meta_data( '_stripe_intent_id', $payment_intent->id );
			$order->update_meta_data( '_stripe_terminal_livemode', ! empty( $payment_intent->livemode ) ? 'yes' : 'no' );
			$order->update_meta_data( '_stripe_card_type', ucfirst( $charge->payment_method_details->card->brand ?? '' ) );

			// Save order.
			$order->save();

			/*
			 * Don't change the order status to processing, as this will trigger the order to be marked as paid.
			 * We will allow the Gateway to handle changing the status and do the order->complete_payment lifecycle.
			 */
			// $order->update_status( 'processing', __( 'Payment processed via Stripe Terminal.', 'woocommerce' ) );

			return rest_ensure_response(
				array(
					'success' => true,
					'message' => 'Order payment details updated successfully.',
				)
			);
		} catch ( Exception $e ) {
			return $this->handle_stripe_exception( $e, 'update_order_payment_error' );
		}
	}

	/**
	 * Retrieve a payment intent from Stripe (seam for testing).
	 *
	 * @param string $payment_intent_id The payment intent ID.
	 *
	 * @return \Stripe\PaymentIntent The payment intent.
	 */
	protected function retrieve_payment_intent( string $payment_intent_id ) {
		return \Stripe\PaymentIntent::retrieve( $payment_intent_id );
	}

	/**
	 * Retrieve the latest charge for a payment intent (seam for testing).
	 *
	 * @param string $payment_intent_id The payment intent ID.
	 *
	 * @return null|\Stripe\Charge The latest charge, if any.
	 */
	protected function retrieve_latest_charge( string $payment_intent_id ) {
		$charges = \Stripe\Charge::all(
			array(
				'payment_intent' => $payment_intent_id,
				'limit'          => 1,
			)
		);

		return $charges->data[0] ?? null;
	}

	/**
	 * Handle Stripe webhook events.
	 *
	 * @param WP_REST_Request $request The incoming webhook request.
	 *
	 * @return WP_Error|WP_REST_Response A success or error response.
	 */
	public function handle_webhook( WP_REST_Request $request ) {
		$payload         = $request->get_body();
		$sig_header      = $request->get_header( 'stripe-signature' );
		$endpoint_secret = Settings::get_webhook_secret();

		try {
			// Verify the webhook signature.
			$event = \Stripe\Webhook::constructEvent( $payload, $sig_header, $endpoint_secret );

			// Process the event based on its type.
			switch ( $event->type ) {
				case 'payment_intent.succeeded':
					$payment_intent = $event->data->object;
					$this->update_order_with_payment_intent( $payment_intent );

					break;

				case 'charge.succeeded':
					$charge = $event->data->object;
					$this->update_order_with_charge( $charge );

					break;

				case 'payment_intent.payment_failed':
					$payment_intent = $event->data->object;
					$this->update_order_with_failed_payment( $payment_intent );

					break;
				default:
					// Event type not handled.
					return rest_ensure_response(
						array(
							'success' => true,
							'message' => 'Event ignored.',
						)
					);
			}

			return rest_ensure_response(
				array(
					'success' => true,
					'message' => 'Webhook handled successfully.',
				)
			);
		} catch ( Exception $e ) {
			return new WP_Error(
				'webhook_error',
				'Webhook error: ' . $e->getMessage(),
				array( 'status' => 400 )
			);
		}
	}

	/**
	 * Retrieve the Stripe API key from WooCommerce settings.
	 *
	 * @return string The Stripe API key, or an error.
	 *
	 * @throws Exception When the Stripe API key is not configured.
	 */
	private function get_stripe_api_key() {
		try {
			$api_key = Settings::get_api_key();

			if ( empty( $api_key ) ) {
				throw new Exception( 'Stripe API key is not set. Please configure the gateway settings.' );
			}

			return $api_key;
		} catch ( Exception $e ) {
			return $this->handle_stripe_exception( $e, 'get_stripe_api_key_error' );
		}
	}

	/**
	 * Update the order with the payment intent ID.
	 *
	 * @param object $payment_intent The payment intent object.
	 */
	private function update_order_with_payment_intent( $payment_intent ): void {
		$order_id = $payment_intent->metadata->order_id ?? null;
		if ( ! $order_id ) {
			Logger::log( 'Payment intent webhook: No order_id found in metadata', 'warning' );

			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			Logger::log( 'Payment intent webhook: Order not found: ' . $order_id, 'error' );

			return;
		}

		// A stale or unrelated intent must not overwrite or complete the order:
		// when a Terminal attempt is recorded, only that intent is accepted.
		$recorded_intent = (string) $order->get_meta( '_stripe_terminal_payment_intent_id' );
		if ( '' !== $recorded_intent && $recorded_intent !== $payment_intent->id ) {
			Logger::log( 'Payment intent webhook: ignoring intent ' . $payment_intent->id . ' for order ' . $order_id . '; the recorded Terminal intent is ' . $recorded_intent, 'warning' );

			return;
		}

		// Save payment metadata before completing the order.
		$order->update_meta_data( '_stripe_terminal_payment_intent_id', $payment_intent->id );
		$order->update_meta_data( '_stripe_terminal_payment_status', 'succeeded' );
		$order->update_meta_data( '_stripe_terminal_payment_amount', $payment_intent->amount );
		$order->update_meta_data( '_stripe_terminal_payment_currency', $payment_intent->currency );
		$order->update_meta_data( '_stripe_terminal_livemode', ! empty( $payment_intent->livemode ) ? 'yes' : 'no' );
		$is_moto        = isset( $payment_intent->payment_method_types ) && in_array( 'card', (array) $payment_intent->payment_method_types, true );
		$payment_method = $is_moto ? 'card' : 'card_present';
		if ( $is_moto ) {
			$order->update_meta_data( '_stripe_terminal_moto', 'yes' );
		} else {
			$order->delete_meta_data( '_stripe_terminal_moto' );
		}
		$order->update_meta_data( '_stripe_terminal_payment_method', $payment_method );
		TipReconciler::maybe_add_tip_to_order( $order, $payment_intent );
		$order->save();

		if ( 'succeeded' === $payment_intent->status && $order->needs_payment() ) {
			// The tip fee (if any) is on the order by now, so the intent must carry
			// exactly the order total in the order's currency to complete it.
			$expected_amount = CurrencyConverter::convert_to_stripe_amount( $order->get_total(), $order->get_currency() );
			$amount_matches  = (int) $payment_intent->amount === (int) $expected_amount
				&& strtolower( (string) $payment_intent->currency ) === strtolower( (string) $order->get_currency() );

			if ( $amount_matches ) {
				$transaction_id = $payment_intent->latest_charge ?? $payment_intent->id;
				$order->set_transaction_id( $transaction_id );
				$order->payment_complete( $transaction_id );
				$order->add_order_note( __( 'Stripe Terminal: Order completed from the payment_intent.succeeded webhook.', 'stripe-terminal-for-woocommerce' ) );
			} else {
				Logger::log( 'Payment intent webhook: intent ' . $payment_intent->id . ' amount ' . $payment_intent->amount . ' ' . $payment_intent->currency . ' does not match order ' . $order_id . ' total ' . $expected_amount . ' ' . $order->get_currency() . '; order left unpaid', 'warning' );
				$order->add_order_note( __( 'Stripe Terminal: Payment Intent succeeded but its amount does not match the order total; the order was not completed. Check the payment in Stripe.', 'stripe-terminal-for-woocommerce' ) );
			}
		}

		// Add detailed order note.
		/* translators: 1: Payment intent ID, 2: payment amount, 3: payment currency, 4: payment status. */
		$order_note = __( 'Stripe Terminal: Payment Intent succeeded - ID: %1$s, Amount: %2$s %3$s, Status: %4$s. Order ready for processing.', 'stripe-terminal-for-woocommerce' );

		$order->add_order_note(
			\sprintf(
				$order_note,
				$payment_intent->id,
				number_format( $payment_intent->amount / 100, 2 ),
				strtoupper( $payment_intent->currency ),
				$payment_intent->status
			)
		);

		Logger::log( 'Payment intent webhook: Metadata saved for order ' . $order_id . ' - Payment Intent: ' . $payment_intent->id, 'info' );
	}


	/**
	 * Update the order with the charge details.
	 *
	 * @param object $charge The charge object.
	 */
	private function update_order_with_charge( $charge ): void {
		// Get the payment intent from the charge to find the order.
		$payment_intent_id = $charge->payment_intent ?? null;
		if ( ! $payment_intent_id ) {
			Logger::log( 'Charge webhook: No payment_intent found in charge', 'warning' );

			return;
		}

		// Retrieve the payment intent to get order metadata.
		try {
			\Stripe\Stripe::setApiKey( Settings::get_api_key() );
			$payment_intent = \Stripe\PaymentIntent::retrieve( $payment_intent_id );
			$order_id       = $payment_intent->metadata->order_id ?? null;
		} catch ( Exception $e ) {
			Logger::log( 'Charge webhook: Failed to retrieve payment intent: ' . $e->getMessage(), 'error' );

			return;
		}

		if ( ! $order_id ) {
			Logger::log( 'Charge webhook: No order_id found in payment intent metadata', 'warning' );

			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			Logger::log( 'Charge webhook: Order not found: ' . $order_id, 'error' );

			return;
		}

		// Save payment metadata instead of completing the order immediately.
		$order->update_meta_data( '_stripe_terminal_payment_intent_id', $payment_intent_id );
		$order->update_meta_data( '_stripe_terminal_charge_id', $charge->id );
		$order->update_meta_data( '_stripe_terminal_payment_status', 'succeeded' );
		$order->update_meta_data( '_stripe_terminal_payment_amount', $charge->amount );
		$order->update_meta_data( '_stripe_terminal_payment_currency', $charge->currency );
		$order->update_meta_data( '_stripe_terminal_livemode', ! empty( $payment_intent->livemode ) ? 'yes' : 'no' );
		$is_moto        = isset( $payment_intent->payment_method_types ) && in_array( 'card', (array) $payment_intent->payment_method_types, true );
		$payment_method = $is_moto ? 'card' : 'card_present';
		if ( $is_moto ) {
			$order->update_meta_data( '_stripe_terminal_moto', 'yes' );
		} else {
			$order->delete_meta_data( '_stripe_terminal_moto' );
		}
		$order->update_meta_data( '_stripe_terminal_payment_method', $payment_method );
		TipReconciler::maybe_add_tip_to_order( $order, $payment_intent );
		$order->save();

		// Add detailed order note.
		/* translators: 1: Payment intent ID, 2: charge ID, 3: payment amount, 4: payment currency, 5: payment status. */
		$order_note = __( 'Stripe Terminal: Charge succeeded - Payment Intent: %1$s, Charge: %2$s, Amount: %3$s %4$s, Status: %5$s. Order ready for processing.', 'stripe-terminal-for-woocommerce' );

		$order->add_order_note(
			\sprintf(
				$order_note,
				$payment_intent_id,
				$charge->id,
				number_format( $charge->amount / 100, 2 ),
				strtoupper( $charge->currency ),
				$charge->status
			)
		);

		Logger::log( 'Charge webhook: Metadata saved for order ' . $order_id . ' - Payment Intent: ' . $payment_intent_id . ', Charge: ' . $charge->id, 'info' );
	}

	/**
	 * Update the order with failed payment intent details.
	 *
	 * @param object $payment_intent The failed payment intent object.
	 */
	private function update_order_with_failed_payment( $payment_intent ): void {
		$order_id = $payment_intent->metadata->order_id ?? null;
		if ( ! $order_id ) {
			Logger::log( 'Payment failed webhook: No order_id found in metadata', 'warning' );
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			Logger::log( 'Payment failed webhook: Order not found: ' . $order_id, 'error' );
			return;
		}

		// Guard against stale failed events arriving after a successful payment.
		$current_terminal_status = $order->get_meta( '_stripe_terminal_payment_status' );
		if ( $order->is_paid() || 'succeeded' === $current_terminal_status ) {
			Logger::log( 'Payment failed webhook: Ignored stale failed event for order ' . $order_id, 'info' );
			return;
		}

		// Save failure metadata.
		$error_message = $payment_intent->last_payment_error->message ?? 'Unknown error';
		$error_code    = $payment_intent->last_payment_error->code ?? null;
		$decline_code  = $payment_intent->last_payment_error->decline_code ?? null;

		$order->update_meta_data( '_stripe_terminal_payment_status', 'failed' );
		$order->update_meta_data( '_stripe_terminal_payment_error', $error_message );
		$order->save();

		$order->add_order_note(
			\sprintf(
				/* translators: 1: error message, 2: error code, 3: decline code, 4: payment intent ID */
				__( 'Stripe Terminal: Payment declined - %1$s (code: %2$s, decline_code: %3$s). Payment Intent: %4$s', 'stripe-terminal-for-woocommerce' ),
				$error_message,
				$error_code ?? 'n/a',
				$decline_code ?? 'n/a',
				$payment_intent->id
			)
		);

		Logger::log( 'Payment failed webhook: Failure metadata saved for order ' . $order_id . ' - ' . $error_message, 'info' );
	}
}
