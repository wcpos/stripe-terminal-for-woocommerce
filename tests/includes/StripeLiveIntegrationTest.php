<?php
/**
 * Stripe and request-contract smoke tests.
 *
 * Stripe tests intentionally hit Stripe's real test-mode API when
 * STWC_STRIPE_TEST_SECRET_KEY is available. They skip otherwise.
 */

namespace WCPOS\WooCommercePOS\StripeTerminal\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\StripeTerminal\AjaxHandler;
use WCPOS\WooCommercePOS\StripeTerminal\StripeTerminalService;

/**
 * @coversNothing
 */
class StripeLiveIntegrationTest extends TestCase {
	/**
	 * Stripe test secret key loaded from environment or local ignored env file.
	 *
	 * @var string
	 */
	private $secret_key;

	/**
	 * @var string|false
	 */
	private $previous_secret_key_env = false;

	/**
	 * @var bool
	 */
	private $previous_secret_key_superglobal_exists = false;

	/**
	 * @var string|null
	 */
	private $previous_secret_key_superglobal;

	protected function setUp(): void {
		parent::setUp();

		$this->previous_secret_key_env                = getenv( 'STWC_STRIPE_TEST_SECRET_KEY' );
		$this->previous_secret_key_superglobal_exists = array_key_exists( 'STWC_STRIPE_TEST_SECRET_KEY', $_ENV );
		$this->previous_secret_key_superglobal        = $this->previous_secret_key_superglobal_exists ? $_ENV['STWC_STRIPE_TEST_SECRET_KEY'] : null;
	}

	public function test_invalid_ajax_nonce_returns_invalid_request_before_payment_validation(): void {
		$original_post = $_POST;

		Monkey\setUp();
		try {
			$_POST = array(
				'nonce'     => 'invalid_nonce',
				'order_id'  => '42',
				'amount'    => '1234',
				'reader_id' => 'tmr_test_reader',
			);

			Functions\stubs(
				array(
					'add_action'          => true,
					'get_option'          => array(),
					'absint'              => function ( $value ) {
						return abs( (int) $value );
					},
					'sanitize_text_field' => function ( $value ) {
						return $value;
					},
					'wp_unslash'          => function ( $value ) {
						return $value;
					},
				)
			);
			$order = \Mockery::mock( 'WC_Order' );
			$order->shouldReceive( 'get_order_key' )->andReturn( 'wc_order_test' );
			Functions\when( 'wc_get_order' )->justReturn( $order );
			Functions\when( 'check_ajax_referer' )->justReturn( false );
			Functions\when( 'wp_send_json_error' )->alias(
				function ( $data = null ) {
					throw new StripeLiveJsonErrorSentinel( $data );
				}
			);

			$handler = new AjaxHandler();

			try {
				$handler->create_payment_intent();
				$this->fail( 'Expected invalid nonce to return a JSON error.' );
			} catch ( StripeLiveJsonErrorSentinel $error ) {
				$this->assertSame( 'Security token expired or invalid. Please refresh or reopen the POS checkout and try again.', $error->data );
			}
		} finally {
			$_POST = $original_post;
			\Mockery::close();
			Monkey\tearDown();
		}
	}

	public function test_creates_terminal_connection_token_against_stripe_test_mode(): void {
		$this->require_stripe_test_key();

		$token = \Stripe\Terminal\ConnectionToken::create();

		$this->assertIsString( $token->secret );
		$this->assertStringStartsWith( 'pst_test_', $token->secret );
	}

	public function test_creates_card_present_payment_intent_against_stripe_test_mode(): void {
		$this->require_stripe_test_key();

		$payment_intent = \Stripe\PaymentIntent::create(
			array(
				'amount'                       => 1234,
				'currency'                     => 'usd',
				'allowed_payment_method_types' => array( 'card_present' ),
				'capture_method'               => 'manual',
				'description'                  => 'STWC live integration smoke test',
				'metadata'                     => array(
					'stwc_test' => 'stripe_live_integration',
				),
			)
		);

		$this->assertStringStartsWith( 'pi_', $payment_intent->id );
		$this->assertSame( 1234, $payment_intent->amount );
		$this->assertSame( 'usd', $payment_intent->currency );
		$this->assertSame( array( 'card_present' ), $payment_intent->payment_method_types );
		$this->assertIsString( $payment_intent->client_secret );
		$this->assertStringContainsString( '_secret_', $payment_intent->client_secret );
	}

