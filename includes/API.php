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
	public static function get_connection_token() {
		try {
			$token = \Stripe\Terminal\ConnectionToken::create();
			return rest_ensure_response( array( 'secret' => $token->secret ) );
		} catch ( \Exception $e ) {
			return new \WP_Error( 'connection_token_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}
}
