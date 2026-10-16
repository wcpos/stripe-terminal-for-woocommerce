<?php
/**
 * Tests for the TipReconciler utility class.
 */

namespace WCPOS\WooCommercePOS\StripeTerminal\Tests\Utils;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\StripeTerminal\Utils\TipReconciler;

require_once __DIR__ . '/../../stubs/class-wc-payment-gateway.php';

/**
 * @covers \WCPOS\WooCommercePOS\StripeTerminal\Utils\TipReconciler
 */
class TipReconcilerTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		\Mockery::close();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_tip_adds_fee_and_increases_order_total(): void {
		list( $order, $state ) = $this->tip_order( 10.00 );

		TipReconciler::maybe_add_tip_to_order( $order, $this->payment_intent_with_tip( 150, 'usd', 'pi_tip' ) );

		$this->assertCount( 1, $state->fees );
		$this->assertSame( 'Tip', $state->fees[0]->name );
		$this->assertSame( 1.5, $state->fees[0]->amount );
		$this->assertSame( 1.5, $state->fees[0]->total );
		$this->assertSame( 'none', $state->fees[0]->tax_status );
		$this->assertSame( 11.5, $state->total );
		$this->assertSame( 1.5, $state->meta['_stripe_terminal_tip_amount'] );
		$this->assertSame( 'pi_tip', $state->meta['_stripe_terminal_tip_payment_intent_id'] );
		$this->assertStringContainsString( '1.50 USD', $state->notes[0] );
		$this->assertStringContainsString( 'pi_tip', $state->notes[0] );
	}

	/**
	 * @dataProvider no_tip_provider
	 */
	public function test_zero_missing_or_null_tip_is_a_no_op( $amount_details ): void {
		list( $order, $state ) = $this->tip_order( 10.00 );
		$payment_intent       = (object) array(
			'id'             => 'pi_no_tip',
			'currency'       => 'usd',
			'amount_details' => $amount_details,
		);

		TipReconciler::maybe_add_tip_to_order( $order, $payment_intent );

		$this->assertSame( array(), $state->fees );
		$this->assertSame( 10.0, $state->total );
		$this->assertSame( array(), $state->meta );
		$this->assertSame( 0, $state->save_count );
	}

	public function no_tip_provider(): array {
		return array(
			'zero'    => array( (object) array( 'tip' => (object) array( 'amount' => 0 ) ) ),
			'missing' => array( (object) array() ),
			'null'    => array( (object) array( 'tip' => null ) ),
		);
	}

	public function test_tip_is_added_only_once_for_the_same_payment_intent(): void {
		list( $order, $state ) = $this->tip_order( 10.00 );
		$payment_intent       = $this->payment_intent_with_tip( 150, 'usd', 'pi_duplicate' );

		TipReconciler::maybe_add_tip_to_order( $order, $payment_intent );
		TipReconciler::maybe_add_tip_to_order( $order, $payment_intent );

		$this->assertCount( 1, $state->fees );
		$this->assertSame( 11.5, $state->total );
		$this->assertSame( 1, $state->save_count );
	}

	public function test_tip_uses_zero_decimal_currency_conversion(): void {
		list( $order, $state ) = $this->tip_order( 5000.0 );

		TipReconciler::maybe_add_tip_to_order( $order, $this->payment_intent_with_tip( 1000, 'jpy', 'pi_jpy' ) );

		$this->assertSame( 1000.0, $state->fees[0]->amount );
		$this->assertSame( 1000.0, $state->fees[0]->total );
		$this->assertSame( 6000.0, $state->total );
		// JPY has no fractional unit; the note must not read "1,000.00 JPY".
		$this->assertStringContainsString( '1,000 JPY', $state->notes[0] );
		$this->assertStringNotContainsString( '.00', $state->notes[0] );
	}

	public function test_service_choke_point_reconciles_tip_before_charge_handling(): void {
		list( $order, $state ) = $this->tip_order( 10.00 );
		// A succeeded intent whose charges list is not expanded: the tip must
		// still be reconciled even though the charge-metadata block is skipped.
		$payment_intent = \Stripe\PaymentIntent::constructFrom(
			array(
				'id'             => 'pi_service',
				'currency'       => 'usd',
				'status'         => 'succeeded',
				'amount_details' => array( 'tip' => array( 'amount' => 250 ) ),
				'charges'        => array( 'data' => array() ),
			)
		);
		$service = ( new \ReflectionClass( \WCPOS\WooCommercePOS\StripeTerminal\StripeTerminalService::class ) )->newInstanceWithoutConstructor();

		$service->update_order_from_payment_intent( $order, $payment_intent );

		$this->assertCount( 1, $state->fees );
		$this->assertSame( 2.5, $state->fees[0]->total );
		$this->assertSame( 12.5, $state->total );
	}

	/**
	 * @testWith [false]
	 *           [true]
	 */
	public function test_status_check_reconciles_tip_before_recovered_payment_completes( bool $webhook_race ): void {
		list( $order, $state ) = $this->tip_order( 10.00 );
		$state->meta['_stripe_terminal_payment_intent_id'] = 'pi_recovered_tip';
		$order->shouldReceive( 'get_id' )->andReturn( 42 );
		$order->shouldReceive( 'get_transaction_id' )->andReturn( '' );
		$state->paid = false;
		$order->shouldReceive( 'is_paid' )->andReturnUsing( fn() => $state->paid );
		$order->shouldReceive( 'get_status' )->andReturn( 'pending' );
		$order->shouldReceive( 'delete_meta_data' )->with( '_stripe_terminal_moto' );
		$order->shouldReceive( 'read_meta_data' )->with( true );
		$order->shouldReceive( 'set_transaction_id' )->with( $webhook_race ? 'pi_recovered_tip' : 'ch_recovered_tip' );
		$order->shouldReceive( 'get_payment_method' )->andReturn( 'stripe_terminal_for_woocommerce' );
		$order->shouldReceive( 'get_payment_method_title' )->andReturn( 'Stripe Terminal' );
		$order->shouldReceive( 'payment_complete' )->once()->with( $webhook_race ? 'pi_recovered_tip' : 'ch_recovered_tip' )->andReturnUsing( function () use ( $state ) {
			$state->completed_total = $state->total;
			$state->paid = true;
		} );
		Functions\when( 'apply_filters' )->alias( fn( $hook, $value ) => $value );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'uuid-tip-recovery' );
		Functions\when( 'wc_get_order' )->alias( function () use ( $order, $state ) {
			if ( $state->paid ) {
				$this->assertArrayHasKey( 'stwc_lock_order_42_complete_payment', $GLOBALS['wpdb']->rows );
			}
			return $order;
		} );
		$gateways = \Mockery::mock();
		$gateways->shouldReceive( 'payment_gateways' )->andReturn( array() );
		$woocommerce = \Mockery::mock();
		$woocommerce->shouldReceive( 'payment_gateways' )->andReturn( $gateways );
		Functions\when( 'WC' )->justReturn( $woocommerce );
		$checked_order = $order;
		if ( $webhook_race ) {
			list( $checked_order, $stale ) = $this->tip_order( 10.00 );
			$stale->meta = $state->meta;
			$stale->fees =& $state->fees; // Fees are persisted separately from the stale order metadata.
			$checked_order->shouldReceive( 'get_id' )->andReturn( 42 );
			$checked_order->shouldReceive( 'get_transaction_id' )->andReturn( '' );
			$checked_order->shouldReceive( 'is_paid' )->andReturn( false );
			$checked_order->shouldReceive( 'get_status' )->andReturn( 'pending' );
			$checked_order->shouldReceive( 'delete_meta_data' )->with( '_stripe_terminal_moto' );
		}
		Functions\when( 'clean_post_cache' )->justReturn( null );

		$service = new \WCPOS\WooCommercePOS\StripeTerminal\StripeTerminalService( 'sk_test_status_key' );
		$client  = \Mockery::mock( \Stripe\HttpClient\ClientInterface::class );
		$responses = array(
			array( json_encode( array(
				'id'                   => 'pi_recovered_tip',
				'object'               => 'payment_intent',
				'metadata'             => array( 'order_id' => 42 ),
				'status'               => 'succeeded',
				'amount'               => 1250,
				'currency'             => 'usd',
				'created'              => 1700000000,
				'payment_method_types' => array( 'card_present' ),
				'amount_details'       => array( 'tip' => array( 'amount' => 250 ) ),
			) ), 200, array() ),
			array( json_encode( array(
				'object' => 'list',
				'data'   => array( array(
					'id'       => 'ch_recovered_tip',
					'object'   => 'charge',
					'status'   => 'succeeded',
					'paid'     => true,
					'amount'   => 1250,
					'currency' => 'usd',
					'created'  => 1700000000,
				) ),
			) ), 200, array() )
		);
		$client->shouldReceive( 'request' )->twice()->andReturnUsing( function () use ( &$responses, $webhook_race, $service ) {
			$response = array_shift( $responses );
			if ( $webhook_race && 1 === count( $responses ) ) {
				$handler = new \ReflectionMethod( $service, 'handle_payment_intent_succeeded' );
				$handler->setAccessible( true );
				$handler->invoke( $service, \Stripe\PaymentIntent::constructFrom( json_decode( $response[0], true ) ) );
			}
			return $response;
		} );
		\Stripe\ApiRequestor::setHttpClient( $client );
		$previous_wpdb   = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new \WCPOS\WooCommercePOS\StripeTerminal\Tests\Support\FakeWpdb();
		try {
			$result = $service->check_payment_status_from_stripe( $checked_order );
			$this->assertCount( 1, $state->fees );
			$this->assertSame( ! $webhook_race, $result['metadata_saved'] );
			// Complete through the same boundary used by Gateway::process_payment().
			$this->assertSame( $webhook_race ? 'already_paid' : 'completed', \WCPOS\WooCommercePOS\StripeTerminal\OrderCompletion::complete( $order, $result['charge']['id'] ) );
			$this->assertSame( 12.5, $state->completed_total );
			$this->assertCount( 1, $state->fees );
			$this->assertSame( 'Tip', $state->fees[0]->name );
			$this->assertSame( 2.5, $state->fees[0]->total );
			$this->assertSame( 2.5, $state->meta['_stripe_terminal_tip_amount'] );
			$this->assertSame( 'pi_recovered_tip', $state->meta['_stripe_terminal_tip_payment_intent_id'] );
		} finally {
			$GLOBALS['wpdb'] = $previous_wpdb;
			\Stripe\ApiRequestor::setHttpClient( \Stripe\HttpClient\CurlClient::instance() );
		}
	}

	private function payment_intent_with_tip( int $tip, string $currency, string $id ) {
		return (object) array(
			'id'             => $id,
			'currency'       => $currency,
			'amount_details' => (object) array( 'tip' => (object) array( 'amount' => $tip ) ),
		);
	}

	private function tip_order( float $base_total ): array {
		$state = (object) array(
			'base_total' => $base_total,
			'fees'       => array(),
			'meta'       => array(),
			'notes'      => array(),
			'total'      => $base_total,
			'save_count' => 0,
		);
		$order = \Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_meta' )->andReturnUsing( function ( $key ) use ( $state ) {
			return $state->meta[ $key ] ?? '';
		} );
		$order->shouldReceive( 'add_item' )->andReturnUsing( function ( $fee ) use ( $state ) {
			$state->fees[] = $fee;
		} );
		$order->shouldReceive( 'update_meta_data' )->andReturnUsing( function ( $key, $value ) use ( $state ) {
			$state->meta[ $key ] = $value;
		} );
		$order->shouldReceive( 'calculate_totals' )->with( false )->andReturnUsing( function () use ( $state ) {
			$state->total = $state->base_total + array_sum( array_column( $state->fees, 'total' ) );
		} );
		$order->shouldReceive( 'save' )->andReturnUsing( function () use ( $state ) {
			++$state->save_count;
		} );
		$order->shouldReceive( 'add_order_note' )->andReturnUsing( function ( $note ) use ( $state ) {
			$state->notes[] = $note;
		} );

		return array( $order, $state );
	}
}
