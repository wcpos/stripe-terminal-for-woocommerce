<?php
namespace WCPOS\WooCommercePOS\StripeTerminal\Tests\Server;
use Brain\Monkey\Functions;
require_once __DIR__ . '/ServerTestCase.php';

class StripeServerProviderCreateActionTest extends ServerTestCase {
	private function order(): void {
		$order = \Mockery::mock();
		$order->shouldReceive( 'get_id' )->andReturn( 42 );
		$order->shouldReceive( 'get_order_number' )->andReturn( 'CUSTOM-42' );
		$order->shouldReceive( 'get_total' )->andReturn( '100.00' );
		$order->shouldNotReceive( 'update_meta_data' );
		$order->shouldNotReceive( 'save' );
		Functions\when( 'wc_get_order' )->justReturn( $order );
	}

	/** @dataProvider currencies */
	public function test_row_payload_and_idempotency( string $currency, string $country, array $methods ): void {
		$this->order();
		Functions\when( 'get_transient' )->justReturn( $country );
		$provider = $this->provider( array( $this->ok( $this->intent() ), $this->ok( array( 'id' => 'tmr_test' ) ) ) );
		$this->assertSame(
			array(
				'ref'        => 'pi_test',
				'expires_at' => null,
			),
			$provider->create_reader_action( $this->row( $currency ), 'tmr_test' )
		);
		$this->assertSame(
			array(
				'amount'               => 1250,
				'currency'             => strtolower( $currency ),
				'description'          => 'Order #CUSTOM-42',
				'metadata'             => array(
					'order_id'         => '42',
					'wcpos_payment_id' => self::PAYMENT_ID,
					'wcpos_reader'     => 'tmr_test',
				),
				'capture_method'       => 'automatic',
				'allowed_payment_method_types' => $methods,
			),
			$this->http->requests[0]['params']
		);
		$this->assert_idempotency( 0, self::PAYMENT_ID );
		$this->assertSame(
			array(
				'payment_intent' => 'pi_test',
				'process_config' => array( 'enable_customer_cancellation' => 'true' ),
			),
			$this->http->requests[1]['params']
		);
		$this->assertEqualsWithDelta( time(), $this->options['stwc_payment_dispatch_at'], 1 );
	}

	public function currencies(): array {
		return array( array( 'USD', 'US', array( 'card_present' ) ), array( 'CAD', 'CA', array( 'card_present', 'interac_present' ) ) );
	}

	public function test_dispatch_failure_cancels_intent(): void {
		$this->order();
		$provider = $this->provider( array( $this->ok( $this->intent() ), $this->error(), $this->ok( array( 'id' => 'tmr_test', 'action' => null ) ), $this->ok( $this->intent() ), $this->ok( $this->intent( array( 'status' => 'canceled' ) ) ) ) );
		$this->assert_provider_error( $provider->create_reader_action( $this->row(), 'tmr_test' ), 'card_declined' );
		$this->assertSame( 'get', $this->http->requests[2]['method'] );
		$this->assertStringEndsWith( '/terminal/readers/tmr_test', $this->http->requests[2]['url'] );
		$this->assertStringEndsWith( '/payment_intents/pi_test', $this->http->requests[3]['url'] );
		$this->assertStringEndsWith( '/payment_intents/pi_test/cancel', $this->http->requests[4]['url'] );
		$this->assertCount( 5, $this->http->requests );
	}

	/** @dataProvider active_action_statuses */
	public function test_dispatch_error_preserves_intent_held_by_reader( string $status ): void {
		$this->order();
		$this->options['stwc_payment_dispatch_at'] = 1;
		$provider = $this->provider(
			array(
				$this->ok( $this->intent() ),
				$this->error( 'reader_busy' ),
				$this->ok(
					array(
						'id' => 'tmr_test',
						'action' => array(
							'status' => $status,
							'process_payment_intent' => array( 'payment_intent' => 'pi_test' ),
						),
					)
				),
			)
		);
		$this->assertSame( array( 'ref' => 'pi_test', 'expires_at' => null ), $provider->create_reader_action( $this->row(), 'tmr_test' ) );
		$this->assertCount( 3, $this->http->requests );
		$this->assertSame( 'get', $this->http->requests[2]['method'] );
		$this->assertStringEndsWith( '/terminal/readers/tmr_test', $this->http->requests[2]['url'] );
		$this->assertEqualsWithDelta( time(), $this->options['stwc_payment_dispatch_at'], 1 );
	}

	public function active_action_statuses(): array {
		return array( array( 'in_progress' ), array( 'succeeded' ) );
	}

	public function test_dispatch_error_and_reader_read_error_do_not_cancel(): void {
		$this->order();
		$provider = $this->provider( array( $this->ok( $this->intent() ), $this->error( 'reader_busy' ), $this->error( 'resource_missing' ), $this->ok( $this->intent() ) ) );
		$error = $provider->create_reader_action( $this->row(), 'tmr_test' );
		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertTrue( $error->get_error_data()['indeterminate'], 'an unreadable reader may still be collecting the intent: the leg stays pending' );
		// The reader read, then the intent read (unpaid); no cancel: the unreadable reader may still hold it.
		$this->assertCount( 4, $this->http->requests );
		$this->assertSame( 'get', $this->http->requests[2]['method'] );
		$this->assertStringEndsWith( '/terminal/readers/tmr_test', $this->http->requests[2]['url'] );
		$this->assertSame( 'get', $this->http->requests[3]['method'] );
		$this->assertStringEndsWith( '/payment_intents/pi_test', $this->http->requests[3]['url'] );
	}

