<?php
/**
 * Logger for the Stripe Terminal integration.
 */

namespace WCPOS\WooCommercePOS\StripeTerminal;

/**
 * Class Logger.
 *
 * NOTE: do not put any SQL queries in this class, eg: options table lookup
 */
class Logger {
	public const WC_LOG_FILENAME = 'stripe-terminal-for-woocommerce';
	public static $logger;
	public static $log_level;

	public static function set_log_level( $level ): void {
		self::$log_level = $level;
	}

	/**
	 * Utilize WC logger class.
	 *
	 * @param mixed $message
	 */
	public static function log( $message ): void {
		if ( ! class_exists( 'WC_Logger' ) ) {
			return;
		}

		if ( apply_filters( 'stwc_logging', true, $message ) ) {
			if ( empty( self::$logger ) ) {
				self::$logger = wc_get_logger();
			}

			if ( \is_null( self::$log_level ) ) {
				self::$log_level = 'info';
			}

			if ( ! \is_string( $message ) ) {
				$message = print_r( $message, true );
			}

			self::$logger->log( self::$log_level, $message, array( 'source' => self::WC_LOG_FILENAME ) );
		}
	}
}
