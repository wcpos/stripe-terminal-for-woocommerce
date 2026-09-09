<?php
namespace WCPOS\WooCommercePOS\StripeTerminal\Tests\Server;
require_once __DIR__ . '/ServerTestCase.php';
class StripeServerProviderRefundTest extends ServerTestCase {
	/** @dataProvider statuses */
	public function test_refund_payload_and_status( string $stripe_status, string $expected ): void {
		$provider = $this->provider(
			array(
				$this->ok(
					array(
						'id'     => 're_test',
						'status' => $stripe_status,
					)
				),
			)
		);
		$this->assertSame(
			array(
				'status'       => $expected,
				'provider_ref' => 're_test',
			),
			$provider->refund( $this->row(), 123, '2.50' )
		);
		$this->assertSame(
			array(
				'payment_intent' => 'pi_test',
				'amount'         => 250,
				'metadata'       => array(
					'wcpos_payment_id' => self::PAYMENT_ID,
					'wcpos_refund_id'  => '123',
				),
			),
			$this->http->requests[0]['params']
		);
		$this->assert_idempotency( 0, 'wcpos_refund_' . self::PAYMENT_ID . '_123' );
	}

	public function statuses(): array {
		return array( array( 'succeeded', 'succeeded' ), array( 'pending', 'pending' ), array( 'requires_action', 'pending' ), array( 'failed', 'failed' ), array( 'canceled', 'failed' ) );
	}

	public function test_missing_reference(): void {
		$row                  = $this->row();
		$row['provider_refs'] = array();
		$this->assert_provider_error( $this->provider()->refund( $row, 123, '2.50' ), 'missing_payment_ref' );
	}

	public function test_interac_requires_reader(): void {
		$provider = $this->provider(
			array(
				$this->ok(
					$this->intent(
						array(
							'latest_charge' => array(
								'id'                     => 'ch_test',
								'payment_method_details' => array( 'type' => 'interac_present' ),
							),
						)
					)
				),
			)
		);
		$this->assert_provider_error( $provider->refund( $this->row( 'CAD' ), 123, '2.50' ), 'interac_refund_on_reader' );
		$this->assertCount( 1, $this->http->requests );
	}

	public function test_cad_card_can_refund(): void {
		$provider = $this->provider(
			array(
				$this->ok( $this->intent( array( 'latest_charge' => $this->charge() ) ) ),
				$this->ok(
					array(
						'id'     => 're_test',
						'status' => 'succeeded',
					)
				),
			)
		);
		$this->assertSame( 'succeeded', $provider->refund( $this->row( 'CAD' ), 123, '2.50' )['status'] );
		$this->assertCount( 2, $this->http->requests );
	}
}
