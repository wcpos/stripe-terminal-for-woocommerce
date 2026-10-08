<?php
/**
 * Tests for the plugin bootstrap, run in a child PHP process so the plugin file can define its constants.
 *
 * @package WCPOS\WooCommercePOS\StripeTerminal
 */

namespace WCPOS\WooCommercePOS\StripeTerminal\Tests;

use PHPUnit\Framework\TestCase;

/** The Pro gate in the bootstrap. */
class PluginTest extends TestCase {
	/** An unsupported Pro installation must register only an admin notice. */
	public function test_init_registers_nothing_when_pro_requirement_fails(): void {
		$plugin = dirname( __DIR__, 2 ) . '/stripe-terminal-for-woocommerce.php';
		$code = <<<'PHP'
define( 'ABSPATH', '/tmp/wordpress/' );
function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
function plugin_dir_url( $file ) { return 'https://example.test/plugins/stripe-terminal/'; }
function register_activation_hook( $file, $callback ) {}
function wcpos_pro_requires( $version, $file = '' ) { return false; }
function add_action( $hook, $callback, ...$args ) { $GLOBALS['actions'][] = $hook; }
function add_filter( $hook, $callback, ...$args ) { $GLOBALS['filters'][] = $hook; }
function get_option( $key, $default = false ) { return $default; }
class WP_Error { public function __construct( ...$args ) {} }
PHP;
		$code .= "\nrequire " . var_export( $plugin, true ) . ';';
		$code .= <<<'PHP'
$GLOBALS['actions'] = array();
$GLOBALS['filters'] = array();
\WCPOS\WooCommercePOS\StripeTerminal\init();
echo json_encode( array( $GLOBALS['actions'], $GLOBALS['filters'] ) );
PHP;
		exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $code ), $output, $exit_code );
		$this->assertSame( 0, $exit_code );
		$this->assertSame( array( array( 'admin_notices' ), array() ), json_decode( implode( "\n", $output ), true ) );
	}
}
