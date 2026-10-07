<?php
/**
 * Location display-name lookup tests.
 *
 * @package WCPOS\WooCommercePOS\StripeTerminal
 */

namespace WCPOS\WooCommercePOS\StripeTerminal\Tests\Server;

use Brain\Monkey\Functions;
use WCPOS\WooCommercePOS\StripeTerminal\StripeTerminalService;
use WCPOS\WooCommercePOS\StripeTerminal\Tests\StripeHttpClientFake;

require_once __DIR__ . '/ServerTestCase.php';

/** The POS shows a Terminal location by name; the lookup is cached and never blocks. */
class LocationNameTest extends ServerTestCase {
	/**
	 * Service bound to a fake Stripe HTTP client.
	 *
	 * @param array $responses Queued responses.
	 */
	private function service( array $responses ): StripeTerminalService {
		$service = new StripeTerminalService( 'sk_test_fake' );
		$this->http = new StripeHttpClientFake( $responses );
		\Stripe\ApiRequestor::setHttpClient( $this->http );
		return $service;
	}

	/** A fetched name is returned and written to the week-long transient. */
	public function test_fetches_and_caches_the_display_name(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		$stored = array();
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value, $ttl ) use ( &$stored ) {
				$stored[] = array( $key, $value, $ttl );
				return true;
			}
		);
		$service = $this->service(
			array(
				$this->ok(
					array(
						'id'           => 'tml_test',
						'object'       => 'terminal.location',
						'display_name' => 'London Shop',
					)
				),
			)
		);
		$this->assertSame( 'London Shop', $service->get_location_display_name( 'tml_test' ) );
		$this->assertCount( 1, $stored );
		$this->assertSame( 'London Shop', $stored[0][1] );
		$this->assertSame( 604800, $stored[0][2] );
	}

	/** A cached name is served without a request; an empty id asks nothing. */
	public function test_cache_and_empty_id(): void {
		Functions\when( 'get_transient' )->justReturn( 'Cached Shop' );
		$service = $this->service( array() );
		$this->assertSame( 'Cached Shop', $service->get_location_display_name( 'tml_test' ) );
		$this->assertNull( $service->get_location_display_name( '' ) );
	}

	/**
	 * Stripe refusing the lookup is a null name, never an error the bootstrap has to carry — and
	 * the refusal is remembered for an hour so a restricted key does not cost a read per descriptor.
	 */
	public function test_failed_lookup_is_null_and_not_retried(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		$stored = array();
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value, $ttl ) use ( &$stored ) {
				$stored[] = array( $key, $value, $ttl );
				return true;
			}
		);
		$service = $this->service( array( $this->error( 'resource_missing' ) ) );
		$this->assertNull( $service->get_location_display_name( 'tml_missing' ) );
		$this->assertCount( 1, $stored );
		$this->assertSame( '__unknown__', $stored[0][1] );
		$this->assertSame( 3600, $stored[0][2] );
		// The remembered refusal is served without a request.
		Functions\when( 'get_transient' )->justReturn( '__unknown__' );
		$this->assertNull( $this->service( array() )->get_location_display_name( 'tml_missing' ) );
	}

	/** One key per API key and location: two shops, or test and live, never share a name. */
	public function test_cache_keys_are_separate_per_key_and_location(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		$keys = array();
		Functions\when( 'set_transient' )->alias(
			function ( $key ) use ( &$keys ) {
				$keys[] = $key;
				return true;
			}
		);
		$body = array(
			'id'           => 'tml_a',
			'object'       => 'terminal.location',
			'display_name' => 'Shop',
		);
		$this->service( array( $this->ok( $body ), $this->ok( $body ) ) );
		$test_key = new StripeTerminalService( 'sk_test_fake' );
		$test_key->get_location_display_name( 'tml_a' );
		$test_key->get_location_display_name( 'tml_b' );
		$this->service( array( $this->ok( $body ) ) );
		( new StripeTerminalService( 'sk_live_fake' ) )->get_location_display_name( 'tml_a' );
		$this->assertCount( 3, $keys );
		$this->assertCount( 3, array_unique( $keys ) );
	}
}