	public function test_refunds_confirmed_payment_intent_against_stripe_test_mode(): void {
		$this->require_stripe_test_key();

		Monkey\setUp();
		try {
			$payment_intent = \Stripe\PaymentIntent::create(
				array(
					'amount'                       => 1234,
					'currency'                     => 'usd',
					'allowed_payment_method_types' => array( 'card' ),
					'payment_method'               => 'pm_card_visa',
					'confirm'                      => true,
					'description'                  => 'STWC live integration refund smoke test',
					'metadata'                     => array(
						'stwc_test' => 'stripe_live_integration',
					),
				)
			);
			$this->assertSame( 'succeeded', $payment_intent->status );

			$service = new StripeTerminalService( $this->secret_key );
			$refund  = $service->refund_payment(
				$payment_intent->id,
				null,
				array(
					'request_options' => array(
						'idempotency_key' => 'stwc-smoke-refund-' . $payment_intent->id,
					),
				)
			);

			if ( is_wp_error( $refund ) ) {
				$this->fail( 'refund_payment returned WP_Error: ' . $refund->get_error_message() );
			}

			$this->assertStringStartsWith( 're_', $refund['id'] );
			$this->assertSame( 1234, $refund['amount'] );
			$this->assertContains( $refund['status'], array( 'succeeded', 'pending' ) );
		} finally {
			Monkey\tearDown();
		}
	}

	/**
	 * POS-31F reproduction: on API 2026-09-30.endive `allowed_payment_method_types` is a filter, so a
	 * currency the account cannot take in person drops `card_present` and the create succeeds; the
	 * reader dispatch is then refused.
	 */
	public function test_endive_drops_card_present_for_a_currency_the_account_cannot_take_in_person(): void {
		$this->require_stripe_test_key();

		Monkey\setUp();
		$intent  = null;
		$created = array();
		try {
			$this->stub_service_functions();
			$currency = $this->currency_the_account_cannot_take_in_person();

			// Before endive this create failed; it must now succeed with card_present filtered out.
			$intent = \Stripe\PaymentIntent::create(
				array(
					'amount'                       => 1234,
					'currency'                     => $currency,
					'allowed_payment_method_types' => array( 'card_present' ),
					'description'                  => 'STWC live POS-31F reproduction',
					'metadata'                     => array( 'stwc_test' => 'stripe_live_integration' ),
				)
			);
			$this->assertNotContains( 'card_present', $intent->payment_method_types );

			$reader_id = $this->simulated_reader( $created );
			$result    = ( new StripeTerminalService( $this->secret_key ) )->process_payment_intent( $reader_id, $intent->id );
			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertStringContainsStringIgnoringCase( 'in-person', $result->get_error_message() );
		} finally {
			if ( null !== $intent ) {
				$this->cancel_quietly( $intent->id );
			}
			$this->delete_created( $created );
			Monkey\tearDown();
		}
	}

