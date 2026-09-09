<?php
/**
 * Settings for the Stripe Terminal integration.
 *
 * @package WCPOS\WooCommercePOS\StripeTerminal
 */

namespace WCPOS\WooCommercePOS\StripeTerminal;

/**
 * Settings.
 */
class Settings {
	/** Stable gateway ID used by the Pro provider registry. */
	public const GATEWAY_ID = 'stripe_terminal_for_woocommerce';

	/** Whether the currently selected API mode is test. */
	public static function is_test_mode(): bool {
		return 'yes' === ( self::get_gateway_settings()['test_mode'] ?? 'no' );
	}

	/** Retrieve only the POS route secret for the current mode. */
	public static function get_pos_webhook_secret(): string {
		$settings = self::get_gateway_settings();
		return (string) ( $settings[ self::is_test_mode() ? 'test_pos_webhook_secret' : 'pos_webhook_secret' ] ?? '' );
	}

	/** Retrieve the POS endpoint identity for the current mode. */
	public static function get_pos_webhook_endpoint_id(): string {
		$settings = self::get_gateway_settings();
		return (string) ( $settings[ self::is_test_mode() ? 'test_pos_webhook_endpoint_id' : 'pos_webhook_endpoint_id' ] ?? '' );
	}

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