	public function test_missing_order(): void {
		Functions\when( 'wc_get_order' )->justReturn( false );
		$provider = $this->provider();
		$error    = $provider->create_reader_action( $this->row(), 'tmr_test' );
		$this->assertSame( 'wcpos_order_not_found', $error->get_error_code() );
		$this->assertSame( 404, $error->get_error_data()['status'] );
		$this->assertCount( 0, $this->http->requests );
	}

	public function test_create_error_does_not_dispatch(): void {
		$this->order();
		$provider = $this->provider( array( $this->error( 'parameter_invalid_integer' ) ) );
		$this->assert_provider_error( $provider->create_reader_action( $this->row(), 'tmr_test' ), 'parameter_invalid_integer' );
		$this->assertCount( 1, $this->http->requests );
	}

	public function test_unsupported_currency_uses_the_shared_account_check(): void {
		$this->order();
		$provider = $this->provider(
			array(
				$this->ok(
					array(
						'id'      => 'acct_us',
						'object'  => 'account',
						'country' => 'US',
					)
				),
			)
		);
		$error    = $provider->create_reader_action( $this->row( 'CAD' ), 'tmr_test' );
		$this->assert_provider_error( $error, 'stripe_api_error' );
		$this->assertStringContainsString( 'Currency CAD is not supported', $error->get_error_message() );
		$this->assertCount( 1, $this->http->requests );
		$this->assertStringEndsWith( '/account', $this->http->requests[0]['url'] );
	}

	/** A create Stripe did not answer may exist: Pro replays it under the same idempotency key. */
	public function test_unanswered_create_is_indeterminate(): void {
		$this->order();
		$provider = $this->provider( array( \Stripe\Exception\ApiConnectionException::factory( 'Connection lost' ) ) );
		$error    = $provider->create_reader_action( $this->row(), 'tmr_test' );
		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'stripe_create_unanswered', $error->get_error_code() );
		$this->assertTrue( $error->get_error_data()['indeterminate'] );
		$this->assertCount( 1, $this->http->requests );
	}

	/** On a replay the reader may have moved on while this intent was paid: the ref is returned, nothing is retired. */
	public function test_dispatch_refused_after_the_intent_succeeded_returns_the_ref(): void {
		$this->order();
		$other    = array( 'id' => 'tmr_test', 'action' => array( 'status' => 'in_progress', 'process_payment_intent' => array( 'payment_intent' => 'pi_other' ) ) );
		$provider = $this->provider( array( $this->ok( $this->intent() ), $this->error( 'terminal_reader_busy' ), $this->ok( $other ), $this->ok( $other ), $this->ok( $this->intent( array( 'status' => 'succeeded', 'amount_received' => 1250 ) ) ) ) );
		$this->assertSame( array( 'ref' => 'pi_test', 'expires_at' => null ), $provider->create_reader_action( $this->row(), 'tmr_test' ) );
		$this->assertCount( 5, $this->http->requests );
	}

	/** A refused dispatch whose reader cannot be read back still reads the intent: on a replay it may be paid. */
	public function test_dispatch_refused_with_unreadable_reader_reads_the_intent(): void {
		$this->order();
		$other    = array( 'id' => 'tmr_test', 'action' => array( 'status' => 'in_progress', 'process_payment_intent' => array( 'payment_intent' => 'pi_other' ) ) );
		$provider = $this->provider( array( $this->ok( $this->intent() ), $this->error( 'terminal_reader_busy' ), $this->ok( $other ), $this->error( 'resource_missing' ), $this->ok( $this->intent( array( 'status' => 'succeeded', 'amount_received' => 1250 ) ) ) ) );
		$this->assertSame( array( 'ref' => 'pi_test', 'expires_at' => null ), $provider->create_reader_action( $this->row(), 'tmr_test' ) );
		$this->assertStringEndsWith( '/payment_intents/pi_test', end( $this->http->requests )['url'] );
		$this->assertCount( 5, $this->http->requests );
	}

	/** A retry on another reader changes the metadata under the row's idempotency key: the first dispatch may be live. */
	public function test_idempotency_conflict_on_replay_is_indeterminate(): void {
		$this->order();
		$provider = $this->provider( array( array( 'body' => array( 'error' => array( 'type' => 'idempotency_error', 'message' => 'Keys for idempotent requests can only be used with the same parameters they were first used with.' ) ), 'status' => 400 ) ) );
		$error    = $provider->create_reader_action( $this->row(), 'tmr_other' );
		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertTrue( $error->get_error_data()['indeterminate'] );
	}

	/** An unanswered dispatch whose reader cannot be read back may be on the reader already. */
	public function test_unanswered_dispatch_with_unreadable_reader_is_indeterminate(): void {
		$this->order();
		// Dispatch lost; the service's timeout recovery cancels the reader action (idle) and retries once, lost again; the readback fails.
		$provider = $this->provider( array( $this->ok( $this->intent() ), \Stripe\Exception\ApiConnectionException::factory( 'Connection lost' ), $this->error( 'resource_missing' ), \Stripe\Exception\ApiConnectionException::factory( 'Connection lost' ), $this->error( 'resource_missing' ) ) );
		$error    = $provider->create_reader_action( $this->row(), 'tmr_test' );
		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'stripe_dispatch_unanswered', $error->get_error_code() );
		$this->assertTrue( $error->get_error_data()['indeterminate'] );
		$this->assertStringEndsWith( '/terminal/readers/tmr_test', end( $this->http->requests )['url'] );
		$this->assertCount( 5, $this->http->requests );
	}
}
