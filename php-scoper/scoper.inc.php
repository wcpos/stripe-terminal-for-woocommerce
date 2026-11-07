<?php
/**
 * PHP-Scoper configuration for the release build.
 *
 * Prefixes the Stripe PHP SDK under WCPOS\WooCommercePOS\StripeTerminal\Vendor so the
 * released plugin cannot collide with another plugin's copy of stripe/stripe-php.
 * Run by scripts/scope-release.sh; see docs/dependency-scoping.md.
 *
 * @package WCPOS\WooCommercePOS\StripeTerminal
 */

declare(strict_types=1);

use Isolated\Symfony\Component\Finder\Finder;

// The SDK is read from the build tree's vendor/; lib/ and data/ keep their relative layout,
// because lib/Stripe.php finds the CA bundle at __DIR__ . '/../data/ca-certificates.crt'.
$stripe_sdk = dirname( __DIR__ ) . '/vendor/stripe/stripe-php';

return array(
	'prefix'             => 'WCPOS\\WooCommercePOS\\StripeTerminal\\Vendor',
	'finders'            => array(
		Finder::create()->files()->in( array( $stripe_sdk . '/lib', $stripe_sdk . '/data' ) ),
	),
	'patchers'           => array(),
	// No Stripe symbol is exposed or excluded: every Stripe class lives only under the prefix.
	// PHPUnit is excluded only so the SDK's own test-runner guard, class_exists( 'PHPUnit\Framework\TestCase' ) in
	// Util/AgentPluginHint.php, keeps naming the real PHPUnit class instead of a prefixed one.
	'exclude-namespaces' => array( 'PHPUnit' ),
	'exclude-classes'    => array(),
	'exclude-functions'  => array(),
	'exclude-constants'  => array(),
	'expose-namespaces'  => array(),
	'expose-classes'     => array(),
	'expose-functions'   => array(),
	'expose-constants'   => array(),
);