	/** POS-31F: the service refuses and cancels the intent Stripe created without `card_present`. */
	public function test_server_intent_without_card_present_is_refused_and_cancelled(): void {
		$this->require_stripe_test_key();

		Monkey\setUp();
		$intent_id = null;
		$api_base  = \Stripe\Stripe::$apiBase; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Stripe SDK property.
		try {
			$this->stub_service_functions();
			$currency = $this->currency_the_account_cannot_take_in_person();
			$service  = new StripeTerminalService( $this->secret_key );

			// The country is unknown, as when the key lacks the Account read: no cached country, and
			// the static Account::retrieve() goes to a closed port. The service's StripeClient keeps
			// its own api_base, so the create still reaches Stripe.
			\Stripe\Stripe::$apiBase = 'https://127.0.0.1:9'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Stripe SDK property.
			$error                   = $service->create_server_payment_intent( 1234, $currency, 'STWC live POS-31F', array( 'stwc_test' => 'stripe_live_integration' ), 'stwc-live-31f-' . bin2hex( random_bytes( 6 ) ), false );
			\Stripe\Stripe::$apiBase = $api_base; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Stripe SDK property.

			$this->assertInstanceOf( \WP_Error::class, $error, 'the create must be refused, not returned' );
			$this->assertSame( 'card_present_unavailable', $error->get_error_code(), $error->get_error_message() );
			$intent_id = $error->get_error_data()['payment_intent'];
			$this->assertSame( 'canceled', \Stripe\PaymentIntent::retrieve( $intent_id )->status );
		} finally {
			\Stripe\Stripe::$apiBase = $api_base; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Stripe SDK property.
			if ( null !== $intent_id ) {
				$this->cancel_quietly( $intent_id );
			}
			Monkey\tearDown();
		}
	}

	/** POS-31F diagnostic D1: does a fresh server-provider intent carry card_present, and does it dispatch? */
	public function test_pos31f_diag_server_intent_create_and_dispatch(): void {
		$this->require_stripe_test_key();
		$this->diag_run(
			'D1',
			function ( StripeTerminalService $service, array &$run ): void {
				$intent = $service->create_server_payment_intent( 1234, $this->currency_the_account_takes_in_person(), 'STWC diag POS-31F D1', array( 'stwc_test' => 'pos31f_diag' ), $this->diag_key(), false );
				if ( is_wp_error( $intent ) ) {
					$this->diag( $service, 'D1', array( 'create' => $this->diag_error( $intent ) ) );
					$this->markTestIncomplete( 'create_server_payment_intent returned a WP_Error; see the POS31F-DIAG line.' );
				}
				$this->assertArrayHasKey( 'id', $intent );
				$run['ids'][] = $intent['id'];
				try {
					$headers        = $service->get_stripe_client()->paymentIntents->retrieve( $intent['id'] )->getLastResponse()->headers;
					$stripe_version = $headers['Stripe-Version'] ?? $headers['stripe-version'] ?? null;
				} catch ( \Throwable $e ) {
					$stripe_version = $this->diag_thrown( $e );
				}
				$sdk_api_version = \Stripe\Util\ApiVersion::CURRENT;
				$this->diag( $service, 'D1', $this->diag_intent( $intent ) + compact( 'stripe_version', 'sdk_api_version' ) );
				$run['step'] = 'dispatch';
				$this->diag( $service, 'D1', array( 'dispatch' => $this->diag_dispatch( $service, $run, $intent['id'] ) ) );
			}
		);
	}

	/** POS-31F diagnostic D2: what does a replay return when the key was first used with other parameters? */
	public function test_pos31f_diag_replay_with_other_params(): void {
		$this->require_stripe_test_key();
		$this->diag_run(
			'D2',
			function ( StripeTerminalService $service, array &$run ): void {
				$key          = $this->diag_key();
				$first        = $this->diag_card_intent( $service, array( 'idempotency_key' => $key ) );
				$run['ids'][] = $first['id'];
				$this->diag( $service, 'D2', array( 'first' => $this->diag_intent( $first ) ) );
				$run['step'] = 'replay';
				$replay      = $service->create_server_payment_intent( 1234, $this->currency_the_account_takes_in_person(), 'STWC diag POS-31F D2', array( 'stwc_test' => 'pos31f_diag' ), $key, false );
				$this->diag( $service, 'D2', array( 'replay' => $this->diag_replay( $replay, $first['id'], $run ) ) );
			}
		);
	}

