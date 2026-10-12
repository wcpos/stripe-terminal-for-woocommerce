<?php
namespace WCPOS\WooCommercePOS\StripeTerminal\Tests\Server;

use Mockery;
use WCPOS\WooCommercePOS\StripeTerminal\Server\Refund_Reask;
use WCPOS\WooCommercePOS\StripeTerminal\Tests\StripeHttpClientFake;

require_once __DIR__ . '/ServerTestCase.php';

/** The same refund create, sent again under the same key, until Stripe answers. */
class RefundReaskTest extends ServerTestCase {
	/**
	 * The request as `refund()` scheduled it.
	 *
	 * @param array $overrides Fields to replace.
	 */
	private function request( array $overrides = array() ): array {
		return array_replace(
			array(
				'payment'   => 'pi_test',
				'amount'    => 250,
				'metadata'  => array( 'wcpos_payment_id' => self::PAYMENT_ID, 'wcpos_refund_id' => '123' ),
				'key'       => 'wcpos_refund_' . self::PAYMENT_ID . '_123',
				'mode'      => 'test',
				'refund_id' => 123,
				'attempt'   => 0,
			),
			$overrides
		);
	}

	/**
	 * Scripted Stripe for the re-send: the service is built inside `run()`, so the fake goes in first
	 * and the constructor leaves it in place.
	 *
	 * @param array $responses Fake answers.
	 */
	private function stripe( array $responses ): StripeHttpClientFake {
		$this->options['woocommerce_stripe_terminal_for_woocommerce_settings']['test_secret_key'] = 'sk_test_fake';
		$this->options['woocommerce_stripe_terminal_for_woocommerce_settings']['secret_key']      = 'sk_live_fake';
		$this->http = new StripeHttpClientFake( $responses );
		\Stripe\ApiRequestor::setHttpClient( $this->http );
		return $this->http;
	}

	/** The re-send is byte for byte the till's request, under the till's key and mode. */
	public function test_re_sends_the_same_request_under_the_same_key(): void {
		$this->stripe( array( $this->ok( array( 'id' => 're_test', 'object' => 'refund', 'status' => 'succeeded' ) ) ) );

		Refund_Reask::run( $this->request() );

		$this->assertCount( 1, $this->http->requests );
		$this->assertStringEndsWith( '/refunds', $this->http->requests[0]['url'] );
		$this->assertSame( array( 'payment_intent' => 'pi_test', 'amount' => 250, 'metadata' => array( 'wcpos_payment_id' => self::PAYMENT_ID, 'wcpos_refund_id' => '123' ) ), $this->http->requests[0]['params'] );
		$this->assert_idempotency( 0, 'wcpos_refund_' . self::PAYMENT_ID . '_123' );
		$this->assertContains( 'Authorization: Bearer sk_test_fake', $this->http->requests[0]['headers'] );
		$this->assertCount( 0, $this->scheduled, 'Answered: nothing more to send; the sweep finds the refund by its metadata' );
	}

	/** A request the till made in live mode is re-sent under the live key, whatever the gateway's mode is now. */
	public function test_re_sends_under_the_recorded_mode(): void {
		$this->stripe( array( $this->ok( array( 'id' => 're_test', 'object' => 'refund', 'status' => 'succeeded' ) ) ) );

		Refund_Reask::run( $this->request( array( 'mode' => 'live' ) ) );

		$this->assertContains( 'Authorization: Bearer sk_live_fake', $this->http->requests[0]['headers'] );
	}

	/** Still unanswered: the next try is queued with the next delay, up to the last one. */
	public function test_still_unanswered_queues_the_next_try(): void {
		$this->stripe( array( new \Stripe\Exception\ApiConnectionException( 'timed out' ) ) );

		Refund_Reask::run( $this->request( array( 'attempt' => 0 ) ) );

		$this->assertCount( 1, $this->scheduled );
		$this->assertSame( 1, $this->scheduled[0]['args'][0]['attempt'] );
		$this->assertEqualsWithDelta( time() + Refund_Reask::DELAYS[1], $this->scheduled[0]['timestamp'], 5 );
		$this->assertSame( 'pi_test', $this->scheduled[0]['args'][0]['payment'] );
	}

	/** After the last delay nothing more is queued: the sweep's own give-up governs from here. */
	public function test_last_try_unanswered_queues_nothing(): void {
		$this->stripe( array( new \Stripe\Exception\ApiConnectionException( 'timed out' ) ) );

		Refund_Reask::run( $this->request( array( 'attempt' => count( Refund_Reask::DELAYS ) - 1 ) ) );

		$this->assertCount( 0, $this->scheduled );
	}

	/** A key conflict (Stripe sends it as a 400 of type idempotency_error; the SDK and the error handler make it a 409) keeps the question open the same way: Stripe may still be writing the first answer. */
	public function test_idempotency_conflict_queues_the_next_try(): void {
		$this->stripe( array( array( 'body' => array( 'error' => array( 'type' => 'idempotency_error', 'message' => 'Keys for idempotent requests can only be used with the same parameters' ) ), 'status' => 400 ) ) );

		Refund_Reask::run( $this->request() );

		$this->assertCount( 1, $this->scheduled );
	}

	/** Refused under the same key with the same body: never made. The record is marked for the sweep and staff read Stripe's reason on the order. */
	public function test_refused_replay_marks_the_record_and_notes_the_order(): void {
		$this->stripe( array( $this->error( 'charge_already_refunded' ) ) );
		$order  = Mockery::mock( 'WC_Order' );
		$refund = Mockery::mock( 'WC_Order_Refund' );
		$refund->shouldReceive( 'update_meta_data' )->once()->with( Refund_Reask::REFUSED_META, 'Stripe refused' );
		$refund->shouldReceive( 'save' )->once();
		$refund->shouldReceive( 'get_parent_id' )->andReturn( 42 );
		$refund->shouldReceive( 'get_amount' )->andReturn( '2.50' );
		$refund->shouldReceive( 'get_currency' )->andReturn( 'USD' );
		$order->shouldReceive( 'add_order_note' )->once()->with( Mockery::on( static fn( $note ) => false !== strpos( $note, 'Stripe refused the POS refund of 2.50 USD' ) && false !== strpos( $note, 'Stripe refused' ) && false !== strpos( $note, 'The refund was not made.' ) ) );
		$this->orders = array( 123 => $refund, 42 => $order );

		Refund_Reask::run( $this->request() );

		$this->assertCount( 0, $this->scheduled );
	}

	/** The till's mode has no key any more: nothing can be sent, and nothing is queued. */
	public function test_missing_key_for_the_mode_sends_nothing(): void {
		$this->stripe( array() );
		unset( $this->options['woocommerce_stripe_terminal_for_woocommerce_settings']['secret_key'] );

		Refund_Reask::run( $this->request( array( 'mode' => 'live' ) ) );

		$this->assertCount( 0, $this->http->requests );
		$this->assertCount( 0, $this->scheduled );
	}

	/** `schedule()` refuses an attempt past the last delay. */
	public function test_schedule_stops_after_the_last_delay(): void {
		Refund_Reask::schedule( $this->request( array( 'attempt' => count( Refund_Reask::DELAYS ) ) ) );
		$this->assertCount( 0, $this->scheduled );
		Refund_Reask::schedule( $this->request( array( 'attempt' => 2 ) ) );
		$this->assertCount( 1, $this->scheduled );
		$this->assertEqualsWithDelta( time() + Refund_Reask::DELAYS[2], $this->scheduled[0]['timestamp'], 5 );
	}
}
