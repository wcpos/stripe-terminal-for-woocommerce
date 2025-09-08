<?php

namespace WCPOS\WooCommercePOS\StripeTerminal;

use Exception;
use WC_Order;

/**
 * Handles AJAX requests for Stripe Terminal payments.
 */
class AjaxHandler {
	/**
	 * The Stripe Terminal service instance.
	 *
	 * @var StripeTerminalService
	 */
	private $stripe_service;

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Initialize the Stripe Terminal service
		$this->init_stripe_service();
		
		// Payment intent creation
		add_action( 'wp_ajax_stripe_terminal_create_payment_intent', array( $this, 'create_payment_intent' ) );
		add_action( 'wp_ajax_nopriv_stripe_terminal_create_payment_intent', array( $this, 'create_payment_intent' ) );

		// Payment confirmation
		add_action( 'wp_ajax_stripe_terminal_confirm_payment', array( $this, 'confirm_payment' ) );
		add_action( 'wp_ajax_nopriv_stripe_terminal_confirm_payment', array( $this, 'confirm_payment' ) );

		// Payment cancellation
		add_action( 'wp_ajax_stripe_terminal_cancel_payment', array( $this, 'cancel_payment' ) );
		add_action( 'wp_ajax_nopriv_stripe_terminal_cancel_payment', array( $this, 'cancel_payment' ) );

		// Reader connection status
		add_action( 'wp_ajax_stripe_terminal_get_reader_status', array( $this, 'get_reader_status' ) );
		add_action( 'wp_ajax_nopriv_stripe_terminal_get_reader_status', array( $this, 'get_reader_status' ) );

		// Service validation and reader listing
		add_action( 'wp_ajax_stripe_terminal_validate_service', array( $this, 'validate_service' ) );
		add_action( 'wp_ajax_nopriv_stripe_terminal_validate_service', array( $this, 'validate_service' ) );
		add_action( 'wp_ajax_stripe_terminal_get_readers', array( $this, 'get_readers' ) );
		add_action( 'wp_ajax_nopriv_stripe_terminal_get_readers', array( $this, 'get_readers' ) );

		// Payment status check
		add_action( 'wp_ajax_stripe_terminal_check_payment_status', array( $this, 'check_payment_status' ) );
		add_action( 'wp_ajax_nopriv_stripe_terminal_check_payment_status', array( $this, 'check_payment_status' ) );
		
		add_action( 'wp_ajax_stripe_terminal_check_stripe_status', array( $this, 'check_stripe_status' ) );
		add_action( 'wp_ajax_nopriv_stripe_terminal_check_stripe_status', array( $this, 'check_stripe_status' ) );

		// Test helper - simulate payment
		add_action( 'wp_ajax_stripe_terminal_simulate_payment', array( $this, 'simulate_payment' ) );
		add_action( 'wp_ajax_nopriv_stripe_terminal_simulate_payment', array( $this, 'simulate_payment' ) );

