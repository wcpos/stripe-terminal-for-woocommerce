<?php
/**
 * Tests for the Settings class.
 *
 * Verifies that API key and webhook secret retrieval respects the
 * test_mode toggle stored in WooCommerce gateway options.
 */

namespace WCPOS\WooCommercePOS\StripeTerminal\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\StripeTerminal\Settings;

/**
 * @covers \WCPOS\WooCommercePOS\StripeTerminal\Settings
 */
class SettingsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// -------------------------------------------------------------------
	// get_api_key
	// -------------------------------------------------------------------

	public function test_get_api_key_returns_test_key_when_test_mode_yes(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'test_mode'       => 'yes',
				'test_secret_key' => 'sk_test_abc123',
				'secret_key'      => 'sk_live_xyz789',
			)
		);

		$this->assertSame( 'sk_test_abc123', Settings::get_api_key() );
	}

	public function test_get_api_key_returns_live_key_when_test_mode_no(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'test_mode'       => 'no',
				'test_secret_key' => 'sk_test_abc123',
				'secret_key'      => 'sk_live_xyz789',
			)
		);

		$this->assertSame( 'sk_live_xyz789', Settings::get_api_key() );
	}

	public function test_get_api_key_returns_empty_string_when_no_settings(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$this->assertSame( '', Settings::get_api_key() );
	}

	public function test_get_api_key_returns_live_key_when_test_mode_absent(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'secret_key' => 'sk_live_xyz789',
			)
		);

		$this->assertSame( 'sk_live_xyz789', Settings::get_api_key() );
	}

	// -------------------------------------------------------------------
	// get_webhook_secret
	// -------------------------------------------------------------------

	public function test_get_webhook_secret_returns_test_secret_in_test_mode(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'test_mode'           => 'yes',
				'test_webhook_secret' => 'whsec_test_secret',
				'webhook_secret'      => 'whsec_live_secret',
			)
		);

		$this->assertSame( 'whsec_test_secret', Settings::get_webhook_secret() );
	}

	public function test_get_webhook_secret_returns_live_secret_in_live_mode(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'test_mode'           => 'no',
				'test_webhook_secret' => 'whsec_test_secret',
				'webhook_secret'      => 'whsec_live_secret',
			)
		);

		$this->assertSame( 'whsec_live_secret', Settings::get_webhook_secret() );
	}

	public function test_get_webhook_secret_returns_empty_string_when_no_settings(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$this->assertSame( '', Settings::get_webhook_secret() );
	}

	// -------------------------------------------------------------------
	// get_gateway_settings
	// -------------------------------------------------------------------

	public function test_get_gateway_settings_returns_option_array(): void {
		$expected = array(
			'test_mode'  => 'yes',
			'secret_key' => 'sk_live_xyz',
		);

		Functions\when( 'get_option' )->justReturn( $expected );

		$this->assertSame( $expected, Settings::get_gateway_settings() );
	}

	public function test_get_gateway_settings_returns_empty_array_when_missing(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$this->assertSame( array(), Settings::get_gateway_settings() );
	}
}
