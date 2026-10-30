<?php
/**
 * Pro's refund sweep driving the real Stripe adapter end to end over a scripted Stripe: a refund
 * Stripe leaves pending is read back on the sweep's hook until Stripe settles it.
 *
 * @package WCPOS\WooCommercePOS\StripeTerminal\Tests\Conformance
 */

namespace WCPOS\WooCommercePOS\StripeTerminal\Tests\Conformance;

use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
use WCPOS\WooCommercePOS\Payments\Contract\Payments_Sweeper;
use WCPOS\WooCommercePOS\StripeTerminal\Settings;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;
use WCPOS\WooCommercePOSPro\Payments\Server\Reader_Curation;
use WCPOS\WooCommercePOS\StripeTerminal\Server\Refund_Reask;
use WCPOS\WooCommercePOSPro\Payments\Server\Refund_Sweep;

require_once __DIR__ . '/Stripe_Conformance_Fixture.php';

/**
 * The same wiring as Pro's conformance case (the real gateway, adapter, service and ledger, only
 * Stripe's transport faked), for the sweep lessons the shared case does not carry yet.
 */
class Test_Stripe_Refund_Sweep extends WCPOS_REST_Unit_Test_Case {
	/**
	 * The installed fixture.
	 *
	 * @var Stripe_Conformance_Fixture
	 */
	private $fixture;
	private $logger;
	private $old_logger;

	public function setUp(): void {
		$this->fixture = new Stripe_Conformance_Fixture();
		add_filter( 'wcpos_payment_gateways_settings', array( $this, 'enable_gateway' ) );
		parent::setUp();
		$this->old_logger = wc_get_logger();
		$this->logger     = new \WC_Logger( array( $this->getMockForAbstractClass( \WC_Log_Handler::class ) ), 'debug' );
		add_filter( 'woocommerce_logging_class', array( $this, 'logging_class' ) );
		$this->fixture->install();
		Reader_Curation::forget( Settings::GATEWAY_ID );
		$this->fixture->reset_transcript();
	}

	public function tearDown(): void {
		try {
			$this->fixture->uninstall();
		} finally {
			$this->logger = $this->old_logger;
			wc_get_logger();
			remove_filter( 'woocommerce_logging_class', array( $this, 'logging_class' ) );
			remove_filter( 'wcpos_payment_gateways_settings', array( $this, 'enable_gateway' ) );
			parent::tearDown();
		}
	}

	/** The quiet logger the conformance case uses. */
	public function logging_class() {
		return $this->logger;
	}

	/**
	 * Pro's curation for the gateway under test.
	 *
	 * @param array $settings POS payment gateway settings.
	 */
	public function enable_gateway( array $settings ): array {
		$settings['gateways'][ Settings::GATEWAY_ID ] = array( 'enabled' => true, 'default_reader' => '', 'allowed_readers' => array(), 'lock_to_default' => false );
		return $settings;
	}

	/** A pending refund Stripe later confirms is settled on the order by the sweep, under the mark's own hook. */
	public function test_pending_refund_is_confirmed_by_the_sweep(): void {
		list( $order, $id, $refund ) = $this->pending_refund();
		$this->assertSame( 'pending', $this->allocation( $order, $id )['status'] );
		$this->assertSame( '0.00', $this->stored( $order, $id )['refunded_amount'] );
		$this->assertSame( '0', $this->mark( $order ), 'The pending allocation marks the order for the sweep' );

		$this->fixture->transport->settle_refunds( 'succeeded' );
		do_action( Payments_Sweeper::HOOK );

		$allocation = $this->allocation( $order, $id );
		$this->assertSame( 'succeeded', $allocation['status'] );
		$this->assertSame( $this->first_refund_id(), $allocation['provider_ref'] );
		$this->assertSame( '5.00', $this->stored( $order, $id )['refunded_amount'] );
		$this->assertSame( '', $this->mark( $order ), 'A settled order leaves the sweep\'s candidate set' );
		$this->assertStringContainsString( 'confirmed by the provider (reference ' . $allocation['provider_ref'] . ')', $this->last_note( $order ) );
		$reads = $this->calls( 'fetch_refund' );
		$this->assertCount( 1, $reads );
		$this->assertStringContainsString( 'refund=by_id mode=test answer=succeeded', $reads[0]['request'] );
		$this->assertSame( array( 'get', '/v1/refunds/' . $allocation['provider_ref'] ), $this->last_stripe_request() );
	}

