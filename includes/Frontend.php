<?php
/**
 * Stripe Terminal frontend
 * Handles frontend assets for Stripe Terminal.
 */

namespace WCPOS\WooCommercePOS\StripeTerminal;

/**
 * Class Frontend.
 */
class Frontend {
	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Enqueue frontend JavaScript and CSS.
	 */
	public function enqueue_assets(): void {
		// Only enqueue on the POS checkout 'order-pay' endpoint
		if (
			! \function_exists( 'woocommerce_pos_request' )
			 || ! woocommerce_pos_request()
			 || ! get_query_var( 'order-pay' )
		) {
			return;
		}
		
		// Enqueue Stripe Terminal SDK.
		wp_enqueue_script(
			'stripe-terminal-js',
			'https://js.stripe.com/terminal/v1/',
			array(),
			null,
			true
		);

		// Enqueue main JavaScript and CSS files.
		wp_enqueue_script(
			'stripe-terminal',
			STWC_PLUGIN_URL . 'assets/js/main.js',
			array(),
			STWC_VERSION,
			true
		);

		wp_enqueue_style(
			'stripe-terminal',
			STWC_PLUGIN_URL . 'assets/css/main.css',
			array(),
			STWC_VERSION
		);
	}
}
