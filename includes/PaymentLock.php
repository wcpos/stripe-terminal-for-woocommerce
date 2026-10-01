<?php
/**
 * Atomic per-order payment claims.
 *
 * @package WCPOS\WooCommercePOS\StripeTerminal
 */

namespace WCPOS\WooCommercePOS\StripeTerminal;

/**
 * Claims the options table's unique option_name with INSERT IGNORE.
 */
final class PaymentLock {
	/**
	 * Claims held by this request, for compare-and-delete release.
	 *
	 * @var array
	 */
	private static $held = array();

	/**
	 * Acquire a claim, taking over an expired claim if necessary.
	 *
	 * @param int    $order_id  Order ID.
	 * @param string $operation Operation name.
	 * @param int    $ttl       Claim lifetime in seconds.
	 * @return bool Whether this request acquired the claim.
	 */
	public static function acquire( int $order_id, string $operation, int $ttl = 30 ): bool {
		global $wpdb;
		$key   = self::key( $order_id, $operation );
		$token = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'stwc_', true );
		$data  = array(
			'token'      => $token,
			'expires_at' => time() + $ttl,
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Fallback when WordPress JSON encoding is unavailable.
		$value = function_exists( 'wp_json_encode' ) ? wp_json_encode( $data ) : json_encode( $data );
		$claim = $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $key, $value );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Atomic claim requires uncached SQL; $claim is prepared above.
		if ( 1 === $wpdb->query( $claim ) ) {
			self::$held[ $key ] = $value;
			return true;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Read the current claim directly for compare-and-delete takeover.
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key ) );
		$lock     = json_decode( (string) $existing, true );
		if ( ! isset( $lock['expires_at'] ) || ! is_numeric( $lock['expires_at'] ) || $lock['expires_at'] < time() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Compare-and-delete cannot remove a replacement claim.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $key, $existing ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Reuse the prepared atomic claim after removing the expired value.
			if ( 1 === $wpdb->query( $claim ) ) {
				self::$held[ $key ] = $value;
				return true;
			}
		}
		return false;
	}

	/**
	 * Release only the claim held by this request.
	 *
	 * @param int    $order_id  Order ID.
	 * @param string $operation Operation name.
	 */
	public static function release( int $order_id, string $operation ): void {
		global $wpdb;
		$key = self::key( $order_id, $operation );
		if ( isset( self::$held[ $key ] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Compare-and-delete preserves any replacement claim.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $key, self::$held[ $key ] ) );
			unset( self::$held[ $key ] );
		}
	}

	/**
	 * Build the option name for an order operation.
	 *
	 * @param int    $order_id  Order ID.
	 * @param string $operation Operation name.
	 * @return string Claim option name.
	 */
	private static function key( int $order_id, string $operation ): string {
		return 'stwc_lock_order_' . $order_id . '_' . $operation;
	}
}
