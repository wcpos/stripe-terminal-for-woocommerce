<?php
/** Tests for gateway reader fields and the write-only POS mirror. */
namespace WCPOS\WooCommercePOS\StripeTerminal\Tests\Server;

use Brain\Monkey\Functions;
use WCPOS\WooCommercePOS\StripeTerminal\Gateway;
use WCPOS\WooCommercePOS\StripeTerminal\Settings;
use WCPOS\WooCommercePOS\StripeTerminal\Server\Pos_Reader_Settings;
use WCPOS\WooCommercePOS\StripeTerminal\StripeTerminalService;

require_once __DIR__ . '/ServerTestCase.php';
require_once dirname( __DIR__ ) . '/GatewayTest.php';

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class PosReaderSettingsTest extends ServerTestCase {
	private const FREE_OPTION = 'woocommerce_pos_settings_payment_gateways';
	private const GATEWAY_OPTION = 'woocommerce_stripe_terminal_for_woocommerce_settings';

	protected function setUp(): void {
		parent::setUp();
		Functions\stubs( array( 'is_admin' => true, 'wp_doing_ajax' => false, 'is_ssl' => true, 'get_transient' => false ) );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		$_GET['section'] = Settings::GATEWAY_ID;
		if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
			define( 'MINUTE_IN_SECONDS', 60 );
		}
	}

	protected function tearDown(): void {
		$_GET = $_POST = array();
		parent::tearDown();
	}

	private function gateway(): Gateway {
		$gateway = ( new \ReflectionClass( Gateway::class ) )->newInstanceWithoutConstructor();
		$gateway->id = Settings::GATEWAY_ID;
		return $gateway;
	}

	private function pro( bool $supported = true ): void {
		Functions\when( 'wcpos_pro_register_server_provider' )->justReturn( null );
		Functions\when( 'wcpos_pro_requires' )->justReturn( $supported );
	}

	public function test_fields_reuse_provider_projection_and_cache_for_five_minutes(): void {
		$this->options[ self::GATEWAY_OPTION ]['test_secret_key'] = 'sk_test_fake';
		$service = \Mockery::mock( 'overload:' . StripeTerminalService::class );
		$service->shouldReceive( 'list_all_readers' )->once()->andReturn( array(
			array( 'id' => 'tmr_a', 'label' => '', 'serial_number' => 'Till', 'status' => 'offline', 'device_type' => 'simulated_wisepos_e' ),
			array( 'id' => 'tmr_mobile', 'status' => 'online', 'device_type' => 'stripe_m2' ),
		) );
		$cache = array();
		Functions\when( 'get_transient' )->alias( function ( $key ) use ( &$cache ) { return $cache[ $key ] ?? false; } );
		$cache_writes = array();
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value, $ttl ) use ( &$cache, &$cache_writes ) { $cache[ $key ] = $value; $cache_writes[] = array( $value, $ttl ); return true; }
		);
		Functions\expect( 'update_option' )->never();
		$gateway = $this->gateway();
		$gateway->init_form_fields();
		$gateway->init_form_fields();
		$another = $this->gateway();
		$another->init_form_fields();
		$this->assertSame( array( array( array( 'tmr_a' => 'Till (tmr_a)' ), 300 ) ), $cache_writes );
		$this->assertSame( $gateway->form_fields, $another->form_fields );
		$this->assertSame( 'select', $gateway->form_fields['default_reader']['type'] );
		$this->assertSame( 'Till (tmr_a)', $gateway->form_fields['default_reader']['options']['tmr_a'] );
		$this->assertSame( 'multiselect', $gateway->form_fields['allowed_readers']['type'] );
		$this->assertSame( 'wc-enhanced-select', $gateway->form_fields['allowed_readers']['class'] );
		$this->assertSame( array( 'tmr_a' => 'Till (tmr_a)' ), $gateway->form_fields['allowed_readers']['options'] );
		$this->assertSame( 'checkbox', $gateway->form_fields['lock_to_default']['type'] );
		$this->assertSame( array( 'default_reader', 'allowed_readers', 'lock_to_default' ), array_slice( array_keys( $gateway->form_fields ), 5, 3 ) );
	}

	/** @dataProvider unavailable_contexts */
	public function test_unavailable_list_uses_text_and_omits_multiselect( string $context ): void {
		$this->options[ self::GATEWAY_OPTION ]['test_secret_key'] = 'sk_test_fake';
		$service = \Mockery::mock( 'overload:' . StripeTerminalService::class );
		if ( 'error' === $context ) {
			$service->shouldReceive( 'list_all_readers' )->once()->andReturn( new \WP_Error( 'offline', 'Unavailable' ) );
		} else {
			$service->shouldNotReceive( 'list_all_readers' );
		}
		if ( 'section' === $context ) { $_GET['section'] = 'other'; }
		if ( 'frontend' === $context ) { Functions\when( 'is_admin' )->justReturn( false ); }
		if ( 'ajax' === $context ) { Functions\when( 'wp_doing_ajax' )->justReturn( true ); }
		if ( 'key' === $context ) { unset( $this->options[ self::GATEWAY_OPTION ]['test_secret_key'] ); }
		Functions\expect( 'set_transient' )->never();
		$gateway = $this->gateway();
		$gateway->init_form_fields();
		$gateway->init_form_fields();
		$this->assertSame( 'text', $gateway->form_fields['default_reader']['type'] );
		$this->assertArrayNotHasKey( 'allowed_readers', $gateway->form_fields );
		$this->assertSame( 'checkbox', $gateway->form_fields['lock_to_default']['type'] );
	}

	public function unavailable_contexts(): array {
		return array_map( function ( $context ) { return array( $context ); }, array( 'error', 'section', 'frontend', 'ajax', 'key' ) );
	}

	public function test_save_mirrors_saved_values_and_replaces_shrunk_lists(): void {
		$this->pro();
		$this->options[ self::FREE_OPTION ] = array( 'gateways' => array( 'cash' => array( 'enabled' => true ) ), 'default' => 'cash' );
		$gateway = $this->gateway();
		$gateway->init_form_fields();
		$gateway->form_fields['allowed_readers'] = array( 'type' => 'multiselect' );
		$_POST[ $gateway->get_field_key( 'default_reader' ) ] = 'tmr_a';
		$_POST[ $gateway->get_field_key( 'allowed_readers' ) ] = array( 2 => 'tmr_a', 4 => '', 6 => 'tmr_b', 7 => false, 9 => 12 );
		$_POST[ $gateway->get_field_key( 'lock_to_default' ) ] = '1';
		$this->assertTrue( $gateway->process_admin_options() );
		$this->assertSame( array(
			'gateways' => array( 'cash' => array( 'enabled' => true ), Settings::GATEWAY_ID => array(
				'default_reader' => 'tmr_a', 'allowed_readers' => array( 'tmr_a', 'tmr_b' ), 'lock_to_default' => true,
			) ), 'default' => 'cash',
		), $this->options[ self::FREE_OPTION ] );
		$_POST[ $gateway->get_field_key( 'default_reader' ) ] = '';
		$_POST[ $gateway->get_field_key( 'allowed_readers' ) ] = array( 'tmr_b' );
		$gateway->process_admin_options();
		$this->assertSame( array( 'default_reader' => '', 'allowed_readers' => array( 'tmr_b' ), 'lock_to_default' => false ), $this->options[ self::FREE_OPTION ]['gateways'][ Settings::GATEWAY_ID ] );
	}

	public function test_failed_fetch_preserves_saved_values_on_save(): void {
		$this->pro();
		$this->options[ self::GATEWAY_OPTION ]['test_secret_key'] = 'sk_test_fake';
		$this->options[ self::GATEWAY_OPTION ]['allowed_readers'] = array( 'tmr_saved' );
		$service = \Mockery::mock( 'overload:' . StripeTerminalService::class );
		$service->shouldReceive( 'list_all_readers' )->once()->andReturn( new \WP_Error( 'offline', 'Unavailable' ) );
		$gateway = $this->gateway();
		$gateway->init_form_fields();
		$_POST[ $gateway->get_field_key( 'default_reader' ) ] = 'tmr_saved';
		$gateway->process_admin_options();
		$this->assertSame( array( 'tmr_saved' ), $this->options[ self::GATEWAY_OPTION ]['allowed_readers'] );
		$this->assertSame( 'tmr_saved', $this->options[ self::FREE_OPTION ]['gateways'][ Settings::GATEWAY_ID ]['default_reader'] );
		$this->assertSame( array( 'tmr_saved' ), $this->options[ self::FREE_OPTION ]['gateways'][ Settings::GATEWAY_ID ]['allowed_readers'] );
	}

	public function test_without_pro_only_gateway_settings_are_written(): void {
		$this->pro( false );
		$gateway = $this->gateway();
		$gateway->init_form_fields();
		$_POST[ $gateway->get_field_key( 'default_reader' ) ] = 'tmr_a';
		$gateway->process_admin_options();
		$before = $this->options;
		Pos_Reader_Settings::mirror( array( 'default_reader' => 'tmr_a' ) );
		Pos_Reader_Settings::migrate_once();
		$this->assertSame( $before, $this->options );
		$this->assertArrayNotHasKey( self::FREE_OPTION, $this->options );
		$this->assertSame( 'tmr_a', $this->options[ self::GATEWAY_OPTION ]['default_reader'] );
	}

	public function test_migration_seeds_once_without_overwriting_existing_settings(): void {
		$this->pro();
		$this->options[ self::GATEWAY_OPTION ] += array( 'default_reader' => 'tmr_a', 'allowed_readers' => array( 'tmr_a' ), 'lock_to_default' => 'yes' );
		$this->options[ self::FREE_OPTION ] = array( 'gateways' => array( Settings::GATEWAY_ID => array( 'allowed_readers' => array( 'tmr_existing' ), 'enabled' => true ) ) );
		Pos_Reader_Settings::migrate_once();
		$this->assertSame( array( 'allowed_readers' => array( 'tmr_existing' ), 'enabled' => true, 'default_reader' => 'tmr_a', 'lock_to_default' => true ), $this->options[ self::FREE_OPTION ]['gateways'][ Settings::GATEWAY_ID ] );
		$this->assertContains( STWC_VERSION, $this->options );
		$this->options[ self::GATEWAY_OPTION ]['default_reader'] = 'tmr_new';
		$this->options['stwc_pos_reader_settings_version'] = 'previous-version';
		$before = $this->options;
		Functions\when( 'update_option' )->alias( function () { $this->fail( 'Migration must not write twice.' ); } );
		Pos_Reader_Settings::migrate_once();
		$this->assertSame( $before, $this->options );
	}
}
