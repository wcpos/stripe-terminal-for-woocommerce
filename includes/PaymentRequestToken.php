<?php
/**
 * Stateless signed payment request tokens.
 *
 * @package WCPOS\WooCommercePOS\StripeTerminal
 */

namespace WCPOS\WooCommercePOS\StripeTerminal;

/**
 * Creates and validates order-scoped payment request tokens.
 */
class PaymentRequestToken {
	/**
	 * Create a signed token for an order payment request.
	 *
	 * @param int      $order_id  Order ID.
	 * @param string   $order_key WooCommerce order key.
	 * @param int|null $expires   Expiry timestamp, defaults to one hour from now.
	 * @return array{token:string,expires:int}
	 */
	public static function create( int $order_id, string $order_key, ?int $expires = null ): array {
		$expires = $expires ?? ( time() + ( \defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600 ) );

		return array(
			'token'   => self::sign( $order_id, $order_key, $expires ),
			'expires' => $expires,
		);
	}

	/**
	 * Validate a signed payment request token.
	 *
	 * @param int    $order_id  Order ID.
	 * @param string $order_key WooCommerce order key.
	 * @param string $token     Token to validate.
	 * @param int    $expires   Expiry timestamp.
	 * @return bool
	 */
	public static function validate( int $order_id, string $order_key, string $token, int $expires ): bool {
		if ( '' === $token || '' === $order_key || $expires < time() ) {
			return false;
		}

		$expected = self::sign( $order_id, $order_key, $expires );

		return hash_equals( $expected, $token );
	}

	/**
	 * Sign order-scoped token payload.
	 *
	 * @param int    $order_id  Order ID.
	 * @param string $order_key WooCommerce order key.
	 * @param int    $expires   Expiry timestamp.
	 * @return string
	 */
	private static function sign( int $order_id, string $order_key, int $expires ): string {
		$message = implode( '|', array( 'stripe_terminal_payment', $order_id, $order_key, $expires ) );

		return hash_hmac( 'sha256', $message, self::secret() );
	}

	/**
	 * Get signing secret.
	 *
	 * @return string
	 */
	private static function secret(): string {
		if ( \function_exists( 'wp_salt' ) ) {
			return wp_salt( 'auth' );
		}

		return __FILE__;
	}
}
