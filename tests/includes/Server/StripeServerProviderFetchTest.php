<?php
namespace WCPOS\WooCommercePOS\StripeTerminal\Tests\Server;
require_once __DIR__ . '/ServerTestCase.php';
class StripeServerProviderFetchTest extends ServerTestCase {
	private function reader_on( string $intent_id ): array {
		return array(
			'id'     => 'tmr_test',
			'object' => 'terminal.reader',
			'action' => array(
				'type'                   => 'process_payment_intent',
				'status'                 => 'in_progress',
				'process_payment_intent' => array( 'payment_intent' => $intent_id ),
			),
		);
	}

	public function test_superseded_is_final_only_after_a_second_intent_read(): void {
		$provider = $this->provider( array( $this->ok( $this->intent() ), $this->ok( $this->reader_on( 'pi_other' ) ), $this->ok( $this->intent() ), $this->ok( $this->intent( array( 'status' => 'canceled' ) ) ) ) );
		$result   = $provider->fetch( 'pi_test' );
		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( 'superseded', $result['failure_reason'] );
		$this->assertStringEndsWith( '/payment_intents/pi_test', $this->http->requests[2]['url'] );
		$this->assertStringEndsWith( '/payment_intents/pi_test/cancel', $this->http->requests[3]['url'] );
	}

	public function test_intent_approved_between_the_two_reads_is_completed(): void {
		// The reader moved on, but the intent succeeded in the gap: never report money as lost.
		$provider = $this->provider( array( $this->ok( $this->intent() ), $this->ok( $this->reader_on( 'pi_other' ) ), $this->ok( $this->intent( array( 'status' => 'succeeded', 'amount_received' => 1234 ) ) ) ) );
		$result   = $provider->fetch( 'pi_test' );
		$this->assertSame( 'completed', $result['status'] );
		$this->assertCount( 3, $this->http->requests );
	}
}
