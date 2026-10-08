<?php
/**
 * Tests for the one-off adoption of the old panel's in-flight attempts.
 *
 * @package WCPOS\WooCommercePOS\StripeTerminal
 */

namespace WCPOS\WooCommercePOS\StripeTerminal\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\StripeTerminal\Legacy_Adoption;

/** Which attempts are folded into Pro's ledger, and how the pass advances. */
class LegacyAdoptionTest extends TestCase {
	/** Options the double stores. */
	private $options = array();
	/** Modified times per order id, as the order doubles report them. */
	private $modified = array();
	/** Order doubles by id, what wc_get_order() answers inside the lock. */
	private $orders = array();

	/** Arm Brain Monkey and the option double. */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options  = array();
		$this->modified = array();
		$this->orders   = array();
		$GLOBALS['stwc_payment_id_for_action'] = array();
		Functions\when( 'wc_get_order' )->alias(
			function ( $id ) {
				return $this->orders[ $id ] ?? null;
			}
		);
		Functions\when( 'get_option' )->alias(
			function ( $key, $default = false ) {
				return $this->options[ $key ] ?? $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) {
				$this->options[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $key ) {
				unset( $this->options[ $key ] );
				return true;
			}
		);
	}

	/** Release Brain Monkey. */
	protected function tearDown(): void {
		Monkey\tearDown();
		\Mockery::close();
		parent::tearDown();
	}

	/**
	 * Build an order double with the old panel's meta.
	 *
	 * @param int    $id            Order id.
	 * @param string $intent        PaymentIntent id.
	 * @param string $status        Old panel status: '' while in flight, 'succeeded' or 'failed' at an outcome.
	 * @param bool   $needs_payment Whether WooCommerce still expects payment.
	 */
	private function order( int $id, string $intent, string $status = '', bool $needs_payment = true ) {
		$order = \Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_meta' )->with( Legacy_Adoption::META_INTENT )->andReturn( $intent );
		$order->shouldReceive( 'get_meta' )->with( Legacy_Adoption::META_STATUS )->andReturn( $status );
		$order->shouldReceive( 'get_id' )->andReturn( $id );
		$order->shouldReceive( 'is_paid' )->andReturn( ! $needs_payment );
		$order->shouldReceive( 'get_date_modified' )->andReturn( $this->modified[ $id ] ?? null );
		$order->shouldReceive( 'needs_payment' )->andReturn( $needs_payment );
		$order->shouldReceive( 'get_total' )->andReturn( '12.50' );
		$order->shouldReceive( 'get_currency' )->andReturn( 'USD' );
		$this->orders[ $id ] = $order;
		return $order;
	}

	/** Record which intents reach Pro's adoption. */
	private function record_adoptions( array &$adopted ): void {
		Functions\when( 'wcpos_pro_adopt_legacy_attempt' )->alias(
			function ( $order, $gateway_id, $intent ) use ( &$adopted ) {
				$adopted[] = $intent;
				return array( 'id' => 'row' );
			}
		);
	}

	/**
	 * Only an attempt still in flight is adopted: an intent, no status, an order waiting for
	 * payment. A succeeded, failed, paid, closed or already-adopted one gets no row.
	 */
	public function test_adopts_only_attempts_still_in_flight(): void {
		$in_flight = $this->order( 1, 'pi_live' );
		$done      = $this->order( 2, 'pi_done', 'succeeded', false );
		$failed    = $this->order( 3, 'pi_failed', 'failed' );
		$adopted   = $this->order( 4, 'pi_adopted' );
		$paid_cash = $this->order( 5, 'pi_stale', '', false );
		$this->options['stwc_adoption_boundary'] = 5;
		Functions\expect( 'wc_get_orders' )->once()->andReturn( array( $in_flight, $done, $failed, $adopted, $paid_cash ) );
		$GLOBALS['stwc_payment_id_for_action'] = array( 'pi_adopted' => 'row-4' );
		$recorded = array();
		$this->record_adoptions( $recorded );
		\WCPOS\WooCommercePOS\Payments\Contract\Order_Lock::$locked = array();

		Legacy_Adoption::upgrade();

		$this->assertSame( array( 'pi_live' ), $recorded );
		$this->assertSame( array( 1, 4 ), \WCPOS\WooCommercePOS\Payments\Contract\Order_Lock::$locked, 'the lock is taken before the adopted check is repeated' );
		$this->assertSame( Legacy_Adoption::VERSION, $this->options['stwc_adoption_version'] );
		$this->assertArrayNotHasKey( 'stwc_adoption_offset', $this->options );
	}

