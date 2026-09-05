<?php
/**
 * Logger for the Stripe Terminal integration.
 *
 * @package WCPOS\WooCommercePOS\StripeTerminal
 */

namespace WCPOS\WooCommercePOS\StripeTerminal;

/**
 * Class Logger.
 *
 * NOTE: do not put any SQL queries in this class, eg: options table lookup
 */
class Logger {
	public const WC_LOG_FILENAME = 'stripe-terminal-for-woocommerce';

	/**
	 * WooCommerce logger instance.
	 *
	 * @var null|\WC_Logger
	 */
	public static $logger;

	/**
	 * Active log level.
	 *
	 * @var null|string
	 */
	public static $log_level;

	/**
	 * Set the active log level.
	 *
	 * @param string $level Log level string.
	 */
	public static function set_log_level( $level ): void {
		self::$log_level = $level;
	}

	/**
	 * Utilize WC logger class.
	 *
	 * @param mixed  $message Message to log.
	 * @param string $level   Optional severity for this entry (debug, info, notice, warning, error, …); defaults to the configured level.
	 */
	public static function log( $message, string $level = '' ): void {
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

			self::$logger->log( '' !== $level ? $level : self::$log_level, $message, array( 'source' => self::WC_LOG_FILENAME ) );
		}
	}
}
