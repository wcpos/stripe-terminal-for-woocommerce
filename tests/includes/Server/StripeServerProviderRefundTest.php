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


	/**
	 * Stripe did not answer the create: pending with no reference (the record stands), and the same
	 * request is queued to be sent again under the same key.
	 *
	 * @dataProvider unanswered_creates
	 */
	public function test_unanswered_create_is_pending_and_re_sent( $response ): void {
		$provider = $this->provider( array( $response ) );
		$this->assertSame( array( 'status' => 'pending', 'provider_ref' => null ), $provider->refund( $this->row(), 123, '2.50' ) );
		$this->assertCount( 1, $this->scheduled );
		$this->assertSame( \WCPOS\WooCommercePOS\StripeTerminal\Server\Refund_Reask::HOOK, $this->scheduled[0]['hook'] );
		$this->assertEqualsWithDelta( time() + \WCPOS\WooCommercePOS\StripeTerminal\Server\Refund_Reask::DELAYS[0], $this->scheduled[0]['timestamp'], 5 );
		$this->assertSame(
			array(
				'payment'   => 'pi_test',
				'amount'    => 250,
				'metadata'  => array( 'wcpos_payment_id' => self::PAYMENT_ID, 'wcpos_refund_id' => '123' ),
				'key'       => 'wcpos_refund_' . self::PAYMENT_ID . '_123',
				'mode'      => 'test',
				'refund_id' => 123,
				'attempt'   => 0,
			),
			$this->scheduled[0]['args'][0]
		);
	}

	public function unanswered_creates(): array {
		return array(
			'connection dropped' => array( new \Stripe\Exception\ApiConnectionException( 'timed out' ) ),
			'stripe 5xx'         => array( array( 'body' => array( 'error' => array( 'type' => 'api_error', 'message' => 'Stripe is down' ) ), 'status' => 503 ) ),
		);
	}

	/** A refusal is an answer: nothing was made, the error stands, nothing is re-sent. */
	public function test_refused_create_is_an_error_and_not_re_sent(): void {
		$provider = $this->provider( array( $this->error( 'charge_already_refunded' ) ) );
		$this->assert_provider_error( $provider->refund( $this->row(), 123, '2.50' ), 'charge_already_refunded' );
		$this->assertCount( 0, $this->scheduled );
	}

	/** A read before the create (the Interac check) that goes unanswered stays an error: no refund was asked for. */
	public function test_unanswered_interac_read_stays_an_error(): void {
		$provider = $this->provider( array( new \Stripe\Exception\ApiConnectionException( 'timed out' ) ) );
		$this->assertInstanceOf( \WP_Error::class, $provider->refund( $this->row( 'CAD' ), 123, '2.50' ) );
		$this->assertCount( 1, $this->http->requests );
		$this->assertCount( 0, $this->scheduled );
	}

	/** A 200 without the refund in it is pending with no reference too, with nothing re-sent: Stripe answered, so the refund exists for the sweep to find. */
	public function test_success_without_the_refund_is_pending_and_not_re_sent(): void {
		$provider = $this->provider( array( $this->ok( array( 'object' => 'refund' ) ) ) );
		$this->assertSame( array( 'status' => 'pending', 'provider_ref' => null ), $provider->refund( $this->row(), 123, '2.50' ) );
		$this->assertCount( 0, $this->scheduled );
	}

	/** The re-send carries the mode the till was in, so a later mode switch does not move it to the other account. */
	public function test_unanswered_create_in_live_mode_is_re_sent_as_live(): void {
		$this->options['woocommerce_stripe_terminal_for_woocommerce_settings']['test_mode'] = 'no';
		$provider = $this->provider( array( new \Stripe\Exception\ApiConnectionException( 'timed out' ) ) );
		$provider->refund( $this->row(), 123, '2.50' );
		$this->assertSame( 'live', $this->scheduled[0]['args'][0]['mode'] );
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

	/** A historical webview row names the charge; the Interac check reads the charge, and the refund keys on it. */
	public function test_cad_charge_reference_reads_the_charge(): void {
		$provider = $this->provider( array( $this->ok( $this->charge() ), $this->ok( array( 'id' => 're_test', 'status' => 'succeeded' ) ) ) );
		$row      = array( 'id' => self::PAYMENT_ID, 'order_id' => 42, 'amount' => '12.50', 'currency' => 'CAD', 'provider_refs' => array( 'transaction_id' => 'ch_test' ) );
		$this->assertSame( 'succeeded', $provider->refund( $row, 123, '2.50' )['status'] );
		$this->assertStringEndsWith( '/charges/ch_test', $this->http->requests[0]['url'] );
		$this->assertSame( 'ch_test', $this->http->requests[1]['params']['charge'] );
	}

	public function test_cad_interac_charge_reference_requires_reader(): void {
		$charge = $this->charge();
		$charge['payment_method_details']['type'] = 'interac_present';
		$provider = $this->provider( array( $this->ok( $charge ) ) );
		$row      = array( 'id' => self::PAYMENT_ID, 'order_id' => 42, 'amount' => '12.50', 'currency' => 'CAD', 'provider_refs' => array( 'transaction_id' => 'ch_test' ) );
		$this->assert_provider_error( $provider->refund( $row, 123, '2.50' ), 'interac_refund_on_reader' );
		$this->assertCount( 1, $this->http->requests );
	}
}
