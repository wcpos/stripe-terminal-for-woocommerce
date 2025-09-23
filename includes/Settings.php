<?php
/**
 * Settings for the Stripe Terminal integration.
 */

namespace WCPOS\WooCommercePOS\StripeTerminal;

/**
 * Settings.
 */
class Settings {
	/**
	 * Get the Gateway settings.
	 */
	public static function get_gateway_settings() {
		// Retrieve and return the gateway settings.
		return get_option( 'woocommerce_stripe_terminal_for_woocommerce_settings', array() );
	}

	/**
	 * Get the Stripe Terminal API key.
	 */
	public static function get_api_key() {
		$settings = self::get_gateway_settings();
		if ( isset( $settings['test_mode'] ) && 'yes' === $settings['test_mode'] ) {
			return $settings['test_secret_key'] ?? '';
		}

		return $settings['secret_key'] ?? '';
	}

	/**
	 * Get the Stripe webhook secret.
	 */
	public static function get_webhook_secret() {
		$settings = self::get_gateway_settings();
		if ( isset( $settings['test_mode'] ) && 'yes' === $settings['test_mode'] ) {
			return $settings['test_webhook_secret'] ?? '';
		}

		return $settings['webhook_secret'] ?? '';
	}
}
