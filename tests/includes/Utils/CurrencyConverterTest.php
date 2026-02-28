<?php
/**
 * Tests for the CurrencyConverter utility class.
 */

namespace WCPOS\WooCommercePOS\StripeTerminal\Tests\Utils;

use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\StripeTerminal\Utils\CurrencyConverter;

/**
 * @covers \WCPOS\WooCommercePOS\StripeTerminal\Utils\CurrencyConverter
 */
class CurrencyConverterTest extends TestCase {

	// -------------------------------------------------------
	// convert_to_stripe_amount — standard two-decimal currencies
	// -------------------------------------------------------

	/**
	 * @dataProvider standard_currency_provider
	 */
	public function test_convert_to_stripe_amount_standard_currencies( float $amount, string $currency, int $expected ): void {
		$this->assertSame( $expected, CurrencyConverter::convert_to_stripe_amount( $amount, $currency ) );
	}

	public function standard_currency_provider(): array {
		return array(
			'USD whole dollar'      => array( 10.00, 'USD', 1000 ),
			'USD with cents'        => array( 19.99, 'USD', 1999 ),
			'USD zero'              => array( 0.00, 'USD', 0 ),
			'USD one cent'          => array( 0.01, 'USD', 1 ),
			'USD large amount'      => array( 9999.99, 'USD', 999999 ),
			'EUR whole euro'        => array( 25.00, 'EUR', 2500 ),
			'EUR with cents'        => array( 14.50, 'EUR', 1450 ),
			'GBP whole pound'       => array( 100.00, 'GBP', 10000 ),
			'GBP with pence'        => array( 7.89, 'GBP', 789 ),
			'AUD standard'          => array( 42.42, 'AUD', 4242 ),
			'CAD standard'          => array( 55.55, 'CAD', 5555 ),
		);
	}

	// -------------------------------------------------------
	// convert_to_stripe_amount — zero-decimal currencies
	// -------------------------------------------------------

	/**
	 * @dataProvider zero_decimal_currency_provider
	 */
	public function test_convert_to_stripe_amount_zero_decimal_currencies( float $amount, string $currency, int $expected ): void {
		$this->assertSame( $expected, CurrencyConverter::convert_to_stripe_amount( $amount, $currency ) );
	}

	public function zero_decimal_currency_provider(): array {
		return array(
			'JPY whole yen'         => array( 1000.0, 'JPY', 1000 ),
			'JPY large amount'      => array( 50000.0, 'JPY', 50000 ),
			'JPY zero'              => array( 0.0, 'JPY', 0 ),
			'KRW won'               => array( 15000.0, 'KRW', 15000 ),
			'BIF franc'             => array( 500.0, 'BIF', 500 ),
			'CLP peso'              => array( 3000.0, 'CLP', 3000 ),
			'DJF franc'             => array( 200.0, 'DJF', 200 ),
			'GNF franc'             => array( 8000.0, 'GNF', 8000 ),
			'KMF franc'             => array( 450.0, 'KMF', 450 ),
			'MGA ariary'            => array( 4000.0, 'MGA', 4000 ),
			'PYG guarani'           => array( 7000.0, 'PYG', 7000 ),
			'RWF franc'             => array( 1200.0, 'RWF', 1200 ),
			'UGX shilling'          => array( 36000.0, 'UGX', 36000 ),
			'VND dong'              => array( 230000.0, 'VND', 230000 ),
			'VUV vatu'              => array( 110.0, 'VUV', 110 ),
			'XAF franc'             => array( 6500.0, 'XAF', 6500 ),
			'XOF franc'             => array( 7500.0, 'XOF', 7500 ),
			'XPF franc'             => array( 950.0, 'XPF', 950 ),
		);
	}

	// -------------------------------------------------------
	// convert_to_stripe_amount — special-case currencies
	// -------------------------------------------------------

	/**
	 * @dataProvider special_case_currency_provider
	 */
	public function test_convert_to_stripe_amount_special_currencies( float $amount, string $currency, int $expected ): void {
		$this->assertSame( $expected, CurrencyConverter::convert_to_stripe_amount( $amount, $currency ) );
	}

	public function special_case_currency_provider(): array {
		return array(
			// ISK: special_cases decimal = 2, so multiplied by 100
			'ISK whole krona'       => array( 500.0, 'ISK', 50000 ),
			'ISK with decimals'     => array( 12.34, 'ISK', 1234 ),
			'ISK zero'              => array( 0.0, 'ISK', 0 ),
			'ISK large amount'      => array( 9999.99, 'ISK', 999999 ),

			// HUF: special_cases decimal = 0, returned as-is (rounded integer)
			'HUF whole forint'      => array( 3000.0, 'HUF', 3000 ),
			'HUF rounded up'        => array( 3000.6, 'HUF', 3001 ),
			'HUF rounded down'      => array( 3000.4, 'HUF', 3000 ),
			'HUF zero'              => array( 0.0, 'HUF', 0 ),

			// TWD: special_cases decimal = 0, returned as-is (rounded integer)
			'TWD whole dollar'      => array( 150.0, 'TWD', 150 ),
			'TWD rounded up'        => array( 150.7, 'TWD', 151 ),
			'TWD rounded down'      => array( 150.3, 'TWD', 150 ),
			'TWD zero'              => array( 0.0, 'TWD', 0 ),
		);
	}

