<?php
/**
 * Settings for the Stripe Terminal integration.
 *
 * @package WCPOS\WooCommercePOS\StripeTerminal
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
		if ( isset( $settings['test_mode'] ) && $settings['test_mode'] === 'yes' ) {
			return isset( $settings['test_secret_key'] ) ? $settings['test_secret_key'] : '';
		}
		return isset( $settings['secret_key'] ) ? $settings['secret_key'] : '';
	}

	/**
	 * Get the Stripe webhook secret.
	 */
	public static function get_webhook_secret() {
		$settings = self::get_gateway_settings();
		if ( isset( $settings['test_mode'] ) && $settings['test_mode'] === 'yes' ) {
			return isset( $settings['test_webhook_secret'] ) ? $settings['test_webhook_secret'] : '';
		}
		return isset( $settings['webhook_secret'] ) ? $settings['webhook_secret'] : '';
	}
}
