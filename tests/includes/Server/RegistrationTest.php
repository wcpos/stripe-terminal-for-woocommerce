<?php
namespace WCPOS\WooCommercePOS\StripeTerminal\Tests\Server;
use Brain\Monkey\Functions;
use WCPOS\WooCommercePOS\StripeTerminal\Server\Registration;
use WCPOS\WooCommercePOS\StripeTerminal\Server\Stripe_Server_Provider;
require_once __DIR__ . '/ServerTestCase.php';
/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RegistrationTest extends ServerTestCase {
	public function test_no_pro_is_legacy_only(): void {
		$this->assertFalse( Registration::pro_supported() );
		$this->assertFalse( Registration::register() );
		Registration::activation_check( '/plugin.php' );
	}

	public function test_old_pro_does_not_register_and_receives_activation_notice(): void {
		Functions\when( 'wcpos_pro_register_server_provider' )->justReturn( null );
		$calls = array();
		Functions\when( 'wcpos_pro_requires' )->alias(
			function ( $version, $file = '' ) use ( &$calls ) {
				$calls[] = array( $version, $file );
				return false;
			}
		);
		$this->assertFalse( Registration::pro_supported() );
		Registration::activation_check( '/plugin.php' );
		$this->assertSame( array( array( '1.11.0', '' ), array( '1.11.0', '' ), array( '1.11.0', '/plugin.php' ) ), $calls );
	}

	public function test_supported_pro_registers_once(): void {
		Functions\when( 'wcpos_pro_requires' )->justReturn( true );
		Functions\expect( 'wcpos_pro_register_server_provider' )->once()->with( 'stripe_terminal_for_woocommerce', Stripe_Server_Provider::class );
		$this->assertTrue( Registration::register() );
		$this->assertTrue( Registration::register() );
	}
}
