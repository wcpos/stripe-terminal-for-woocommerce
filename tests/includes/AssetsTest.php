<?php
/**
 * Tests for built asset URL resolution.
 */

namespace WCPOS\WooCommercePOS\StripeTerminal\Tests;

use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\StripeTerminal\Assets;

/**
 * @covers \WCPOS\WooCommercePOS\StripeTerminal\Assets
 */
class AssetsTest extends TestCase {
	/**
	 * The committed manifest must point at files that exist, with a hash in
	 * the name; a stale manifest would enqueue a 404 on every order-pay page.
	 */
	public function test_committed_manifest_resolves_payment_assets_to_hashed_files_on_disk(): void {
		foreach ( array( 'js/payment.js', 'css/payment.css' ) as $path ) {
			$resolved = Assets::resolve( $path );

			$this->assertMatchesRegularExpression( '#^(js|css)/payment\.[0-9a-f]{8}\.(js|css)$#', $resolved, $path );
			$this->assertFileExists( STWC_PLUGIN_DIR . 'assets/' . $resolved, $path );
		}
	}

	public function test_url_prefixes_plugin_assets_directory(): void {
		$this->assertSame(
			STWC_PLUGIN_URL . 'assets/' . Assets::resolve( 'js/payment.js' ),
			Assets::url( 'js/payment.js' )
		);
	}

	public function test_unlisted_path_falls_back_to_itself(): void {
		$this->assertSame( 'js/other.js', Assets::resolve( 'js/other.js' ) );
	}

	public function test_missing_manifest_falls_back_to_source_path(): void {
		$this->assertSame( 'js/payment.js', Assets::resolve( 'js/payment.js', '/nonexistent/manifest.json' ) );
	}

	public function test_malformed_manifest_falls_back_to_source_path(): void {
		$manifest = tempnam( sys_get_temp_dir(), 'stwc-manifest' );
		file_put_contents( $manifest, '{"js/payment.js": 42' );

		try {
			$this->assertSame( 'js/payment.js', Assets::resolve( 'js/payment.js', $manifest ) );
		} finally {
			unlink( $manifest );
		}
	}

	public function test_non_string_manifest_entry_falls_back_to_source_path(): void {
		$manifest = tempnam( sys_get_temp_dir(), 'stwc-manifest' );
		file_put_contents( $manifest, '{"js/payment.js": ["js/payment.abc.js"]}' );

		try {
			$this->assertSame( 'js/payment.js', Assets::resolve( 'js/payment.js', $manifest ) );
		} finally {
			unlink( $manifest );
		}
	}
}
