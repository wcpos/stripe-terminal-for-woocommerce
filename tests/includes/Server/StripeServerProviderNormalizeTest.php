<?php
namespace WCPOS\WooCommercePOS\StripeTerminal\Tests\Server;
use WCPOS\WooCommercePOS\StripeTerminal\Server\Stripe_Server_Provider as Provider;
require_once __DIR__ . '/ServerTestCase.php';

class StripeServerProviderNormalizeTest extends ServerTestCase {
	/** @dataProvider states */
	public function test_intent_mapping( string $state, string $expected ): void {
		$this->assertSame( $expected, Provider::normalize( $this->intent( array( 'status' => $state ) ), null )['status'] );
	}

	public function states(): array {
		return array( array( 'succeeded', 'completed' ), array( 'requires_capture', 'completed' ), array( 'canceled', 'cancelled' ), array( 'processing', 'in_progress' ), array( 'requires_confirmation', 'in_progress' ), array( 'requires_action', 'in_progress' ), array( 'requires_payment_method', 'pending' ), array( 'unknown', 'pending' ) );
	}

	public function test_manual_authorization_flag(): void {
		$this->assertTrue( Provider::normalize( $this->intent( array( 'status' => 'requires_capture' ) ), null )['authorized'] );
	}

	/** @dataProvider declines */
	public function test_intent_decline_precedes_reader_state( array $error, string $reason ): void {
		$result = Provider::normalize(
			$this->intent( array( 'last_payment_error' => $error ) ),
			array(
				'status'                 => 'failed',
				'failure_code'           => 'customer_canceled',
				'process_payment_intent' => array( 'payment_intent' => 'pi_test' ),
			)
		);
		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( $reason, $result['failure_reason'] );
	}

	public function declines(): array {
		return array(
			array(
				array(
					'decline_code' => 'insufficient_funds',
					'code'         => 'card_declined',
				),
				'insufficient_funds',
			),
			array( array( 'code' => 'card_declined' ), 'card_declined' ),
			array( array( 'message' => 'No' ), 'provider_error' ),
		);
	}

	/** @dataProvider actions */
	public function test_reader_action_mapping( string $id, string $state, string $code, string $expected, ?string $reason ): void {
		$result = Provider::normalize(
			$this->intent(),
			array(
				'status'                 => $state,
				'failure_code'           => $code,
				'process_payment_intent' => array( 'payment_intent' => $id ),
			)
		);
		$this->assertSame( $expected, $result['status'] );
		$this->assertSame( $reason, $result['failure_reason'] ?? null );
	}

	public function actions(): array {
		return array( array( 'pi_test', 'failed', 'customer_canceled', 'cancelled', null ), array( 'pi_test', 'failed', 'card_declined', 'failed', 'card_declined' ), array( 'pi_test', 'failed', 'connection_error', 'failed', 'connection_error' ), array( 'pi_test', 'in_progress', '', 'in_progress', null ), array( 'pi_test', 'succeeded', '', 'in_progress', null ), array( 'pi_other', 'in_progress', '', 'failed', 'superseded' ) );
	}

	public function test_tip_amount_and_owned_refs(): void {
		$result = Provider::normalize(
			$this->intent(
				array(
					'amount_received' => 1500,
					'latest_charge'   => $this->charge(),
				)
			),
			null
		);
		$this->assertSame( '15.00', $result['amount'] );
		$this->assertSame( 'USD', $result['currency'] );
		$this->assertSame(
			array(
				'stripe_payment_intent' => 'pi_test',
				'stripe_charge'         => 'ch_test',
				'stripe_mode'           => 'test',
			),
			$result['provider_refs']
		);
	}

	public function test_jpy_and_unexpanded_charge(): void {
		$result = Provider::normalize(
			$this->intent(
				array(
					'currency'      => 'jpy',
					'livemode'      => true,
					'latest_charge' => 'ch_test',
				)
			),
			null
		);
		$this->assertSame( '1250', $result['amount'] );
		$this->assertSame( 'live', $result['provider_refs']['stripe_mode'] );
		$this->assertNull( $result['provider_refs']['stripe_charge'] );
		$this->assertSame( array(), $result['receipt'] );
	}

