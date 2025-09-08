<?php
/**
 * Plugin Name: Stripe Terminal for WooCommerce
 * Description: Adds Stripe Terminal support to WooCommerce for in-person payments.
 * Version:     0.0.10
 * Author:      kilbot
 * Author URI:  https://kilbot.com/
 * License:     GPL v2 or later
 * Text Domain: stripe-terminal-for-woocommerce.
 *
 * Requires at least: 5.2
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 */

namespace WCPOS\WooCommercePOS\StripeTerminal;

if ( ! \defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Define constants.
\define( 'STWC_VERSION', '0.0.10' );
\define( 'STWC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
\define( 'STWC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Include Composer's autoloader.
if ( file_exists( STWC_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once STWC_PLUGIN_DIR . 'vendor/autoload.php';
} else {
	error_log( 'Stripe Terminal for WooCommerce: Composer autoloader not found.' );
}

// Autoload classes using PSR-4.
spl_autoload_register(
	function ( $class ): void {
		$prefix   = __NAMESPACE__ . '\\';
		$base_dir = STWC_PLUGIN_DIR . 'includes/';
		$len      = \strlen( $prefix );

		if ( 0 !== strncmp( $prefix, $class, $len ) ) {
			return; // Not in our namespace.
		}

		$relative_class = substr( $class, $len );
		$file           = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);

/**
 * Initialize the plugin.
 */
function init(): void {
	// Register the gateway.
	add_filter( 'woocommerce_payment_gateways', array( Gateway::class, 'register_gateway' ) );

	/*
	 * Removed the complex React application and terminal-js integration.
	 * We're now using a simple jQuery-based frontend that communicates with the server via WordPress AJAX.
	 * We still need the API for webhook processing from Stripe.
	 */

	// // Initialize frontend.
	// new Frontend();

	// Initialize API.
	add_action(
		'rest_api_init',
		function (): void {
			new API();
		}
	);

	// Initialize AJAX handlers early.
	new AjaxHandler();
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\init', 11 );
