<?php
namespace WCPOS\WooCommercePOS\StripeTerminal\Tests\Server;
require_once __DIR__ . '/ServerTestCase.php';
class StripeServerProviderCancelTest extends ServerTestCase {
	/** @dataProvider settled */
	public function test_settled_intent_never_calls_reader( string $state, string $expected ): void {
		$provider = $this->provider( array( $this->ok( $this->intent( array( 'status' => $state ) ) ) ) );
		$this->assertSame( $expected, $provider->cancel( 'pi_test' ) );
		$this->assertCount( 1, $this->http->requests );
	}

	public function settled(): array {
		return array( array( 'succeeded', 'requested' ), array( 'requires_capture', 'requested' ), array( 'canceled', 'final' ) );
	}

	private function reader_holding( string $intent_id, string $status = 'in_progress' ): array {
		return array(
			'id'     => 'tmr_test',
			'object' => 'terminal.reader',
			'action' => array(
				'type'                   => 'process_payment_intent',
				'status'                 => $status,
				'process_payment_intent' => array( 'payment_intent' => $intent_id ),
			),
		);
	}

	public function test_busy_reader_does_not_cancel_intent(): void {
		$provider = $this->provider( array( $this->ok( $this->intent() ), $this->ok( $this->reader_holding( 'pi_test' ) ), $this->error( 'terminal_reader_busy' ) ) );
		$this->assertSame( 'requested', $provider->cancel( 'pi_test' ) );
		$this->assertCount( 3, $this->http->requests );
	}

	public function test_idle_reader_then_cancel_is_final(): void {
		$provider = $this->provider( array( $this->ok( $this->intent() ), $this->ok( $this->reader_holding( 'pi_test' ) ), $this->error( 'resource_missing' ), $this->ok( $this->intent( array( 'status' => 'canceled' ) ) ) ) );
		$this->assertSame( 'final', $provider->cancel( 'pi_test' ) );
		$this->assertStringEndsWith( '/terminal/readers/tmr_test', $this->http->requests[1]['url'] );
		$this->assertStringEndsWith( '/terminal/readers/tmr_test/cancel_action', $this->http->requests[2]['url'] );
		$this->assertStringEndsWith( '/payment_intents/pi_test/cancel', $this->http->requests[3]['url'] );
	}

	public function test_reader_on_another_intent_is_left_alone(): void {
		// The reader has moved on to a later leg: cancel only this intent, never the reader's action.
		$provider = $this->provider( array( $this->ok( $this->intent() ), $this->ok( $this->reader_holding( 'pi_other' ) ), $this->ok( $this->intent( array( 'status' => 'canceled' ) ) ) ) );
		$this->assertSame( 'final', $provider->cancel( 'pi_test' ) );
		$this->assertCount( 3, $this->http->requests );
		$this->assertStringEndsWith( '/payment_intents/pi_test/cancel', $this->http->requests[2]['url'] );
	}

	/** @dataProvider race_states */
	public function test_cancel_exception_rereads_money_state( string $state, string $expected ): void {
		$provider = $this->provider( array( $this->ok( $this->intent() ), $this->ok( array( 'id' => 'tmr_test' ) ), $this->error( 'payment_intent_unexpected_state' ), $this->ok( $this->intent( array( 'status' => $state ) ) ) ) );
		$this->assertSame( $expected, $provider->cancel( 'pi_test' ) );
		$this->assertCount( 4, $this->http->requests );
	}

	public function race_states(): array {
		return array( array( 'succeeded', 'requested' ), array( 'canceled', 'final' ), array( 'processing', 'requested' ) );
	}

	public function test_reader_error_is_returned(): void {
		$provider = $this->provider( array( $this->ok( $this->intent() ), $this->error( 'reader_offline' ) ) );
		$this->assert_provider_error( $provider->cancel( 'pi_test' ), 'reader_offline' );
	}
	public function test_unrelated_intent_cancel_error_is_returned(): void {
		$provider = $this->provider( array( $this->ok( $this->intent() ), $this->ok( array( 'id' => 'tmr_test' ) ), $this->error( 'api_key_expired' ) ) );
		$this->assert_provider_error( $provider->cancel( 'pi_test' ), 'api_key_expired' );
		$this->assertCount( 3, $this->http->requests );
	}
}