		// Check payment status from Stripe
	}

	/**
	 * Create a payment intent for Stripe Terminal.
	 */
	public function create_payment_intent(): void {
		try {
			// Get and validate parameters
			$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
			$amount   = isset( $_POST['amount'] ) ? absint( $_POST['amount'] ) : 0;

			if ( ! $order_id || ! $amount ) {
				wp_send_json_error( 'Missing order ID or amount' );

				return;
			}

			// Get the order
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				wp_send_json_error( 'Order not found' );

				return;
			}

			// Debug order key validation
			$provided_order_key = isset( $_POST['order_key'] ) ? sanitize_text_field( $_POST['order_key'] ) : '';
			$order_key          = $order->get_order_key();
			$needs_payment      = $order->needs_payment();

			// Verify access using order key (works for both logged in and guest users)
			if ( ! $this->can_access_order( $order ) ) {
				wp_send_json_error( 'Access denied - invalid order key or order does not need payment' );

				return;
			}

			// Check if service is initialized
			if ( ! $this->stripe_service ) {
				wp_send_json_error( 'Stripe service not initialized - check API key configuration' );

				return;
			}

			// Create payment intent using the service
			$payment_intent = $this->stripe_service->create_payment_intent( $order, $amount );

			if ( is_wp_error( $payment_intent ) ) {
				Logger::log( 'Stripe Terminal AJAX - Payment intent creation failed: ' . $payment_intent->get_error_message() );
				wp_send_json_error( $payment_intent->get_error_message() );

				return;
			}

			wp_send_json_success( $payment_intent );
		} catch ( Exception $e ) {
			Logger::log( 'Stripe Terminal AJAX - Exception in create_payment_intent: ' . $e->getMessage() );
			wp_send_json_error( 'An error occurred while creating payment intent: ' . $e->getMessage() );
		}
	}

	/**
	 * Confirm a payment intent.
	 */
	public function confirm_payment(): void {
		try {
			// Get and validate parameters
			$payment_intent_id = isset( $_POST['payment_intent_id'] ) ? sanitize_text_field( $_POST['payment_intent_id'] ) : '';
			$order_id          = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;

			if ( ! $payment_intent_id || ! $order_id ) {
				wp_send_json_error( 'Missing payment intent ID or order ID' );

				return;
			}

			// Get the order
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				wp_send_json_error( 'Order not found' );

				return;
			}

			// Verify access using order key
			if ( ! $this->can_access_order( $order ) ) {
				wp_send_json_error( 'Access denied - invalid order key or order does not need payment' );

				return;
			}

			// Check if service is initialized
			if ( ! $this->stripe_service ) {
				wp_send_json_error( 'Stripe service not initialized - check API key configuration' );

				return;
			}

			// Confirm payment using the service
			$result = $this->stripe_service->confirm_payment_intent( $payment_intent_id, $order );

			if ( is_wp_error( $result ) ) {
				Logger::log( 'Stripe Terminal AJAX - Payment confirmation failed: ' . $result->get_error_message() );
				wp_send_json_error( $result->get_error_message() );

				return;
			}

			wp_send_json_success( $result );
		} catch ( Exception $e ) {
			Logger::log( 'Stripe Terminal AJAX - Exception in confirm_payment: ' . $e->getMessage() );
			wp_send_json_error( 'An error occurred while confirming payment: ' . $e->getMessage() );
		}
	}

	/**
	 * Cancel a payment intent.
	 */
	public function cancel_payment(): void {
		try {
			// Get and validate parameters
			$payment_intent_id = isset( $_POST['payment_intent_id'] ) ? sanitize_text_field( $_POST['payment_intent_id'] ) : '';
			$order_id          = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;

			if ( ! $payment_intent_id || ! $order_id ) {
				wp_send_json_error( 'Missing payment intent ID or order ID' );

				return;
			}

			// Get the order
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				wp_send_json_error( 'Order not found' );

				return;
			}

			// Verify access using order key
			if ( ! $this->can_access_order( $order ) ) {
				wp_send_json_error( 'Access denied - invalid order key or order does not need payment' );

				return;
			}

			// Check if service is initialized
			if ( ! $this->stripe_service ) {
				wp_send_json_error( 'Stripe service not initialized - check API key configuration' );

				return;
			}

			// Cancel payment using the service
			$result = $this->stripe_service->cancel_payment_intent( $payment_intent_id, $order );

			if ( is_wp_error( $result ) ) {
				Logger::log( 'Stripe Terminal AJAX - Payment cancellation failed: ' . $result->get_error_message() );
				wp_send_json_error( $result->get_error_message() );

				return;
			}

			wp_send_json_success( $result );
		} catch ( Exception $e ) {
			Logger::log( 'Stripe Terminal AJAX - Exception in cancel_payment: ' . $e->getMessage() );
			wp_send_json_error( 'An error occurred while cancelling payment: ' . $e->getMessage() );
		}
	}

	/**
	 * Get reader connection status.
	 */
	public function get_reader_status(): void {
		try {
			// Check if service is initialized
			if ( ! $this->stripe_service ) {
				wp_send_json_error( 'Stripe service not initialized - check API key configuration' );

				return;
			}

			// Get reader status using the service
			$result = $this->stripe_service->get_reader_status();

			if ( is_wp_error( $result ) ) {
				Logger::log( 'Stripe Terminal AJAX - Get reader status failed: ' . $result->get_error_message() );
				wp_send_json_error( $result->get_error_message() );

				return;
			}

			wp_send_json_success( $result );
		} catch ( Exception $e ) {
			Logger::log( 'Stripe Terminal AJAX - Exception in get_reader_status: ' . $e->getMessage() );
			wp_send_json_error( 'An error occurred while getting reader status: ' . $e->getMessage() );
		}
	}

	/**
	 * Validate the Stripe Terminal service.
	 */
	public function validate_service(): void {
		try {
			// Check if service is initialized
			if ( ! $this->stripe_service ) {
				wp_send_json_error( 'Stripe service not initialized - check API key configuration' );

				return;
			}

			// Test the API key by trying to list locations
			$result = $this->stripe_service->list_locations();
			
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( 'API key validation failed: ' . $result->get_error_message() );

				return;
			}

			wp_send_json_success( array(
				'valid'   => true,
				'message' => 'Service is ready',
			) );
		} catch ( Exception $e ) {
			Logger::log( 'Stripe Terminal AJAX - Service validation error: ' . $e->getMessage() );
			wp_send_json_error( 'Service validation failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Get available readers from Stripe Terminal.
	 */
	public function get_readers(): void {
		try {
			// Check if service is initialized
			if ( ! $this->stripe_service ) {
				wp_send_json_error( 'Stripe service not initialized - check API key configuration' );

				return;
			}

			// Get readers from Stripe
			$result = $this->stripe_service->get_reader_status();
			
			if ( is_wp_error( $result ) ) {
				Logger::log( 'Stripe Terminal AJAX - Failed to get readers: ' . $result->get_error_message() );
				wp_send_json_error( 'Failed to retrieve readers: ' . $result->get_error_message() );

				return;
			}

			$readers = $result['data'] ?? array();

			wp_send_json_success( array(
				'readers' => $readers,
				'count'   => \count( $readers ),
			) );
		} catch ( Exception $e ) {
			Logger::log( 'Stripe Terminal AJAX - Get readers error: ' . $e->getMessage() );
			wp_send_json_error( 'Failed to retrieve readers: ' . $e->getMessage() );
		}
	}

	/**
	 * Check payment status for an order (lightweight check).
	 */
	public function check_payment_status(): void {
		try {
			// Get and validate parameters
			$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;

			if ( ! $order_id ) {
				wp_send_json_error( 'Missing order ID' );

				return;
			}

			// Get the order
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				wp_send_json_error( 'Order not found' );

				return;
			}

			// Verify access using order key
			if ( ! $this->can_access_order( $order ) ) {
				wp_send_json_error( 'Access denied - invalid order key or order does not need payment' );

				return;
			}

			// Check if order is paid or has successful payment metadata
			$is_paid = $order->is_paid();
			$status  = $order->get_status();
			
			// Also check for saved payment metadata (from webhooks)
			$payment_status         = $order->get_meta( '_stripe_terminal_payment_status' );
			$payment_intent_id      = $order->get_meta( '_stripe_terminal_payment_intent_id' );
			$has_successful_payment = ( 'succeeded' === $payment_status && ! empty( $payment_intent_id ) );
			
			// Consider payment successful if order is paid OR has successful payment metadata
			$payment_successful = $is_paid || $has_successful_payment;

			// Get the return URL if payment is successful
			$return_url = null;
			if ( $payment_successful ) {
				$gateway = WC()->payment_gateways()->payment_gateways()['stripe_terminal_for_woocommerce'] ?? null;
				if ( $gateway ) {
					// Get the default return URL first
					$default_url = $gateway->get_return_url( $order );
					// Then apply our custom POS logic
					$return_url = $gateway->order_received_url( $default_url, $order );
				}
			}

			wp_send_json_success( array(
				'is_paid'          => $payment_successful, // Use the combined check
				'status'           => $status,
				'order_id'         => $order_id,
				'transaction_id'   => $order->get_transaction_id(),
				'return_url'       => $return_url,
				'payment_metadata' => array(
					'payment_status'         => $payment_status,
					'payment_intent_id'      => $payment_intent_id,
					'has_successful_payment' => $has_successful_payment,
				),
			) );
		} catch ( Exception $e ) {
			Logger::log( 'Stripe Terminal AJAX - Exception in check_payment_status: ' . $e->getMessage() );
			wp_send_json_error( 'An error occurred while checking payment status: ' . $e->getMessage() );
		}
	}

	/**
	 * Check payment status directly from Stripe API (manual check).
	 */
	public function check_stripe_status(): void {
		try {
			// Get and validate parameters
			$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;

			if ( ! $order_id ) {
				wp_send_json_error( 'Missing order ID' );

				return;
			}

			// Get the order
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				wp_send_json_error( 'Order not found' );

				return;
			}

			// Verify access using order key
			if ( ! $this->can_access_order( $order ) ) {
				wp_send_json_error( 'Access denied - invalid order key or order does not need payment' );

				return;
			}

			// Initialize Stripe service
			$stripe_service = $this->init_stripe_service();
			if ( is_wp_error( $stripe_service ) ) {
				wp_send_json_error( 'Failed to initialize Stripe service: ' . $stripe_service->get_error_message() );

				return;
			}

			// Check payment status from Stripe
			$result = $stripe_service->check_payment_status_from_stripe( $order );
			
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( 'Failed to check payment status: ' . $result->get_error_message() );

				return;
			}

			// Determine if payment was found and successful
			$payment_found      = ! empty( $result['charge'] );
			$payment_successful = $payment_found && true === $result['charge']['paid'];

			wp_send_json_success( array(
				'payment_found'      => $payment_found,
				'payment_successful' => $payment_successful,
				'payment_status'     => $result['charge']['status'] ?? null,
				'payment_intent'     => $result['payment_intent']   ?? null,
				'charge'             => $result['charge']           ?? null,
				'order_status'       => $result['order_status'],
				'order_paid'         => $result['order_paid'],
				'metadata_saved'     => $result['metadata_saved'] ?? false,
				'return_url'         => $result['return_url']     ?? null,
			) );
		} catch ( Exception $e ) {
			Logger::log( 'Stripe Terminal AJAX - Exception in check_stripe_status: ' . $e->getMessage() );
			wp_send_json_error( 'An error occurred while checking Stripe status: ' . $e->getMessage() );
		}
	}

	/**
	 * Simulate a payment on a terminal reader (test helper).
	 */
	public function simulate_payment(): void {
		try {
			// Get and validate parameters
			$reader_id = isset( $_POST['reader_id'] ) ? sanitize_text_field( $_POST['reader_id'] ) : '';
			$order_id  = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;

			if ( ! $reader_id ) {
				wp_send_json_error( 'Missing reader ID' );

				return;
			}

			if ( ! $order_id ) {
				wp_send_json_error( 'Missing order ID' );

				return;
			}

			// Get the order
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				wp_send_json_error( 'Order not found' );

				return;
			}

			// Verify access using order key
			if ( ! $this->can_access_order( $order ) ) {
				wp_send_json_error( 'Access denied - invalid order key or order does not need payment' );

				return;
			}

			// Initialize Stripe service
			$this->init_stripe_service();
			if ( ! $this->stripe_service ) {
				wp_send_json_error( 'Stripe service not initialized' );

				return;
			}

			// First, create a payment intent for this order
			$payment_intent_result = $this->stripe_service->create_payment_intent( $order );
			if ( is_wp_error( $payment_intent_result ) ) {
				wp_send_json_error( 'Failed to create payment intent: ' . $payment_intent_result->get_error_message() );

				return;
			}

			$payment_intent = $payment_intent_result;
			

			// Now process the payment intent on the reader
			$stripe = $this->stripe_service->get_stripe_client();
			$reader = $stripe->terminal->readers->processPaymentIntent(
				$reader_id,
				array(
					'payment_intent' => $payment_intent['id'],
				)
			);

			// Wait a moment for the reader to be ready, then simulate payment method presentation
			sleep( 1 );

			// Now simulate the payment method presentation
			$reader = $stripe->testHelpers->terminal->readers->presentPaymentMethod(
				$reader_id,
				array()
			);

			wp_send_json_success( array(
				'message'         => 'Payment simulation triggered successfully',
				'reader_id'       => $reader_id,
				'reader_status'   => $reader->status,
				'action'          => $reader->action ?? null,
				'payment_intent'  => $payment_intent['id'],
			) );
		} catch ( Exception $e ) {
			Logger::log( 'Stripe Terminal AJAX - Exception in simulate_payment: ' . $e->getMessage() );
			wp_send_json_error( 'Failed to simulate payment: ' . $e->getMessage() );
		}
	}


	/**
	 * Initialize the Stripe Terminal service.
	 *
	 * @return StripeTerminalService|WP_Error The service instance or error.
	 */
	private function init_stripe_service() {
		// Get the API key from the gateway settings
		$settings  = get_option( 'woocommerce_stripe_terminal_for_woocommerce_settings', array() );
		$test_mode = $settings['test_mode'] ?? 'no';
		$api_key   = 'yes' === $test_mode
			? $settings['test_secret_key'] ?? ''
			: $settings['secret_key']      ?? '';

		if ( empty( $api_key ) ) {
			Logger::log( 'Stripe Terminal AJAX - No API key found in settings' );

			return new WP_Error( 'no_api_key', 'No API key found in settings' );
		}

		$this->stripe_service = new StripeTerminalService( $api_key );
		
		return $this->stripe_service;
	}


	/**
	 * Check if the request can access the given order using order key validation.
	 */
	private function can_access_order( WC_Order $order ): bool {
		// Always verify using order key - this works for both logged in and guest users
		$provided_order_key = isset( $_POST['order_key'] ) ? sanitize_text_field( $_POST['order_key'] ) : '';
		$order_key          = $order->get_order_key();
		
		// Verify the order key matches and order needs payment
		return ! empty( $provided_order_key )  &&
			   $provided_order_key === $order_key &&
			   $order->needs_payment();
	}
}
