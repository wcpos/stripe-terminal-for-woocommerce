<?php
/**
 * Tests for the ReaderWarmer class.
 */

namespace WCPOS\WooCommercePOS\StripeTerminal\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\StripeTerminal\ReaderWarmer;
use WCPOS\WooCommercePOS\StripeTerminal\StripeTerminalService;

/**
 * @covers \WCPOS\WooCommercePOS\StripeTerminal\ReaderWarmer
 */
class ReaderWarmerTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubs(
			array(
				'add_action'    => true,
				'add_filter'    => true,
				'get_option'    => function () {
					return array();
				},
				// Warming is opt-in; tests exercise it as if the site enabled it.
				'apply_filters' => function ( $hook, $value ) {
					return 'stwc_enable_reader_keep_warm' === $hook ? true : $value;
				},
			)
		);
	}

	protected function tearDown(): void {
		Mockery::close();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Build an order mock with the given provenance and gateway.
	 *
	 * @param string $created_via    Order created_via value.
	 * @param string $payment_method Order payment method.
	 * @param string $pos_meta       Legacy _pos meta value.
	 */
	private function mock_order( string $created_via, string $payment_method, string $pos_meta = '' ) {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_created_via' )->andReturn( $created_via );
		$order->shouldReceive( 'get_payment_method' )->andReturn( $payment_method );
		$order->shouldReceive( 'get_meta' )->with( '_pos' )->andReturn( $pos_meta );

		return $order;
	}

	public function test_pos_created_order_schedules_warm_regardless_of_gateway(): void {
		$this->assertTrue( class_exists( ReaderWarmer::class ) );
		Functions\when( 'get_option' )->justReturn( 0 );
		Functions\expect( 'wp_schedule_single_event' )
			->once()
			->with( Mockery::type( 'int' ), 'stwc_warm_reader' );

		$warmer = new ReaderWarmer( Mockery::mock( StripeTerminalService::class ) );

		// POS open order: no gateway chosen yet, still warms.
		$warmer->warm_new_order( 42, $this->mock_order( 'woocommerce-pos', '' ) );
	}

	public function test_legacy_pos_meta_schedules_warm(): void {
		$this->assertTrue( class_exists( ReaderWarmer::class ) );
		Functions\when( 'get_option' )->justReturn( 0 );
		Functions\expect( 'wp_schedule_single_event' )
			->once()
			->with( Mockery::type( 'int' ), 'stwc_warm_reader' );

		$warmer = new ReaderWarmer( Mockery::mock( StripeTerminalService::class ) );

		$warmer->warm_new_order( 42, $this->mock_order( 'rest-api', 'cod', '1' ) );
	}

	public function test_terminal_gateway_web_order_schedules_warm(): void {
		$this->assertTrue( class_exists( ReaderWarmer::class ) );
		Functions\when( 'get_option' )->justReturn( 0 );
		Functions\expect( 'wp_schedule_single_event' )
			->once()
			->with( Mockery::type( 'int' ), 'stwc_warm_reader' );

		$warmer = new ReaderWarmer( Mockery::mock( StripeTerminalService::class ) );

		$warmer->warm_new_order( 42, $this->mock_order( 'checkout', 'stripe_terminal_for_woocommerce' ) );
	}

	public function test_unrelated_order_does_not_schedule_warm(): void {
		$this->assertTrue( class_exists( ReaderWarmer::class ) );
		Functions\when( 'get_option' )->justReturn( 0 );
		Functions\expect( 'wp_schedule_single_event' )->never();

		$warmer = new ReaderWarmer( Mockery::mock( StripeTerminalService::class ) );

		$warmer->warm_new_order( 43, $this->mock_order( 'checkout', 'cod' ) );
	}

	public function test_recent_warm_throttles_new_order_warm(): void {
		$this->assertTrue( class_exists( ReaderWarmer::class ) );
		Functions\when( 'get_option' )->justReturn( time() );
		Functions\expect( 'wp_schedule_single_event' )->never();

		$warmer = new ReaderWarmer( Mockery::mock( StripeTerminalService::class ) );

		$warmer->warm_new_order( 42, $this->mock_order( 'woocommerce-pos', '' ) );
	}

	public function test_rest_pre_dispatch_returns_result_and_schedules_elapsed_warm(): void {
		$updates = array();
		Functions\when( 'get_option' )->alias(
			function ( $key, $default = false ) {
				return 'stwc_pos_last_active' === $key || 'stwc_last_warm_at' === $key ? 0 : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $key, $value, $autoload ) use ( &$updates ) {
				$updates[ $key ] = array( $value, $autoload );
			}
		);
		Functions\expect( 'wp_schedule_single_event' )
			->once()
			->with( Mockery::type( 'int' ), 'stwc_warm_reader' );

		$request = Mockery::mock( 'WP_REST_Request' );
		$request->shouldReceive( 'get_route' )->andReturn( '/wcpos/v1/orders' );
		$result = new \stdClass();
		$warmer = new ReaderWarmer( Mockery::mock( StripeTerminalService::class ) );

		$this->assertSame( $result, $warmer->track_pos_activity( $result, null, $request ) );
		$this->assertFalse( $updates['stwc_pos_last_active'][1] );
		$this->assertFalse( $updates['stwc_last_warm_at'][1] );
	}

	public function test_non_pos_route_is_ignored(): void {
		Functions\expect( 'wp_schedule_single_event' )->never();
		Functions\expect( 'update_option' )->never();

		$request = Mockery::mock( 'WP_REST_Request' );
		$request->shouldReceive( 'get_route' )->andReturn( '/wc/v3/products' );
		$result = new \stdClass();
		$warmer = new ReaderWarmer( Mockery::mock( StripeTerminalService::class ) );

		$this->assertSame( $result, $warmer->track_pos_activity( $result, null, $request ) );
	}

	public function test_maybe_warm_is_disabled_by_default(): void {
		// Unchecked settings box, unfiltered: warming off. No API traffic at all.
		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value ) {
				return $value;
			}
		);
		Functions\expect( 'update_option' )->never();

		$service = Mockery::mock( StripeTerminalService::class );
		$service->shouldReceive( 'get_reader' )->never();
		$service->shouldReceive( 'set_reader_display' )->never();

		$warmer = new ReaderWarmer( $service );

		$this->assertFalse( $warmer->maybe_warm( 'tmr_any' ) );
	}

	public function test_maybe_warm_skips_reader_with_in_progress_payment_action(): void {
		Functions\when( 'get_option' )->justReturn( 0 );
		Functions\expect( 'update_option' )->never();

		$service = Mockery::mock( StripeTerminalService::class );
		$service->shouldReceive( 'get_reader' )->once()->with( 'tmr_busy' )->andReturn(
			array(
				'action' => array(
					'type'   => 'process_payment_intent',
					'status' => 'in_progress',
				),
			)
		);
		$service->shouldReceive( 'set_reader_display' )->never();

		$warmer = new ReaderWarmer( $service );

		$this->assertFalse( $warmer->maybe_warm( 'tmr_busy' ) );
	}

	public function test_maybe_warm_skips_after_recent_payment_dispatch(): void {
		Functions\when( 'get_option' )->justReturn( time() - 30 );
		Functions\expect( 'update_option' )->never();

		$service = Mockery::mock( StripeTerminalService::class );
		$service->shouldReceive( 'get_reader' )->never();
		$service->shouldReceive( 'set_reader_display' )->never();

		$warmer = new ReaderWarmer( $service );

		$this->assertFalse( $warmer->maybe_warm( 'tmr_busy' ) );
	}

	public function test_maybe_warm_repings_over_previous_display_action(): void {
		// A completed warm leaves a set_reader_display action that reports
		// in_progress indefinitely; replacing display with display is safe.
		Functions\when( 'get_option' )->justReturn( 0 );
		Functions\expect( 'update_option' )
			->once()
			->with( 'stwc_last_warm_at', Mockery::type( 'int' ), false );

		$service = Mockery::mock( StripeTerminalService::class );
		$service->shouldReceive( 'get_reader' )->once()->with( 'tmr_warm' )->andReturn(
			array(
				'action' => array(
					'type'   => 'set_reader_display',
					'status' => 'in_progress',
				),
			)
		);
		$service->shouldReceive( 'set_reader_display' )->once()->with( 'tmr_warm' )->andReturn(
			array( 'id' => 'tmr_warm' )
		);

		$warmer = new ReaderWarmer( $service );

		$this->assertTrue( $warmer->maybe_warm( 'tmr_warm' ) );
	}

	public function test_maybe_warm_pings_idle_reader(): void {
		Functions\when( 'get_option' )->justReturn( 0 );
		Functions\expect( 'update_option' )
			->once()
			->with( 'stwc_last_warm_at', Mockery::type( 'int' ), false );

		$service = Mockery::mock( StripeTerminalService::class );
		$service->shouldReceive( 'get_reader' )->once()->with( 'tmr_idle' )->andReturn(
			array( 'action' => null )
		);
		$service->shouldReceive( 'set_reader_display' )->once()->with( 'tmr_idle' )->andReturn(
			array( 'id' => 'tmr_idle' )
		);

		$warmer = new ReaderWarmer( $service );

		$this->assertTrue( $warmer->maybe_warm( 'tmr_idle' ) );
	}

	public function test_resolve_reader_target_uses_and_stores_only_reader(): void {
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\expect( 'update_option' )
			->once()
			->with( 'stwc_last_reader_id', 'tmr_only', false );
		$service = Mockery::mock( StripeTerminalService::class );
		$service->shouldReceive( 'get_reader_status' )->once()->andReturn(
			array( 'data' => array( array( 'id' => 'tmr_only' ) ) )
		);

		$warmer = new ReaderWarmer( $service );

		$this->assertSame( 'tmr_only', $warmer->resolve_reader_target() );
	}
}
