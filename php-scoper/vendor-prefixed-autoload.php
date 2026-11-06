<?php
/**
 * Autoloader for the prefixed Stripe PHP SDK in the release zip.
 *
 * Copied to vendor_prefixed/autoload.php by scripts/scope-release.sh. It ships in the plugin,
 * so it must stay PHP 7.4 syntax. See docs/dependency-scoping.md.
 *
 * @package WCPOS\WooCommercePOS\StripeTerminal
 */

spl_autoload_register(
	function ( $class ) {
		$prefix = 'WCPOS\\WooCommercePOS\\StripeTerminal\\Vendor\\Stripe\\';
		$len    = strlen( $prefix );

		if ( 0 !== strncmp( $prefix, $class, $len ) ) {
			return;
		}

		$file = __DIR__ . '/stripe-php/lib/' . str_replace( '\\', '/', substr( $class, $len ) ) . '.php';
		if ( is_file( $file ) ) {
			require $file;
		}
	}
);