	/** POS-31F diagnostic D3: what does a replay return after the intent was cancelled, and does it dispatch? */
	public function test_pos31f_diag_replay_after_cancel(): void {
		$this->require_stripe_test_key();
		$this->diag_run(
			'D3',
			function ( StripeTerminalService $service, array &$run ): void {
				$args  = array( 1234, $this->currency_the_account_takes_in_person(), 'STWC diag POS-31F D3', array( 'stwc_test' => 'pos31f_diag' ), $this->diag_key(), false );
				$first = $service->create_server_payment_intent( ...$args );
				if ( is_wp_error( $first ) ) {
					$this->diag( $service, 'D3', array( 'first' => $this->diag_error( $first ) ) );
					$this->fail( 'The first create returned a WP_Error; see the POS31F-DIAG line.' );
				}
				$run['ids'][] = $first['id'];
				$first_id     = $first['id'];
				$run['step']  = 'cancel';
				$cancel       = $this->diag_error( $service->cancel_payment_intent_by_id( $first_id ) );
				$run['step']  = 'replay';
				$replay       = $this->diag_replay( $service->create_server_payment_intent( ...$args ), $first_id, $run );
				$this->diag( $service, 'D3', compact( 'first_id', 'cancel', 'replay' ) );
				if ( isset( $replay['id'] ) ) {
					$run['step'] = 'dispatch';
					$this->diag( $service, 'D3', array( 'dispatch' => $this->diag_dispatch( $service, $run, $replay['id'] ) ) );
				}
			}
		);
	}

	/** POS-31F diagnostic D4: Stripe's exact dispatch refusal for an intent without card_present. */
	public function test_pos31f_diag_card_only_dispatch_message(): void {
		$this->require_stripe_test_key();
		$this->diag_run(
			'D4',
			function ( StripeTerminalService $service, array &$run ): void {
				$intent               = $this->diag_card_intent( $service, array() );
				$run['ids'][]         = $intent['id'];
				$payment_method_types = $intent['payment_method_types'] ?? null;
				$run['step']          = 'dispatch';
				$plain                = $this->diag_dispatch( $service, $run, $intent['id'] );
				$moto                 = $this->diag_dispatch( $service, $run, $intent['id'], array( 'moto' => true ) );
				$matches_sentry       = 'PaymentIntent must accept an in-person payment method.' === ( $plain['message'] ?? null );
				$this->diag( $service, 'D4', compact( 'payment_method_types', 'plain', 'moto', 'matches_sentry' ) );
			}
		);
	}

