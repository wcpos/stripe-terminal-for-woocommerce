<?php
/**
 * Source-level regression tests for the payment frontend.
 */

namespace WCPOS\WooCommercePOS\StripeTerminal\Tests;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class PaymentFrontendSourceTest extends TestCase {
	/**
	 * Unchanged reader last_seen_at must not cancel an already-dispatched payment.
	 */
	public function test_reader_pickup_verification_does_not_cancel_on_unchanged_last_seen(): void {
		foreach ( $this->payment_frontend_files() as $file ) {
			$source = file_get_contents( dirname( __DIR__, 2 ) . '/' . $file );

			$this->assertIsString( $source );
			$this->assertStringNotContainsString(
				"Reader didn\\'t respond to the payment command. It may need to be restarted.",
				$source,
				$file
			);
		}
	}

	/**
	 * @return string[]
	 */
	private function payment_frontend_files(): array {
		return array(
			'packages/payment-frontend/src/payment.js',
			'assets/js/payment.js',
		);
	}
}
