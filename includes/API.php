<?php
namespace WCPOS\WooCommercePOS\StripeTerminal;

/**
 * Class API
 * Handles the API for Stripe Terminal.
 */
class API {

	use StripeErrorHandler; // Include the Stripe error handler trait.

	/**
	 * Base URL for the API.
	 */
	private $base_url = 'stripe-terminal/v1';

	/**
	 * Stripe API Key.
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
			$this->base_url,
			'/connection-token',
			array(
				'methods'  => 'POST',
				'callback' => array( $this, 'get_connection_token' ),
				'permission_callback' => '__return_true', // Allow all users to access this endpoint.
			)
		);

		register_rest_route(
			$this->base_url,
			'/list-locations',
			array(
				'methods'  => 'GET',
				'callback' => array( $this, 'list_locations' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			$this->base_url,
			'/register-reader',
			array(
				'methods'  => 'POST',
				'callback' => array( $this, 'register_reader' ),
				'permission_callback' => '__return_true',
			)
		);

		// Add endpoint for creating payment intents.
		register_rest_route(
			$this->base_url,
			'/create-payment-intent',
			array(
				'methods'  => 'POST',
				'callback' => array( $this, 'create_payment_intent' ),
				'permission_callback' => '__return_true',
			)
		);

		// Add endpoint for capturing payment intents.
		register_rest_route(
			$this->base_url,
			'/capture-payment-intent',
			array(
				'methods'  => 'POST',
				'callback' => array( $this, 'capture_payment_intent' ),
				'permission_callback' => '__return_true',
			)
		);

		// Add endpoint for attaching a payment method to a customer.
		register_rest_route(
			$this->base_url,
			'/attach-payment-method-to-customer',
			array(
				'methods'  => 'POST',
				'callback' => array( $this, 'attach_payment_method_to_customer' ),
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
	 * Capture a payment intent.
	 *
	 * @param \WP_REST_Request $request The request object containing payment intent ID.
	 *
	 * @return \WP_REST_Response|\WP_Error The captured payment intent or an error response.
	 */
	public function capture_payment_intent( \WP_REST_Request $request ) {
		try {
			\Stripe\Stripe::setApiKey( $this->api_key );

			$params = $request->get_json_params();
			$payment_intent_id = $params['payment_intent_id'] ?? null;

			if ( empty( $payment_intent_id ) ) {
				return new \WP_Error(
					'missing_params',
					'The payment_intent_id is required.',
					array( 'status' => 400 )
				);
			}

			$payment_intent = \Stripe\PaymentIntent::retrieve( $payment_intent_id );
			$payment_intent->capture();

			return rest_ensure_response( $payment_intent );

		} catch ( \Exception $e ) {
			return $this->handle_stripe_exception( $e, 'capture_payment_intent_error' );
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
}
