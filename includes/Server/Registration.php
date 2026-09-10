<?php
/**
 * Optional Pro integration; legacy checkout remains available without Pro.
 *
 * @package WCPOS\WooCommercePOS\StripeTerminal
 */

namespace WCPOS\WooCommercePOS\StripeTerminal\Server;

use WCPOS\WooCommercePOS\StripeTerminal\Settings;

/** Register only when Pro supplies the shared server contract. */
final class Registration {
	/** First Pro release with the shared server handler and provider registration API. */
	public const REQUIRED_PRO_VERSION = '1.11.0';

	/**
	 * Avoid registering twice in the same request.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/** Check for the optional Pro API and minimum version. */
	public static function pro_supported(): bool {
		return function_exists( 'wcpos_pro_register_server_provider' ) && function_exists( 'wcpos_pro_requires' ) && wcpos_pro_requires( self::REQUIRED_PRO_VERSION );
	}

	/** Register once without loading the adapter on legacy-only sites. */
	public static function register(): bool {
		if ( ! self::pro_supported() ) {
			return false;
		}
		if ( ! self::$registered ) {
			wcpos_pro_register_server_provider( Settings::GATEWAY_ID, Stripe_Server_Provider::class );
			if ( 'device' === Settings::get_wcpos_connection() && function_exists( 'wcpos_pro_register_device_provider' ) ) {
				wcpos_pro_register_device_provider( Settings::GATEWAY_ID, Stripe_Device_Provider::class );
			}
			self::$registered = true;
		}
		return true;
	}

	/**
	 * Let old Pro persist its upgrade notice without requiring Pro for legacy checkout.
	 *
	 * @param string $plugin_file Activated plugin file.
	 */
	public static function activation_check( string $plugin_file ): void {
		if ( function_exists( 'wcpos_pro_requires' ) && ! wcpos_pro_requires( self::REQUIRED_PRO_VERSION ) ) {
			wcpos_pro_requires( self::REQUIRED_PRO_VERSION, $plugin_file );
		}
	}
}
