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
				'permission_callback' => '__return_true',
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
}
