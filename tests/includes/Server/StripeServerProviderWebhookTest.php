<?php
namespace WCPOS\WooCommercePOS\StripeTerminal\Tests\Server;
use WCPOS\WooCommercePOS\StripeTerminal\Server\Stripe_Server_Provider as Provider;
require_once __DIR__ . '/ServerTestCase.php';
class StripeServerProviderWebhookTest extends ServerTestCase {
	private function request( string $type, array $event_object, string $secret = 'whsec_test' ): \WP_REST_Request {
		$payload = json_encode(
			array(
				'id'     => 'evt_test',
				'object' => 'event',
				'type'   => $type,
				'data'   => array( 'object' => $event_object ),
			)
		);
		$t       = time();
		$request = new \WP_REST_Request();
		$request->set_body( $payload );
		$request->set_header( 'stripe-signature', 't=' . $t . ',v1=' . hash_hmac( 'sha256', "$t.$payload", $secret ) );
		return $request;
	}

	public function test_signed_success_patch(): void {
		$result = $this->provider()->verify_webhook(
			$this->request(
				'payment_intent.succeeded',
				$this->intent(
					array(
						'status'          => 'succeeded',
						'amount_received' => 1500,
						'latest_charge'   => $this->charge(),
					)
				)
			)
		);
		$this->assertSame( strtolower( self::PAYMENT_ID ), $result['payment_id'] );
		$this->assertSame( 'evt_test', $result['patch']['event_id'] );
		$this->assertSame( 'captured', $result['patch']['status'] );
		$this->assertSame( '15.00', $result['patch']['amount'] );
		$this->assertSame( 'USD', $result['patch']['currency'] );
		$this->assertSame( '4242', $result['patch']['receipt']['card_last4'] );
		$this->assertArrayNotHasKey( 'provider_refs', $result['patch'] );
	}

	/** @dataProvider reader_events */
	public function test_reader_event_retrieves_intent( string $event, string $state, array $patch ): void {
		$provider = $this->provider( array( $this->ok( $this->intent( array( 'status' => $state ) ) ) ) );
		$result   = $provider->verify_webhook(
			$this->request(
				$event,
				array(
					'id'     => 'tmr_test',
					'object' => 'terminal.reader',
					'action' => array( 'process_payment_intent' => array( 'payment_intent' => 'pi_test' ) ),
				)
			)
		);
		$this->assertSame( $patch, $result['patch'] );
		$this->assertStringEndsWith( '/payment_intents/pi_test', $this->http->requests[0]['url'] );
	}

	public function reader_events(): array {
		return array(
			array(
				'terminal.reader.action_succeeded',
				'succeeded',
				array(
					'event_id' => 'evt_test',
					'status'   => 'captured',
					'amount'   => '12.50',
					'currency' => 'USD',
					'receipt'  => array(),
				),
			),
			array( 'terminal.reader.action_failed', 'requires_payment_method', array( 'event_id' => 'evt_test' ) ),
		);
	}

	/** @dataProvider rejected */
	public function test_rejected_webhooks( string $scenario, int $status, string $code ): void {
		$intent = $this->intent();
		$type   = 'payment_intent.succeeded';
		$secret = 'bad' === $scenario ? 'wrong' : 'whsec_test';
		if ( 'missing' === $scenario ) {
			$this->options = array();
		}
		if ( 'uuid' === $scenario ) {
			$intent['metadata'] = array();
		}
		if ( 'mode' === $scenario ) {
			$intent['livemode'] = true;
		}
		if ( 'ignored' === $scenario ) {
			$type = 'charge.succeeded';
		}
		if ( 'reader' === $scenario ) {
			$type = 'terminal.reader.action_failed';
		}
		$result = $this->provider()->verify_webhook( $this->request( $type, $intent, $secret ) );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( $status, $result->get_error_data()['status'] );
	}

	public function rejected(): array {
		return array( array( 'bad', 401, 'stripe_webhook_bad_signature' ), array( 'missing', 500, 'stripe_pos_webhook_unconfigured' ), array( 'uuid', 404, 'stripe_webhook_unknown_payment' ), array( 'mode', 403, 'stripe_webhook_mode_mismatch' ), array( 'ignored', 200, 'stripe_webhook_ignored' ), array( 'reader', 404, 'stripe_webhook_no_intent' ) );
	}

	/** @dataProvider non_money */
	public function test_non_money_patch_never_forces_a_transition( string $state ): void {
		$this->assertSame( array( 'event_id' => 'evt_other' ), Provider::webhook_patch( $this->intent( array( 'status' => $state ) ), 'evt_other' ) );
	}

	public function non_money(): array {
		return array( array( 'canceled' ), array( 'requires_capture' ), array( 'requires_payment_method' ), array( 'processing' ), array( 'requires_action' ), array( 'requires_confirmation' ) );
	}

	public function test_live_mode_uses_only_the_pos_live_secret(): void {
		$this->options['woocommerce_stripe_terminal_for_woocommerce_settings'] = array(
			'pos_webhook_secret' => 'whsec_live',
			'webhook_secret'     => 'legacy',
		);
		$result = $this->provider()->verify_webhook(
			$this->request(
				'payment_intent.succeeded',
				$this->intent(
					array(
						'status'   => 'succeeded',
						'livemode' => true,
					)
				),
				'whsec_live'
			)
		);
		$this->assertSame( 'captured', $result['patch']['status'] );
	}

	public function test_reader_event_api_error_is_enveloped(): void {
		$provider = $this->provider( array( $this->error( 'resource_missing' ) ) );
		$request  = $this->request( 'terminal.reader.action_failed', array( 'action' => array( 'process_payment_intent' => array( 'payment_intent' => 'pi_test' ) ) ) );
		$this->assert_provider_error( $provider->verify_webhook( $request ), 'resource_missing' );
	}

	public function test_signed_invalid_json_is_bad_request(): void {
		$request = new \WP_REST_Request();
		$request->set_body( 'invalid json' );
		$t = time();
		$request->set_header( 'stripe-signature', 't=' . $t . ',v1=' . hash_hmac( 'sha256', "$t.invalid json", 'whsec_test' ) );
		$this->assertSame( 400, $this->provider()->verify_webhook( $request )->get_error_data()['status'] );
	}
}
