<?php
/**
 * Stripe Terminal gateway
 * Handles the gateway for Stripe Terminal.
 *
 * @package WCPOS\WooCommercePOS\StripeTerminal
 */

namespace WCPOS\WooCommercePOS\StripeTerminal;

use WC_Payment_Gateway;

/**
 * Class StripeTerminalGateway
 */
class Gateway extends WC_Payment_Gateway {
	/**
	 * Constructor for the gateway.
	 */
	public function __construct() {
		$this->id = 'stripe_terminal_for_woocommerce';
		$this->method_title = __( 'Stripe Terminal', 'stripe-terminal-for-woocommerce' );
		$this->method_description = __( 'Accept in-person payments using Stripe Terminal.', 'stripe-terminal-for-woocommerce' );

		// Load gateway settings.
		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		// Save settings hook.
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Initialize gateway form fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled' => array(
				'title'       => __( 'Enable/Disable', 'stripe-terminal-for-woocommerce' ),
				'type'        => 'checkbox',
				'label' => sprintf(
					/* Translators: Placeholders %s is the link to WooCommerce POS. */
					__( 'Enable Stripe Terminal for web checkout (not necessary for %s)', 'stripe-terminal-for-woocommerce' ),
					'<a href="https://wcpos.com" target="_blank">WooCommerce POS</a>'
				),
				'default'     => 'no',
			),
			'title' => array(
				'title'       => __( 'Title', 'stripe-terminal-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'The title displayed to customers during checkout.', 'stripe-terminal-for-woocommerce' ),
				'default'     => __( 'Stripe Terminal', 'stripe-terminal-for-woocommerce' ),
			),
			'description' => array(
				'title'       => __( 'Description', 'stripe-terminal-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'The description displayed to customers during checkout.', 'stripe-terminal-for-woocommerce' ),
				'default'     => __( 'Pay in person using Stripe Terminal.', 'stripe-terminal-for-woocommerce' ),
			),
			'api_key' => array(
				'title'       => __( 'Stripe API Key', 'stripe-terminal-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Your Stripe secret API key. This is required for the Stripe Terminal integration.', 'stripe-terminal-for-woocommerce' ),
				'default'     => '',
			),
			'locations' => array(
				'title'       => __( 'Stripe Terminal Locations', 'stripe-terminal-for-woocommerce' ),
				'type'        => 'title',
				'description' => implode( '<br>', $this->fetch_terminal_locations() ),
			),
		);
	}

	/**
	 * Register the gateway with WooCommerce.
	 *
	 * @param array $methods Existing payment methods.
	 * @return array Updated payment methods.
	 */
	public static function register_gateway( $methods ) {
		$methods[] = __CLASS__;
		return $methods;
	}

