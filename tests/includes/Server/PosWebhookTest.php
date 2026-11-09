<?php
namespace WCPOS\WooCommercePOS\StripeTerminal\Tests\Server;
use WCPOS\WooCommercePOS\StripeTerminal\Server\Pos_Webhook;
use WCPOS\WooCommercePOS\StripeTerminal\Settings;
require_once __DIR__ . '/ServerTestCase.php';
class PosWebhookTest extends ServerTestCase {
	private const URL = 'https://store.test/wp-json/wcpos/v2/payments/webhook?provider=stripe';
	private const EVENTS = array( 'payment_intent.succeeded', 'payment_intent.payment_failed', 'payment_intent.canceled', 'terminal.reader.action_succeeded', 'terminal.reader.action_failed' );
	/** @dataProvider configurations */
	public function test_endpoint_reconciliation( bool $exists, bool $secret, string $mode, string $stored_id = 'we_pos', bool $wiped = false ): void {
		$key = 'test' === $mode ? 'test_pos_webhook_secret' : 'pos_webhook_secret';
		$id_key = 'test' === $mode ? 'test_pos_webhook_endpoint_id' : 'pos_webhook_endpoint_id';
		$reuse = $exists && $secret && 'we_pos' === $stored_id && ! $wiped;
		$this->options['woocommerce_stripe_terminal_for_woocommerce_settings'] = array(
			'webhook_secret' => 'legacy',
			'test_mode' => 'test' === $mode ? 'yes' : 'no',
			$id_key => $stored_id,
			$key             => $secret ? 'saved' : '',
		);
		if ( $wiped ) {
			unset( $this->options['woocommerce_stripe_terminal_for_woocommerce_settings'] );
		}
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
				'enabled_events' => self::EVENTS,
				'status' => 'enabled',
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
		if ( $exists && ! $reuse ) {
			$responses[] = $this->ok(
				array(
					'id'      => 'we_pos',
					'deleted' => true,
				)
			);
		}
		if ( ! $reuse ) {
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
		if ( ! $wiped ) {
			$this->assertSame( 'legacy', $settings['webhook_secret'] );
		}
		$this->assertSame( $reuse ? 'we_pos' : 'we_new', $settings[ $id_key ] );
		$this->options['woocommerce_stripe_terminal_for_woocommerce_settings']['test_mode'] = 'test' === $mode ? 'yes' : 'no';
		$this->assertSame( $reuse ? 'we_pos' : 'we_new', Settings::get_pos_webhook_endpoint_id() );
		$this->assertSame( $reuse ? 'saved' : 'new_secret', $settings[ $key ] );
		if ( $exists && ! $reuse ) {
			$this->assertSame( 'delete', $this->http->requests[1]['method'] );
			$this->assertStringEndsWith( '/webhook_endpoints/we_pos', $this->http->requests[1]['url'] );
		}
		if ( ! $reuse ) {
			$this->assertSame(
				array(
					'url'            => self::URL,
					'enabled_events' => self::EVENTS,
				),
				end( $this->http->requests )['params']
			);
		}
	}

	public function configurations(): array {
		return array(
			array( true, true, 'test' ),
			array( true, true, 'live' ),
			array( true, false, 'test' ),
			array( false, false, 'test' ),
			array( false, false, 'live' ),
			array( true, true, 'test', 'we_old' ),
			array( true, true, 'live', 'we_old' ),
			array( true, false, 'test', '', true ),
		);
	}

	/** @dataProvider endpoint_updates */
	public function test_matching_endpoint_is_updated( array $events, string $status, array $expected ): void {
		$this->options['woocommerce_stripe_terminal_for_woocommerce_settings']['test_pos_webhook_endpoint_id'] = 'we_pos';
		$before = $this->options;
		$this->provider(
			array(
				$this->ok(
					array(
						'object' => 'list',
						'data' => array(
							array( 'id' => 'we_pos', 'object' => 'webhook_endpoint', 'url' => self::URL, 'enabled_events' => $events, 'status' => $status ),
						),
					)
				),
				$this->ok( array( 'id' => 'we_pos', 'object' => 'webhook_endpoint' ) ),
			)
		);
		Pos_Webhook::ensure( 'sk_test_fake', 'test' );
		$this->assertCount( 2, $this->http->requests );
		$this->assertSame( 'post', $this->http->requests[1]['method'] );
		$this->assertStringEndsWith( '/webhook_endpoints/we_pos', $this->http->requests[1]['url'] );
		$this->assertSame( $expected, $this->http->requests[1]['params'] );
		$this->assertSame( $before, $this->options );
	}

	public function endpoint_updates(): array {
		return array(
			array( array_slice( self::EVENTS, 0, 4 ), 'enabled', array( 'enabled_events' => self::EVENTS ) ),
			array( self::EVENTS, 'disabled', array( 'status' => 'enabled' ) ),
			array( array(), 'disabled', array( 'enabled_events' => self::EVENTS, 'status' => 'enabled' ) ),
		);
	}

	public function test_setup_error_preserves_existing_settings(): void {
		$before = $this->options;
		$this->provider( array( $this->error( 'resource_missing' ) ) );
		Pos_Webhook::ensure( 'sk_test_fake', 'test' );
		$this->assertSame( $before, $this->options );
	}
}
