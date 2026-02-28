<?php
/**
 * Tests for the StripeErrorHandler trait.
 */

namespace WCPOS\WooCommercePOS\StripeTerminal\Tests\Abstracts;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * Concrete class that uses the trait so we can test it.
 */
class StripeErrorHandlerTestHarness {
	use \WCPOS\WooCommercePOS\StripeTerminal\Abstracts\StripeErrorHandler;
}

/**
 * Tests for StripeErrorHandler trait.
 */
class StripeErrorHandlerTest extends TestCase {

	/**
	 * The test harness instance.
	 *
	 * @var StripeErrorHandlerTestHarness
	 */
	private $handler;

	/**
	 * Set up Brain\Monkey and the harness before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Stub esc_html to pass through.
		Functions\stubs(
			array(
				'esc_html' => function ( $text ) {
					return $text;
				},
				'__'       => function ( $text, $domain = 'default' ) {
					return $text;
				},
			)
		);

		$this->handler = new StripeErrorHandlerTestHarness();
	}

	/**
	 * Tear down Brain\Monkey after each test.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// -----------------------------------------------------------------------
	// CardException tests
	// -----------------------------------------------------------------------

	/**
	 * Test that CardException returns WP_Error with status 402.
	 */
	public function test_card_exception_returns_wp_error_with_status_402(): void {
		$exception = \Stripe\Exception\CardException::factory(
			'Your card was declined.',
			402,
			null,
			null,
			null,
			'card_declined',
			'insufficient_funds'
		);

		$result = $this->handler->handle_stripe_exception( $exception );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'stripe_error', $result->get_error_code() );
		$this->assertSame( 'Your card was declined.', $result->get_error_message() );