	// -------------------------------------------------------
	// convert_from_stripe_amount — standard two-decimal currencies
	// -------------------------------------------------------

	/**
	 * @dataProvider from_stripe_standard_provider
	 */
	public function test_convert_from_stripe_amount_standard_currencies( int $stripe_amount, string $currency, float $expected ): void {
		$this->assertSame( $expected, CurrencyConverter::convert_from_stripe_amount( $stripe_amount, $currency ) );
	}

	public function from_stripe_standard_provider(): array {
		return array(
			'USD 1000 cents'        => array( 1000, 'USD', 10.0 ),
			'USD 1999 cents'        => array( 1999, 'USD', 19.99 ),
			'USD zero'              => array( 0, 'USD', 0.0 ),
			'USD one cent'          => array( 1, 'USD', 0.01 ),
			'EUR 2500 cents'        => array( 2500, 'EUR', 25.0 ),
			'GBP 789 pence'         => array( 789, 'GBP', 7.89 ),
		);
	}

	// -------------------------------------------------------
	// convert_from_stripe_amount — zero-decimal currencies
	// -------------------------------------------------------

	/**
	 * @dataProvider from_stripe_zero_decimal_provider
	 */
	public function test_convert_from_stripe_amount_zero_decimal_currencies( int $stripe_amount, string $currency, float $expected ): void {
		$this->assertSame( $expected, CurrencyConverter::convert_from_stripe_amount( $stripe_amount, $currency ) );
	}

	public function from_stripe_zero_decimal_provider(): array {
		return array(
			'JPY 1000'              => array( 1000, 'JPY', 1000.0 ),
			'JPY zero'              => array( 0, 'JPY', 0.0 ),
			'KRW 15000'             => array( 15000, 'KRW', 15000.0 ),
			'VND 230000'            => array( 230000, 'VND', 230000.0 ),
			'XAF 6500'              => array( 6500, 'XAF', 6500.0 ),
		);
	}

	// -------------------------------------------------------
	// convert_from_stripe_amount — special-case currencies
	// -------------------------------------------------------

	/**
	 * @dataProvider from_stripe_special_case_provider
	 */
	public function test_convert_from_stripe_amount_special_currencies( int $stripe_amount, string $currency, float $expected ): void {
		$this->assertSame( $expected, CurrencyConverter::convert_from_stripe_amount( $stripe_amount, $currency ) );
	}

	public function from_stripe_special_case_provider(): array {
		return array(
			'ISK 50000 -> 500.0'    => array( 50000, 'ISK', 500.0 ),
			'ISK 1234 -> 12.34'     => array( 1234, 'ISK', 12.34 ),
			'ISK zero'              => array( 0, 'ISK', 0.0 ),
			'HUF 3000 -> 3000.0'    => array( 3000, 'HUF', 3000.0 ),
			'HUF zero'              => array( 0, 'HUF', 0.0 ),
			'TWD 150 -> 150.0'      => array( 150, 'TWD', 150.0 ),
			'TWD zero'              => array( 0, 'TWD', 0.0 ),
		);
	}

	// -------------------------------------------------------
	// Case-insensitivity
	// -------------------------------------------------------

	/**
	 * @dataProvider case_insensitive_provider
	 */
	public function test_currency_codes_are_case_insensitive( string $currency ): void {
		$lower_to   = CurrencyConverter::convert_to_stripe_amount( 10.00, strtolower( $currency ) );
		$upper_to   = CurrencyConverter::convert_to_stripe_amount( 10.00, strtoupper( $currency ) );
		$mixed_to   = CurrencyConverter::convert_to_stripe_amount( 10.00, ucfirst( strtolower( $currency ) ) );

		$this->assertSame( $upper_to, $lower_to, "Lowercase {$currency} should match uppercase for convert_to_stripe_amount" );
		$this->assertSame( $upper_to, $mixed_to, "Mixed-case {$currency} should match uppercase for convert_to_stripe_amount" );

		$lower_from = CurrencyConverter::convert_from_stripe_amount( 1000, strtolower( $currency ) );
		$upper_from = CurrencyConverter::convert_from_stripe_amount( 1000, strtoupper( $currency ) );
		$mixed_from = CurrencyConverter::convert_from_stripe_amount( 1000, ucfirst( strtolower( $currency ) ) );

		$this->assertSame( $upper_from, $lower_from, "Lowercase {$currency} should match uppercase for convert_from_stripe_amount" );
		$this->assertSame( $upper_from, $mixed_from, "Mixed-case {$currency} should match uppercase for convert_from_stripe_amount" );
	}

