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
	 * @param array  $responses Queued responses.
	 * @param string $api_key   API key the service is built with.
	 */
	private function service( array $responses, string $api_key = 'sk_test_fake' ): StripeTerminalService {
		// The constructor installs the real client, so the fake goes in afterwards.
		$service    = new StripeTerminalService( $api_key );
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
		$this->assertCount( 1, $this->http->requests );
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
		$this->assertCount( 0, $this->http->requests );
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
		$this->assertCount( 1, $this->http->requests );
		$this->assertCount( 1, $stored );
		// An array marker: a location literally named "__unknown__" can never be mistaken for it.
		$this->assertSame( array( 'unknown' => true ), $stored[0][1] );
		$this->assertSame( 3600, $stored[0][2] );
		// The remembered refusal is served without a request.
		Functions\when( 'get_transient' )->justReturn( array( 'unknown' => true ) );
		$this->assertNull( $this->service( array() )->get_location_display_name( 'tml_missing' ) );
		$this->assertCount( 0, $this->http->requests );
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
		// Each service is built before its fake is installed, so no lookup reaches the network.
		$test_key = $this->service( array( $this->ok( $body ), $this->ok( $body ) ) );
		$test_key->get_location_display_name( 'tml_a' );
		$test_key->get_location_display_name( 'tml_b' );
		$this->assertCount( 2, $this->http->requests );
		$live_key = $this->service( array( $this->ok( $body ) ), 'sk_live_fake' );
		$live_key->get_location_display_name( 'tml_a' );
		$this->assertCount( 1, $this->http->requests );
		$this->assertCount( 3, $keys );
		$this->assertCount( 3, array_unique( $keys ) );
	}
}
