<?php
namespace WCPOS\WooCommercePOS\StripeTerminal\Tests\Server;
require_once __DIR__ . '/ServerTestCase.php';

/** Pro's refund sweep reads a refund `refund()` answered `pending` back through `fetch_refund()`. */
class StripeServerProviderFetchRefundTest extends ServerTestCase {
	/**
	 * A pending allocation as Pro's sweep hands it over.
	 *
	 * @param string $ref The refund id `refund()` returned.
	 */
	private function allocation( string $ref = 're_test' ): array {
		return array( 'id' => 123, 'amount' => '2.50', 'status' => 'pending', 'provider_ref' => $ref );
	}

	/**
	 * A Stripe refund object.
	 *
	 * @param array $overrides Fields to replace.
	 */
	private function refund( array $overrides = array() ): array {
		return array_replace( array( 'id' => 're_test', 'object' => 'refund', 'status' => 'pending', 'payment_intent' => 'pi_test', 'charge' => 'ch_test', 'metadata' => array( 'wcpos_payment_id' => self::PAYMENT_ID, 'wcpos_refund_id' => '123' ) ), $overrides );
	}

	/**
	 * A Stripe list answer.
	 *
	 * @param array $refunds Refund objects.
	 */
	private function listing( array $refunds ): array {
		return $this->ok( array( 'object' => 'list', 'data' => $refunds, 'has_more' => false ) );
	}

	/**
	 * A historical webview row naming the charge (or another non-intent object) the old panel stored.
	 *
	 * @param string $transaction_id The order's transaction id.
	 */
	private function charge_row( string $transaction_id = 'ch_test' ): array {
		return array( 'id' => self::PAYMENT_ID, 'order_id' => 42, 'amount' => '12.50', 'currency' => 'CAD', 'provider_refs' => array( 'transaction_id' => $transaction_id ) );
	}

	/** @dataProvider statuses */
	public function test_reads_the_refund_by_its_id( string $stripe_status, string $expected ): void {
		$provider = $this->provider( array( $this->ok( $this->refund( array( 'status' => $stripe_status ) ) ) ) );
		$this->assertSame( array( 'status' => $expected, 'provider_ref' => 're_test' ), $provider->fetch_refund( $this->row(), $this->allocation() ) );
		$this->assertCount( 1, $this->http->requests );
		$this->assertSame( 'get', $this->http->requests[0]['method'] );
		$this->assertStringEndsWith( '/refunds/re_test', $this->http->requests[0]['url'] );
		$this->assertContains( 'Authorization: Bearer sk_test_fake', $this->http->requests[0]['headers'] );
	}

	public function statuses(): array {
		return array( array( 'succeeded', 'succeeded' ), array( 'pending', 'pending' ), array( 'requires_action', 'pending' ), array( 'failed', 'failed' ), array( 'canceled', 'failed' ), array( 'something_new', 'pending' ) );
	}

	/** A Stripe 5xx is no answer: the sweep keeps the mark and asks again. */
	public function test_server_error_is_indeterminate(): void {
		$provider = $this->provider( array( array( 'body' => array( 'error' => array( 'type' => 'api_error', 'message' => 'Stripe is down' ) ), 'status' => 500 ) ) );
		$this->assert_indeterminate( $provider->fetch_refund( $this->row(), $this->allocation() ) );
	}

	/** A dropped connection is no answer either. */
	public function test_transport_failure_is_indeterminate(): void {
		$provider = $this->provider( array( new \Stripe\Exception\ApiConnectionException( 'timed out' ) ) );
		$this->assert_indeterminate( $provider->fetch_refund( $this->row(), $this->allocation() ) );
	}