		$data = $result->get_error_data();
		$this->assertSame( 402, $data['status'] );
		$this->assertSame( 'card_declined', $data['stripe_code'] );
		$this->assertSame( 'insufficient_funds', $data['decline_code'] );
		$this->assertSame( 'general', $data['context'] );
		$this->assertArrayHasKey( 'request_id', $data );
	}

	/**
	 * Test CardException includes param and doc_url from error object.
	 */
	public function test_card_exception_includes_error_object_fields(): void {
		$jsonBody = array(
			'error' => array(
				'param'   => 'card_number',
				'doc_url' => 'https://stripe.com/docs/error-codes/card-declined',
			),
		);

		$exception = \Stripe\Exception\CardException::factory(
			'Your card was declined.',
			402,
			json_encode( $jsonBody ),
			$jsonBody,
			null,
			'card_declined',
			'generic_decline',
			'card_number'
		);

		$result = $this->handler->handle_stripe_exception( $exception );
		$data   = $result->get_error_data();

		$this->assertSame( 'card_number', $data['param'] );
		$this->assertSame( 'https://stripe.com/docs/error-codes/card-declined', $data['doc_url'] );
	}

	/**
	 * Test CardException with null error object has null param/doc_url.
	 */
	public function test_card_exception_with_no_error_object_has_null_fields(): void {
		$exception = \Stripe\Exception\CardException::factory(
			'Card declined',
			402,
			null,
			null,
			null,
			'card_declined',
			'generic_decline'
		);

		$result = $this->handler->handle_stripe_exception( $exception );
		$data   = $result->get_error_data();

		$this->assertNull( $data['param'] );
		$this->assertNull( $data['doc_url'] );
	}

	/**
	 * Test CardException outcome data when outcome type is 'blocked'.
	 */
	public function test_card_exception_with_blocked_outcome(): void {
		// We need to use Mockery here because setting up a full nested
		// payment_intent->charges->data[0]->outcome structure via jsonBody
		// requires the Stripe object constructors, which is cumbersome.
		$outcome      = new \stdClass();
		$outcome->type = 'blocked';

		$charge      = new \stdClass();
		$charge->outcome = $outcome;

		$chargesData = new \stdClass();
		// Use ArrayObject to support array access on data property.
		$charges      = new \stdClass();
		$charges->data = array( $charge );

		$paymentIntent         = new \stdClass();
		$paymentIntent->charges = $charges;

		$errorObj                 = new \stdClass();
		$errorObj->param          = 'number';
		$errorObj->doc_url        = 'https://stripe.com/docs';
		$errorObj->payment_intent = $paymentIntent;

		$exception = Mockery::mock( \Stripe\Exception\CardException::class )->makePartial();
		$exception->shouldReceive( 'getMessage' )->andReturn( 'Card blocked' );
		$exception->shouldReceive( 'getCode' )->andReturn( 0 );
		$exception->shouldReceive( 'getRequestId' )->andReturn( 'req_blocked123' );
		$exception->shouldReceive( 'getHttpStatus' )->andReturn( 402 );
		$exception->shouldReceive( 'getStripeCode' )->andReturn( 'card_declined' );
		$exception->shouldReceive( 'getDeclineCode' )->andReturn( 'fraudulent' );
		$exception->shouldReceive( 'getError' )->andReturn( $errorObj );

		$result = $this->handler->handle_stripe_exception( $exception );
		$data   = $result->get_error_data();

		$this->assertSame( 402, $data['status'] );
		$this->assertSame( 'blocked', $data['outcome_type'] );
		$this->assertSame( 'The payment was blocked by Stripe.', $data['outcome_reason'] );
	}

	/**
	 * Test CardException with non-blocked outcome type does not set outcome_reason.
	 */
	public function test_card_exception_with_non_blocked_outcome(): void {
		$outcome       = new \stdClass();
		$outcome->type = 'issuer_declined';

		$charge          = new \stdClass();
		$charge->outcome = $outcome;

		$charges       = new \stdClass();
		$charges->data = array( $charge );

		$paymentIntent          = new \stdClass();
		$paymentIntent->charges = $charges;

		$errorObj                 = new \stdClass();
		$errorObj->param          = null;
		$errorObj->doc_url        = null;
		$errorObj->payment_intent = $paymentIntent;

		$exception = Mockery::mock( \Stripe\Exception\CardException::class )->makePartial();
		$exception->shouldReceive( 'getMessage' )->andReturn( 'Declined' );
		$exception->shouldReceive( 'getCode' )->andReturn( 0 );
		$exception->shouldReceive( 'getRequestId' )->andReturn( 'req_decline456' );
		$exception->shouldReceive( 'getHttpStatus' )->andReturn( 402 );
		$exception->shouldReceive( 'getStripeCode' )->andReturn( 'card_declined' );
		$exception->shouldReceive( 'getDeclineCode' )->andReturn( 'issuer_declined' );
		$exception->shouldReceive( 'getError' )->andReturn( $errorObj );

		$result = $this->handler->handle_stripe_exception( $exception );
		$data   = $result->get_error_data();

		$this->assertSame( 'issuer_declined', $data['outcome_type'] );
		$this->assertArrayNotHasKey( 'outcome_reason', $data );
	}

	/**
	 * Test CardException in admin context returns a string.
	 */
	public function test_card_exception_admin_context_returns_string(): void {
		$exception = \Stripe\Exception\CardException::factory(
			'Your card was declined.',
			402,
			null,
			null,
			null,
			'card_declined',
			'insufficient_funds'
		);

		$result = $this->handler->handle_stripe_exception( $exception, 'admin' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'card_declined', $result );
		$this->assertStringContainsString( 'Your card was declined.', $result );
	}

	// -----------------------------------------------------------------------
	// InvalidRequestException tests
	// -----------------------------------------------------------------------

	/**
	 * Test InvalidRequestException returns WP_Error with status 400.
	 */
	public function test_invalid_request_exception_returns_wp_error_with_status_400(): void {
		$exception = \Stripe\Exception\InvalidRequestException::factory(
			'No such customer: cus_123',
			400,
			null,
			null,
			null,
			'resource_missing',
			'customer'
		);

		$result = $this->handler->handle_stripe_exception( $exception );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'stripe_error', $result->get_error_code() );
		$this->assertSame( 'No such customer: cus_123', $result->get_error_message() );

		$data = $result->get_error_data();
		$this->assertSame( 400, $data['status'] );
		$this->assertSame( 'resource_missing', $data['stripe_code'] );
	}

	/**
	 * Test InvalidRequestException includes param and doc_url from error object.
	 */
	public function test_invalid_request_exception_includes_error_fields(): void {
		$jsonBody = array(
			'error' => array(
				'param'   => 'amount',
				'doc_url' => 'https://stripe.com/docs/error-codes/resource-missing',
			),
		);

		$exception = \Stripe\Exception\InvalidRequestException::factory(
			'Invalid amount',
			400,
			json_encode( $jsonBody ),
			$jsonBody,
			null,
			'parameter_invalid_integer',
			'amount'
		);

		$result = $this->handler->handle_stripe_exception( $exception );
		$data   = $result->get_error_data();

		$this->assertSame( 'amount', $data['param'] );
		$this->assertSame( 'https://stripe.com/docs/error-codes/resource-missing', $data['doc_url'] );
	}

	/**
	 * Test InvalidRequestException with null error object.
	 */
	public function test_invalid_request_exception_with_no_error_object(): void {
		$exception = \Stripe\Exception\InvalidRequestException::factory(
			'Bad request',
			400,
			null,
			null,
			null,
			'invalid_request'
		);

		$result = $this->handler->handle_stripe_exception( $exception );
		$data   = $result->get_error_data();

		$this->assertNull( $data['param'] );
		$this->assertNull( $data['doc_url'] );
	}

	/**
	 * Test InvalidRequestException in admin context.
	 */
	public function test_invalid_request_exception_admin_context_returns_string(): void {
		$exception = \Stripe\Exception\InvalidRequestException::factory(
			'Invalid parameter',
			400,
			null,
			null,
			null,
			'invalid_request'
		);

		$result = $this->handler->handle_stripe_exception( $exception, 'admin' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'invalid_request', $result );
		$this->assertStringContainsString( 'Invalid parameter', $result );
	}

	// -----------------------------------------------------------------------
	// AuthenticationException tests
	// -----------------------------------------------------------------------

	/**
	 * Test AuthenticationException returns WP_Error with status 401.
	 */
	public function test_authentication_exception_returns_wp_error_with_status_401(): void {
		$exception = \Stripe\Exception\AuthenticationException::factory(
			'Invalid API Key provided.',
			401
		);

		$result = $this->handler->handle_stripe_exception( $exception );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'stripe_error', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertSame( 401, $data['status'] );
	}

	/**
	 * Test AuthenticationException in admin context.
	 */
	public function test_authentication_exception_admin_context_returns_string(): void {
		$exception = \Stripe\Exception\AuthenticationException::factory(
			'Invalid API Key provided.',
			401
		);

		$result = $this->handler->handle_stripe_exception( $exception, 'admin' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'Invalid API Key provided.', $result );
	}

	// -----------------------------------------------------------------------
	// ApiConnectionException tests
	// -----------------------------------------------------------------------

	/**
	 * Test ApiConnectionException returns WP_Error with status 502.
	 */
	public function test_api_connection_exception_returns_wp_error_with_status_502(): void {
		$exception = \Stripe\Exception\ApiConnectionException::factory(
			'Could not connect to Stripe.'
		);

		$result = $this->handler->handle_stripe_exception( $exception );

		$this->assertInstanceOf( WP_Error::class, $result );

		$data = $result->get_error_data();
		$this->assertSame( 502, $data['status'] );
	}

	// -----------------------------------------------------------------------
	// PermissionException tests
	// -----------------------------------------------------------------------

	/**
	 * Test PermissionException returns WP_Error with status 403.
	 */
	public function test_permission_exception_returns_wp_error_with_status_403(): void {
		$exception = \Stripe\Exception\PermissionException::factory(
			'You do not have permission to access this resource.'
		);

		$result = $this->handler->handle_stripe_exception( $exception );

		$this->assertInstanceOf( WP_Error::class, $result );

		$data = $result->get_error_data();
		$this->assertSame( 403, $data['status'] );
	}

	// -----------------------------------------------------------------------
	// RateLimitException tests
	// -----------------------------------------------------------------------

	/**
	 * Test RateLimitException is matched as InvalidRequestException.
	 *
	 * RateLimitException extends InvalidRequestException, so the handler's
	 * elseif chain matches InvalidRequestException first (status 400).
	 * The RateLimitException branch (status 429) is unreachable in the
	 * current implementation.
	 */
	public function test_rate_limit_exception_matched_as_invalid_request(): void {
		$exception = \Stripe\Exception\RateLimitException::factory(
			'Too many requests.',
			429
		);

		$result = $this->handler->handle_stripe_exception( $exception );

		$this->assertInstanceOf( WP_Error::class, $result );

		$data = $result->get_error_data();
		// Returns 400 because InvalidRequestException is checked before RateLimitException.
		$this->assertSame( 400, $data['status'] );
	}

	// -----------------------------------------------------------------------
	// IdempotencyException tests
	// -----------------------------------------------------------------------

	/**
	 * Test IdempotencyException returns WP_Error with status 409.
	 */
	public function test_idempotency_exception_returns_wp_error_with_status_409(): void {
		$exception = \Stripe\Exception\IdempotencyException::factory(
			'Idempotency key already used.',
			409
		);

		$result = $this->handler->handle_stripe_exception( $exception );

		$this->assertInstanceOf( WP_Error::class, $result );

		$data = $result->get_error_data();
		$this->assertSame( 409, $data['status'] );
	}

	// -----------------------------------------------------------------------
	// SignatureVerificationException tests
	// -----------------------------------------------------------------------

	/**
	 * Test SignatureVerificationException is handled as a non-Stripe exception.
	 *
	 * SignatureVerificationException extends \Exception directly, not
	 * ApiErrorException, so the handler's ApiErrorException check on line 46
	 * will not match. It falls through to the non-Stripe exception path.
	 */
	public function test_signature_verification_exception_treated_as_non_stripe(): void {
		$exception = \Stripe\Exception\SignatureVerificationException::factory(
			'Unable to verify signature.',
			'{"id": "evt_123"}',
			'v1=abc123'
		);

		$result = $this->handler->handle_stripe_exception( $exception );

		// Because SignatureVerificationException does not extend ApiErrorException,
		// it falls into the non-Stripe branch and returns a generic WP_Error.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'general_error', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertSame( 500, $data['status'] );
	}

	/**
	 * Test SignatureVerificationException in admin context returns generic string.
	 */
	public function test_signature_verification_exception_admin_context(): void {
		$exception = \Stripe\Exception\SignatureVerificationException::factory(
			'Unable to verify signature.',
			'{"id": "evt_123"}',
			'v1=abc123'
		);

		$result = $this->handler->handle_stripe_exception( $exception, 'admin' );

		$this->assertIsString( $result );
		$this->assertSame( 'An unexpected error occurred.', $result );
	}

	// -----------------------------------------------------------------------
	// UnknownApiErrorException tests
	// -----------------------------------------------------------------------

	/**
	 * Test UnknownApiErrorException returns WP_Error with status 500.
	 */
	public function test_unknown_api_error_exception_returns_wp_error_with_status_500(): void {
		$exception = \Stripe\Exception\UnknownApiErrorException::factory(
			'An unknown error occurred.',
			500
		);

		$result = $this->handler->handle_stripe_exception( $exception );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'stripe_error', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertSame( 500, $data['status'] );
	}

	// -----------------------------------------------------------------------
	// Non-Stripe exception tests
	// -----------------------------------------------------------------------

	/**
	 * Test generic Exception returns WP_Error with 'general_error' code.
	 */
	public function test_generic_exception_returns_wp_error_with_general_error(): void {
		$exception = new \Exception( 'Something went wrong' );

		$result = $this->handler->handle_stripe_exception( $exception );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'general_error', $result->get_error_code() );
		$this->assertStringContainsString( 'Something went wrong', $result->get_error_message() );

		$data = $result->get_error_data();
		$this->assertSame( 500, $data['status'] );
	}

	/**
	 * Test generic Exception in admin context returns generic string.
	 */
	public function test_generic_exception_admin_context_returns_string(): void {
		$exception = new \Exception( 'Something went wrong' );

		$result = $this->handler->handle_stripe_exception( $exception, 'admin' );

		$this->assertIsString( $result );
		$this->assertSame( 'An unexpected error occurred.', $result );
	}

	/**
	 * Test RuntimeException (non-Stripe) is handled as generic.
	 */
	public function test_runtime_exception_treated_as_non_stripe(): void {
		$exception = new \RuntimeException( 'Runtime failure' );

		$result = $this->handler->handle_stripe_exception( $exception );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'general_error', $result->get_error_code() );
		$this->assertStringContainsString( 'Runtime failure', $result->get_error_message() );

		$data = $result->get_error_data();
		$this->assertSame( 500, $data['status'] );
	}

	// -----------------------------------------------------------------------
	// Context tests
	// -----------------------------------------------------------------------

	/**
	 * Test that default context is 'general'.
	 */
	public function test_default_context_is_general(): void {
		$exception = \Stripe\Exception\AuthenticationException::factory(
			'Bad key',
			401
		);

		$result = $this->handler->handle_stripe_exception( $exception );
		$data   = $result->get_error_data();

		$this->assertSame( 'general', $data['context'] );
	}

	/**
	 * Test that custom context is preserved in error data.
	 */
	public function test_custom_context_preserved_in_error_data(): void {
		$exception = \Stripe\Exception\AuthenticationException::factory(
			'Bad key',
			401
		);

		$result = $this->handler->handle_stripe_exception( $exception, 'api' );
		$data   = $result->get_error_data();

		$this->assertSame( 'api', $data['context'] );
	}

	/**
	 * Test non-admin context returns WP_Error, not a string.
	 */
	public function test_non_admin_context_returns_wp_error(): void {
		$exception = \Stripe\Exception\AuthenticationException::factory(
			'Bad key',
			401
		);

		$result = $this->handler->handle_stripe_exception( $exception, 'gateway' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertFalse( \is_string( $result ) );
	}

	// -----------------------------------------------------------------------
	// Request ID tests
	// -----------------------------------------------------------------------

	/**
	 * Test that request_id is included in error data for API errors.
	 */
	public function test_request_id_included_for_api_errors(): void {
		$headers = array( 'Request-Id' => 'req_test_abc123' );

		$exception = \Stripe\Exception\AuthenticationException::factory(
			'Invalid API Key',
			401,
			null,
			null,
			$headers
		);

		$result = $this->handler->handle_stripe_exception( $exception );
		$data   = $result->get_error_data();

		$this->assertSame( 'req_test_abc123', $data['request_id'] );
	}

	// -----------------------------------------------------------------------
	// Admin string format tests
	// -----------------------------------------------------------------------

	/**
	 * Test admin context format uses stripe_code and message.
	 */
	public function test_admin_context_format_includes_stripe_code_and_message(): void {
		$exception = \Stripe\Exception\InvalidRequestException::factory(
			'No such customer.',
			400,
			null,
			null,
			null,
			'resource_missing'
		);

		$result = $this->handler->handle_stripe_exception( $exception, 'admin' );

		// The format is: "Stripe error (stripe_code): message"
		$this->assertStringContainsString( 'resource_missing', $result );
		$this->assertStringContainsString( 'No such customer.', $result );
		$this->assertStringContainsString( 'Stripe error', $result );
	}

	/**
	 * Test admin context with null stripe code shows 'unknown'.
	 */
	public function test_admin_context_with_null_stripe_code_shows_unknown(): void {
		$exception = \Stripe\Exception\ApiConnectionException::factory(
			'Connection failed'
		);

		$result = $this->handler->handle_stripe_exception( $exception, 'admin' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'unknown', $result );
		$this->assertStringContainsString( 'Connection failed', $result );
	}

	// -----------------------------------------------------------------------
	// Edge case: non-Stripe exception message in WP_Error
	// -----------------------------------------------------------------------

	/**
	 * Test non-Stripe exception WP_Error message includes original exception message.
	 */
	public function test_non_stripe_wp_error_message_includes_exception_message(): void {
		$exception = new \Exception( 'Database connection lost' );

		$result = $this->handler->handle_stripe_exception( $exception );

		$this->assertSame(
			'An unexpected error occurred: Database connection lost',
			$result->get_error_message()
		);
	}
}