	/**
	 * Process the payment.
	 *
	 * @param int $order_id Order ID.
	 * @return array|void Payment result or void on failure.
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		// Mark as on-hold (payment pending).
		$order->update_status( 'on-hold', __( 'Waiting for payment via Stripe Terminal.', 'stripe-terminal-for-woocommerce' ) );

		// Reduce stock levels.
		wc_reduce_stock_levels( $order_id );

		// Return thank you page URL.
		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * Process admin options and validate API key.
	 */
	/**
	 * Process admin options and validate API key.
	 */
	public function process_admin_options() {
		parent::process_admin_options();

		// Validate the API key.
		$api_key = $this->get_option( 'api_key' );
		$validation_result = $this->validate_api_key( $api_key );

		if ( $validation_result !== true ) {
			// Add an admin notice for invalid API key.
			add_action(
				'admin_notices',
				function () use ( $validation_result ) {
					echo '<div class="notice notice-error is-dismissible">';
					echo '<p>' . esc_html( $validation_result ) . '</p>';
					echo '</div>';
				}
			);

				// Optionally, remove the invalid key from the database.
				$this->update_option( 'api_key', '' );
		} else {
			// Add a success message for valid API key.
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-success is-dismissible">';
					echo '<p>' . esc_html__( 'Stripe API key validated successfully.', 'stripe-terminal-for-woocommerce' ) . '</p>';
					echo '</div>';
				}
			);
		}
	}

	/**
	 * Payment fields displayed during checkout.
	 */
	public function payment_fields() {
		// Description for the payment method.
		echo '<p>' . esc_html( $this->get_option( 'description' ) ) . '</p>';

		// React application container.
		echo '<div id="stripe-terminal-app"></div>';

		// Fallback message for users without JavaScript enabled.
		echo '<noscript>' . esc_html__( 'Please enable JavaScript to use the Stripe Terminal integration.', 'stripe-terminal-for-woocommerce' ) . '</noscript>';
	}

	/**
	 * Validate the Stripe API key.
	 *
	 * @param string $api_key The Stripe API key to validate.
	 * @return bool|string Returns true if valid, or an error message.
	 */
	private function validate_api_key( $api_key ) {
		if ( empty( $api_key ) ) {
			return __( 'API key cannot be empty.', 'stripe-terminal-for-woocommerce' );
		}

		try {
			// Load the Stripe PHP library.
			\Stripe\Stripe::setApiKey( $api_key );

			// Test the API key by fetching account details.
			$account = \Stripe\Account::retrieve();

			// Optionally, log account details or return them for display.
			return true;
		} catch ( \Stripe\Exception\ApiErrorException $e ) {
			return sprintf(
				__( 'Failed to validate API key: %s', 'stripe-terminal-for-woocommerce' ),
				$e->getMessage()
			);
		}
	}

	/**
	 * Fetch Stripe Terminal locations for the account and check for associated readers.
	 *
	 * @return array List of formatted Terminal locations and reader information or a fallback message.
	 */
	private function fetch_terminal_locations() {
		try {
			// Retrieve the API key dynamically.
			$api_key = $this->get_option( 'api_key' );

			if ( empty( $api_key ) ) {
					return array(
						__( 'No API key provided. Please enter your Stripe API key and save the settings.', 'stripe-terminal-for-woocommerce' ),
					);
			}

			// Set the API key for the Stripe library.
			\Stripe\Stripe::setApiKey( $api_key );

			// Fetch locations.
			$locations = \Stripe\Terminal\Location::all();

			if ( empty( $locations->data ) ) {
					return array(
						sprintf(
							__( 'No Stripe Terminal locations found. Please <a href="%s" target="_blank">set up locations</a> in your Stripe Dashboard.', 'stripe-terminal-for-woocommerce' ),
							'https://stripe.com/docs/terminal/locations'
						),
					);
			}

			// Format location and reader data.
			$location_list = array();
			foreach ( $locations->data as $location ) {
					// Fetch readers for this location.
					$readers = \Stripe\Terminal\Reader::all( array( 'location' => $location->id ) );

					// Format the address.
					$address = $location->address;
					$formatted_address = sprintf(
						'%s, %s, %s %s, %s',
						$address['line1'],
						$address['city'],
						$address['state'],
						$address['postal_code'],
						$address['country']
					);

					// Format readers information.
					$readers_info = empty( $readers->data )
							? __( 'No readers associated with this location.', 'stripe-terminal-for-woocommerce' )
							: sprintf( __( '%d reader(s) available.', 'stripe-terminal-for-woocommerce' ), count( $readers->data ) );

					// Add location with readers information.
					$location_list[] = sprintf(
						__( '<strong>%1$s</strong> (ID: %2$s)<br>Address: %3$s<br>%4$s', 'stripe-terminal-for-woocommerce' ),
						esc_html( $location->display_name ),
						esc_html( $location->id ),
						esc_html( $formatted_address ),
						esc_html( $readers_info )
					);
			}

			return $location_list;

		} catch ( \Stripe\Exception\ApiErrorException $e ) {
			// Handle API errors gracefully.
			return array(
				sprintf(
					__( 'Failed to fetch Terminal locations: %s', 'stripe-terminal-for-woocommerce' ),
					esc_html( $e->getMessage() )
				),
			);
		}
	}
}
