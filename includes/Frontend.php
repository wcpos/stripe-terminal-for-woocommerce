<?php
/**
 * Stripe Terminal frontend
 * Handles frontend assets for Stripe Terminal.
 *
 * @package WCPOS\WooCommercePOS\StripeTerminal
 */

namespace WCPOS\WooCommercePOS\StripeTerminal;

/**
 * Class Frontend
 */
class Frontend {

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Enqueue frontend JavaScript and CSS.
	 */
	public function enqueue_assets() {
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
