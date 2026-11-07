<?php
/**
 * Pro's money-path lessons against the real Stripe adapter over a scripted Stripe.
 *
 * @package WCPOS\WooCommercePOS\StripeTerminal\Tests\Conformance
 */

namespace WCPOS\WooCommercePOS\StripeTerminal\Tests\Conformance;

use WCPOS\WooCommercePOSPro\Tests\Conformance\Conformance_Fixture;
use WCPOS\WooCommercePOSPro\Tests\Conformance\Provider_Conformance_Test_Case;

require_once __DIR__ . '/Stripe_Conformance_Fixture.php';

/** The transcripts in ./transcripts are the certified record; a change there is a re-certification. */
class Test_Stripe_Provider_Conformance extends Provider_Conformance_Test_Case {
	protected function fixture(): Conformance_Fixture {
		return new Stripe_Conformance_Fixture();
	}
}