	/** A refund Stripe still holds stays pending, and the mark carries the time of the look. */
	public function test_refund_still_pending_keeps_the_mark(): void {
		list( $order, $id ) = $this->pending_refund();

		do_action( Payments_Sweeper::HOOK );

		$this->assertSame( 'pending', $this->allocation( $order, $id )['status'] );
		$this->assertSame( '0.00', $this->stored( $order, $id )['refunded_amount'] );
		$this->assertGreaterThan( 0, (int) $this->mark( $order ), 'The mark now carries the time of the last look' );
		$this->assertCount( 1, $this->calls( 'fetch_refund' ) );

		$this->fixture->transport->settle_refunds( 'succeeded' );
		do_action( Payments_Sweeper::HOOK );

		$this->assertSame( 'succeeded', $this->allocation( $order, $id )['status'] );
		$this->assertSame( '', $this->mark( $order ) );
		$this->assertCount( 2, $this->calls( 'fetch_refund' ) );
	}

	/** A refund Stripe rejects keeps its record and its money on the order, and staff are told not to refund again. */
	public function test_rejected_refund_is_recorded_as_failed_by_the_sweep(): void {
		list( $order, $id, $refund ) = $this->pending_refund();

		$this->fixture->transport->settle_refunds( 'failed' );
		do_action( Payments_Sweeper::HOOK );

		$this->assertSame( 'failed', $this->allocation( $order, $id )['status'] );
		$this->assertSame( '0.00', $this->stored( $order, $id )['refunded_amount'] );
		$this->assertSame( '', $this->mark( $order ) );
		$this->assertInstanceOf( \WC_Order_Refund::class, wc_get_order( $refund->get_id() ), 'The WooCommerce refund record stands' );
		$this->assertStringContainsString( 'rejected by the provider', $this->last_note( $order ) );
		$this->assertStringContainsString( 'not with a second refund', $this->last_note( $order ) );
	}

	/** A refund create Stripe took but answered without the refund is kept as pending with no reference, found by the sweep through the metadata the create carried, and settled. */
	public function test_refund_answered_without_its_id_is_found_by_metadata(): void {
		$this->fixture->transport->lose_next_refund_body();
		list( $order, $id, $refund ) = $this->pending_refund();
		$allocation                  = $this->allocation( $order, $id );
		$this->assertSame( 'pending', $allocation['status'] );
		$this->assertEmpty( $allocation['provider_ref'], 'No reference: Stripe answered the create without the refund' );
		$this->assertSame( '0', $this->mark( $order ) );

		$this->fixture->transport->settle_refunds( 'succeeded' );
		do_action( Payments_Sweeper::HOOK );

		$allocation = $this->allocation( $order, $id );
		$this->assertSame( 'succeeded', $allocation['status'] );
		$this->assertSame( $this->first_refund_id(), $allocation['provider_ref'], 'The sweep wrote the reference it found' );
		$this->assertSame( '5.00', $this->stored( $order, $id )['refunded_amount'] );
		$this->assertSame( '', $this->mark( $order ) );
		$reads = $this->calls( 'fetch_refund' );
		$this->assertCount( 1, $reads );
		$this->assertStringContainsString( 'refund=by_metadata mode=test answer=succeeded', $reads[0]['request'] );
		$this->assertSame( array( 'get', '/v1/refunds' ), $this->last_stripe_request() );
		$listed = end( $this->fixture->transport->raw );
		$this->assertSame( $this->payment_intent( $order, $id ), $listed['params']['payment_intent'] );
		$this->assertSame( (string) $refund->get_id(), $this->refund_metadata( $order, $id )['wcpos_refund_id'] );
	}

