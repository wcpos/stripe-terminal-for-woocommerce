<?php
/**
 * CurrencyConverter Utility Class.
 *
 * Handles currency conversion for Stripe integration.
 */

namespace WCPOS\WooCommercePOS\StripeTerminal\Utils;

/**
 * CurrencyConverter class.
 */
class CurrencyConverter {
	/**
	 * List of zero-decimal currencies.
	 *
	 * @var array
	 */
	private static $zero_decimal_currencies = array(
		'BIF',
		'CLP',
		'DJF',
		'GNF',
		'JPY',
		'KMF',
		'KRW',
		'MGA',
		'PYG',
		'RWF',
		'UGX',
		'VND',
		'VUV',
		'XAF',
		'XOF',
		'XPF',
	);

	/**
	 * Special cases for certain currencies.
	 *
	 * @var array
	 */
	private static $special_cases = array(
		'ISK' => 2, // Always treat as two-decimal but no fractional amounts allowed.
		'HUF' => 0, // Payouts in HUF require integer amounts divisible by 100.
		'TWD' => 0, // Payouts in TWD require integer amounts divisible by 100.
	);

	/**
	 * Convert an amount to the correct smallest currency unit for Stripe.
	 *
	 * @param float  $amount   The amount in standard currency format.
	 * @param string $currency The ISO 4217 currency code.
	 *
	 * @return int The amount in the smallest currency unit.
	 */
	public static function convert_to_stripe_amount( $amount, $currency ) {
		$currency = strtoupper( $currency );

		if ( \in_array( $currency, self::$zero_decimal_currencies, true ) ) {
			// Zero-decimal currency: no multiplication needed.
			return \intval( round( $amount ) );
		}

		if ( isset( self::$special_cases[ $currency ] ) ) {
			$decimals = self::$special_cases[ $currency ];

			if ( 0 === $decimals ) {
				// Enforce integer amounts divisible by 100 (Stripe handles the rounding).
				return \intval( round( $amount ) );
			}

			// Multiply by the defined decimal factor (e.g., ISK is treated as 2 decimals).
			return \intval( round( $amount * pow( 10, $decimals ) ) );
		}

		// Default to two-decimal currency.
		return \intval( round( $amount * 100 ) );
	}

	/**
	 * Convert an amount from Stripe's smallest currency unit to WooCommerce's standard currency format.
	 *
	 * @param int    $amount   The amount in Stripe's smallest currency unit.
	 * @param string $currency The ISO 4217 currency code.
	 *
	 * @return float The amount in WooCommerce's standard currency format.
	 */
	public static function convert_from_stripe_amount( $amount, $currency ) {
		$currency = strtoupper( $currency );

		if ( \in_array( $currency, self::$zero_decimal_currencies, true ) ) {
			// Zero-decimal currency: no division needed.
			return \floatval( $amount );
		}

		if ( isset( self::$special_cases[ $currency ] ) ) {
			$decimals = self::$special_cases[ $currency ];

			if ( 0 === $decimals ) {
				// Return integer amount for cases divisible by 100.
				return \floatval( $amount );
			}

			// Divide by the defined decimal factor (e.g., ISK is treated as 2 decimals).
			return \floatval( $amount / pow( 10, $decimals ) );
		}

		// Default to two-decimal currency.
		return \floatval( $amount / 100 );
	}
}