	public function test_receipt_map(): void {
		$result = Provider::normalize( $this->intent( array( 'latest_charge' => $this->charge() ) ), null );
		$this->assertSame(
			array(
				'card_brand'    => 'visa',
				'card_last4'    => '4242',
				'card_funding'  => 'credit',
				'auth_code'     => '1234',
				'application'   => 'VISA',
				'aid'           => 'A000',
				'verification'  => 'pin',
				'read_method'   => 'contactless_emv',
				'stripe_charge' => 'ch_test',
			),
			$result['receipt']
		);
	}

	public function test_interac_receipt_omits_empty_values(): void {
		$charge = array(
			'id'                     => 'ch_interac',
			'payment_method_details' => array(
				'interac_present' => array(
					'brand'   => 'interac',
					'last4'   => '',
					'funding' => null,
				),
			),
		);
		$this->assertSame(
			array(
				'card_brand'    => 'interac',
				'stripe_charge' => 'ch_interac',
			),
			Provider::normalize( $this->intent( array( 'latest_charge' => $charge ) ), null )['receipt']
		);
	}

	public function test_fetch_reads_action_and_refreshes_warmer_suppression(): void {
		$provider = $this->provider(
			array(
				$this->ok( $this->intent() ),
				$this->ok(
					array(
						'id'     => 'tmr_test',
						'action' => array(
							'status'                 => 'succeeded',
							'process_payment_intent' => array( 'payment_intent' => 'pi_test' ),
						),
					)
				),
			)
		);
		$this->assertSame( 'in_progress', $provider->fetch( 'pi_test' )['status'] );
		$this->assertSame( array( 'expand' => array( 'latest_charge' ) ), $this->http->requests[0]['params'] );
		$this->assertStringEndsWith( '/terminal/readers/tmr_test', $this->http->requests[1]['url'] );
		$this->assertEqualsWithDelta( time(), $this->options['stwc_payment_dispatch_at'], 1 );
	}

	public function test_fetch_decline_cancels_even_if_cleanup_fails(): void {
		$provider = $this->provider( array( $this->ok( $this->intent( array( 'last_payment_error' => array( 'code' => 'card_declined' ) ) ) ), $this->ok( array( 'id' => 'tmr_test' ) ), $this->error() ) );
		$this->assertSame( 'failed', $provider->fetch( 'pi_test' )['status'] );
		$this->assertStringEndsWith( '/payment_intents/pi_test/cancel', $this->http->requests[2]['url'] );
	}

	public function test_capture_normalizes_confirmation(): void {
		$provider = $this->provider( array( $this->ok( $this->intent( array( 'status' => 'succeeded' ) ) ) ) );
		$this->assertSame( 'completed', $provider->capture( 'pi_test' )['status'] );
		$this->assertStringEndsWith( '/payment_intents/pi_test/capture', $this->http->requests[0]['url'] );
	}

	public function test_pending_fetch_refreshes_warmer_without_a_reader(): void {
		$provider = $this->provider( array( $this->ok( $this->intent( array( 'metadata' => array() ) ) ) ) );
		$this->assertSame( 'pending', $provider->fetch( 'pi_test' )['status'] );
		$this->assertEqualsWithDelta( time(), $this->options['stwc_payment_dispatch_at'], 1 );
		$this->assertCount( 1, $this->http->requests );
	}

	public function test_reader_lookup_error_does_not_invent_an_outcome(): void {
		$provider = $this->provider( array( $this->ok( $this->intent() ), $this->error( 'resource_missing' ) ) );
		$this->assert_provider_error( $provider->fetch( 'pi_test' ), 'resource_missing' );
	}

	/** @dataProvider failing_operations */
	public function test_service_errors_keep_the_stripe_code( string $operation ): void {
		$provider = $this->provider( array( $this->error( 'resource_missing' ) ) );
		$this->assert_provider_error( $provider->$operation( 'pi_test' ), 'resource_missing' );
	}

	public function failing_operations(): array {
		return array( array( 'fetch' ), array( 'capture' ), array( 'cancel' ) );
	}

	public function test_unexpected_exception_is_an_enveloped_error(): void {
		$service = \Mockery::mock( \WCPOS\WooCommercePOS\StripeTerminal\StripeTerminalService::class );
		$service->shouldReceive( 'retrieve_payment_intent' )->with( 'pi_test' )->andThrow( new \RuntimeException( 'Unexpected transport failure' ) );
		$this->assert_provider_error( ( new Provider( $service ) )->fetch( 'pi_test' ), 'stripe_api_error' );
	}
}
