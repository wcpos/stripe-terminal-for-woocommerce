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
			$this->assert_reader_pickup_verification_keeps_polling( $file, $source );
		}
	}

	private function assert_reader_pickup_verification_keeps_polling( string $file, string $source ): void {
		$script = str_replace(
			array( '__PAYMENT_FRONTEND_SOURCE__', '__PAYMENT_FRONTEND_FILE__' ),
			array( json_encode( $source ), json_encode( $file ) ),
			$this->reader_pickup_verification_script()
		);

		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		$process = proc_open( 'node', $descriptors, $pipes, dirname( __DIR__, 2 ) );
		$this->assertIsResource( $process, 'Could not start Node.js for ' . $file );

		fwrite( $pipes[0], $script );
		fclose( $pipes[0] );

		$output = stream_get_contents( $pipes[1] );
		$error  = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		$status = proc_close( $process );

		$this->assertSame( 0, $status, trim( $output . "\n" . $error ) ?: $file );
	}

	private function reader_pickup_verification_script(): string {
		return <<<'JS'
const assert = require('node:assert/strict'), vm = require('node:vm'), filename = __PAYMENT_FRONTEND_FILE__, source = __PAYMENT_FRONTEND_SOURCE__;
const calls = { ajax: null, cancelPayment: 0, resetReader: 0, showError: 0, stopPolling: 0 }, logs = [];
function jQuery() { return { ready() {} }; } jQuery.ajax = async (request) => (calls.ajax = request.data, { success: true, data: { last_seen_at: 100 } });
const executableSource = source.replace(/^\s*import\s+['"].*payment\.css['"];\s*/m, '').replace(/export\s+default\s+StripeTerminalPayment;\s*$/, 'module.exports = StripeTerminalPayment;');
const sandbox = { console: { error() {}, log() {}, warn() {} }, document: {}, exports: {}, jQuery, localStorage: { getItem() { return null; }, removeItem() {}, setItem() {} }, module: { exports: {} }, window: { ajaxurl: '/wp-admin/admin-ajax.php', stripeTerminalData: { ajaxUrl: '/wp-admin/admin-ajax.php', nonce: 'nonce' } } };
sandbox.global = sandbox.globalThis = sandbox; vm.runInNewContext(executableSource, sandbox, { filename });
const PaymentFrontend = sandbox.module.exports.default || sandbox.module.exports;
assert.equal(typeof PaymentFrontend, 'function', `${filename}: expected payment frontend export`); PaymentFrontend.prototype.init = function init() {};
const payment = new PaymentFrontend(); Object.assign(payment, { currentPaymentIntent: { id: 'pi_123' }, activePaymentReaderId: 'tmr_123', readerLastSeenAt: 100, readerVerifyTimeout: { active: true }, pollingInterval: { active: true }, pollingTimeout: { active: true }, addToLog: (message, level) => logs.push([message, level]), cancelPayment: async () => calls.cancelPayment += 1, resetReader: () => calls.resetReader += 1, showError: () => calls.showError += 1, stopPolling: () => { calls.stopPolling += 1; payment.pollingInterval = null; payment.pollingTimeout = null; } });
(async () => {
  await payment.verifyReaderPickup('123', {}); assert.equal(calls.ajax.action, 'stripe_terminal_get_reader_status', `${filename}: reader status was not checked`);
  for (const [method, count] of Object.entries({ cancelPayment: calls.cancelPayment, resetReader: calls.resetReader, showError: calls.showError, stopPolling: calls.stopPolling })) {
    assert.equal(count, 0, `${filename}: ${method} must not run for unchanged last_seen_at`);
  }
  assert.deepEqual([payment.currentPaymentIntent.id, payment.activePaymentReaderId, payment.readerVerifyTimeout, payment.pollingInterval, payment.pollingTimeout], ['pi_123', 'tmr_123', null, { active: true }, { active: true }], `${filename}: payment and polling state should remain active`);
  assert.ok(logs.some(([message, level]) => message.includes('continuing payment status polling') && level === 'warning'), `${filename}: unchanged last_seen_at should keep the continue-polling branch active`);
})().catch((error) => {
  console.error(error && error.stack ? error.stack : error);
  process.exit(1);
});
JS;
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
