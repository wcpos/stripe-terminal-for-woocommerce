<?php
/**
 * Stripe Terminal API
 * Handles the API for Stripe Terminal.
 *
 * @package WCPOS\WooCommercePOS\StripeTerminal
 */

namespace WCPOS\WooCommercePOS\StripeTerminal;

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
		} catch ( \Exception $e ) {
			// Gracefully handle initialization errors for the API key.
			$this->api_key = null;
		}

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the routes for the API.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/connection-token',
			array(
				'methods'  => 'POST',
				'callback' => array( $this, 'get_connection_token' ),
				'permission_callback' => '__return_true', // Allow all users to access this endpoint.
			)
		);

		register_rest_route(
			$this->namespace,
			'/list-locations',
			array(
				'methods'  => 'GET',
				'callback' => array( $this, 'list_locations' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			$this->namespace,
			'/register-reader',
			array(
				'methods'  => 'POST',
				'callback' => array( $this, 'register_reader' ),
				'permission_callback' => '__return_true',
			)
		);

		// Add endpoint for creating payment intents.
		register_rest_route(
			$this->namespace,
			'/create-payment-intent',
			array(
				'methods'  => 'POST',
				'callback' => array( $this, 'create_payment_intent' ),
				'permission_callback' => '__return_true',
			)
		);

		// Add endpoint for capturing payment intents.
		register_rest_route(
			$this->namespace,
			'/capture-payment-intent',
			array(
				'methods'  => 'POST',
				'callback' => array( $this, 'capture_payment_intent' ),
				'permission_callback' => '__return_true',
			)
		);

		// Add endpoint for attaching a payment method to a customer.
		register_rest_route(
			$this->namespace,
			'/attach-payment-method-to-customer',
			array(
				'methods'  => 'POST',
				'callback' => array( $this, 'attach_payment_method_to_customer' ),
				'permission_callback' => '__return_true',
			)
		);

		// Add the webhook route.
		register_rest_route(
			$this->namespace,
			'/webhook',
			array(
				'methods'  => 'POST',
				'callback' => array( $this, 'handle_webhook' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Retrieve the Stripe API key from WooCommerce settings.
	 *
	 * @return string The Stripe API key.
	 * @throws \Exception If the Stripe gateway is not enabled or the API key is missing.
	 */
	private function get_stripe_api_key() {
		try {
			$gateways = WC()->payment_gateways()->payment_gateways();
			$stripe_gateway = $gateways['stripe_terminal_for_woocommerce'] ?? null;

			if ( ! $stripe_gateway ) {
				throw new \Exception( 'Stripe Terminal gateway is not enabled.' );
			}

			$api_key = $stripe_gateway->get_option( 'api_key' );

			if ( empty( $api_key ) ) {
				throw new \Exception( 'Stripe API key is not set. Please configure the gateway settings.' );
			}

			return $api_key;
		} catch ( \Exception $e ) {
			throw $this->handle_stripe_exception( $e, 'get_stripe_api_key_error' );
		}
	}

		/**
		 * Get a connection token for the Stripe Terminal.
		 *
		 * @return \WP_REST_Response|\WP_Error The connection token or an error response.
		 */
	public function get_connection_token() {
		try {
				\Stripe\Stripe::setApiKey( $this->api_key );
				$token = \Stripe\Terminal\ConnectionToken::create();
				return rest_ensure_response( array( 'secret' => $token->secret ) );
		} catch ( \Exception $e ) {
				return $this->handle_stripe_exception( $e, 'connection_token_error' );
		}
	}

	/**
	 * List all locations associated with the Stripe account.
	 *
	 * @return \WP_REST_Response|\WP_Error A list of locations or an error response.
	 */
	public function list_locations() {
		try {
				\Stripe\Stripe::setApiKey( $this->api_key );
				$locations = \Stripe\Terminal\Location::all();
				return rest_ensure_response( $locations->data );
		} catch ( \Exception $e ) {
				return $this->handle_stripe_exception( $e, 'list_locations_error' );
		}
	}

	/**
	 * Register a new reader with the Stripe account.
	 *
	 * @param \WP_REST_Request $request The request object containing reader details.
	 *
	 * @return \WP_REST_Response|\WP_Error The registered reader object or an error response.
	 */
	public function register_reader( \WP_REST_Request $request ) {
		try {
				\Stripe\Stripe::setApiKey( $this->api_key );
				$params = $request->get_json_params();
				$label = $params['label'] ?? null;
				$registration_code = $params['registrationCode'] ?? null;
				$location = $params['location'] ?? null;

			if ( empty( $label ) || empty( $registration_code ) || empty( $location ) ) {
					return new \WP_Error(
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

		} catch ( \Exception $e ) {
				return $this->handle_stripe_exception( $e, 'register_reader_error' );
		}
	}

	/**
	 * Create a payment intent.
	 *
	 * @param \WP_REST_Request $request The request object containing payment intent details.
	 *
	 * @return \WP_REST_Response|\WP_Error The created payment intent or an error response.
	 */
	public function create_payment_intent( \WP_REST_Request $request ) {
		try {
			\Stripe\Stripe::setApiKey( $this->api_key );

			$params = $request->get_json_params();
			$amount = $params['amount'] ?? null;
			$currency = $params['currency'] ?? 'usd';
			$description = $params['description'] ?? null;
			$payment_method_types = $params['payment_method_types'] ?? array( 'card' );

			if ( empty( $amount ) || empty( $currency ) ) {
				return new \WP_Error(
					'missing_params',
					'Both amount and currency are required.',
					array( 'status' => 400 )
				);
			}

			$payment_intent = \Stripe\PaymentIntent::create(
				array(
					'amount' => $amount,
					'currency' => $currency,
					'payment_method_types' => $payment_method_types,
					'description' => $description,
				)
			);

			return rest_ensure_response( $payment_intent );

		} catch ( \Exception $e ) {
			return $this->handle_stripe_exception( $e, 'create_payment_intent_error' );
		}
	}

	/**
	 * Attach a payment method to a customer.
	 *
	 * @param \WP_REST_Request $request The request object containing payment method and customer IDs.
	 *
	 * @return \WP_REST_Response|\WP_Error The attached payment method or an error response.
	 */
	public function attach_payment_method_to_customer( \WP_REST_Request $request ) {
		try {
			\Stripe\Stripe::setApiKey( $this->api_key );

			$params = $request->get_json_params();
			$payment_method_id = $params['payment_method_id'] ?? null;
			$customer_id = $params['customer_id'] ?? null;

			if ( empty( $payment_method_id ) || empty( $customer_id ) ) {
				return new \WP_Error(
					'missing_params',
					'Both payment_method_id and customer_id are required.',
					array( 'status' => 400 )
				);
			}

			$payment_method = \Stripe\PaymentMethod::retrieve( $payment_method_id );
			$payment_method->attach( array( 'customer' => $customer_id ) );

			return rest_ensure_response( $payment_method );

		} catch ( \Exception $e ) {
			return $this->handle_stripe_exception( $e, 'attach_payment_method_to_customer_error' );
		}
	}

	/**
	 * Capture a payment intent.
	 *
	 * @param \WP_REST_Request $request The request object containing payment intent ID.
	 *
	 * @return \WP_REST_Response|\WP_Error The captured payment intent or an error response.
	 */
	public function capture_payment_intent( \WP_REST_Request $request ) {
		try {
			$params = $request->get_json_params();
			$order_id = $params['order_id'] ?? null;
			$payment_intent = $params['payment_intent'] ?? null;

			if ( empty( $order_id ) || empty( $payment_intent ) ) {
				return new \WP_Error(
					'missing_params',
					'Both order_id and payment_intent are required.',
					array( 'status' => 400 )
				);
			}

			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				return new \WP_Error(
					'invalid_order',
					'Invalid order ID.',
					array( 'status' => 404 )
				);
			}

			// Extract charge data.
			$charge = $payment_intent['charges']['data'][0] ?? null;
			$balance_transaction_id = $charge['balance_transaction'] ?? null;

			// Save immediate metadata.
			$order->update_meta_data( '_transaction_id', $charge['id'] ?? null );
			$order->update_meta_data( '_stripe_currency', strtoupper( $charge['currency'] ?? '' ) );
			$order->update_meta_data( '_stripe_charge_captured', $charge['captured'] ? 'yes' : 'no' );
			$order->update_meta_data( '_stripe_intent_id', $payment_intent['id'] ?? null );
			$order->update_meta_data( '_stripe_terminal_reader_id', $payment_intent['terminal_reader_id'] ?? null );
			$order->update_meta_data( '_stripe_terminal_location_id', $payment_intent['terminal_location_id'] ?? null );
			$order->update_meta_data( '_stripe_card_type', ucfirst( $charge['payment_method_details']['card']['brand'] ?? '' ) );

			// Save order.
			$order->save();

			/**
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

		} catch ( \Exception $e ) {
			return $this->handle_stripe_exception( $e, 'update_order_payment_error' );
		}
	}

	/**
	 * Handle Stripe webhook events.
	 *
	 * @param \WP_REST_Request $request The incoming webhook request.
	 *
	 * @return \WP_REST_Response|\WP_Error A success or error response.
	 */
	public function handle_webhook( \WP_REST_Request $request ) {
		$payload = $request->get_body();
		$sig_header = $request->get_header( 'stripe-signature' );
		$endpoint_secret = 'your_webhook_secret'; // Replace with your actual webhook secret from Stripe

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
		} catch ( \Exception $e ) {
			return new \WP_Error(
				'webhook_error',
				'Webhook error: ' . $e->getMessage(),
				array( 'status' => 400 )
			);
		}
	}

	/**
	 * Update the order with the payment intent ID.
	 *
	 * @param object $payment_intent The payment intent object.
	 */
	private function update_order_with_payment_intent( $payment_intent ) {
		$order_id = $payment_intent->metadata->order_id ?? null;
		if ( ! $order_id ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$order->update_meta_data( '_stripe_payment_intent_id', $payment_intent->id );
		$order->save();
	}


	/**
	 * Update the order with the charge details.
	 *
	 * @param object $charge The charge object.
	 */
	private function update_order_with_charge( $charge ) {
		$order_id = $charge->metadata->order_id ?? null; // Ensure `order_id` is added to Charge metadata.
		if ( ! $order_id ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$order->update_meta_data( '_stripe_transaction_id', $charge->id );
		$order->update_meta_data( '_stripe_fee', number_format( $charge->balance_transaction->fee / 100, 2 ) );
		$order->update_meta_data( '_stripe_net', number_format( $charge->balance_transaction->net / 100, 2 ) );
		$order->save();
	}
}
