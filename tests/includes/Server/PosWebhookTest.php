<?php
namespace WCPOS\WooCommercePOS\StripeTerminal\Tests\Server;
use WCPOS\WooCommercePOS\StripeTerminal\Server\Pos_Webhook;
require_once __DIR__ . '/ServerTestCase.php';
class PosWebhookTest extends ServerTestCase {
	private const URL = 'https://store.test/wp-json/wcpos/v2/payments/webhook?provider=stripe';
	/** @dataProvider configurations */
	public function test_endpoint_reconciliation( bool $exists, bool $secret, string $mode ): void {
		$key = 'test' === $mode ? 'test_pos_webhook_secret' : 'pos_webhook_secret';
		$this->options['woocommerce_stripe_terminal_for_woocommerce_settings'] = array(
			'webhook_secret' => 'legacy',
			$key             => $secret ? 'saved' : '',
		);
		$data = array(
			array(
				'id'     => 'we_legacy',
				'object' => 'webhook_endpoint',
				'url'    => 'https://store.test/wp-json/stripe-terminal/v1/webhook',
			),
		);
		if ( $exists ) {
			$data[] = array(
				'id'     => 'we_pos',
				'object' => 'webhook_endpoint',
				'url'    => self::URL,
			);
		}
		$responses = array(
			$this->ok(
				array(
					'object' => 'list',
					'data'   => $data,
				)
			),
		);
		if ( $exists && ! $secret ) {
			$responses[] = $this->ok(
				array(
					'id'      => 'we_pos',
					'deleted' => true,
				)
			);
		}
		if ( ! $exists || ! $secret ) {
			$responses[] = $this->ok(
				array(
					'id'     => 'we_new',
					'secret' => 'new_secret',
				)
			);
		}
		$this->provider( $responses );
		Pos_Webhook::ensure( 'sk_test_fake', $mode );
		$this->assertSame( array( 'limit' => 100 ), $this->http->requests[0]['params'] );
		$this->assertCount( count( $responses ), $this->http->requests );
		$settings = $this->options['woocommerce_stripe_terminal_for_woocommerce_settings'];
		$this->assertSame( 'legacy', $settings['webhook_secret'] );
		$this->assertSame( $exists && $secret ? 'saved' : 'new_secret', $settings[ $key ] );
		if ( $exists && ! $secret ) {
			$this->assertSame( 'delete', $this->http->requests[1]['method'] );
			$this->assertStringEndsWith( '/webhook_endpoints/we_pos', $this->http->requests[1]['url'] );
		}
		if ( ! $exists || ! $secret ) {
			$this->assertSame(
				array(
					'url'            => self::URL,
					'enabled_events' => array( 'payment_intent.succeeded', 'payment_intent.payment_failed', 'payment_intent.canceled', 'terminal.reader.action_succeeded', 'terminal.reader.action_failed' ),
				),
				end( $this->http->requests )['params']
			);
		}
	}

	public function configurations(): array {
		return array( array( true, true, 'test' ), array( true, false, 'test' ), array( false, false, 'test' ), array( false, false, 'live' ) );
	}

	public function test_setup_error_preserves_existing_settings(): void {
		$before = $this->options;
		$this->provider( array( $this->error( 'resource_missing' ) ) );
		Pos_Webhook::ensure( 'sk_test_fake', 'test' );
		$this->assertSame( $before, $this->options );
	}
}
