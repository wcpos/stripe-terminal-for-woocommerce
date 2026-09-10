<?php
/**
 * Device selection and registration tests.
 *
 * @package WCPOS\WooCommercePOS\StripeTerminal
 */

namespace WCPOS\WooCommercePOS\StripeTerminal\Tests\Server;

use Brain\Monkey\Functions;
use WCPOS\WooCommercePOS\StripeTerminal\Gateway;
use WCPOS\WooCommercePOS\StripeTerminal\Settings;
use WCPOS\WooCommercePOS\StripeTerminal\StripeTerminalService;
use WCPOS\WooCommercePOS\StripeTerminal\Server\Registration;
use WCPOS\WooCommercePOS\StripeTerminal\Server\Stripe_Device_Provider;
use WCPOS\WooCommercePOS\StripeTerminal\Server\Stripe_Server_Provider;

require_once __DIR__ . '/ServerTestCase.php';
require_once dirname( __DIR__ ) . '/GatewayTest.php';

/**
 * Isolate optional Pro functions and overloaded Stripe transports.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class DeviceSettingsTest extends ServerTestCase {
	/** Prepare settings-screen functions without real WordPress. */
	protected function setUp(): void {
		parent::setUp();
		Functions\stubs(
			array(
				'is_admin' => true,
				'wp_doing_ajax' => false,
				'is_ssl' => true,
			)
		);
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		$_GET['section'] = Settings::GATEWAY_ID;
		$GLOBALS['stwc_device_provider_registrations'] = array();
	}

	/** Clear request and registration state. */
	protected function tearDown(): void {
		$_GET = array();
		unset( $GLOBALS['stwc_device_provider_registrations'] );
		parent::tearDown();
	}

	/**
	 * Saved selections win; existing default terminals keep the server path.
	 *
	 * @dataProvider connections
	 * @param array  $settings Saved gateway settings.
	 * @param string $expected Selected mode.
	 */
	public function test_connection_default_and_registration( array $settings, string $expected ): void {
		$this->options['woocommerce_stripe_terminal_for_woocommerce_settings'] = $settings;
		Functions\when( 'wcpos_pro_requires' )->justReturn( true );
		Functions\expect( 'wcpos_pro_register_server_provider' )->once()->with( Settings::GATEWAY_ID, Stripe_Server_Provider::class );
		$this->assertSame( $expected, Settings::get_wcpos_connection() );
		$gateway = ( new \ReflectionClass( Gateway::class ) )->newInstanceWithoutConstructor();
		$gateway->init_form_fields();
		$this->assertSame( $expected, $gateway->form_fields['wcpos_connection']['default'] );
		$this->assertSame( 'select', $gateway->form_fields['wcpos_connection']['type'] );
		$this->assertSame( array( 'device', 'server' ), array_keys( $gateway->form_fields['wcpos_connection']['options'] ) );
		$this->assertTrue( Registration::register() );
		$this->assertTrue( Registration::register() );
		$this->assertSame(
			'device' === $expected ? array( array( Settings::GATEWAY_ID, Stripe_Device_Provider::class ) ) : array(),
			$GLOBALS['stwc_device_provider_registrations']
		);
	}

	/** Connection default and explicit selection cases. */
	public function connections(): array {
		return array(
			array( array(), 'device' ),
			array( array( 'default_reader' => '' ), 'device' ),
			array( array( 'default_reader' => 'tmr_saved' ), 'server' ),
			array(
				array(
					'default_reader' => 'tmr_saved',
					'wcpos_connection' => 'device',
				),
				'device',
			),
			array( array( 'wcpos_connection' => 'server' ), 'server' ),
		);
	}

	/** Each select uses its own credentials, independently of the active mode. */
	public function test_location_options(): void {
		$this->options['woocommerce_stripe_terminal_for_woocommerce_settings'] += array(
			'test_secret_key' => 'sk_test_fake',
			'secret_key' => 'sk_live_fake',
			'wcpos_location_test' => 'tml_test',
			'wcpos_location_live' => 'tml_live',
		);
		Functions\when( 'get_transient' )->justReturn( array() );
		$key = '';
		$service = \Mockery::mock( 'overload:' . StripeTerminalService::class );
		$service->shouldReceive( '__construct' )->with(
			\Mockery::on(
				function ( $value ) use ( &$key ) {
					$key = $value;
					return in_array( $value, array( 'sk_test_fake', 'sk_live_fake' ), true );
				}
			)
		);
		$service->shouldReceive( 'list_all_locations' )->andReturnUsing(
			function () use ( &$key ) {
				return 'sk_test_fake' === $key
					? array(
						array(
							'id' => 'tml_test',
							'display_name' => 'Test store',
						),
					)
					: array(
						array(
							'id' => 'tml_live',
							'display_name' => 'Live store',
						),
					);
			}
		);
		$gateway = ( new \ReflectionClass( Gateway::class ) )->newInstanceWithoutConstructor();
		$gateway->init_form_fields();
		$this->assertArrayNotHasKey( 'wcpos_location', $gateway->form_fields );
		$this->assertSame( 'select', $gateway->form_fields['wcpos_location_test']['type'] );
		$this->assertSame( 'select', $gateway->form_fields['wcpos_location_live']['type'] );
		$this->assertSame(
			array(
				'' => 'Select a location',
				'tml_test' => 'Test store',
			),
			$gateway->form_fields['wcpos_location_test']['options']
		);
		$this->assertSame(
			array(
				'' => 'Select a location',
				'tml_live' => 'Live store',
			),
			$gateway->form_fields['wcpos_location_live']['options']
		);
		$this->assertSame( 'tml_test', Settings::get_wcpos_location() );
		$this->options['woocommerce_stripe_terminal_for_woocommerce_settings']['test_mode'] = 'no';
		$this->assertSame( 'tml_live', Settings::get_wcpos_location() );
	}

	/**
	 * Legacy values are read only when the current mode has no selection.
	 *
	 * @dataProvider location_settings
	 * @param array  $settings Saved settings.
	 * @param string $expected Effective location.
	 */
	public function test_location_migration_is_read_only( array $settings, string $expected ): void {
		$this->options['woocommerce_stripe_terminal_for_woocommerce_settings'] = $settings;
		Functions\expect( 'update_option' )->never();
		$this->assertSame( $expected, Settings::get_wcpos_location() );
		$gateway = ( new \ReflectionClass( Gateway::class ) )->newInstanceWithoutConstructor();
		$gateway->init_form_fields();
		$field = 'yes' === $settings['test_mode'] ? 'wcpos_location_test' : 'wcpos_location_live';
		$this->assertSame( $expected, $gateway->form_fields[ $field ]['default'] );
		$this->assertSame( $settings, $this->options['woocommerce_stripe_terminal_for_woocommerce_settings'] );
	}

	/** Missing and empty mode values fall back without crossing mode-specific values. */
	public function location_settings(): array {
		return array(
			array(
				array(
					'test_mode' => 'yes',
					'wcpos_location' => 'tml_old',
				),
				'tml_old',
			),
			array(
				array(
					'test_mode' => 'no',
					'wcpos_location' => 'tml_old',
				),
				'tml_old',
			),
			array(
				array(
					'test_mode' => 'yes',
					'wcpos_location_test' => '',
					'wcpos_location' => 'tml_old',
				),
				'tml_old',
			),
			array(
				array(
					'test_mode' => 'no',
					'wcpos_location_live' => '',
					'wcpos_location' => 'tml_old',
				),
				'tml_old',
			),
			array(
				array(
					'test_mode' => 'yes',
					'wcpos_location_test' => 'tml_test',
					'wcpos_location' => 'tml_old',
				),
				'tml_test',
			),
			array(
				array(
					'test_mode' => 'no',
					'wcpos_location_live' => 'tml_live',
					'wcpos_location' => 'tml_old',
				),
				'tml_live',
			),
			array(
				array(
					'test_mode' => 'no',
					'wcpos_location_test' => 'tml_test',
				),
				'',
			),
			array(
				array(
					'test_mode' => 'yes',
					'wcpos_location_live' => 'tml_live',
				),
				'',
			),
		);
	}

	/**
	 * Do not make location requests outside the settings page or without credentials.
	 *
	 * @dataProvider unavailable_contexts
	 * @param string $context Unavailable context.
	 */
	public function test_unavailable_locations( string $context ): void {
		$this->options['woocommerce_stripe_terminal_for_woocommerce_settings']['test_secret_key'] = 'sk_test_fake';
		$this->options['woocommerce_stripe_terminal_for_woocommerce_settings']['wcpos_location_test'] = 'tml_saved';
		$this->options['woocommerce_stripe_terminal_for_woocommerce_settings']['wcpos_location_live'] = 'tml_live_saved';
		Functions\when( 'get_transient' )->justReturn( array() );
		$service = \Mockery::mock( 'overload:' . StripeTerminalService::class );
		if ( 'error' === $context ) {
			$service->shouldReceive( 'list_all_locations' )->once()->andReturn( new \WP_Error( 'offline', 'Unavailable' ) );
		} else {
			$service->shouldNotReceive( 'list_all_locations' );
		}
		if ( 'frontend' === $context ) {
			Functions\when( 'is_admin' )->justReturn( false );
		} elseif ( 'ajax' === $context ) {
			Functions\when( 'wp_doing_ajax' )->justReturn( true );
		} elseif ( 'section' === $context ) {
			$_GET['section'] = 'other';
		} elseif ( 'key' === $context ) {
			unset( $this->options['woocommerce_stripe_terminal_for_woocommerce_settings']['test_secret_key'] );
		}
		$gateway = ( new \ReflectionClass( Gateway::class ) )->newInstanceWithoutConstructor();
		$gateway->init_form_fields();
		$this->assertSame(
			array(
				'' => 'Select a location',
				'tml_saved' => 'tml_saved',
			),
			$gateway->form_fields['wcpos_location_test']['options']
		);
		$this->assertSame(
			array(
				'' => 'Select a location',
				'tml_live_saved' => 'tml_live_saved',
			),
			$gateway->form_fields['wcpos_location_live']['options']
		);
		$this->assertSame( 'tml_saved', Settings::get_wcpos_location() );
	}

	/** Settings contexts where location choices are unavailable. */
	public function unavailable_contexts(): array {
		return array( array( 'frontend' ), array( 'ajax' ), array( 'section' ), array( 'key' ), array( 'error' ) );
	}
}