	/** A refund Stripe cannot show under this key proves nothing about the refund: an error, not `failed`. */
	public function test_refused_read_keeps_the_question_open(): void {
		$provider = $this->provider( array( array( 'body' => array( 'error' => array( 'type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => 'No such refund' ) ), 'status' => 404 ) ) );
		$this->assert_provider_error( $provider->fetch_refund( $this->row(), $this->allocation() ), 'resource_missing' );
	}

	/** A 200 without the refund in it, or without its state, is no answer. */
	public function test_success_without_the_refund_is_indeterminate(): void {
		$provider = $this->provider( array( $this->ok( array( 'object' => 'refund' ) ) ) );
		$this->assert_indeterminate( $provider->fetch_refund( $this->row(), $this->allocation() ) );
		$provider = $this->provider( array( $this->ok( $this->refund( array( 'status' => '' ) ) ) ) );
		$this->assert_indeterminate( $provider->fetch_refund( $this->row(), $this->allocation() ) );
	}

	/** A reference bound to another sale settles nothing on this row. */
	public function test_refund_of_another_payment_is_refused(): void {
		$provider = $this->provider( array( $this->ok( $this->refund( array( 'status' => 'succeeded', 'payment_intent' => 'pi_other' ) ) ) ) );
		$this->assert_provider_error( $provider->fetch_refund( $this->row(), $this->allocation() ), 'refund_payment_mismatch' );
	}

	/** A refund that names no payment at all cannot be this row's (a refund of an intent or charge always names it). */
	public function test_refund_without_a_payment_is_refused(): void {
		$refund = $this->refund( array( 'status' => 'succeeded' ) );
		unset( $refund['payment_intent'] );
		$provider = $this->provider( array( $this->ok( $refund ) ) );
		$this->assert_provider_error( $provider->fetch_refund( $this->row(), $this->allocation() ), 'refund_payment_mismatch' );
		$refund['payment_intent'] = null;
		$provider                 = $this->provider( array( $this->ok( $refund ) ) );
		$this->assert_provider_error( $provider->fetch_refund( $this->row(), $this->allocation() ), 'refund_payment_mismatch' );
	}

	/** A historical row names the charge; the create keyed the refund on it, so the refund's charge is what must match. */
	public function test_charge_reference_matches_on_the_charge(): void {
		$provider = $this->provider( array( $this->ok( $this->refund( array( 'status' => 'succeeded', 'payment_intent' => 'pi_whatever' ) ) ) ) );
		$this->assertSame( 'succeeded', $provider->fetch_refund( $this->charge_row(), $this->allocation() )['status'] );
		$provider = $this->provider( array( $this->ok( $this->refund( array( 'status' => 'succeeded', 'charge' => 'ch_other' ) ) ) ) );
		$this->assert_provider_error( $provider->fetch_refund( $this->charge_row(), $this->allocation() ), 'refund_payment_mismatch' );
	}

	/** Any reference that is not an intent was sent as the charge (a `py_` bank-debit object, say), so it matches on the charge too. */
	public function test_other_charge_objects_match_on_the_charge(): void {
		$provider = $this->provider( array( $this->ok( $this->refund( array( 'status' => 'succeeded', 'charge' => 'py_test', 'payment_intent' => 'pi_test' ) ) ) ) );
		$this->assertSame( 'succeeded', $provider->fetch_refund( $this->charge_row( 'py_test' ), $this->allocation() )['status'] );
	}

	/** A row restored without its refs is read by the reference alone. */
	public function test_row_without_payment_reference_is_read_by_the_refund_id(): void {
		$row                  = $this->row();
		$row['provider_refs'] = array();
		$provider             = $this->provider( array( $this->ok( $this->refund( array( 'status' => 'succeeded' ) ) ) ) );
		$this->assertSame( 'succeeded', $provider->fetch_refund( $row, $this->allocation() )['status'] );
	}

	/** A device row that carries only Pro's `payment_intent` is listed under it. */
	public function test_no_reference_on_a_device_row_lists_by_pros_payment_intent(): void {
		$row                  = $this->row();
		$row['capture_mode']  = 'device';
		$row['provider_refs'] = array( 'payment_intent' => 'pi_device' );
		$mine                 = $this->refund( array( 'id' => 're_mine', 'status' => 'succeeded', 'payment_intent' => 'pi_device' ) );
		$provider             = $this->provider( array( $this->listing( array( $mine ) ) ) );
		$this->assertSame( array( 'status' => 'succeeded', 'provider_ref' => 're_mine' ), $provider->fetch_refund( $row, $this->allocation( '' ) ) );
		$this->assertSame( array( 'payment_intent' => 'pi_device', 'limit' => 100 ), $this->http->requests[0]['params'] );
	}

	/** Neither a refund id nor a payment to look under: nothing is read and nothing is guessed. */
	public function test_no_reference_and_no_payment_is_not_read(): void {
		$row                  = $this->row();
		$row['provider_refs'] = array();
		$provider             = $this->provider();
		$this->assert_provider_error( $provider->fetch_refund( $row, $this->allocation( '' ) ), 'missing_refund_ref' );
		$this->assertCount( 0, $this->http->requests );
	}

	/** The re-sent create was refused under the same key, and Stripe's list shows nothing of this allocation's: never made, so the allocation is reported failed. */
	public function test_refused_replay_with_nothing_listed_is_reported_failed(): void {
		$refund = \Mockery::mock( 'WC_Order_Refund' );
		$refund->shouldReceive( 'get_meta' )->with( \WCPOS\WooCommercePOS\StripeTerminal\Server\Refund_Reask::REFUSED_META )->andReturn( 'Charge already refunded' );
		$this->orders = array( 123 => $refund );
		$provider     = $this->provider( array( $this->listing( array() ) ) );
		$this->assertSame( array( 'status' => 'failed', 'provider_ref' => null ), $provider->fetch_refund( $this->row(), $this->allocation( '' ) ) );
		$this->assertCount( 1, $this->http->requests, 'The list is Stripe\'s word; the mark alone is not' );
	}

	/** A refused mark the list contradicts (the refund IS there under this allocation's metadata) is not believed: the refund is reported as Stripe has it. */
	public function test_refused_mark_is_overruled_by_a_listed_refund(): void {
		$refund = \Mockery::mock( 'WC_Order_Refund' );
		$refund->shouldReceive( 'get_meta' )->with( \WCPOS\WooCommercePOS\StripeTerminal\Server\Refund_Reask::REFUSED_META )->andReturn( 'Invalid API Key provided' );
		$this->orders = array( 123 => $refund );
		$provider     = $this->provider( array( $this->listing( array( $this->refund( array( 'id' => 're_mine', 'status' => 'succeeded' ) ) ) ) ) );
		$this->assertSame( array( 'status' => 'succeeded', 'provider_ref' => 're_mine' ), $provider->fetch_refund( $this->row(), $this->allocation( '' ) ) );
	}

	/** A refused mark with a list Stripe did not answer, or refused (a key that now belongs to another account): the mark alone is not believed, and the question stays open. */
	public function test_refused_mark_with_an_unanswered_or_refused_list_stays_open(): void {
		$refund = \Mockery::mock( 'WC_Order_Refund' );
		$refund->shouldReceive( 'get_meta' )->andReturn( 'No such payment_intent' );
		$this->orders = array( 123 => $refund );
		$provider     = $this->provider( array( array( 'body' => array( 'error' => array( 'type' => 'api_error', 'message' => 'Stripe is down' ) ), 'status' => 500 ) ) );
		$this->assert_indeterminate( $provider->fetch_refund( $this->row(), $this->allocation( '' ) ) );
		$provider = $this->provider( array( array( 'body' => array( 'error' => array( 'type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => 'No such payment_intent' ) ), 'status' => 404 ) ) );
		$this->assert_provider_error( $provider->fetch_refund( $this->row(), $this->allocation( '' ) ), 'resource_missing' );
	}

	/** A refund carrying this allocation's metadata but for another amount is not the one the till asked for. */
	public function test_metadata_match_for_another_amount_is_refused(): void {
		$provider = $this->provider( array( $this->listing( array( $this->refund( array( 'id' => 're_odd', 'status' => 'succeeded', 'amount' => 999 ) ) ) ) ) );
		$this->assert_provider_error( $provider->fetch_refund( $this->row(), $this->allocation( '' ) ), 'refund_amount_mismatch' );
		$provider = $this->provider( array( $this->listing( array( $this->refund( array( 'id' => 're_mine', 'status' => 'succeeded', 'amount' => 250 ) ) ) ) ) );
		$this->assertSame( 're_mine', $provider->fetch_refund( $this->row(), $this->allocation( '' ) )['provider_ref'] );
	}

	/** A refused mark with no key to look under, or no payment to look under: the record's word stands. */
	public function test_refused_mark_without_a_way_to_look_is_reported_failed(): void {
		$refund = \Mockery::mock( 'WC_Order_Refund' );
		$refund->shouldReceive( 'get_meta' )->andReturn( 'Charge already refunded' );
		$this->orders = array( 123 => $refund );
		$row                                 = $this->row();
		$row['provider_refs']['stripe_mode'] = 'live';
		$provider                            = $this->provider();
		$this->assertSame( array( 'status' => 'failed', 'provider_ref' => null ), $provider->fetch_refund( $row, $this->allocation( '' ) ), 'No live key configured' );
		$row['provider_refs'] = array();
		$this->assertSame( array( 'status' => 'failed', 'provider_ref' => null ), $provider->fetch_refund( $row, $this->allocation( '' ) ), 'No payment to list under' );
		$this->assertCount( 0, $this->http->requests );
	}

	/** An allocation that holds a reference is read by it, whatever the record says: the reference is Stripe's own word. */
	public function test_refused_mark_does_not_override_a_reference(): void {
		$refund = \Mockery::mock( 'WC_Order_Refund' );
		$refund->shouldReceive( 'get_meta' )->andReturn( 'Charge already refunded' );
		$this->orders = array( 123 => $refund );
		$provider     = $this->provider( array( $this->ok( $this->refund( array( 'status' => 'succeeded' ) ) ) ) );
		$this->assertSame( 'succeeded', $provider->fetch_refund( $this->row(), $this->allocation() )['status'] );
	}

	/** A refund whose create was answered without its id is found among the payment's refunds by the metadata the create carried. */
	public function test_no_reference_is_found_by_metadata(): void {
		$other    = $this->refund( array( 'id' => 're_other', 'status' => 'succeeded', 'metadata' => array( 'wcpos_payment_id' => self::PAYMENT_ID, 'wcpos_refund_id' => '99' ) ) );
		$foreign  = $this->refund( array( 'id' => 're_foreign', 'status' => 'succeeded', 'metadata' => array( 'wcpos_payment_id' => 'B1234567-1234-4123-8123-123456789ABC', 'wcpos_refund_id' => '123' ) ) );
		$bare     = $this->refund( array( 'id' => 're_dashboard', 'status' => 'succeeded', 'metadata' => array() ) );
		$half     = $this->refund( array( 'id' => 're_half', 'status' => 'succeeded', 'metadata' => array( 'wcpos_refund_id' => '123' ) ) );
		$mine     = $this->refund( array( 'id' => 're_mine', 'status' => 'succeeded' ) );
		$provider = $this->provider( array( $this->listing( array( $bare, $half, $other, $foreign, $mine ) ) ) );
		$this->assertSame( array( 'status' => 'succeeded', 'provider_ref' => 're_mine' ), $provider->fetch_refund( $this->row(), $this->allocation( '' ) ) );
		$this->assertCount( 1, $this->http->requests );
		$this->assertSame( 'get', $this->http->requests[0]['method'] );
		$this->assertStringEndsWith( '/refunds', $this->http->requests[0]['url'] );
		$this->assertSame( array( 'payment_intent' => 'pi_test', 'limit' => 100 ), $this->http->requests[0]['params'] );
	}

	/** A payment with more refunds than one page holds is read page by page until the refund shows, or the pages end. */
	public function test_no_reference_follows_later_pages(): void {
		$others = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$others[] = $this->refund( array( 'id' => 're_other_' . $i, 'status' => 'succeeded', 'metadata' => array( 'wcpos_payment_id' => self::PAYMENT_ID, 'wcpos_refund_id' => (string) ( 900 + $i ) ) ) );
		}
		$first    = $this->ok( array( 'object' => 'list', 'data' => $others, 'has_more' => true ) );
		$second   = $this->listing( array( $this->refund( array( 'id' => 're_mine', 'status' => 'succeeded' ) ) ) );
		$provider = $this->provider( array( $first, $second ) );
		$this->assertSame( array( 'status' => 'succeeded', 'provider_ref' => 're_mine' ), $provider->fetch_refund( $this->row(), $this->allocation( '' ) ) );
		$this->assertCount( 2, $this->http->requests );
		$this->assertSame( 're_other_2', $this->http->requests[1]['params']['starting_after'] );

		$pages = array();
		for ( $i = 0; $i < \WCPOS\WooCommercePOS\StripeTerminal\StripeTerminalService::REFUND_PAGES_MAX + 1; $i++ ) {
			$pages[] = $this->ok( array( 'object' => 'list', 'data' => $others, 'has_more' => true ) );
		}
		$provider = $this->provider( $pages );
		$this->assertSame( array( 'status' => 'pending', 'provider_ref' => null ), $provider->fetch_refund( $this->row(), $this->allocation( '' ) ) );
		$this->assertCount( \WCPOS\WooCommercePOS\StripeTerminal\StripeTerminalService::REFUND_PAGES_MAX, $this->http->requests );
	}

	/** The refund on the first page is answered from it: no later page is asked for, so a later page's failure cannot lose it. */
	public function test_no_reference_stops_at_the_page_that_holds_the_refund(): void {
		$first    = $this->ok( array( 'object' => 'list', 'data' => array( $this->refund( array( 'id' => 're_mine', 'status' => 'succeeded' ) ) ), 'has_more' => true ) );
		$provider = $this->provider( array( $first, array( 'body' => array( 'error' => array( 'type' => 'api_error', 'message' => 'Stripe is down' ) ), 'status' => 500 ) ) );
		$this->assertSame( array( 'status' => 'succeeded', 'provider_ref' => 're_mine' ), $provider->fetch_refund( $this->row(), $this->allocation( '' ) ) );
		$this->assertCount( 1, $this->http->requests );
	}

	/** The payment's refunds are listed under the charge when the row names one. */
	public function test_no_reference_on_a_charge_row_lists_by_charge(): void {
		$provider = $this->provider( array( $this->listing( array( $this->refund( array( 'status' => 'pending' ) ) ) ) ) );
		$this->assertSame( array( 'status' => 'pending', 'provider_ref' => 're_test' ), $provider->fetch_refund( $this->charge_row(), $this->allocation( '' ) ) );
		$this->assertSame( array( 'charge' => 'ch_test', 'limit' => 100 ), $this->http->requests[0]['params'] );
	}

	/** Until the refund shows among the payment's, the question stays open and the sweep looks again. */
	public function test_no_reference_and_no_match_stays_pending(): void {
		$provider = $this->provider( array( $this->listing( array( $this->refund( array( 'id' => 're_other', 'status' => 'succeeded', 'metadata' => array( 'wcpos_payment_id' => self::PAYMENT_ID, 'wcpos_refund_id' => '99' ) ) ) ) ) ) );
		$this->assertSame( array( 'status' => 'pending', 'provider_ref' => null ), $provider->fetch_refund( $this->row(), $this->allocation( '' ) ) );
		$provider = $this->provider( array( $this->listing( array() ) ) );
		$this->assertSame( array( 'status' => 'pending', 'provider_ref' => null ), $provider->fetch_refund( $this->row(), $this->allocation( '' ) ) );
	}

	/** A listing Stripe did not answer, or refused, is handled like a single read. */
	public function test_no_reference_listing_errors(): void {
		$provider = $this->provider( array( array( 'body' => array( 'error' => array( 'type' => 'api_error', 'message' => 'Stripe is down' ) ), 'status' => 500 ) ) );
		$this->assert_indeterminate( $provider->fetch_refund( $this->row(), $this->allocation( '' ) ) );
		$provider = $this->provider( array( array( 'body' => array( 'error' => array( 'type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => 'No such payment_intent' ) ), 'status' => 404 ) ) );
		$this->assert_provider_error( $provider->fetch_refund( $this->row(), $this->allocation( '' ) ), 'resource_missing' );
	}

	/** A payment made in live mode is read with the live key while the gateway sits in test mode, and the other way round. */
	public function test_reads_under_the_key_of_the_recorded_mode(): void {
		$this->options['woocommerce_stripe_terminal_for_woocommerce_settings']['test_secret_key'] = 'sk_test_fake';
		$this->options['woocommerce_stripe_terminal_for_woocommerce_settings']['secret_key']      = 'sk_live_fake';
		$row                                 = $this->row();
		$row['provider_refs']['stripe_mode'] = 'live';
		$provider                            = $this->provider( array( $this->ok( $this->refund( array( 'status' => 'succeeded' ) ) ) ) );
		$this->assertSame( 'succeeded', $provider->fetch_refund( $row, $this->allocation() )['status'] );
		$this->assertContains( 'Authorization: Bearer sk_live_fake', $this->http->requests[0]['headers'] );

		$this->options['woocommerce_stripe_terminal_for_woocommerce_settings']['test_mode'] = 'no';
		$row['provider_refs']['stripe_mode']                                                = 'test';
		$provider = $this->provider_with_key( 'sk_live_fake', array( $this->ok( $this->refund( array( 'status' => 'succeeded' ) ) ) ) );
		$this->assertSame( 'succeeded', $provider->fetch_refund( $row, $this->allocation() )['status'] );
		$this->assertContains( 'Authorization: Bearer sk_test_fake', $this->http->requests[0]['headers'] );
	}

	/** The listing for a reference-less refund travels under the recorded mode's key too. */
	public function test_lists_under_the_key_of_the_recorded_mode(): void {
		$this->options['woocommerce_stripe_terminal_for_woocommerce_settings']['secret_key'] = 'sk_live_fake';
		$row                                 = $this->row();
		$row['provider_refs']['stripe_mode'] = 'live';
		$provider                            = $this->provider( array( $this->listing( array( $this->refund( array( 'status' => 'succeeded' ) ) ) ) ) );
		$this->assertSame( 'succeeded', $provider->fetch_refund( $row, $this->allocation( '' ) )['status'] );
		$this->assertContains( 'Authorization: Bearer sk_live_fake', $this->http->requests[0]['headers'] );
	}

	/** The gateway's own mode has no key (test mode ticked, no test keys): the other mode's refund is still read under its key. */
	public function test_reads_under_the_recorded_key_when_the_current_mode_has_none(): void {
		$this->options['woocommerce_stripe_terminal_for_woocommerce_settings']['secret_key'] = 'sk_live_fake';
		$row                                 = $this->row();
		$row['provider_refs']['stripe_mode'] = 'live';
		$provider                            = $this->provider_with_key( '', array( $this->ok( $this->refund( array( 'status' => 'succeeded' ) ) ) ) );
		$this->assertSame( 'succeeded', $provider->fetch_refund( $row, $this->allocation() )['status'] );
		$this->assertContains( 'Authorization: Bearer sk_live_fake', $this->http->requests[0]['headers'] );
	}

	/**
	 * A provider whose service holds a key other than the base's `sk_test_fake`, so the key on the
	 * wire can only come from the request.
	 *
	 * @param string $key       The service's own key ('' for none).
	 * @param array  $responses Fake answers.
	 */
	private function provider_with_key( string $key, array $responses ): \WCPOS\WooCommercePOS\StripeTerminal\Server\Stripe_Server_Provider {
		$service    = new \WCPOS\WooCommercePOS\StripeTerminal\StripeTerminalService( $key );
		$this->http = new \WCPOS\WooCommercePOS\StripeTerminal\Tests\StripeHttpClientFake( $responses );
		\Stripe\ApiRequestor::setHttpClient( $this->http );
		return new \WCPOS\WooCommercePOS\StripeTerminal\Server\Stripe_Server_Provider( $service );
	}

	/** A row in the current mode, or without a recorded mode, reads with the gateway's own key. */
	public function test_current_mode_reads_with_the_gateway_key(): void {
		$this->options['woocommerce_stripe_terminal_for_woocommerce_settings']['secret_key'] = 'sk_live_fake';
		$row                                 = $this->row();
		$row['provider_refs']['stripe_mode'] = 'test';
		$provider                            = $this->provider( array( $this->ok( $this->refund( array( 'status' => 'succeeded' ) ) ) ) );
		$this->assertSame( 'succeeded', $provider->fetch_refund( $row, $this->allocation() )['status'] );
		$this->assertContains( 'Authorization: Bearer sk_test_fake', $this->http->requests[0]['headers'] );
	}

	/** The recorded mode has no key configured: nothing is read, and the error names the cause. */
	public function test_missing_key_for_the_recorded_mode_keeps_the_question_open(): void {
		$row                                 = $this->row();
		$row['provider_refs']['stripe_mode'] = 'live';
		$provider                            = $this->provider();
		$this->assert_provider_error( $provider->fetch_refund( $row, $this->allocation() ), 'stripe_mode_key_missing' );
		$this->assertCount( 0, $this->http->requests );
	}

	/**
	 * The answer Pro's sweep treats as "ask again".
	 *
	 * @param mixed $result The adapter's answer.
	 */
	private function assert_indeterminate( $result ): void {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stripe_refund_unanswered', $result->get_error_code() );
		$this->assertTrue( $result->get_error_data()['indeterminate'] );
	}
}
