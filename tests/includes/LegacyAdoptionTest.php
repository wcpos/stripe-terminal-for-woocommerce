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

	/** Arm Brain Monkey and the option double. */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options = array();
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
	 * @param int    $id     Order id.
	 * @param string $intent PaymentIntent id.
	 * @param string $status Old panel status.
	 */
	private function order( int $id, string $intent, string $status ) {
		$order = \Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_meta' )->with( Legacy_Adoption::META_INTENT )->andReturn( $intent );
		$order->shouldReceive( 'get_meta' )->with( Legacy_Adoption::META_STATUS )->andReturn( $status );
		$order->shouldReceive( 'get_id' )->andReturn( $id );
		$order->shouldReceive( 'get_total' )->andReturn( '12.50' );
		$order->shouldReceive( 'get_currency' )->andReturn( 'USD' );
		return $order;
	}

	/** A pending attempt is adopted; a succeeded one and an already-adopted one are skipped. */
	public function test_adopts_pending_attempts_and_skips_finished_or_adopted_ones(): void {
		$pending  = $this->order( 1, 'pi_pending', 'requires_payment_method' );
		$done     = $this->order( 2, 'pi_done', 'succeeded' );
		$adopted  = $this->order( 3, 'pi_adopted', 'processing' );
		$canceled = $this->order( 4, 'pi_gone', 'canceled' );
		$this->options['stwc_adoption_boundary'] = 4;
		Functions\expect( 'wc_get_orders' )->once()->andReturn( array( $pending, $done, $adopted, $canceled ) );
		Functions\expect( 'wcpos_pro_payment_id_for_action' )->with( 'stripe', 'pi_pending' )->andReturn( null );
		Functions\expect( 'wcpos_pro_payment_id_for_action' )->with( 'stripe', 'pi_adopted' )->andReturn( 'row-3' );
		Functions\expect( 'wcpos_pro_adopt_legacy_attempt' )->once()->with( $pending, 'stripe_terminal_for_woocommerce', 'pi_pending', '12.50', 'USD' )->andReturn( array( 'id' => 'row-1' ) );

		Legacy_Adoption::upgrade();

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
		$old = $this->order( 7, 'pi_old', 'processing' );
		$new = $this->order( 9, 'pi_new', 'processing' );
		Functions\expect( 'wc_get_orders' )->twice()->andReturnUsing(
			function ( $args ) use ( $old, $new ) {
				return isset( $args['return'] ) ? array( 8 ) : array( $old, $new );
			}
		);
		Functions\when( 'wcpos_pro_payment_id_for_action' )->justReturn( null );
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

	/** Once the version is recorded, nothing runs. */
	public function test_a_finished_pass_does_not_run_again(): void {
		$this->options['stwc_adoption_version'] = Legacy_Adoption::VERSION;
		Functions\expect( 'wc_get_orders' )->never();
		Legacy_Adoption::upgrade();
		$this->assertTrue( true );
	}

	/** A refused adoption is logged and does not stop the page. */
	public function test_a_refused_adoption_is_logged_and_skipped(): void {
		$first  = $this->order( 1, 'pi_first', 'processing' );
		$second = $this->order( 2, 'pi_second', 'processing' );
		$this->options['stwc_adoption_boundary'] = 2;
		Functions\expect( 'wc_get_orders' )->once()->andReturn( array( $first, $second ) );
		Functions\when( 'wcpos_pro_payment_id_for_action' )->justReturn( null );
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