	/** A full page advances the offset and leaves the version unset for the next request. */
	public function test_a_full_page_advances_the_offset(): void {
		$orders = array();
		for ( $i = 1; $i <= Legacy_Adoption::PAGE_SIZE; $i++ ) {
			$orders[] = $this->order( $i, 'pi_done_' . $i, 'succeeded' );
		}
		$this->options['stwc_adoption_offset']   = 50;
		$this->options['stwc_adoption_boundary'] = 1000;
		Functions\expect( 'wc_get_orders' )->once()->with( \Mockery::on( function ( $args ) {
			return 50 === $args['offset'] && Legacy_Adoption::PAGE_SIZE === $args['limit']
				&& Legacy_Adoption::META_INTENT === $args['meta_key'] && 'EXISTS' === $args['meta_compare']
				&& 'ID' === $args['orderby'] && 'ASC' === $args['order'];
		} ) )->andReturn( $orders );
		Functions\expect( 'wcpos_pro_adopt_legacy_attempt' )->never();

		Legacy_Adoption::upgrade();

		$this->assertSame( 50 + Legacy_Adoption::PAGE_SIZE, $this->options['stwc_adoption_offset'] );
		$this->assertArrayNotHasKey( 'stwc_adoption_version', $this->options );
	}

	/** The first request records the newest order id; later sales stay on the old path. */
	public function test_the_first_request_snapshots_the_boundary_and_newer_orders_are_left_alone(): void {
		$old = $this->order( 7, 'pi_old' );
		$new = $this->order( 9, 'pi_new' );
		Functions\expect( 'wc_get_orders' )->twice()->andReturnUsing(
			function ( $args ) use ( $old, $new ) {
				return isset( $args['return'] ) ? array( 8 ) : array( $old, $new );
			}
		);
		$adopted = array();
		Functions\when( 'wcpos_pro_adopt_legacy_attempt' )->alias(
			function ( $order, $gateway_id, $intent ) use ( &$adopted ) {
				$adopted[] = $intent;
				return array( 'id' => 'row' );
			}
		);

		Legacy_Adoption::upgrade();

		$this->assertSame( array( 'pi_old' ), $adopted );
		$this->assertSame( Legacy_Adoption::VERSION, $this->options['stwc_adoption_version'] );
		$this->assertArrayNotHasKey( 'stwc_adoption_boundary', $this->options );
	}

	/** A new attempt the old panel starts on an older order after the pass began is not adopted. */
	public function test_an_order_modified_after_the_pass_started_is_not_adopted(): void {
		$this->modified[3] = new \DateTimeImmutable( '@900' );
		$this->modified[4] = new \DateTimeImmutable( '@1100' );
		$untouched = $this->order( 3, 'pi_untouched' );
		$retried   = $this->order( 4, 'pi_retried' );
		$this->options['stwc_adoption_boundary'] = 10;
		$this->options['stwc_adoption_started']  = 1000;
		Functions\expect( 'wc_get_orders' )->once()->andReturn( array( $untouched, $retried ) );
		$recorded = array();
		$this->record_adoptions( $recorded );

		Legacy_Adoption::upgrade();

		$this->assertSame( array( 'pi_untouched' ), $recorded );
	}

	/** The order is re-read under the lock; a till payment that landed meanwhile stops the adoption. */
	public function test_adoption_re_reads_the_order_under_the_lock(): void {
		$stale = $this->order( 6, 'pi_meanwhile' );
		// The copy wc_get_order() answers inside the lock: paid meanwhile.
		$this->order( 6, 'pi_meanwhile', '', false );
		$this->options['stwc_adoption_boundary'] = 6;
		$this->options['stwc_adoption_started']  = PHP_INT_MAX;
		Functions\expect( 'wc_get_orders' )->once()->andReturn( array( $stale ) );
		$recorded = array();
		$this->record_adoptions( $recorded );

		Legacy_Adoption::upgrade();

		$this->assertSame( array(), $recorded );
	}

	/** Once the version is recorded, nothing runs. */
	public function test_a_finished_pass_does_not_run_again(): void {
		$this->options['stwc_adoption_version'] = Legacy_Adoption::VERSION;
		Functions\expect( 'wc_get_orders' )->never();
		Legacy_Adoption::upgrade();
		$this->assertTrue( true );
	}

	/** A refused adoption is logged and does not stop the page. */
	public function test_a_refused_adoption_is_logged_and_skipped(): void {
		$first  = $this->order( 1, 'pi_first' );
		$second = $this->order( 2, 'pi_second' );
		$this->options['stwc_adoption_boundary'] = 2;
		Functions\expect( 'wc_get_orders' )->once()->andReturn( array( $first, $second ) );
		Functions\expect( 'wcpos_pro_adopt_legacy_attempt' )->twice()->andReturnUsing(
			function ( $order ) {
				return 1 === $order->get_id() ? new \WP_Error( 'wcpos_adopt_unsupported' ) : array( 'id' => 'row-2' );
			}
		);
		$logger = \Mockery::mock();
		$logger->shouldReceive( 'error' )->once()->with( \Mockery::pattern( '/order 1: wcpos_adopt_unsupported/' ), \Mockery::any() );
		Functions\expect( 'wc_get_logger' )->once()->andReturn( $logger );

		Legacy_Adoption::upgrade();

		$this->assertSame( Legacy_Adoption::VERSION, $this->options['stwc_adoption_version'] );
	}
}
