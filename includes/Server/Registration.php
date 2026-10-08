<?php
/**
 * Registration of the keypad providers on Pro's shared base.
 *
 * @package WCPOS\WooCommercePOS\StripeTerminal
 */

namespace WCPOS\WooCommercePOS\StripeTerminal\Server;

use WCPOS\WooCommercePOS\StripeTerminal\Settings;

/** Register the server and device providers with Pro. */
final class Registration {
	/** First Pro release the extension runs on: the shared payments base and the order-pay panel. */
	public const REQUIRED_PRO_VERSION = '2.0.0';

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

	/** Register once per request. */
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
}
