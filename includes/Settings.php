<?php
/**
 * Settings for the Stripe Terminal integration.
 *
 * @package WooCommerce\POS\StripeTerminal
 */

namespace WCPOS\WooCommercePOS\StripeTerminal;

/**
 * Settings
 */
class Settings {

	/**
	 * Get the Gateway settings.
	 */
	public static function get_gateway_settings() {
		// Retrieve and return the gateway settings.
		$settings = get_option( 'woocommerce_stripe_terminal_for_woocommerce_settings', array() );
		return $settings;
	}

	/**
	 * Get the Stripe Terminal API key.
	 */
	public static function get_api_key() {
		$settings = self::get_gateway_settings();
		if ( $settings['test_mode'] === 'yes' ) {
			return isset( $settings['test_secret_key'] ) ? $settings['test_secret_key'] : '';
		}
		return isset( $settings['secret_key'] ) ? $settings['secret_key'] : '';
	}
}
