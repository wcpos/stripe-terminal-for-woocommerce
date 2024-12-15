<?php
/**
 * Stripe Terminal API
 * Handles the API for Stripe Terminal.
 *
 * @package WCPOS\WooCommercePOS\StripeTerminal
 */

namespace WCPOS\WooCommercePOS\StripeTerminal;

/**
 * Class StripeTerminalAPI
 */
class API {
	/**
	 * Initialize the API
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register the routes for the API
	 */
	public static function register_routes() {
		register_rest_route(
			'stripe-terminal/v1',
			'/connection-token',
			array(
				'methods'  => 'POST',
				'callback' => array( __CLASS__, 'get_connection_token' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Get a connection token
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	/**
	 * Get a connection token
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_connection_token() {
		try {
			// Load the gateway instance correctly via WooCommerce.
			$gateways = WC()->payment_gateways()->payment_gateways();
			$stripe_gateway = isset( $gateways['stripe_terminal_for_woocommerce'] ) ? $gateways['stripe_terminal_for_woocommerce'] : null;

			if ( ! $stripe_gateway ) {
				throw new \Exception( 'Stripe Terminal gateway is not enabled.' );
			}

			// Retrieve the API key from the gateway settings.
			$api_key = $stripe_gateway->get_option( 'api_key' );

			if ( empty( $api_key ) ) {
				throw new \Exception( 'Stripe API key is not set. Please configure the gateway settings.' );
			}

			// Set the API key in the Stripe library.
			\Stripe\Stripe::setApiKey( $api_key );

			// Create a connection token.
			$token = \Stripe\Terminal\ConnectionToken::create();

			return rest_ensure_response( array( 'secret' => $token->secret ) );

		} catch ( \Exception $e ) {
			return new \WP_Error( 'connection_token_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}
}
