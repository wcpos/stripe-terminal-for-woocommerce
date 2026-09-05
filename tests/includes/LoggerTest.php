<?php
/**
 * Tests for the Logger class.
 *
 * Verifies log-level management, the WC_LOG_FILENAME constant, and
 * graceful behaviour when WC_Logger is unavailable.
 */

namespace WCPOS\WooCommercePOS\StripeTerminal\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\StripeTerminal\Logger;

/**
 * @covers \WCPOS\WooCommercePOS\StripeTerminal\Logger
 */
class LoggerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Reset static state between tests.
		Logger::$logger    = null;
		Logger::$log_level = null;
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// -------------------------------------------------------------------
	// WC_LOG_FILENAME constant
	// -------------------------------------------------------------------

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_log_forwards_a_per_call_level_and_falls_back_to_the_configured_level(): void {
		\Mockery::mock( 'alias:WC_Logger' ); // Makes class_exists( 'WC_Logger' ) true in this process.
		$wc_logger = \Mockery::mock();
		$wc_logger->shouldReceive( 'log' )->once()->with( 'warning', 'careful', array( 'source' => Logger::WC_LOG_FILENAME ) );
		$wc_logger->shouldReceive( 'log' )->once()->with( 'info', 'plain', array( 'source' => Logger::WC_LOG_FILENAME ) );
		Functions\when( 'wc_get_logger' )->justReturn( $wc_logger );

		Logger::log( 'careful', 'warning' );
		Logger::log( 'plain' );

		$this->addToAssertionCount( \Mockery::getContainer()->mockery_getExpectationCount() );
	}

	public function test_log_filename_constant_equals_plugin_slug(): void {
		$this->assertSame( 'stripe-terminal-for-woocommerce', Logger::WC_LOG_FILENAME );
	}

	// -------------------------------------------------------------------
	// set_log_level
	// -------------------------------------------------------------------

	public function test_set_log_level_sets_static_property(): void {
		Logger::set_log_level( 'debug' );
		$this->assertSame( 'debug', Logger::$log_level );
	}

	public function test_set_log_level_can_be_changed(): void {
		Logger::set_log_level( 'info' );
		$this->assertSame( 'info', Logger::$log_level );

		Logger::set_log_level( 'error' );
		$this->assertSame( 'error', Logger::$log_level );
	}

	// -------------------------------------------------------------------
	// log — WC_Logger not available
	// -------------------------------------------------------------------

	public function test_log_does_nothing_when_wc_logger_class_missing(): void {
		// WC_Logger is not defined in the test environment, so
		// class_exists('WC_Logger') returns false. Calling log()
		// should simply return without error.
		Logger::log( 'This message should be silently ignored' );

		// If we reach this assertion, no exception or fatal error occurred.
		$this->assertTrue( true );
	}

	public function test_log_does_not_set_logger_when_wc_logger_missing(): void {
		Logger::log( 'test message' );

		$this->assertNull( Logger::$logger );
	}
}
