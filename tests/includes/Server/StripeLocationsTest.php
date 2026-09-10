<?php
/**
 * Location pagination tests.
 *
 * @package WCPOS\WooCommercePOS\StripeTerminal
 */

namespace WCPOS\WooCommercePOS\StripeTerminal\Tests\Server;

use WCPOS\WooCommercePOS\StripeTerminal\StripeTerminalService;
use WCPOS\WooCommercePOS\StripeTerminal\Tests\StripeHttpClientFake;

require_once __DIR__ . '/ServerTestCase.php';

/** Exercise the real Stripe pagination through the HTTP boundary. */
class StripeLocationsTest extends ServerTestCase {

	/** Follow has_more with the last location as starting_after. */
	public function test_list_all_locations_follows_two_pages(): void {
		$service = new StripeTerminalService( 'sk_test_fake' );
		$first = array(
			'id' => 'tml_first',
			'object' => 'terminal.location',
			'display_name' => 'First store',
		);
		$second = array(
			'id' => 'tml_second',
			'object' => 'terminal.location',
			'display_name' => 'Second store',
		);
		$this->http = new StripeHttpClientFake(
			array(
				$this->ok(
					array(
						'object' => 'list',
						'url' => '/v1/terminal/locations',
						'has_more' => true,
						'data' => array( $first ),
					)
				),
				$this->ok(
					array(
						'object' => 'list',
						'url' => '/v1/terminal/locations',
						'has_more' => false,
						'data' => array( $second ),
					)
				),
			)
		);
		\Stripe\ApiRequestor::setHttpClient( $this->http );
		$this->assertSame( array( $first, $second ), $service->list_all_locations() );
		$this->assertCount( 2, $this->http->requests );
		$this->assertStringEndsWith( '/v1/terminal/locations', $this->http->requests[0]['url'] );
		$this->assertSame( array( 'limit' => 100 ), $this->http->requests[0]['params'] );
		$this->assertSame(
			array(
				'limit' => 100,
				'starting_after' => 'tml_first',
			),
			$this->http->requests[1]['params']
		);
	}
}
