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
						'object'   => 'list',
						'url'      => '/v1/terminal/readers',
						'has_more' => false,
						'data'     => $readers,
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

	public function test_all_reader_pages_are_filtered_and_returned(): void {
		$pages = array(
			array(
				array( 'id' => 'tmr_smart_first', 'object' => 'terminal.reader', 'device_type' => 'stripe_s700', 'label' => 'First', 'status' => 'online' ),
				array( 'id' => 'tmr_mobile_first', 'object' => 'terminal.reader', 'device_type' => 'stripe_m2', 'status' => 'online' ),
			),
			array(
				array( 'id' => 'tmr_smart_second', 'object' => 'terminal.reader', 'device_type' => 'simulated_wisepos_e', 'label' => 'Second', 'status' => 'offline' ),
				array( 'id' => 'tmr_mobile_second', 'object' => 'terminal.reader', 'device_type' => 'bbpos_wisepad3', 'status' => 'online' ),
			),
		);
		$provider = $this->provider(
			array(
				$this->ok( array( 'object' => 'list', 'url' => '/v1/terminal/readers', 'has_more' => true, 'data' => $pages[0] ) ),
				$this->ok( array( 'object' => 'list', 'url' => '/v1/terminal/readers', 'has_more' => false, 'data' => $pages[1] ) ),
			)
		);
		$this->assertSame(
			array(
				array( 'id' => 'tmr_smart_first', 'label' => 'First', 'status' => 'online' ),
				array( 'id' => 'tmr_smart_second', 'label' => 'Second', 'status' => 'offline' ),
			),
			$provider->list_readers()
		);
		$this->assertCount( 2, $this->http->requests );
		$this->assertSame( array( 'limit' => 100 ), $this->http->requests[0]['params'] );
		$this->assertSame( array( 'limit' => 100, 'starting_after' => 'tmr_mobile_first' ), $this->http->requests[1]['params'] );
		$this->assertSame( 'get', $this->http->requests[1]['method'] );
		$this->assertStringEndsWith( '/terminal/readers', $this->http->requests[1]['url'] );
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
