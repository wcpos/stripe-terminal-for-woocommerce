<?php

namespace WCPOS\WooCommercePOS\StripeTerminal;

use Exception;
use WC_Order;
use WP_Error;

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

		// Retry payment on reader
		add_action( 'wp_ajax_stripe_terminal_retry_payment', array( $this, 'retry_payment' ) );
		add_action( 'wp_ajax_nopriv_stripe_terminal_retry_payment', array( $this, 'retry_payment' ) );

		// Check payment status from Stripe
	}

	/**
	 * Create and process a payment intent for Stripe Terminal.
	 */
	public function create_payment_intent(): void {
		try {
			// Get and validate parameters
			$order_id  = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
			$amount    = isset( $_POST['amount'] ) ? absint( $_POST['amount'] ) : 0;
			$reader_id = isset( $_POST['reader_id'] ) ? sanitize_text_field( $_POST['reader_id'] ) : '';

			if ( ! $order_id || ! $amount || ! $reader_id ) {
				wp_send_json_error( 'Missing order ID, amount, or reader ID' );

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

			// Step 1: Create payment intent using the service
			Logger::log( 'Stripe Terminal AJAX - Creating payment intent for Order #' . $order_id . ' (Amount: ' . $amount . ')' );
			$payment_intent = $this->stripe_service->create_payment_intent( $order, $amount );

			if ( is_wp_error( $payment_intent ) ) {
				Logger::log( 'Stripe Terminal AJAX - Payment intent creation failed: ' . $payment_intent->get_error_message() );
				wp_send_json_error( $payment_intent->get_error_message() );

				return;
			}

			$payment_intent_id = $payment_intent['id'];
			Logger::log( 'Stripe Terminal AJAX - Payment intent created: ' . $payment_intent_id );

			// Save payment intent ID to order metadata for later use
			$order->update_meta_data( '_stripe_terminal_payment_intent_id', $payment_intent_id );
			$order->save();

			// Add order note with payment intent ID
			$order->add_order_note(
				\sprintf(
					'Stripe Terminal: Payment intent created - ID: %s, Amount: %s %s',
					$payment_intent_id,
					number_format( $amount / 100, 2 ),
					strtoupper( $order->get_currency() )
				)
			);

			// Step 2: Process payment intent on the reader
			Logger::log( 'Stripe Terminal AJAX - Processing payment intent ' . $payment_intent_id . ' on reader ' . $reader_id );
			$reader_result = $this->stripe_service->process_payment_intent( $reader_id, $payment_intent_id );

			if ( is_wp_error( $reader_result ) ) {
				Logger::log( 'Stripe Terminal AJAX - Payment intent processing failed: ' . $reader_result->get_error_message() );
				wp_send_json_error( 'Failed to process payment on reader: ' . $reader_result->get_error_message() );

				return;
			}

			Logger::log( 'Stripe Terminal AJAX - Payment intent processed successfully on reader ' . $reader_id );

			// Add order note with reader processing info
			$order->add_order_note(
				\sprintf(
					'Stripe Terminal: Payment intent processed on reader %s - Status: %s',
					$reader_id,
					$reader_result['action']['status'] ?? 'unknown'
				)
			);

			// Return both payment intent and reader data
			wp_send_json_success( array(
				'payment_intent' => $payment_intent,
				'reader'         => $reader_result,
			) );
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
			$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;

			if ( ! $order_id ) {
				wp_send_json_error( 'Missing order ID' );
				return;
			}

			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				wp_send_json_error( 'Order not found' );
				return;
			}

			if ( ! $this->can_access_order( $order ) ) {
				wp_send_json_error( 'Access denied - invalid order key or order does not need payment' );
				return;
			}

			$is_paid = $order->is_paid();
			$status  = $order->get_status();

			$payment_status         = $order->get_meta( '_stripe_terminal_payment_status' );
			$payment_intent_id      = $order->get_meta( '_stripe_terminal_payment_intent_id' );
			$has_successful_payment = ( 'succeeded' === $payment_status && ! empty( $payment_intent_id ) );
			$payment_successful     = $is_paid || $has_successful_payment;

			// If payment hasn't succeeded locally and we have an intent ID, check Stripe directly
			$payment_intent_status = null;
			$last_payment_error    = null;

			if ( ! $payment_successful && ! empty( $payment_intent_id ) && $this->stripe_service ) {
				try {
					\Stripe\Stripe::setApiKey( $this->stripe_service->get_api_key() );
					$stripe_intent         = \Stripe\PaymentIntent::retrieve( $payment_intent_id );
					$payment_intent_status = $stripe_intent->status;

					if ( $stripe_intent->last_payment_error ) {
						$last_payment_error = array(
							'message'      => $stripe_intent->last_payment_error->message ?? 'Card declined',
							'code'         => $stripe_intent->last_payment_error->code ?? null,
							'decline_code' => $stripe_intent->last_payment_error->decline_code ?? null,
						);
					}

					// If Stripe says succeeded but local metadata didn't catch it yet
					if ( 'succeeded' === $payment_intent_status ) {
						$payment_successful = true;
					}
				} catch ( \Exception $e ) {
					Logger::log( 'Stripe Terminal AJAX - Failed to retrieve payment intent: ' . $e->getMessage() );
				}
			}

			$return_url = null;
			if ( $payment_successful ) {
				$gateway = WC()->payment_gateways()->payment_gateways()['stripe_terminal_for_woocommerce'] ?? null;
				if ( $gateway ) {
					$default_url = $gateway->get_return_url( $order );
					$return_url  = $gateway->order_received_url( $default_url, $order );
				}
			}

			wp_send_json_success( array(
				'is_paid'               => $payment_successful,
				'status'                => $status,
				'order_id'              => $order_id,
				'transaction_id'        => $order->get_transaction_id(),
				'return_url'            => $return_url,
				'payment_intent_status' => $payment_intent_status,
				'last_payment_error'    => $last_payment_error,
				'payment_metadata'      => array(
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

			// Get the existing payment intent from order metadata
			$payment_intent_id = $order->get_meta( '_stripe_terminal_payment_intent_id' );
			if ( empty( $payment_intent_id ) ) {
				wp_send_json_error( 'No payment intent found for this order. Please create a payment intent first.' );

				return;
			}

			Logger::log( 'Stripe Terminal AJAX - Simulating payment for existing payment intent: ' . $payment_intent_id );

			// Get Stripe client and simulate payment method presentation on the existing payment intent
			$stripe = $this->stripe_service->get_stripe_client();
			$reader = $stripe->testHelpers->terminal->readers->presentPaymentMethod(
				$reader_id,
				array()
			);

			wp_send_json_success( array(
				'message'         => 'Payment simulation triggered successfully',
				'reader_id'       => $reader_id,
				'reader_status'   => $reader->status,
				'action'          => $reader->action ?? null,
				'payment_intent'  => $payment_intent_id,
			) );
		} catch ( Exception $e ) {
			Logger::log( 'Stripe Terminal AJAX - Exception in simulate_payment: ' . $e->getMessage() );
			wp_send_json_error( 'Failed to simulate payment: ' . $e->getMessage() );
		}
	}

	/**
	 * Retry a payment by re-processing the existing payment intent on the reader.
	 */
	public function retry_payment(): void {
		try {
			$order_id  = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
			$reader_id = isset( $_POST['reader_id'] ) ? sanitize_text_field( $_POST['reader_id'] ) : '';

			if ( ! $order_id || ! $reader_id ) {
				wp_send_json_error( 'Missing order ID or reader ID' );
				return;
			}

			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				wp_send_json_error( 'Order not found' );
				return;
			}

			if ( ! $this->can_access_order( $order ) ) {
				wp_send_json_error( 'Access denied - invalid order key or order does not need payment' );
				return;
			}

			if ( ! $this->stripe_service ) {
				wp_send_json_error( 'Stripe service not initialized - check API key configuration' );
				return;
			}

			$payment_intent_id = $order->get_meta( '_stripe_terminal_payment_intent_id' );
			if ( empty( $payment_intent_id ) ) {
				wp_send_json_error( 'No payment intent found for this order' );
				return;
			}

			Logger::log( 'Stripe Terminal AJAX - Retrying payment intent ' . $payment_intent_id . ' on reader ' . $reader_id );

			$reader_result = $this->stripe_service->process_payment_intent( $reader_id, $payment_intent_id );

			if ( is_wp_error( $reader_result ) ) {
				Logger::log( 'Stripe Terminal AJAX - Retry failed: ' . $reader_result->get_error_message() );
				wp_send_json_error( 'Failed to retry payment on reader: ' . $reader_result->get_error_message() );
				return;
			}

			Logger::log( 'Stripe Terminal AJAX - Retry successful on reader ' . $reader_id );

			$order->add_order_note(
				\sprintf(
					'Stripe Terminal: Payment retry sent to reader %s - Payment Intent: %s',
					$reader_id,
					$payment_intent_id
				)
			);

			wp_send_json_success( array(
				'payment_intent_id' => $payment_intent_id,
				'reader'            => $reader_result,
			) );
		} catch ( Exception $e ) {
			Logger::log( 'Stripe Terminal AJAX - Exception in retry_payment: ' . $e->getMessage() );
			wp_send_json_error( 'An error occurred while retrying payment: ' . $e->getMessage() );
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
