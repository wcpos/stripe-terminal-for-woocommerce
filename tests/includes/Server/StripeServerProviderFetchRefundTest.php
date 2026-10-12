<?php
namespace WCPOS\WooCommercePOS\StripeTerminal\Tests\Server;

require_once __DIR__ . '/ServerTestCase.php';

/** Pro's refund sweep reads a refund `refund()` answered `pending` back through `fetch_refund()`. */
class StripeServerProviderFetchRefundTest extends ServerTestCase {
	/**
	 * A pending allocation as Pro's sweep hands it over.
	 *
	 * @param string $ref The refund id `refund()` returned.
	 */
	private function allocation( string $ref = 're_test' ): array {
		return array(
			'id' => 123,
			'amount' => '2.50',
			'status' => 'pending',
			'provider_ref' => $ref,
		);
	}

	/**
	 * A Stripe refund object.
	 *
	 * @param array $overrides Fields to replace.
	 */
	private function refund( array $overrides = array() ): array {
		return array_replace(
			array(
				'id' => 're_test',
				'object' => 'refund',
				'status' => 'pending',
				'payment_intent' => 'pi_test',
				'charge' => 'ch_test',
			),
			$overrides
		);
	}

	/** @dataProvider statuses */
	public function test_reads_the_refund_by_its_id( string $stripe_status, string $expected ): void {
		$provider = $this->provider( array( $this->ok( $this->refund( array( 'status' => $stripe_status ) ) ) ) );
		$this->assertSame(
			array(
				'status' => $expected,
				'provider_ref' => 're_test',
			),
			$provider->fetch_refund( $this->row(), $this->allocation() )
		);
		$this->assertCount( 1, $this->http->requests );
		$this->assertSame( 'get', $this->http->requests[0]['method'] );
		$this->assertStringEndsWith( '/refunds/re_test', $this->http->requests[0]['url'] );
	}

	public function statuses(): array {
		return array( array( 'succeeded', 'succeeded' ), array( 'pending', 'pending' ), array( 'requires_action', 'pending' ), array( 'failed', 'failed' ), array( 'canceled', 'failed' ), array( 'something_new', 'pending' ) );
	}

	/** A reference-less allocation was not made by this plugin's `refund()`; nothing is read and nothing is guessed. */
	public function test_no_reference_is_not_read(): void {
		$provider = $this->provider();
		$this->assert_provider_error( $provider->fetch_refund( $this->row(), $this->allocation( '' ) ), 'missing_refund_ref' );
		$this->assertCount( 0, $this->http->requests );
	}

	/** A Stripe 5xx is no answer: the sweep keeps the mark and asks again. */
	public function test_server_error_is_indeterminate(): void {
		$provider = $this->provider(
			array(
				array(
					'body' => array(
						'error' => array(
							'type' => 'api_error',
							'message' => 'Stripe is down',
						),
					),
					'status' => 500,
				),
			)
		);
		$this->assert_indeterminate( $provider->fetch_refund( $this->row(), $this->allocation() ) );
	}

	/** A dropped connection is no answer either. */
	public function test_transport_failure_is_indeterminate(): void {
		$provider = $this->provider( array( new \Stripe\Exception\ApiConnectionException( 'timed out' ) ) );
		$this->assert_indeterminate( $provider->fetch_refund( $this->row(), $this->allocation() ) );
	}

	/** A refund Stripe cannot show under this key proves nothing about the refund: an error, not `failed`. */
	public function test_refused_read_keeps_the_question_open(): void {
		$provider = $this->provider(
			array(
				array(
					'body' => array(
						'error' => array(
							'type' => 'invalid_request_error',
							'code' => 'resource_missing',
							'message' => 'No such refund',
						),
					),
					'status' => 404,
				),
			)
		);
		$this->assert_provider_error( $provider->fetch_refund( $this->row(), $this->allocation() ), 'resource_missing' );
	}

	/** A 200 without the refund in it is no answer. */
	public function test_success_without_the_refund_is_indeterminate(): void {
		$provider = $this->provider( array( $this->ok( array( 'object' => 'refund' ) ) ) );
		$this->assert_indeterminate( $provider->fetch_refund( $this->row(), $this->allocation() ) );
	}

	/** A reference bound to another sale settles nothing on this row. */
	public function test_refund_of_another_payment_is_refused(): void {
		$provider = $this->provider(
			array(
				$this->ok(
					$this->refund(
						array(
							'status' => 'succeeded',
							'payment_intent' => 'pi_other',
						)
					)
				),
			)
		);
		$this->assert_provider_error( $provider->fetch_refund( $this->row(), $this->allocation() ), 'refund_payment_mismatch' );
	}

	/** A historical webview row names the charge; the refund's charge is what must match. */
	public function test_charge_reference_matches_on_the_charge(): void {
		$row = array(
			'id' => self::PAYMENT_ID,
			'order_id' => 42,
			'amount' => '12.50',
			'currency' => 'CAD',
			'provider_refs' => array( 'transaction_id' => 'ch_test' ),
		);
		$provider = $this->provider(
			array(
				$this->ok(
					$this->refund(
						array(
							'status' => 'succeeded',
							'payment_intent' => 'pi_whatever',
						)
					)
				),
			)
		);
		$this->assertSame( 'succeeded', $provider->fetch_refund( $row, $this->allocation() )['status'] );
		$provider = $this->provider(
			array(
				$this->ok(
					$this->refund(
						array(
							'status' => 'succeeded',
							'charge' => 'ch_other',
						)
					)
				),
			)
		);
		$this->assert_provider_error( $provider->fetch_refund( $row, $this->allocation() ), 'refund_payment_mismatch' );
	}

	/** A row restored without its refs is read by the reference alone. */
	public function test_row_without_payment_reference_is_read_by_the_refund_id(): void {
		$row                  = $this->row();
		$row['provider_refs'] = array();
		$provider             = $this->provider( array( $this->ok( $this->refund( array( 'status' => 'succeeded' ) ) ) ) );
		$this->assertSame( 'succeeded', $provider->fetch_refund( $row, $this->allocation() )['status'] );
	}

	/**
	 * The answer Pro's sweep treats as "ask again".
	 *
	 * @param mixed $result The adapter's answer.
	 */
	private function assert_indeterminate( $result ): void {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stripe_refund_unanswered', $result->get_error_code() );
		$this->assertTrue( $result->get_error_data()['indeterminate'] );
	}
}
