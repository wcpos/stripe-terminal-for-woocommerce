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

	/**
	 * Base URL for the API
	 */
	private $base_url = 'stripe-terminal/v1';

	/**
	 * Stripe API Key
	 */
	private $api_key;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->api_key = $this->get_stripe_api_key();

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the routes for the API
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
	 * Get the Stripe API key from WooCommerce settings
	 *
	 * @return string
	 */
	private function get_stripe_api_key() {
		$gateways = WC()->payment_gateways()->payment_gateways();
		$stripe_gateway = isset( $gateways['stripe_terminal_for_woocommerce'] ) ? $gateways['stripe_terminal_for_woocommerce'] : null;

		if ( ! $stripe_gateway ) {
			throw new \Exception( 'Stripe Terminal gateway is not enabled.' );
		}

		$api_key = $stripe_gateway->get_option( 'api_key' );

		if ( empty( $api_key ) ) {
			throw new \Exception( 'Stripe API key is not set. Please configure the gateway settings.' );
		}

		return $api_key;
	}

	/**
	 * Get a connection token
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_connection_token() {
		try {
			\Stripe\Stripe::setApiKey( $this->api_key );

			$token = \Stripe\Terminal\ConnectionToken::create();

			return rest_ensure_response( array( 'secret' => $token->secret ) );

		} catch ( \Exception $e ) {
			return new \WP_Error( 'connection_token_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * List locations
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function list_locations() {
		try {
			\Stripe\Stripe::setApiKey( $this->api_key );

			$locations = \Stripe\Terminal\Location::all();

			return rest_ensure_response( $locations->data );

		} catch ( \Exception $e ) {
			return new \WP_Error( 'list_locations_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Register a new reader
	 *
	 * @param \WP_REST_Request $request The request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function register_reader( \WP_REST_Request $request ) {
		try {
			\Stripe\Stripe::setApiKey( $this->api_key );

			// Decode JSON request body.
			$params = $request->get_json_params();

			// Extract parameters.
			$label = $params['label'] ?? null;
			$registration_code = $params['registrationCode'] ?? null; // Match the client key.
			$location = $params['location'] ?? null;

			if ( empty( $label ) || empty( $registration_code ) || empty( $location ) ) {
					return new \WP_Error(
						'missing_params',
						'Each reader object must include label, registrationCode, and location.',
						array( 'status' => 400 )
					);
			}

			// Register the reader with Stripe.
			$reader = \Stripe\Terminal\Reader::create(
				array(
					'label'             => $label,
					'registration_code' => $registration_code, // Stripe API requires snake_case.
					'location'          => $location,
				)
			);

				return rest_ensure_response( $reader );

		} catch ( \Exception $e ) {
			return new \WP_Error( 'register_reader_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}
}