	/** POS-31F diagnostic D5: does Stripe drop card_present at create on an account without card_present_payments? */
	public function test_pos31f_diag_account_without_card_present_capability(): void {
		$this->require_stripe_test_key();
		$this->diag_run(
			'D5',
			function ( StripeTerminalService $service, array &$run ): void {
				$platform = $service->get_stripe_client();
				$country  = $this->diag_ignoring_stripe_notices( fn() => \Stripe\Account::retrieve()->country );
				if ( 'US' !== $country ) {
					$this->diag( $service, 'D5', array( 'skipped' => 'platform country ' . $country ) );
					$this->markTestSkipped( 'D5 needs a US platform account.' );
				}
				$run['step'] = 'account';
				$this->diag( $service, 'D5 sweep', $this->diag_sweep_accounts( $platform ) );
				try {
					$acct_id = $this->diag_ignoring_stripe_notices( fn() => $platform->accounts->create( $this->diag_account_params() )->id );
				} catch ( \Throwable $e ) {
					$this->diag( $service, 'D5', array( 'account' => $this->diag_thrown( $e ) ) );
					$this->markTestSkipped( 'Could not create a Custom connected account; see the POS31F-DIAG line.' );
				}
				try {
					$account = $this->diag_ignoring_stripe_notices( fn() => $platform->accounts->retrieve( $acct_id )->toArray() );
					$fields  = array(
						'id'                    => $acct_id,
						'card_payments'         => $account['capabilities']['card_payments'] ?? null,
						'card_present_payments' => $account['capabilities']['card_present_payments'] ?? null,
						'charges_enabled'       => $account['charges_enabled'] ?? null,
					);
					$this->diag( $service, 'D5 account', $fields );
					if ( 'active' !== $fields['card_payments'] ) {
						$this->markTestIncomplete( 'The connected account cannot charge (card_payments is not active); see the POS31F-DIAG line.' );
					}
					// The server path on the connected account: every intent call below goes through this client.
					$service->set_stripe_client(
						new \Stripe\StripeClient(
							array(
								'api_key'        => $this->secret_key,
								'stripe_account' => $acct_id,
							)
						)
					);
					$run['step'] = 'service';
					$result      = $service->create_server_payment_intent( 1234, 'usd', 'STWC diag POS-31F D5', array( 'stwc_test' => 'pos31f_diag' ), $this->diag_key(), false );
					$service_id  = is_wp_error( $result ) ? ( ( (array) $result->get_error_data() )['payment_intent'] ?? null ) : ( $result['id'] ?? null );
					if ( null !== $service_id ) {
						$run['ids'][] = $service_id;
					}

					$run['step'] = 'raw';
					try {
						$params       = array(
							'amount'                       => 1234,
							'currency'                     => 'usd',
							'description'                  => 'STWC diag POS-31F D5',
							'metadata'                     => array( 'stwc_test' => 'pos31f_diag' ),
							'capture_method'               => 'automatic',
							'allowed_payment_method_types' => array( 'card_present' ),
						);
						$raw          = $service->get_stripe_client()->paymentIntents->create( $params, array( 'idempotency_key' => $this->diag_key() ) )->toArray();
						$run['ids'][] = $raw['id'];
						$raw_fields   = array_intersect_key( $raw, array_flip( array( 'payment_method_types', 'status' ) ) );
					} catch ( \Throwable $e ) {
						$raw_fields = $this->diag_thrown( $e );
					}
					$this->diag( $service, 'D5 raw', $raw_fields );
					$this->diag( $service, 'D5 service', is_wp_error( $result ) ? $this->diag_error( $result ) : $this->diag_intent( $result ) );

					if ( ! isset( $raw['payment_method_types'] ) || \in_array( 'card_present', $raw['payment_method_types'], true ) ) {
						$this->markTestIncomplete( 'Stripe did not drop card_present on an account without card_present_payments' );
					}
					$this->assertInstanceOf( \WP_Error::class, $result, 'the guard must refuse an intent created without card_present' );
					$this->assertSame( 'card_present_unavailable', $result->get_error_code(), $result->get_error_message() );
					$this->assertSame( 'canceled', $service->get_stripe_client()->paymentIntents->retrieve( $service_id )->status );
				} finally {
					foreach ( array_unique( $run['ids'] ) as $intent_id ) {
						try {
							$service->get_stripe_client()->paymentIntents->cancel( $intent_id );
						} catch ( \Throwable $e ) {
							unset( $e );
						}
					}
					try {
						$this->diag_ignoring_stripe_notices( fn() => $platform->accounts->delete( $acct_id ) );
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
			}
		);
	}

	/**
	 * Run a Connect account call with stripe-php's Stripe-Notice E_USER_WARNING swallowed; other levels return false.
	 *
	 * @param callable $call The account call.
	 * @return mixed What the call returned.
	 */
	private function diag_ignoring_stripe_notices( callable $call ) {
		set_error_handler( fn( int $errno ): bool => E_USER_WARNING === $errno ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- the Accounts v2 notice must not fail D5.
		try {
			return $call();
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * Delete diag accounts left by earlier D5 runs: `deleted` count, or what the list threw.
	 *
	 * @param \Stripe\StripeClient $platform The platform client.
	 */
	private function diag_sweep_accounts( $platform ): array {
		try {
			$accounts = $this->diag_ignoring_stripe_notices( fn() => $platform->accounts->all( array( 'limit' => 20 ) )->data );
		} catch ( \Throwable $e ) {
			return $this->diag_thrown( $e );
		}
		$deleted = 0;
		foreach ( $accounts as $account ) {
			try {
				if ( 'stwc-diag-31f@example.com' === $account->email ) {
					$this->diag_ignoring_stripe_notices( fn() => $platform->accounts->delete( $account->id ) );
					++$deleted;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
		return array( 'deleted' => $deleted );
	}

	/** A test-mode US Custom account with card_payments and transfers requested, and no card_present_payments. */
	private function diag_account_params(): array {
		return array(
			'type'             => 'custom',
			'country'          => 'US',
			'email'            => 'stwc-diag-31f@example.com',
			'business_type'    => 'individual',
			'capabilities'     => array(
				'card_payments' => array( 'requested' => true ),
				'transfers'     => array( 'requested' => true ),
			),
			'business_profile' => array(
				'mcc' => '5734',
				'url' => 'https://example.com',
			),
			'individual'       => array(
				'first_name' => 'Jenny',
				'last_name'  => 'Rosen',
				'dob'        => array(
					'day'   => 1,
					'month' => 1,
					'year'  => 1901,
				),
				'ssn_last_4' => '0000',
				'phone'      => '0000000000',
				'email'      => 'stwc-diag-31f@example.com',
				'address'    => array(
					'line1'       => 'address_full_match',
					'city'        => 'San Francisco',
					'state'       => 'CA',
					'postal_code' => '94103',
				),
			),
			'tos_acceptance'   => array(
				'date' => time(),
				'ip'   => '8.8.8.8',
			),
			'external_account' => 'btok_us_verified',
		);
	}

	/**
	 * Run a POS-31F diagnostic body: print what a step threw, clean up, then assert the first create ran.
	 *
	 * @param string   $id   Diagnostic id.
	 * @param callable $body Receives the service and the run state (step, ids, reader, created) by reference.
	 * @throws \PHPUnit\Framework\AssertionFailedError A failure, skip or incomplete from the body.
	 */
	private function diag_run( string $id, callable $body ): void {
		Monkey\setUp();
		$service = null;
		$run     = array(
			'step'    => 'create',
			'ids'     => array(),
			'reader'  => null,
			'created' => array(),
		);
		try {
			$this->stub_service_functions();
			$service = new StripeTerminalService( $this->secret_key );
			$body( $service, $run );
		} catch ( \PHPUnit\Framework\AssertionFailedError $e ) {
			throw $e;
		} catch ( \Throwable $e ) {
			$this->diag( $service, $id, array( $run['step'] => $this->diag_thrown( $e ) ) );
		} finally {
			if ( null !== $run['reader'] ) {
				$service->cancel_reader_action( $run['reader'] ); // Catches Stripe errors itself; the result is ignored.
			}
			foreach ( array_unique( $run['ids'] ) as $intent_id ) {
				$this->cancel_quietly( $intent_id );
			}
			$this->delete_created( $run['created'] );
			Monkey\tearDown();
		}
		$this->assertNotEmpty( $run['ids'], 'the first create ran and returned an id' );
	}

	/**
	 * Print one POS31F-DIAG finding line to STDERR, with the SDK client class.
	 *
	 * @param StripeTerminalService|null $service The service.
	 * @param string                     $id      Diagnostic id.
	 * @param array                      $fields  Findings.
	 */
	private function diag( ?StripeTerminalService $service, string $id, array $fields ): void {
		$fields['sdk'] = $service ? get_class( $service->get_stripe_client() ) : null;
		fwrite( STDERR, 'POS31F-DIAG ' . $id . ' ' . json_encode( $fields, JSON_UNESCAPED_SLASHES ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CI diagnostic; wp_json_encode is not loaded.
	}

	/** A fresh diagnostic idempotency key. */
	private function diag_key(): string {
		return 'stwc-diag-31f-' . bin2hex( random_bytes( 6 ) );
	}

	/**
	 * A raw client create that allows only `card`, as an array.
	 *
	 * @param StripeTerminalService $service The service.
	 * @param array                 $opts    Request options.
	 */
	private function diag_card_intent( StripeTerminalService $service, array $opts ): array {
		$params = array(
			'amount'                       => 1234,
			'currency'                     => $this->currency_the_account_takes_in_person(),
			'allowed_payment_method_types' => array( 'card' ),
		);
		return $service->get_stripe_client()->paymentIntents->create( $params, $opts )->toArray();
	}

	/**
	 * The fields printed for an intent.
	 *
	 * @param array $intent PaymentIntent.
	 */
	private function diag_intent( array $intent ): array {
		return array_intersect_key( $intent, array_flip( array( 'id', 'status', 'payment_method_types' ) ) );
	}

	/**
	 * The code, message and Stripe code of an error, or `ok` for a result that is not one.
	 *
	 * @param mixed $result Service result.
	 * @return array|string
	 */
	private function diag_error( $result ) {
		if ( ! is_wp_error( $result ) ) {
			return 'ok';
		}
		return array(
			'code'        => $result->get_error_code(),
			'message'     => $result->get_error_message(),
			'stripe_code' => ( (array) $result->get_error_data() )['stripe_code'] ?? null,
		);
	}

	/**
	 * A thrown step, by class and message.
	 *
	 * @param \Throwable $e What the step threw.
	 */
	private function diag_thrown( \Throwable $e ): array {
		return array(
			'error'   => get_class( $e ),
			'message' => $e->getMessage(),
		);
	}

	/**
	 * Dispatch an intent to a simulated reader: `ok`, the error fields, or what it threw.
	 *
	 * @param StripeTerminalService $service   The service.
	 * @param array                 $run       Run state; the reader is found once and kept for cleanup.
	 * @param string                $intent_id PaymentIntent ID.
	 * @param array                 $config    Process config.
	 * @return array|string
	 */
	private function diag_dispatch( StripeTerminalService $service, array &$run, string $intent_id, array $config = array() ) {
		$run['reader'] = $run['reader'] ?? $this->simulated_reader( $run['created'] );
		try {
			return $this->diag_error( $service->process_payment_intent( $run['reader'], $intent_id, $config ) );
		} catch ( \Throwable $e ) {
			return $this->diag_thrown( $e );
		}
	}

	/**
	 * A replayed create: the error fields, or the intent compared with the first.
	 *
	 * @param mixed  $replay   Service result.
	 * @param string $first_id The first intent's ID.
	 * @param array  $run      Run state; a returned intent is added for cleanup.
	 * @return array|string
	 */
	private function diag_replay( $replay, string $first_id, array &$run ) {
		if ( is_wp_error( $replay ) || ! isset( $replay['id'] ) ) {
			return $this->diag_error( $replay );
		}
		$run['ids'][] = $replay['id'];
		return array( 'same_id' => $first_id === $replay['id'] ) + $this->diag_intent( $replay );
	}

	/** WordPress functions the service calls, with the account country never cached. */
	private function stub_service_functions(): void {
		Functions\stubTranslationFunctions();
		Functions\stubs(
			array(
				'get_transient'    => false,
				'set_transient'    => true,
				'delete_transient' => true,
			)
		);
	}

	/** The first of EUR, GBP, USD the service's own country map does not give the account country. */
	private function currency_the_account_cannot_take_in_person(): string {
		$country = (string) \Stripe\Account::retrieve()->country;
		$method  = new \ReflectionMethod( StripeTerminalService::class, 'currencies_for_country' );
		$method->setAccessible( true );
		$supported = $method->invoke( new StripeTerminalService( $this->secret_key ), $country );
		foreach ( array( 'eur', 'gbp', 'usd' ) as $currency ) {
			if ( ! \in_array( $currency, $supported, true ) ) {
				return $currency;
			}
		}
		$this->fail( 'No candidate currency is outside the in-person currencies of ' . $country );
	}

	/** The first currency the service's own country map gives the account country. */
	private function currency_the_account_takes_in_person(): string {
		$country = (string) \Stripe\Account::retrieve()->country;
		$method  = new \ReflectionMethod( StripeTerminalService::class, 'currencies_for_country' );
		$method->setAccessible( true );
		$supported = $method->invoke( new StripeTerminalService( $this->secret_key ), $country );
		$this->assertNotEmpty( $supported, 'The service gives no in-person currency for ' . $country );
		return (string) reset( $supported );
	}

	/**
	 * An existing simulated reader, or one registered at a new US location.
	 *
	 * @param array $created Objects this test created, deleted by the caller.
	 */
	private function simulated_reader( array &$created ): string {
		foreach ( \Stripe\Terminal\Reader::all( array( 'limit' => 100 ) )->data as $reader ) {
			if ( 0 === strpos( (string) $reader->device_type, 'simulated_' ) ) {
				return $reader->id;
			}
		}
		$country = (string) \Stripe\Account::retrieve()->country;
		if ( 'US' !== $country ) {
			$this->markTestSkipped( 'No simulated reader, and no test address for a location in ' . $country );
		}
		$created[] = \Stripe\Terminal\Location::create(
			array(
				'display_name' => 'STWC live POS-31F',
				'address'      => array(
					'line1'       => '510 Townsend St',
					'city'        => 'San Francisco',
					'state'       => 'CA',
					'postal_code' => '94103',
					'country'     => 'US',
				),
			)
		);
		$reader    = \Stripe\Terminal\Reader::create(
			array(
				'registration_code' => 'simulated-wpe',
				'location'          => $created[0]->id,
				'label'             => 'STWC live POS-31F',
			)
		);
		array_unshift( $created, $reader );
		return $reader->id;
	}

	/**
	 * Delete what `simulated_reader()` created, reader first.
	 *
	 * @param array $created Stripe objects to delete.
	 */
	private function delete_created( array $created ): void {
		foreach ( $created as $object ) {
			try {
				$object->delete();
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
	}

	/**
	 * Cancel an intent unless already cancelled, ignoring errors.
	 *
	 * @param string $intent_id PaymentIntent ID.
	 */
	private function cancel_quietly( string $intent_id ): void {
		try {
			$intent = \Stripe\PaymentIntent::retrieve( $intent_id );
			if ( 'canceled' !== $intent->status ) {
				$intent->cancel();
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	private function require_stripe_test_key(): void {
		$this->load_local_env_file();

		$this->secret_key = (string) getenv( 'STWC_STRIPE_TEST_SECRET_KEY' );
		if ( '' === $this->secret_key ) {
			$this->markTestSkipped( 'STWC_STRIPE_TEST_SECRET_KEY is not set.' );
		}

		$this->assertStringStartsWith( 'sk_test_', $this->secret_key, 'Stripe integration tests must use a test-mode secret key.' );
		\Stripe\Stripe::setApiKey( $this->secret_key );
	}

	protected function tearDown(): void {
		\Stripe\Stripe::setApiKey( '' );

		if ( false === $this->previous_secret_key_env ) {
			putenv( 'STWC_STRIPE_TEST_SECRET_KEY' );
		} else {
			putenv( 'STWC_STRIPE_TEST_SECRET_KEY=' . $this->previous_secret_key_env );
		}

		if ( $this->previous_secret_key_superglobal_exists ) {
			$_ENV['STWC_STRIPE_TEST_SECRET_KEY'] = $this->previous_secret_key_superglobal;
		} else {
			unset( $_ENV['STWC_STRIPE_TEST_SECRET_KEY'] );
		}

		parent::tearDown();
	}

	private function load_local_env_file(): void {
		$env_file = dirname( __DIR__, 2 ) . '/.env.local';
		if ( ! is_readable( $env_file ) ) {
			return;
		}

		$lines = file( $env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		if ( false === $lines ) {
			return;
		}

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === $line[0] || false === strpos( $line, '=' ) ) {
				continue;
			}

			list( $name, $value ) = array_map( 'trim', explode( '=', $line, 2 ) );
			if ( 'STWC_STRIPE_TEST_SECRET_KEY' !== $name || false !== getenv( $name ) ) {
				continue;
			}

			$value = trim( $value, "\"'" );
			putenv( $name . '=' . $value );
			$_ENV[ $name ] = $value;
		}
	}
}

class StripeLiveJsonErrorSentinel extends \Error {
	/**
	 * @var mixed
	 */
	public $data;

	public function __construct( $data = null ) {
		$this->data = $data;
		parent::__construct( 'wp_send_json_error' );
	}
}
