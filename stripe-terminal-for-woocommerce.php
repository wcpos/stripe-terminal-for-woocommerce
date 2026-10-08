<?php
/**
 * Plugin Name: Stripe Terminal for WooCommerce
 * Description: Adds Stripe Terminal support to WooCommerce for in-person payments.
 * Version:     0.0.37
 * Author:      kilbot
 * Author URI:  https://kilbot.com/
 * Update URI:  https://github.com/wcpos/stripe-terminal-for-woocommerce
 * License:     GPL v3 or later
 * Text Domain: stripe-terminal-for-woocommerce.
 *
 * Requires at least: 5.2
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 *
 * @package WCPOS\WooCommercePOS\StripeTerminal
 */

namespace WCPOS\WooCommercePOS\StripeTerminal;

if ( ! \defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Define constants.
\define( 'STWC_VERSION', '0.0.37' );
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
	// Terminal extensions are Pro-only at 2.0: the keypad modes and the order-pay panel
	// both rely on Pro's shared payments base.
	if ( ! \function_exists( 'wcpos_pro_requires' ) || ! wcpos_pro_requires( Server\Registration::REQUIRED_PRO_VERSION, __FILE__ ) ) {
		add_action(
			'admin_notices',
			static function (): void {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Stripe Terminal for WooCommerce needs WooCommerce POS Pro 2.0.0 or newer.', 'stripe-terminal-for-woocommerce' ) . '</p></div>';
			}
		);
		return;
	}

	// Register the gateway.
	add_filter( 'woocommerce_payment_gateways', array( Gateway::class, 'register_gateway' ) );

	// Recover duplicate paid Terminal form submissions before the POS template renders an error.
	add_action( 'wp', array( Gateway::class, 'maybe_redirect_paid_order_submission' ), 20 );

	// The keypad's server and device modes, on Pro's shared base.
	Server\Registration::register();

	// Fold attempts the old order-pay panel left mid-flight into Pro's ledger.
	add_action( 'init', array( Legacy_Adoption::class, 'upgrade' ), 20 );

	// The REST API serves Stripe's webhooks.
	add_action(
		'rest_api_init',
		function (): void {
			new API();
		}
	);

	// Initialize AJAX handlers early.
	new AjaxHandler();

	// Best-effort reader keep-warm (POS activity + new-order triggers).
	( new ReaderWarmer() )->register();
}
// Pro defines its helpers (wcpos_pro_requires and the provider registration API) from its own
// plugins_loaded hook at priority 20, so the gate must run after that: 30, where provider
// registration already sat. Reader-settings migration stays one step later.
add_action( 'plugins_loaded', __NAMESPACE__ . '\init', 30 );
add_action( 'plugins_loaded', array( Server\Pos_Reader_Settings::class, 'migrate_once' ), 31 );
register_activation_hook(
	__FILE__,
	static function (): void {
		if ( \function_exists( 'wcpos_pro_requires' ) ) {
			wcpos_pro_requires( Server\Registration::REQUIRED_PRO_VERSION, __FILE__ );
		}
	}
);
