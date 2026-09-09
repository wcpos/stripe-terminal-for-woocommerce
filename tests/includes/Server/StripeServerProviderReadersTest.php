<?php
namespace WCPOS\WooCommercePOS\StripeTerminal\Tests\Server;
require_once __DIR__ . '/ServerTestCase.php';
class StripeServerProviderReadersTest extends ServerTestCase {
	public function test_smart_reader_filter_and_labels(): void {
		$readers = array();
		foreach ( array( 'bbpos_wisepos_e', 'stripe_s700', 'stripe_s710', 'verifone_P400', 'simulated_wisepos_e', 'stripe_m2', 'bbpos_chipper2x', 'bbpos_wisepad3', 'mobile_phone_reader' ) as $i => $type ) {
			$readers[] = array(
				'id'            => 'tmr_' . $i,
				'device_type'   => $type,
				'label'         => 0 === $i ? 'Till' : '',
				'serial_number' => 1 === $i ? 'serial' : '',
				'status'        => 1 === $i ? 'offline' : 'online',
			);
		}
		$result = $this->provider(
			array(
				$this->ok(
					array(
						'object' => 'list',
						'data'   => $readers,
					)
				),
			)
		)->list_readers();
		$this->assertCount( 5, $result );
		$this->assertSame(
			array(
				'id'     => 'tmr_0',
				'label'  => 'Till',
				'status' => 'online',
			),
			$result[0]
		);
		$this->assertSame(
			array(
				'id'     => 'tmr_1',
				'label'  => 'serial',
				'status' => 'offline',
			),
			$result[1]
		);
		$this->assertSame( 'tmr_2', $result[2]['label'] );
	}

	public function test_service_error_is_normalized(): void {
		$this->assert_provider_error( $this->provider( array( $this->error( 'resource_missing' ) ) )->list_readers(), 'resource_missing' );
	}

	public function test_descriptor_enables_tip_fee_and_provider_refunds(): void {
		$provider = $this->provider();
		$this->assertSame( 'stripe', $provider->provider() );
		$gateway = \Mockery::mock( 'WC_Payment_Gateway' );
		$this->assertSame(
			array(
				'capabilities'  => array(
					'tips'    => 'on_reader',
					'refunds' => array(
						'via'     => 'provider',
						'partial' => true,
					),
					'void'    => true,
				),
				'provider_data' => array( 'mode' => 'test' ),
			),
			$provider->describe( $gateway )
		);
	}
}