	public function case_insensitive_provider(): array {
		return array(
			'standard USD' => array( 'USD' ),
			'zero-dec JPY' => array( 'JPY' ),
			'special ISK'  => array( 'ISK' ),
			'special HUF'  => array( 'HUF' ),
			'special TWD'  => array( 'TWD' ),
		);
	}

	// -------------------------------------------------------
	// Rounding behaviour
	// -------------------------------------------------------

	/**
	 * @dataProvider rounding_provider
	 */
	public function test_rounding_to_stripe( float $amount, string $currency, int $expected ): void {
		$this->assertSame( $expected, CurrencyConverter::convert_to_stripe_amount( $amount, $currency ) );
	}

	public function rounding_provider(): array {
		return array(
			'USD rounds half up'             => array( 10.005, 'USD', 1001 ),
			'USD sub-cent rounds down'       => array( 10.004, 'USD', 1000 ),
			'USD sub-cent rounds up'         => array( 10.006, 'USD', 1001 ),
			'JPY rounds half up'             => array( 100.5, 'JPY', 101 ),
			'JPY rounds down'                => array( 100.4, 'JPY', 100 ),
			'ISK rounds half up'             => array( 10.005, 'ISK', 1001 ),
			'HUF rounds half up'             => array( 100.5, 'HUF', 101 ),
			'HUF rounds down'                => array( 100.4, 'HUF', 100 ),
		);
	}

	// -------------------------------------------------------
	// Roundtrip conversion — to Stripe and back
	// -------------------------------------------------------

	/**
	 * @dataProvider roundtrip_provider
	 */
	public function test_roundtrip_conversion( float $original, string $currency ): void {
		$stripe   = CurrencyConverter::convert_to_stripe_amount( $original, $currency );
		$restored = CurrencyConverter::convert_from_stripe_amount( $stripe, $currency );

		$this->assertSame( $original, $restored, "Roundtrip failed for {$currency}: {$original} -> {$stripe} -> {$restored}" );
	}

	public function roundtrip_provider(): array {
		return array(
			'USD 19.99'     => array( 19.99, 'USD' ),
			'USD 0.01'      => array( 0.01, 'USD' ),
			'USD 0.00'      => array( 0.00, 'USD' ),
			'USD 100.00'    => array( 100.00, 'USD' ),
			'EUR 42.50'     => array( 42.50, 'EUR' ),
			'GBP 7.89'      => array( 7.89, 'GBP' ),
			'JPY 1000'      => array( 1000.0, 'JPY' ),
			'JPY 0'         => array( 0.0, 'JPY' ),
			'KRW 15000'     => array( 15000.0, 'KRW' ),
			'ISK 500.00'    => array( 500.00, 'ISK' ),
			'ISK 12.34'     => array( 12.34, 'ISK' ),
			'HUF 3000'      => array( 3000.0, 'HUF' ),
			'TWD 150'       => array( 150.0, 'TWD' ),
		);
	}

	// -------------------------------------------------------
	// Return types
	// -------------------------------------------------------

	public function test_convert_to_stripe_amount_returns_int(): void {
		$result = CurrencyConverter::convert_to_stripe_amount( 10.50, 'USD' );
		$this->assertIsInt( $result );
	}

	public function test_convert_from_stripe_amount_returns_float(): void {
		$result = CurrencyConverter::convert_from_stripe_amount( 1050, 'USD' );
		$this->assertIsFloat( $result );
	}

	public function test_convert_to_stripe_zero_decimal_returns_int(): void {
		$result = CurrencyConverter::convert_to_stripe_amount( 1000, 'JPY' );
		$this->assertIsInt( $result );
	}

	public function test_convert_from_stripe_zero_decimal_returns_float(): void {
		$result = CurrencyConverter::convert_from_stripe_amount( 1000, 'JPY' );
		$this->assertIsFloat( $result );
	}

	// -------------------------------------------------------
	// Edge cases
	// -------------------------------------------------------

	public function test_negative_amount_to_stripe(): void {
		// Refund scenarios may pass negative values.
		$this->assertSame( -1999, CurrencyConverter::convert_to_stripe_amount( -19.99, 'USD' ) );
	}

	public function test_negative_amount_from_stripe(): void {
		$this->assertSame( -19.99, CurrencyConverter::convert_from_stripe_amount( -1999, 'USD' ) );
	}

	public function test_very_small_fractional_amount(): void {
		// 0.001 USD -> should round to 0 cents
		$this->assertSame( 0, CurrencyConverter::convert_to_stripe_amount( 0.001, 'USD' ) );
	}

	public function test_very_large_amount(): void {
		// Large but realistic amount: 999,999.99 USD
		$this->assertSame( 99999999, CurrencyConverter::convert_to_stripe_amount( 999999.99, 'USD' ) );
	}
}