	/**
	 * Neither a newer refund made from the Stripe dashboard on the same sale (no metadata) nor a
	 * newer refund of the till's own, for another record on the same row, is bound to the till's
	 * reference-less allocation: only the one carrying this allocation's own record id is.
	 */
	public function test_metadata_match_ignores_a_refund_the_till_did_not_make(): void {
		// The reference-less refund comes FIRST, so every other refund of the sale is newer and is
		// listed ahead of it: a match that takes the first listed, or that checks the row alone,
		// would bind the wrong one.
		$this->fixture->transport->lose_next_refund_body();
		list( $order, $id, $first ) = $this->pending_refund();
		$this->assertEmpty( $this->allocation( $order, $id )['provider_ref'] );
		$second = wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => '2.00', 'refund_payment' => false ) );
		$this->assertNotWPError( $second );
		$this->assertNotWPError( Ledger::instance()->refund( wc_get_order( $order->get_id() ), $id, $second->get_id(), '2.00' ) );
		$dashboard = $this->fixture->transport->dashboard_refund( $this->payment_intent( $order, $id ), 300 );
		$this->fixture->transport->settle_refunds( 'succeeded' );

		do_action( Payments_Sweeper::HOOK );

		$ids         = $this->fixture->transport->refund_ids();
		$allocations = array_column( $this->stored( $order, $id )['refunds'], null, 'id' );
		$this->assertSame( 'succeeded', $allocations[ $first->get_id() ]['status'] );
		$this->assertSame( $ids[0], $allocations[ $first->get_id() ]['provider_ref'], 'The reference-less allocation took the refund carrying its own record id, the oldest of three' );
		$this->assertSame( $ids[1], $allocations[ $second->get_id() ]['provider_ref'], 'The sibling allocation kept its own refund' );
		$this->assertNotSame( $dashboard, $allocations[ $first->get_id() ]['provider_ref'] );
		$this->assertSame( '7.00', $this->stored( $order, $id )['refunded_amount'], 'The dashboard refund is not the till\'s to record' );
	}

	/** A refund made in test mode is still read under the test key after the gateway is switched to live, by an adapter built after the switch (the sweep runs in its own request). */
	public function test_mode_switch_does_not_strand_a_pending_refund(): void {
		list( $order, $id ) = $this->pending_refund();
		$settings              = get_option( 'woocommerce_' . Settings::GATEWAY_ID . '_settings' );
		$settings['test_mode'] = 'no';
		update_option( 'woocommerce_' . Settings::GATEWAY_ID . '_settings', $settings );
		$this->rebuild_adapter();

		$this->fixture->transport->settle_refunds( 'succeeded' );
		do_action( Payments_Sweeper::HOOK );

		$this->assertSame( 'succeeded', $this->allocation( $order, $id )['status'] );
		$reads = $this->calls( 'fetch_refund' );
		$this->assertCount( 1, $reads );
		$this->assertStringContainsString( 'mode=test answer=succeeded', $reads[0]['request'], 'Read under the recorded test key while the gateway sits in live mode' );
	}

	/** The connection dropped after Stripe took the refund: the record stands as pending, the same request is re-sent under the same key, Stripe replays the one refund it holds, and the sweep settles it. */
	public function test_connection_lost_after_stripe_took_the_refund_is_recovered(): void {
		$this->fixture->transport->lose_next_refund_answer( 'taken' );
		list( $order, $id, $refund ) = $this->pending_refund();
		$this->assertEmpty( $this->allocation( $order, $id )['provider_ref'] );
		$this->assertSame( '0', $this->mark( $order ) );
		$this->assertCount( 1, $this->fixture->transport->refund_ids(), 'Stripe holds the refund the till never heard about' );

		$this->run_scheduled_reask( $refund );

		$this->assertCount( 1, $this->fixture->transport->refund_ids(), 'The replay under the same key made nothing new' );
		$this->assertNull( $this->scheduled_reask( $refund ), 'Stripe replayed its answer, so nothing more is queued' );
		$this->fixture->transport->settle_refunds( 'succeeded' );
		do_action( Payments_Sweeper::HOOK );

		$allocation = $this->allocation( $order, $id );
		$this->assertSame( 'succeeded', $allocation['status'] );
		$this->assertSame( $this->first_refund_id(), $allocation['provider_ref'] );
		$this->assertSame( '5.00', $this->stored( $order, $id )['refunded_amount'] );
		$this->assertSame( '', $this->mark( $order ) );
	}

	/** The connection dropped before the request arrived: nothing at Stripe, so the re-send makes the refund, and the sweep settles it. */
	public function test_connection_lost_before_stripe_got_the_refund_is_recovered(): void {
		$this->fixture->transport->lose_next_refund_answer( 'never' );
		list( $order, $id, $refund ) = $this->pending_refund();
		$this->assertEmpty( $this->allocation( $order, $id )['provider_ref'] );
		$this->assertCount( 0, $this->fixture->transport->refund_ids(), 'Stripe holds nothing' );

		$this->run_scheduled_reask( $refund );

		$this->assertCount( 1, $this->fixture->transport->refund_ids(), 'The re-send made the refund' );
		$this->assertNull( $this->scheduled_reask( $refund ), 'Stripe answered, so nothing more is queued' );
		$this->fixture->transport->settle_refunds( 'succeeded' );
		do_action( Payments_Sweeper::HOOK );

		$this->assertSame( 'succeeded', $this->allocation( $order, $id )['status'] );
		$this->assertSame( $this->first_refund_id(), $this->allocation( $order, $id )['provider_ref'] );
		$this->assertSame( '5.00', $this->stored( $order, $id )['refunded_amount'] );
	}

	/** Stripe refuses the re-sent create (the sale was refunded in full from the dashboard meanwhile): the refund was never made, the sweep records the allocation failed, and staff read Stripe's reason. */
	public function test_refused_replay_fails_the_allocation(): void {
		$this->fixture->transport->lose_next_refund_answer( 'never' );
		list( $order, $id, $refund ) = $this->pending_refund();
		$this->fixture->transport->dashboard_refund( $this->payment_intent( $order, $id ), 9295 );

		$this->run_scheduled_reask( $refund );

		$this->assertSame( 'The refund amount exceeds what is left of the charge.', wc_get_order( $refund->get_id() )->get_meta( Refund_Reask::REFUSED_META ) );
		$this->assertStringContainsString( 'Stripe refused the POS refund of 5.00 EUR when it was sent again', $this->last_note( $order ) );
		$this->assertStringNotContainsString( 'not made', $this->last_note( $order ), 'Only the sweep, after Stripe\'s list, says whether it was made' );
		do_action( Payments_Sweeper::HOOK );

		$this->assertSame( 'failed', $this->allocation( $order, $id )['status'] );
		$this->assertSame( '0.00', $this->stored( $order, $id )['refunded_amount'] );
		$this->assertSame( '', $this->mark( $order ) );
		$this->assertStringContainsString( 'rejected by the provider', $this->last_note( $order ) );
		$this->assertInstanceOf( \WC_Order_Refund::class, wc_get_order( $refund->get_id() ), 'The WooCommerce refund record stands' );
	}

	// ----------------------------------------------------------------------------------------------

	/**
	 * The re-send WP cron holds for this refund, as timestamp and event, or null.
	 *
	 * @param \WC_Order_Refund $refund The refund record.
	 */
	private function scheduled_reask( \WC_Order_Refund $refund ): ?array {
		$found = null;
		foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
			foreach ( (array) ( $hooks[ Refund_Reask::HOOK ] ?? array() ) as $event ) {
				if ( (int) $event['args'][0]['refund_id'] === $refund->get_id() ) {
					$found = array( $timestamp, $event );
				}
			}
		}
		return $found;
	}

	/**
	 * Run the one re-send WP cron holds for this refund, through its registered hook, and take it off
	 * the schedule the way cron would.
	 *
	 * @param \WC_Order_Refund $refund The refund record.
	 */
	private function run_scheduled_reask( \WC_Order_Refund $refund ): void {
		$found = $this->scheduled_reask( $refund );
		$this->assertNotNull( $found, 'A re-send was scheduled' );
		$this->assertEqualsWithDelta( time() + Refund_Reask::DELAYS[0], $found[0], 30 );
		$this->assertSame( 0, $found[1]['args'][0]['attempt'] );
		wp_unschedule_event( $found[0], Refund_Reask::HOOK, $found[1]['args'] );
		do_action_ref_array( Refund_Reask::HOOK, $found[1]['args'] );
	}


	/**
	 * A captured Stripe sale with a 5.00 refund Stripe answered `pending`.
	 *
	 * @return array{0: \WC_Order, 1: string, 2: \WC_Order_Refund}
	 */
	private function pending_refund(): array {
		$this->fixture->script( 'refund_pending' );
		$order = wc_create_order();
		$order->set_created_via( 'woocommerce-pos' );
		$order->set_status( 'pos-open' );
		$order->set_currency( 'EUR' );
		$order->set_total( '92.95' );
		$order->save();
		$readers = Reader_Curation::curated( Settings::GATEWAY_ID );
		$online  = array_values( array_filter( $readers, static fn( $r ) => 'online' === $r['status'] ) );
		$this->assertNotEmpty( $online );
		$result = Ledger::instance()->intent( $order, wp_generate_uuid4(), array( 'method_id' => Settings::GATEWAY_ID, 'amount' => '92.95', 'currency' => 'EUR' ), array( 'reader' => $online[0]['id'] ) );
		$this->assertNotWPError( $result );
		$id = $result['payment']['id'];
		$this->assertSame( 'captured', Ledger::instance()->status( $order, $id )['status'] );
		$refund = wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => '5.00', 'refund_payment' => false ) );
		$this->assertNotWPError( $refund );
		$result = Ledger::instance()->refund( wc_get_order( $order->get_id() ), $id, $refund->get_id(), '5.00' );
		$this->assertNotWPError( $result );
		$this->assertSame( 'pending', $result['refunds'][0]['status'] );
		return array( $order, $id, $refund );
	}

	/** The sweep runs in its own request, where Pro builds the adapter afresh with the gateway's current key. */
	private function rebuild_adapter(): void {
		$instances = new \ReflectionProperty( \WCPOS\WooCommercePOSPro\Payments\Server\Server_Providers::class, 'instances' );
		$instances->setAccessible( true );
		$instances->setValue( \WCPOS\WooCommercePOSPro\Payments\Server\Server_Providers::instance(), array() );
	}

	private function stored( \WC_Order $order, string $id ): array {
		return Ledger::instance()->find( wc_get_order( $order->get_id() ), $id );
	}

	private function allocation( \WC_Order $order, string $id ): array {
		$allocations = $this->stored( $order, $id )['refunds'] ?? array();
		$this->assertNotEmpty( $allocations );
		return $allocations[0];
	}

	private function mark( \WC_Order $order ): string {
		return (string) wc_get_order( $order->get_id() )->get_meta( Refund_Sweep::META_KEY );
	}

	private function last_note( \WC_Order $order ): string {
		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id(), 'limit' => 1 ) );
		$this->assertNotEmpty( $notes );
		return (string) $notes[0]->content;
	}

	private function calls( string $op ): array {
		return array_values( array_filter( $this->fixture->transcript(), static fn( $call ) => $op === $call['op'] ) );
	}

	/** Method and path of the most recent request the SDK sent the fake. */
	private function last_stripe_request(): array {
		$last = end( $this->fixture->transport->raw );
		return array( $last['method'], $last['path'] );
	}

	private function payment_intent( \WC_Order $order, string $id ): string {
		return (string) $this->stored( $order, $id )['provider_refs']['action'];
	}

	/** The first refund the fake holds: the sale's own, in the cases that make one. */
	private function first_refund_id(): string {
		$ids = $this->fixture->transport->refund_ids();
		$this->assertNotEmpty( $ids, 'No refund was created' );
		return $ids[0];
	}

	private function refund_metadata( \WC_Order $order, string $id ): array {
		foreach ( $this->fixture->transport->raw as $request ) {
			if ( 'post' === $request['method'] && '/v1/refunds' === $request['path'] ) {
				return $request['params']['metadata'];
			}
		}
		$this->fail( 'No refund was created' );
	}
}
