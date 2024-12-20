<?php
/**
 * Abstract Class APIController
 * A base class for API-related controllers.
 *
 * @package WooCommerce\POS\StripeTerminal
 */

namespace WCPOS\WooCommercePOS\StripeTerminal\Abstracts;

use WP_REST_Controller;
use WP_Error;

/**
 * Abstract Class StripeBaseController
 * A base class for Stripe-related controllers.
 */
abstract class APIController extends WP_REST_Controller {
	use StripeErrorHandler; // Include the Stripe error handler trait.

	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'stripe-terminal/v1';

	/**
	 * Register routes for the API.
	 *
	 * This method must be implemented by child classes.
	 *
	 * @throws \LogicException If not implemented in a child class.
	 */
	public function register_routes() {
			throw new \LogicException(
				sprintf(
					'%s must implement the register_routes() method.',
					get_class( $this )
				)
			);
	}

	/**
	 * Process a Stripe exception and return a formatted WP_Error or string.
	 *
	 * @param \Exception $e The exception to handle.
	 * @param string     $context A context string (e.g., 'api', 'gateway').
	 *
	 * @return \WP_Error|string A formatted error for WordPress or a string for admin notices.
	 */
	public function handle_stripe_exception( \Exception $e, string $context = 'general' ) {
		if ( $e instanceof \Stripe\Exception\ApiErrorException ) {
			$status_code = 500; // Default status.
			$error_data = array(
				'context' => $context,
				'status' => $status_code,
				'request_id' => $e->getRequestId(),
			);

			if ( $e instanceof \Stripe\Exception\CardException ) {
				$status_code = 402; // Payment Required.
				$error_data['stripe_code'] = $e->getStripeCode();
				$error_data['decline_code'] = $e->getDeclineCode();
				$error_data['param'] = $e->getError() ? $e->getError()->param : null;
				$error_data['doc_url'] = $e->getError() ? $e->getError()->doc_url : null;

				// Additional outcome data if available.
				if ( isset( $e->getError()->payment_intent->charges->data[0]->outcome->type ) ) {
					$outcome_type = $e->getError()->payment_intent->charges->data[0]->outcome->type;
					$error_data['outcome_type'] = $outcome_type;

					if ( $outcome_type === 'blocked' ) {
						$error_data['outcome_reason'] = 'The payment was blocked by Stripe.';
					}
				}
			} elseif ( $e instanceof \Stripe\Exception\InvalidRequestException ) {
				$status_code = 400; // Bad Request.
				$error_data['stripe_code'] = $e->getStripeCode();
				$error_data['param'] = $e->getError() ? $e->getError()->param : null;
				$error_data['doc_url'] = $e->getError() ? $e->getError()->doc_url : null;
			} elseif ( $e instanceof \Stripe\Exception\AuthenticationException ) {
				$status_code = 401; // Unauthorized.
			} elseif ( $e instanceof \Stripe\Exception\ApiConnectionException ) {
				$status_code = 502; // Bad Gateway.
			} elseif ( $e instanceof \Stripe\Exception\PermissionException ) {
				$status_code = 403; // Forbidden.
			} elseif ( $e instanceof \Stripe\Exception\RateLimitException ) {
				$status_code = 429; // Too Many Requests.
			} elseif ( $e instanceof \Stripe\Exception\IdempotencyException ) {
				$status_code = 409; // Conflict.
			} elseif ( $e instanceof \Stripe\Exception\SignatureVerificationException ) {
				$status_code = 400; // Bad Request.
				$error_data['http_body'] = $e->getHttpBody();
				$error_data['sig_header'] = $e->getSigHeader();
			} elseif ( $e instanceof \Stripe\Exception\UnknownApiErrorException ) {
				$status_code = 500; // Internal Server Error.
			}

			$error_data['status'] = $status_code;

			// For admin notices, return a string.
			if ( $context === 'admin' ) {
				return sprintf(
					__( 'Stripe error (%1$s): %2$s', 'stripe-terminal-for-woocommerce' ),
					esc_html( $error_data['stripe_code'] ?? 'unknown' ),
					esc_html( $e->getMessage() )
				);
			}

			// For API responses, return a WP_Error.
			return new \WP_Error(
				'stripe_error',
				$e->getMessage(),
				$error_data
			);
		}

		// For non-Stripe exceptions.
		return $context === 'admin'
			? __( 'An unexpected error occurred.', 'stripe-terminal-for-woocommerce' )
			: new \WP_Error(
				'general_error',
				'An unexpected error occurred: ' . $e->getMessage(),
				array( 'status' => 500 )
			);
	}
}
