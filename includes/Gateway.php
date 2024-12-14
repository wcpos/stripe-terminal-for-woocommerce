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
		$this->id = 'stripe_terminal';
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
				'label'       => __( 'Enable Stripe Terminal', 'stripe-terminal-for-woocommerce' ),
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
}
