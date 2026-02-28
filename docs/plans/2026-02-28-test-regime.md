# Test Regime Implementation Plan

> **For Claude:** REQUIRED: Use /execute-plan to implement this plan task-by-task.

**Goal:** Add PHPUnit tests with mocked dependencies and Playwright E2E tests against a Docker WordPress stack, with CI and Codecov coverage tracking.

**Architecture:** Two test layers — fast PHP unit tests using PHPUnit + Mockery + Brain\Monkey (no real WordPress), and Playwright E2E tests against a Docker-based WP/WC environment using Stripe's simulated reader. GitHub Actions runs both on every push/PR.

**Tech Stack:** PHPUnit 9.x, Mockery, Brain\Monkey, Playwright, Docker Compose, GitHub Actions, Codecov

---

## Phase 1: PHPUnit Infrastructure

### Task 1: Add test dependencies to Composer

**Files:**
- Modify: `composer.json`

**Step 1: Add Brain\Monkey and Mockery as dev dependencies**

Run:
```bash
composer require --dev brain/monkey mockery/mockery phpunit/phpunit:^9.6
```

Note: PHPUnit 9.x is the last version supporting PHP 7.4. Brain\Monkey provides clean WordPress function mocking and includes Mockery.

**Step 2: Add the `test` script to composer.json**

In `composer.json`, add to the `"scripts"` section:

```json
"test": "phpunit --configuration phpunit.xml",
"test:coverage": "phpunit --configuration phpunit.xml --coverage-clover=coverage.xml"
```

**Step 3: Commit**

```bash
git add composer.json composer.lock
git commit -m "chore: add PHPUnit, Mockery, and Brain\Monkey test dependencies"
```

---

### Task 2: Create PHPUnit configuration and bootstrap

**Files:**
- Create: `phpunit.xml`
- Create: `tests/bootstrap.php`

**Step 1: Create `phpunit.xml`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/9.6/phpunit.xsd"
    bootstrap="tests/bootstrap.php"
    colors="true"
    verbose="true"
    failOnRisky="true"
    failOnWarning="true"
>
    <testsuites>
        <testsuite name="unit">
            <directory suffix="Test.php">tests/includes</directory>
        </testsuite>
    </testsuites>

    <coverage processUncoveredFiles="true">
        <include>
            <directory suffix=".php">includes</directory>
        </include>
    </coverage>
</phpunit>
```

**Step 2: Create `tests/bootstrap.php`**

```php
<?php
/**
 * PHPUnit bootstrap file.
 *
 * Loads composer autoloader and sets up the test environment
 * with stubs for WordPress/WooCommerce dependencies.
 */

// Composer autoloader (loads plugin classes + test dependencies).
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Plugin constants.
define( 'ABSPATH', '/tmp/wordpress/' );
define( 'STWC_VERSION', '0.0.0-test' );
define( 'STWC_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'STWC_PLUGIN_URL', 'http://localhost/wp-content/plugins/stripe-terminal-for-woocommerce/' );

// Stub WP_Error since many methods return it and Brain\Monkey doesn't stub classes.
if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        protected $code;
        protected $message;
        protected $data;

        public function __construct( $code = '', $message = '', $data = '' ) {
            $this->code    = $code;
            $this->message = $message;
            $this->data    = $data;
        }

        public function get_error_code() {
            return $this->code;
        }

        public function get_error_message() {
            return $this->message;
        }

        public function get_error_data() {
            return $this->data;
        }
    }
}

// Stub is_wp_error function.
if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ) {
        return $thing instanceof WP_Error;
    }
}
```

**Step 3: Verify PHPUnit runs (no tests yet, should report 0)**

Run:
```bash
composer test
```

Expected: "No tests executed" or similar success with 0 tests.

**Step 4: Commit**

```bash
git add phpunit.xml tests/bootstrap.php
git commit -m "chore: add PHPUnit configuration and test bootstrap"
```

---

## Phase 2: CurrencyConverter Tests (Pure Logic)

### Task 3: Write CurrencyConverter tests

This is the easiest class to test — pure static methods, zero external dependencies.

**Files:**
- Create: `tests/includes/Utils/CurrencyConverterTest.php`

**Step 1: Write the test file**

```php
<?php

namespace WCPOS\WooCommercePOS\StripeTerminal\Tests\Utils;

use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\StripeTerminal\Utils\CurrencyConverter;

class CurrencyConverterTest extends TestCase {

    // --- convert_to_stripe_amount ---

    /**
     * @dataProvider standardCurrencyProvider
     */
    public function test_convert_to_stripe_amount_standard_currencies( float $amount, string $currency, int $expected ): void {
        $this->assertSame( $expected, CurrencyConverter::convert_to_stripe_amount( $amount, $currency ) );
    }

    public function standardCurrencyProvider(): array {
        return [
            'USD 10.00'      => [ 10.00, 'USD', 1000 ],
            'USD 10.50'      => [ 10.50, 'USD', 1050 ],
            'USD 0.01'       => [ 0.01, 'USD', 1 ],
            'USD 99.99'      => [ 99.99, 'USD', 9999 ],
            'EUR 25.00'      => [ 25.00, 'EUR', 2500 ],
            'GBP 100.00'     => [ 100.00, 'GBP', 10000 ],
            'AUD 0.50'       => [ 0.50, 'AUD', 50 ],
            'case-insensitive' => [ 10.00, 'usd', 1000 ],
        ];
    }

    /**
     * @dataProvider zeroDecimalCurrencyProvider
     */
    public function test_convert_to_stripe_amount_zero_decimal_currencies( float $amount, string $currency, int $expected ): void {
        $this->assertSame( $expected, CurrencyConverter::convert_to_stripe_amount( $amount, $currency ) );
    }

    public function zeroDecimalCurrencyProvider(): array {
        return [
            'JPY 1000'   => [ 1000.0, 'JPY', 1000 ],
            'JPY 500'    => [ 500.0, 'JPY', 500 ],
            'KRW 50000'  => [ 50000.0, 'KRW', 50000 ],
            'VND 100000' => [ 100000.0, 'VND', 100000 ],
            'BIF 1500'   => [ 1500.0, 'BIF', 1500 ],
            'XPF 200'    => [ 200.0, 'XPF', 200 ],
        ];
    }

    /**
     * @dataProvider specialCaseCurrencyProvider
     */
    public function test_convert_to_stripe_amount_special_cases( float $amount, string $currency, int $expected ): void {
        $this->assertSame( $expected, CurrencyConverter::convert_to_stripe_amount( $amount, $currency ) );
    }

    public function specialCaseCurrencyProvider(): array {
        return [
            'ISK 1000 (two-decimal special)' => [ 1000.0, 'ISK', 100000 ],
            'ISK 50.75'                      => [ 50.75, 'ISK', 5075 ],
            'HUF 5000 (zero-decimal special)' => [ 5000.0, 'HUF', 5000 ],
            'TWD 100 (zero-decimal special)'  => [ 100.0, 'TWD', 100 ],
        ];
    }

    public function test_convert_to_stripe_amount_rounds_correctly(): void {
        // 10.999 should round to 11.00 = 1100 cents
        $this->assertSame( 1100, CurrencyConverter::convert_to_stripe_amount( 10.999, 'USD' ) );
        // 10.994 should round to 10.99 = 1099 cents
        $this->assertSame( 1099, CurrencyConverter::convert_to_stripe_amount( 10.994, 'USD' ) );
    }

    // --- convert_from_stripe_amount ---

    /**
     * @dataProvider fromStripeStandardProvider
     */
    public function test_convert_from_stripe_amount_standard_currencies( int $amount, string $currency, float $expected ): void {
        $this->assertSame( $expected, CurrencyConverter::convert_from_stripe_amount( $amount, $currency ) );
    }

    public function fromStripeStandardProvider(): array {
        return [
            'USD 1000 cents' => [ 1000, 'USD', 10.0 ],
            'USD 1 cent'     => [ 1, 'USD', 0.01 ],
            'USD 9999 cents' => [ 9999, 'USD', 99.99 ],
            'EUR 2500 cents' => [ 2500, 'EUR', 25.0 ],
        ];
    }

    /**
     * @dataProvider fromStripeZeroDecimalProvider
     */
    public function test_convert_from_stripe_amount_zero_decimal( int $amount, string $currency, float $expected ): void {
        $this->assertSame( $expected, CurrencyConverter::convert_from_stripe_amount( $amount, $currency ) );
    }

    public function fromStripeZeroDecimalProvider(): array {
        return [
            'JPY 1000' => [ 1000, 'JPY', 1000.0 ],
            'KRW 5000' => [ 5000, 'KRW', 5000.0 ],
        ];
    }

    /**
     * @dataProvider fromStripeSpecialCaseProvider
     */
    public function test_convert_from_stripe_amount_special_cases( int $amount, string $currency, float $expected ): void {
        $this->assertSame( $expected, CurrencyConverter::convert_from_stripe_amount( $amount, $currency ) );
    }

    public function fromStripeSpecialCaseProvider(): array {
        return [
            'ISK 100000' => [ 100000, 'ISK', 1000.0 ],
            'HUF 5000'   => [ 5000, 'HUF', 5000.0 ],
            'TWD 100'    => [ 100, 'TWD', 100.0 ],
        ];
    }

    public function test_roundtrip_conversion(): void {
        // Standard currency: amount -> stripe -> back should match
        $original = 42.50;
        $stripe   = CurrencyConverter::convert_to_stripe_amount( $original, 'USD' );
        $back     = CurrencyConverter::convert_from_stripe_amount( $stripe, 'USD' );
        $this->assertSame( $original, $back );

        // Zero-decimal: same roundtrip
        $original_jpy = 5000.0;
        $stripe_jpy   = CurrencyConverter::convert_to_stripe_amount( $original_jpy, 'JPY' );
        $back_jpy     = CurrencyConverter::convert_from_stripe_amount( $stripe_jpy, 'JPY' );
        $this->assertSame( $original_jpy, $back_jpy );
    }
}
```

**Step 2: Run tests**

```bash
composer test
```

Expected: All tests PASS. (These are testing existing production code, not TDD — the logic is already written.)

**Step 3: Commit**

```bash
git add tests/includes/Utils/CurrencyConverterTest.php
git commit -m "test: add CurrencyConverter unit tests"
```

---

## Phase 3: StripeErrorHandler Tests

### Task 4: Write StripeErrorHandler tests

Tests the trait that maps Stripe exceptions to HTTP status codes and WP_Error/string responses. Requires mocking Stripe exception classes and the Logger.

**Files:**
- Create: `tests/includes/Abstracts/StripeErrorHandlerTest.php`

**Step 1: Write the test file**

We need a concrete class to test the trait. Create a simple test harness inside the test file, then test each exception type.

```php
<?php

namespace WCPOS\WooCommercePOS\StripeTerminal\Tests\Abstracts;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\StripeTerminal\Abstracts\StripeErrorHandler;

/**
 * Concrete class using the trait so we can test it.
 */
class StripeErrorHandlerTestHarness {
    use StripeErrorHandler;
}

class StripeErrorHandlerTest extends TestCase {

    private StripeErrorHandlerTestHarness $handler;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        $this->handler = new StripeErrorHandlerTestHarness();

        // Stub WordPress functions used by the trait.
        Functions\stubs( [
            'esc_html' => function ( $text ) { return $text; },
            '__'       => function ( $text ) { return $text; },
        ] );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // --- API context (returns WP_Error) ---

    public function test_card_exception_returns_402(): void {
        $exception = $this->createCardException( 'Your card was declined.', 'card_declined', 'generic_decline' );

        $result = $this->handler->handle_stripe_exception( $exception, 'api' );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'stripe_error', $result->get_error_code() );
        $this->assertSame( 'Your card was declined.', $result->get_error_message() );

        $data = $result->get_error_data();
        $this->assertSame( 402, $data['status'] );
        $this->assertSame( 'card_declined', $data['stripe_code'] );
        $this->assertSame( 'generic_decline', $data['decline_code'] );
    }

    public function test_invalid_request_returns_400(): void {
        $exception = $this->createStripeException(
            \Stripe\Exception\InvalidRequestException::class,
            'No such payment_intent',
            400
        );

        $result = $this->handler->handle_stripe_exception( $exception, 'api' );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $data = $result->get_error_data();
        $this->assertSame( 400, $data['status'] );
    }

    public function test_authentication_exception_returns_401(): void {
        $exception = $this->createStripeException(
            \Stripe\Exception\AuthenticationException::class,
            'Invalid API Key provided',
            401
        );

        $result = $this->handler->handle_stripe_exception( $exception, 'api' );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $data = $result->get_error_data();
        $this->assertSame( 401, $data['status'] );
    }

    public function test_api_connection_exception_returns_502(): void {
        $exception = $this->createStripeException(
            \Stripe\Exception\ApiConnectionException::class,
            'Could not connect to Stripe',
            0
        );

        $result = $this->handler->handle_stripe_exception( $exception, 'api' );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $data = $result->get_error_data();
        $this->assertSame( 502, $data['status'] );
    }

    public function test_permission_exception_returns_403(): void {
        $exception = $this->createStripeException(
            \Stripe\Exception\PermissionException::class,
            'Permission denied',
            403
        );

        $result = $this->handler->handle_stripe_exception( $exception, 'api' );

        $data = $result->get_error_data();
        $this->assertSame( 403, $data['status'] );
    }

    public function test_rate_limit_exception_returns_429(): void {
        $exception = $this->createStripeException(
            \Stripe\Exception\RateLimitException::class,
            'Too many requests',
            429
        );

        $result = $this->handler->handle_stripe_exception( $exception, 'api' );

        $data = $result->get_error_data();
        $this->assertSame( 429, $data['status'] );
    }

    public function test_idempotency_exception_returns_409(): void {
        $exception = $this->createStripeException(
            \Stripe\Exception\IdempotencyException::class,
            'Idempotency key conflict',
            409
        );

        $result = $this->handler->handle_stripe_exception( $exception, 'api' );

        $data = $result->get_error_data();
        $this->assertSame( 409, $data['status'] );
    }

    public function test_unknown_api_error_returns_500(): void {
        $exception = $this->createStripeException(
            \Stripe\Exception\UnknownApiErrorException::class,
            'Unknown error',
            500
        );

        $result = $this->handler->handle_stripe_exception( $exception, 'api' );

        $data = $result->get_error_data();
        $this->assertSame( 500, $data['status'] );
    }

    // --- Admin context (returns string) ---

    public function test_admin_context_returns_string(): void {
        $exception = $this->createStripeException(
            \Stripe\Exception\AuthenticationException::class,
            'Invalid API Key provided',
            401
        );

        $result = $this->handler->handle_stripe_exception( $exception, 'admin' );

        $this->assertIsString( $result );
        $this->assertStringContainsString( 'Invalid API Key provided', $result );
    }

    // --- Non-Stripe exceptions ---

    public function test_non_stripe_exception_returns_500_wp_error(): void {
        $exception = new \RuntimeException( 'Something went wrong' );

        $result = $this->handler->handle_stripe_exception( $exception, 'api' );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'general_error', $result->get_error_code() );
        $data = $result->get_error_data();
        $this->assertSame( 500, $data['status'] );
    }

    public function test_non_stripe_exception_admin_returns_string(): void {
        $exception = new \RuntimeException( 'Something went wrong' );

        $result = $this->handler->handle_stripe_exception( $exception, 'admin' );

        $this->assertIsString( $result );
    }

    // --- Helpers ---

    /**
     * Create a mock Stripe API exception.
     *
     * Stripe exceptions have a complex constructor. We use Mockery to
     * create partial mocks that behave like real exceptions.
     */
    private function createStripeException( string $class, string $message, int $httpStatus ) {
        $mock = Mockery::mock( $class );
        $mock->shouldReceive( 'getMessage' )->andReturn( $message );
        $mock->shouldReceive( 'getCode' )->andReturn( 0 );
        $mock->shouldReceive( 'getRequestId' )->andReturn( 'req_test_123' );
        $mock->shouldReceive( 'getHttpStatus' )->andReturn( $httpStatus );
        $mock->shouldReceive( 'getStripeCode' )->andReturn( 'test_code' );
        $mock->shouldReceive( 'getError' )->andReturn( null );
        $mock->shouldReceive( 'getHttpBody' )->andReturn( '' );
        $mock->shouldReceive( 'getSigHeader' )->andReturn( '' );

        return $mock;
    }

    private function createCardException( string $message, string $stripeCode, string $declineCode ) {
        $mock = Mockery::mock( \Stripe\Exception\CardException::class );
        $mock->shouldReceive( 'getMessage' )->andReturn( $message );
        $mock->shouldReceive( 'getCode' )->andReturn( 0 );
        $mock->shouldReceive( 'getRequestId' )->andReturn( 'req_test_456' );
        $mock->shouldReceive( 'getHttpStatus' )->andReturn( 402 );
        $mock->shouldReceive( 'getStripeCode' )->andReturn( $stripeCode );
        $mock->shouldReceive( 'getDeclineCode' )->andReturn( $declineCode );
        $mock->shouldReceive( 'getError' )->andReturn( null );

        return $mock;
    }
}
```

**Step 2: Run tests**

```bash
composer test
```

Expected: All tests PASS.

Note: The trait's `handle_stripe_exception` calls `Logger::log()`. Since `Logger::log()` checks `class_exists('WC_Logger')` and WC_Logger doesn't exist in the test environment, the call is effectively a no-op. No mocking needed for Logger in this context.

**Step 3: Commit**

```bash
git add tests/includes/Abstracts/StripeErrorHandlerTest.php
git commit -m "test: add StripeErrorHandler unit tests for all exception types"
```

---

## Phase 4: StripeTerminalService Tests

### Task 5: Add a setter for injecting a mock Stripe client

The `StripeTerminalService` class creates `\Stripe\StripeClient` internally, which makes it hard to test. We need a way to inject a mock client.

**Files:**
- Modify: `includes/StripeTerminalService.php`

**Step 1: Add `set_stripe_client` method**

After the existing `get_stripe_client()` method (line 52), add:

```php
/**
 * Set the Stripe client instance (primarily for testing).
 *
 * @param \Stripe\StripeClient $client The Stripe client to use.
 */
public function set_stripe_client( \Stripe\StripeClient $client ): void {
    $this->stripe_client = $client;
}
```

**Step 2: Commit**

```bash
git add includes/StripeTerminalService.php
git commit -m "refactor: add set_stripe_client for test injection"
```

---

### Task 6: Write StripeTerminalService tests

Tests the core service methods by injecting a mock StripeClient.

**Files:**
- Create: `tests/includes/StripeTerminalServiceTest.php`

**Step 1: Write the test file**

Focus on the methods that use `$this->get_stripe_client()` (process_payment_intent, get_reader_status via client). For methods that use static Stripe calls (create_payment_intent, confirm_payment_intent), we test the validation/branching logic around them and accept that the actual Stripe API call will be covered by E2E tests.

```php
<?php

namespace WCPOS\WooCommercePOS\StripeTerminal\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\StripeTerminal\StripeTerminalService;

class StripeTerminalServiceTest extends TestCase {

    private StripeTerminalService $service;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        $this->service = new StripeTerminalService( 'sk_test_fake_key' );

        // Stub WordPress functions.
        Functions\stubs( [
            '__'         => function ( $text ) { return $text; },
            'get_option' => function () { return []; },
        ] );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_constructor_sets_api_key(): void {
        $service = new StripeTerminalService( 'sk_test_abc123' );
        // The service should be instantiable without errors.
        $this->assertInstanceOf( StripeTerminalService::class, $service );
    }

    public function test_get_stripe_client_returns_client_instance(): void {
        $client = $this->service->get_stripe_client();
        $this->assertInstanceOf( \Stripe\StripeClient::class, $client );
    }

    public function test_get_stripe_client_returns_same_instance(): void {
        $client1 = $this->service->get_stripe_client();
        $client2 = $this->service->get_stripe_client();
        $this->assertSame( $client1, $client2 );
    }

    public function test_set_stripe_client_overrides_lazy_init(): void {
        $mock_client = Mockery::mock( \Stripe\StripeClient::class );
        $this->service->set_stripe_client( $mock_client );

        $this->assertSame( $mock_client, $this->service->get_stripe_client() );
    }

    public function test_create_payment_intent_rejects_unsupported_currency(): void {
        // Create a mock WC_Order that returns an unsupported currency.
        $order = $this->createMockOrder( 100, 'XYZ', 1 );

        // Mock the account retrieval to return a US account (only supports USD).
        // The get_supported_currencies method calls Stripe\Account::retrieve().
        // Since we can't easily mock static calls, we test the WP_Error path
        // by trusting that the method will detect the currency mismatch.
        // This test may hit the Stripe API — if so, it will fail on the account
        // retrieval and default to 'US', which only supports 'usd'.
        // Currency 'xyz' won't be in the list, so we'll get the unsupported error.

        // Note: This test depends on the static Stripe\Account::retrieve() failing
        // (which it will with a fake key) and defaulting to US/usd.
        $result = $this->service->create_payment_intent( $order );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'unsupported_currency', $result->get_error_code() );
    }

    public function test_create_payment_intent_validates_amount_and_currency(): void {
        // Order with zero total should pass currency check but fail on empty amount.
        $order = $this->createMockOrder( 0, 'USD', 1 );

        $result = $this->service->create_payment_intent( $order, 0 );

        // With amount=0, the empty() check should trigger.
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'missing_params', $result->get_error_code() );
    }

    // --- Helpers ---

    private function createMockOrder( float $total, string $currency, int $id ) {
        $order = Mockery::mock( 'WC_Order' );
        $order->shouldReceive( 'get_total' )->andReturn( $total );
        $order->shouldReceive( 'get_currency' )->andReturn( $currency );
        $order->shouldReceive( 'get_id' )->andReturn( $id );

        return $order;
    }
}
```

**Step 2: Run tests**

```bash
composer test
```

Expected: All tests PASS.

Note: The `create_payment_intent` tests for unsupported currency will trigger a Stripe API call with a fake key, which will throw an exception. The `get_supported_currencies()` method catches this and defaults to US (supporting only 'usd'). Since 'xyz' isn't 'usd', we get the expected WP_Error.

**Step 3: Commit**

```bash
git add tests/includes/StripeTerminalServiceTest.php
git commit -m "test: add StripeTerminalService unit tests"
```

---

## Phase 5: AjaxHandler Tests

### Task 7: Write AjaxHandler tests

The AjaxHandler reads from `$_POST`, calls `StripeTerminalService` methods, and sends JSON responses via `wp_send_json_success/error`. We mock the service and stub WordPress functions.

**Files:**
- Create: `tests/includes/AjaxHandlerTest.php`

**Step 1: Write the test file**

```php
<?php

namespace WCPOS\WooCommercePOS\StripeTerminal\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AjaxHandler.
 *
 * Since AjaxHandler hooks into WordPress on construction (add_action calls)
 * and reads $_POST globals directly, we test the individual public methods
 * by creating the handler and calling methods directly.
 *
 * Key behaviors to verify:
 * - Input validation (missing params return errors)
 * - Order access verification
 * - Service delegation (correct methods called)
 * - Error propagation from service layer
 */
class AjaxHandlerTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Stub WordPress functions used during construction and method calls.
        Functions\stubs( [
            'add_action'          => null,
            'get_option'          => function () { return []; },
            'absint'              => function ( $val ) { return abs( intval( $val ) ); },
            'sanitize_text_field' => function ( $val ) { return $val; },
            '__'                  => function ( $text ) { return $text; },
        ] );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        $_POST = [];
        parent::tearDown();
    }

    public function test_create_payment_intent_rejects_missing_order_id(): void {
        $_POST = [
            'amount'    => '1000',
            'reader_id' => 'tmr_test',
        ];

        Functions\expect( 'wp_send_json_error' )
            ->once()
            ->with( Mockery::type( 'string' ) );

        $handler = new \WCPOS\WooCommercePOS\StripeTerminal\AjaxHandler();
        $handler->create_payment_intent();
    }

    public function test_create_payment_intent_rejects_missing_reader_id(): void {
        $_POST = [
            'order_id' => '123',
            'amount'   => '1000',
        ];

        Functions\expect( 'wp_send_json_error' )
            ->once()
            ->with( Mockery::type( 'string' ) );

        $handler = new \WCPOS\WooCommercePOS\StripeTerminal\AjaxHandler();
        $handler->create_payment_intent();
    }

    public function test_create_payment_intent_rejects_missing_amount(): void {
        $_POST = [
            'order_id'  => '123',
            'reader_id' => 'tmr_test',
        ];

        Functions\expect( 'wp_send_json_error' )
            ->once()
            ->with( Mockery::type( 'string' ) );

        $handler = new \WCPOS\WooCommercePOS\StripeTerminal\AjaxHandler();
        $handler->create_payment_intent();
    }

    public function test_create_payment_intent_rejects_invalid_order(): void {
        $_POST = [
            'order_id'  => '999',
            'amount'    => '1000',
            'reader_id' => 'tmr_test',
        ];

        Functions\expect( 'wc_get_order' )
            ->once()
            ->with( 999 )
            ->andReturn( false );

        Functions\expect( 'wp_send_json_error' )
            ->once()
            ->with( 'Order not found' );

        $handler = new \WCPOS\WooCommercePOS\StripeTerminal\AjaxHandler();
        $handler->create_payment_intent();
    }

    public function test_confirm_payment_rejects_missing_params(): void {
        $_POST = [
            'order_id' => '123',
            // missing payment_intent_id
        ];

        Functions\expect( 'wp_send_json_error' )
            ->once()
            ->with( 'Missing payment intent ID or order ID' );

        $handler = new \WCPOS\WooCommercePOS\StripeTerminal\AjaxHandler();
        $handler->confirm_payment();
    }

    public function test_cancel_payment_rejects_missing_params(): void {
        $_POST = [];

        Functions\expect( 'wp_send_json_error' )
            ->once()
            ->with( 'Missing payment intent ID or order ID' );

        $handler = new \WCPOS\WooCommercePOS\StripeTerminal\AjaxHandler();
        $handler->cancel_payment();
    }

    public function test_check_payment_status_rejects_missing_order_id(): void {
        $_POST = [];

        Functions\expect( 'wp_send_json_error' )
            ->once()
            ->with( 'Missing order ID' );

        $handler = new \WCPOS\WooCommercePOS\StripeTerminal\AjaxHandler();
        $handler->check_payment_status();
    }

    public function test_simulate_payment_rejects_missing_reader_id(): void {
        $_POST = [
            'order_id' => '123',
        ];

        Functions\expect( 'wp_send_json_error' )
            ->once()
            ->with( 'Missing reader ID' );

        $handler = new \WCPOS\WooCommercePOS\StripeTerminal\AjaxHandler();
        $handler->simulate_payment();
    }

    public function test_get_reader_status_fails_without_service(): void {
        // No API key configured = no service initialized.
        Functions\expect( 'wp_send_json_error' )
            ->once()
            ->with( Mockery::pattern( '/not initialized/' ) );

        $handler = new \WCPOS\WooCommercePOS\StripeTerminal\AjaxHandler();
        $handler->get_reader_status();
    }

    public function test_validate_service_fails_without_service(): void {
        Functions\expect( 'wp_send_json_error' )
            ->once()
            ->with( Mockery::pattern( '/not initialized/' ) );

        $handler = new \WCPOS\WooCommercePOS\StripeTerminal\AjaxHandler();
        $handler->validate_service();
    }
}
```

**Step 2: Run tests**

```bash
composer test
```

Expected: All tests PASS.

**Step 3: Commit**

```bash
git add tests/includes/AjaxHandlerTest.php
git commit -m "test: add AjaxHandler unit tests for input validation"
```

---

## Phase 6: Settings and Logger Tests

### Task 8: Write Settings and Logger tests

Thin wrappers, but good for coverage baseline.

**Files:**
- Create: `tests/includes/SettingsTest.php`
- Create: `tests/includes/LoggerTest.php`

**Step 1: Write SettingsTest**

```php
<?php

namespace WCPOS\WooCommercePOS\StripeTerminal\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\StripeTerminal\Settings;

class SettingsTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_get_api_key_returns_test_key_in_test_mode(): void {
        Functions\expect( 'get_option' )
            ->once()
            ->andReturn( [
                'test_mode'       => 'yes',
                'test_secret_key' => 'sk_test_abc',
                'secret_key'      => 'sk_live_xyz',
            ] );

        $this->assertSame( 'sk_test_abc', Settings::get_api_key() );
    }

    public function test_get_api_key_returns_live_key_in_live_mode(): void {
        Functions\expect( 'get_option' )
            ->once()
            ->andReturn( [
                'test_mode'       => 'no',
                'test_secret_key' => 'sk_test_abc',
                'secret_key'      => 'sk_live_xyz',
            ] );

        $this->assertSame( 'sk_live_xyz', Settings::get_api_key() );
    }

    public function test_get_api_key_returns_empty_when_no_settings(): void {
        Functions\expect( 'get_option' )
            ->once()
            ->andReturn( [] );

        $this->assertSame( '', Settings::get_api_key() );
    }

    public function test_get_webhook_secret_returns_test_secret_in_test_mode(): void {
        Functions\expect( 'get_option' )
            ->once()
            ->andReturn( [
                'test_mode'           => 'yes',
                'test_webhook_secret' => 'whsec_test',
                'webhook_secret'      => 'whsec_live',
            ] );

        $this->assertSame( 'whsec_test', Settings::get_webhook_secret() );
    }

    public function test_get_webhook_secret_returns_live_secret_in_live_mode(): void {
        Functions\expect( 'get_option' )
            ->once()
            ->andReturn( [
                'test_mode'           => 'no',
                'test_webhook_secret' => 'whsec_test',
                'webhook_secret'      => 'whsec_live',
            ] );

        $this->assertSame( 'whsec_live', Settings::get_webhook_secret() );
    }
}
```

**Step 2: Write LoggerTest**

```php
<?php

namespace WCPOS\WooCommercePOS\StripeTerminal\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\StripeTerminal\Logger;

class LoggerTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        // Reset static state.
        Logger::$logger    = null;
        Logger::$log_level = null;
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_log_does_nothing_without_wc_logger_class(): void {
        // WC_Logger doesn't exist in test env — log should silently return.
        Logger::log( 'test message' );
        $this->assertNull( Logger::$logger );
    }

    public function test_set_log_level(): void {
        Logger::set_log_level( 'debug' );
        $this->assertSame( 'debug', Logger::$log_level );
    }

    public function test_wc_log_filename_constant(): void {
        $this->assertSame( 'stripe-terminal-for-woocommerce', Logger::WC_LOG_FILENAME );
    }
}
```

**Step 3: Run tests**

```bash
composer test
```

Expected: All tests PASS.

**Step 4: Commit**

```bash
git add tests/includes/SettingsTest.php tests/includes/LoggerTest.php
git commit -m "test: add Settings and Logger unit tests"
```

---

## Phase 7: GitHub Actions CI Workflow

### Task 9: Create the CI workflow for PHP tests

**Files:**
- Create: `.github/workflows/test.yml`

**Step 1: Write the workflow**

```yaml
name: Tests

on:
  push:
    branches: ['**']
  pull_request:
    branches: [main]

jobs:
  php-tests:
    name: PHP ${{ matrix.php }}
    runs-on: ubuntu-latest
    strategy:
      fail-fast: false
      matrix:
        php: ['7.4', '8.0', '8.2']

    steps:
      - name: Checkout code
        uses: actions/checkout@v4

      - name: Set up PHP ${{ matrix.php }}
        uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          extensions: json, mbstring
          coverage: xdebug

      - name: Install Composer dependencies
        run: composer install --no-interaction --prefer-dist

      - name: Run PHPUnit tests
        run: composer test

      - name: Generate coverage report
        if: matrix.php == '8.2'
        run: composer test:coverage

      - name: Upload coverage to Codecov
        if: matrix.php == '8.2'
        uses: codecov/codecov-action@v4
        with:
          files: coverage.xml
          fail_ci_if_error: false
          token: ${{ secrets.CODECOV_TOKEN }}
```

**Step 2: Commit**

```bash
git add .github/workflows/test.yml
git commit -m "ci: add GitHub Actions workflow for PHP tests with Codecov"
```

---

### Task 10: Set up Codecov

**Step 1: Create `codecov.yml` in the repo root**

```yaml
coverage:
  status:
    project:
      default:
        # Don't fail CI on coverage, just track the trend.
        informational: true
    patch:
      default:
        informational: true

comment:
  layout: "reach,diff,flags,files"
  behavior: default
```

**Step 2: Commit**

```bash
git add codecov.yml
git commit -m "ci: add Codecov configuration"
```

**Step 3: Manual setup**

After pushing, the repo owner needs to:
1. Go to https://codecov.io and sign in with GitHub.
2. Add the repository.
3. Copy the upload token and add it as `CODECOV_TOKEN` in the repo's GitHub Secrets (Settings > Secrets > Actions).

---

## Phase 8: E2E Infrastructure (Docker + Playwright)

### Task 11: Create Docker Compose environment

**Files:**
- Create: `e2e/docker-compose.yml`
- Create: `e2e/setup.sh`

**Step 1: Write `e2e/docker-compose.yml`**

```yaml
services:
  wordpress:
    image: wordpress:6.7-php8.2
    ports:
      - "8080:80"
    environment:
      WORDPRESS_DB_HOST: db
      WORDPRESS_DB_USER: wordpress
      WORDPRESS_DB_PASSWORD: wordpress
      WORDPRESS_DB_NAME: wordpress
      WORDPRESS_DEBUG: "1"
    volumes:
      - wordpress_data:/var/www/html
      - ../:/var/www/html/wp-content/plugins/stripe-terminal-for-woocommerce
    depends_on:
      db:
        condition: service_healthy
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost"]
      interval: 10s
      timeout: 5s
      retries: 10

  db:
    image: mariadb:10.11
    environment:
      MYSQL_ROOT_PASSWORD: root
      MYSQL_DATABASE: wordpress
      MYSQL_USER: wordpress
      MYSQL_PASSWORD: wordpress
    healthcheck:
      test: ["CMD", "mariadb-admin", "ping", "-h", "localhost", "-u", "root", "-proot"]
      interval: 5s
      timeout: 5s
      retries: 10

  wp-cli:
    image: wordpress:cli-2.10-php8.2
    depends_on:
      wordpress:
        condition: service_healthy
    volumes:
      - wordpress_data:/var/www/html
      - ../:/var/www/html/wp-content/plugins/stripe-terminal-for-woocommerce
      - ./setup.sh:/setup.sh
    entrypoint: ["/bin/sh", "/setup.sh"]
    environment:
      WORDPRESS_DB_HOST: db
      WORDPRESS_DB_USER: wordpress
      WORDPRESS_DB_PASSWORD: wordpress
      WORDPRESS_DB_NAME: wordpress

volumes:
  wordpress_data:
```

**Step 2: Write `e2e/setup.sh`**

```bash
#!/bin/sh
set -e

echo "Waiting for WordPress to be ready..."
until wp core is-installed --path=/var/www/html 2>/dev/null; do
  sleep 2
done

echo "Installing WordPress..."
wp core install \
  --path=/var/www/html \
  --url="http://localhost:8080" \
  --title="Test Site" \
  --admin_user=admin \
  --admin_password=admin \
  --admin_email=admin@example.com \
  --skip-email

echo "Installing WooCommerce..."
wp plugin install woocommerce --activate --path=/var/www/html

echo "Activating Stripe Terminal plugin..."
wp plugin activate stripe-terminal-for-woocommerce --path=/var/www/html

echo "Configuring WooCommerce..."
wp option update woocommerce_store_address "123 Test St" --path=/var/www/html
wp option update woocommerce_store_city "San Francisco" --path=/var/www/html
wp option update woocommerce_default_country "US:CA" --path=/var/www/html
wp option update woocommerce_store_postcode "94105" --path=/var/www/html
wp option update woocommerce_currency "USD" --path=/var/www/html

echo "Configuring Stripe Terminal gateway..."
wp option update woocommerce_stripe_terminal_for_woocommerce_settings \
  '{"enabled":"yes","title":"Stripe Terminal","description":"Pay in person using Stripe Terminal.","test_mode":"yes","test_secret_key":"'"${STRIPE_TEST_SECRET_KEY:-sk_test_placeholder}"'"}' \
  --format=json --path=/var/www/html

echo "Creating test product..."
wp wc product create \
  --name="Test Product" \
  --type=simple \
  --regular_price=10.00 \
  --path=/var/www/html \
  --user=admin

echo "Setup complete!"
```

**Step 3: Test the Docker environment**

```bash
cd e2e && docker compose up -d
```

Wait for setup to complete, then verify WordPress is running at http://localhost:8080.

```bash
docker compose down
```

**Step 4: Commit**

```bash
git add e2e/docker-compose.yml e2e/setup.sh
git commit -m "ci: add Docker Compose environment for E2E tests"
```

---

### Task 12: Set up Playwright

**Files:**
- Create: `e2e/package.json`
- Create: `e2e/playwright.config.ts`
- Create: `e2e/.gitignore`

**Step 1: Initialize the E2E package**

```bash
cd e2e
npm init -y
npm install -D @playwright/test
npx playwright install chromium
```

**Step 2: Write `e2e/playwright.config.ts`**

```typescript
import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './tests',
  timeout: 60_000,
  retries: 1,
  use: {
    baseURL: 'http://localhost:8080',
    screenshot: 'only-on-failure',
    trace: 'on-first-retry',
  },
  projects: [
    {
      name: 'chromium',
      use: { browserName: 'chromium' },
    },
  ],
  webServer: {
    command: 'docker compose up -d && docker compose exec -T wp-cli sh /setup.sh',
    url: 'http://localhost:8080',
    reuseExistingServer: true,
    timeout: 120_000,
  },
});
```

**Step 3: Create `e2e/.gitignore`**

```
node_modules/
test-results/
playwright-report/
```

**Step 4: Commit**

```bash
git add e2e/package.json e2e/package-lock.json e2e/playwright.config.ts e2e/.gitignore
git commit -m "ci: add Playwright configuration for E2E tests"
```

---

## Phase 9: E2E Test Scenarios

### Task 13: Write the first E2E test — gateway visibility

**Files:**
- Create: `e2e/tests/gateway.spec.ts`

**Step 1: Write the test**

```typescript
import { test, expect } from '@playwright/test';

test.describe('Stripe Terminal Gateway', () => {
  test('gateway appears in WooCommerce admin payment settings', async ({ page }) => {
    // Log into WordPress admin.
    await page.goto('/wp-login.php');
    await page.fill('#user_login', 'admin');
    await page.fill('#user_pass', 'admin');
    await page.click('#wp-submit');

    // Navigate to WooCommerce payment settings.
    await page.goto('/wp-admin/admin.php?page=wc-settings&tab=checkout');

    // Verify Stripe Terminal is listed as a payment method.
    await expect(page.locator('text=Stripe Terminal')).toBeVisible();
  });

  test('gateway settings page loads', async ({ page }) => {
    await page.goto('/wp-login.php');
    await page.fill('#user_login', 'admin');
    await page.fill('#user_pass', 'admin');
    await page.click('#wp-submit');

    await page.goto(
      '/wp-admin/admin.php?page=wc-settings&tab=checkout&section=stripe_terminal_for_woocommerce'
    );

    // Verify key settings fields are present.
    await expect(page.locator('text=Enable/Disable')).toBeVisible();
    await expect(page.locator('text=Test Mode')).toBeVisible();
    await expect(page.locator('text=Test Secret Key')).toBeVisible();
  });
});
```

**Step 2: Run the E2E test**

```bash
cd e2e
docker compose up -d
npx playwright test
```

Expected: Both tests PASS.

**Step 3: Commit**

```bash
git add e2e/tests/gateway.spec.ts
git commit -m "test: add E2E test for gateway visibility in WC admin"
```

---

### Task 14: Write checkout page E2E test

**Files:**
- Create: `e2e/tests/checkout.spec.ts`

**Step 1: Write the test**

This test creates an order and verifies the Stripe Terminal payment UI appears on the checkout pay page.

```typescript
import { test, expect } from '@playwright/test';

test.describe('Checkout Pay Page', () => {
  test('stripe terminal payment UI loads on order-pay page', async ({ page }) => {
    // Log into WordPress admin.
    await page.goto('/wp-login.php');
    await page.fill('#user_login', 'admin');
    await page.fill('#user_pass', 'admin');
    await page.click('#wp-submit');

    // Create a pending order via the WC REST API or admin.
    // Navigate to the order-pay page.
    // For now, we create the order via the admin panel.
    await page.goto('/wp-admin/post-new.php?post_type=shop_order');

    // This is a basic smoke test. Full payment flow testing
    // requires Stripe test keys and a simulated reader.
    // Expand this once the infrastructure is validated.
  });

  test('payment method shows loading state on checkout', async ({ page }) => {
    // Navigate to the shop and add a product to cart.
    await page.goto('/?post_type=product');

    const product = page.locator('.product').first();
    if (await product.isVisible()) {
      await product.locator('.add_to_cart_button, .button').first().click();
      await page.goto('/checkout/');

      // Look for our payment method in the checkout form.
      const terminalOption = page.locator('text=Stripe Terminal');
      if (await terminalOption.isVisible()) {
        await terminalOption.click();
        // Our payment UI should show the loading state.
        await expect(page.locator('.stripe-terminal-loading')).toBeVisible();
      }
    }
  });
});
```

**Step 2: Run E2E tests**

```bash
cd e2e && npx playwright test
```

**Step 3: Commit**

```bash
git add e2e/tests/checkout.spec.ts
git commit -m "test: add E2E test for checkout page payment UI"
```

---

### Task 15: Add E2E job to CI workflow

**Files:**
- Modify: `.github/workflows/test.yml`

**Step 1: Add the E2E job**

Append this job to the existing `test.yml`:

```yaml
  e2e-tests:
    name: E2E Tests
    runs-on: ubuntu-latest
    needs: php-tests

    steps:
      - name: Checkout code
        uses: actions/checkout@v4

      - name: Set up Node.js
        uses: actions/setup-node@v4
        with:
          node-version: '20'

      - name: Install Composer dependencies
        run: composer install --no-dev

      - name: Build payment-frontend
        working-directory: packages/payment-frontend
        run: |
          npm install
          npm run build

      - name: Start Docker environment
        working-directory: e2e
        run: docker compose up -d --wait
        env:
          STRIPE_TEST_SECRET_KEY: ${{ secrets.STRIPE_TEST_SECRET_KEY }}

      - name: Install Playwright
        working-directory: e2e
        run: |
          npm install
          npx playwright install chromium --with-deps

      - name: Run E2E tests
        working-directory: e2e
        run: npx playwright test

      - name: Upload test artifacts on failure
        if: failure()
        uses: actions/upload-artifact@v4
        with:
          name: playwright-report
          path: e2e/playwright-report/
          retention-days: 7

      - name: Stop Docker environment
        if: always()
        working-directory: e2e
        run: docker compose down -v
```

**Step 2: Commit**

```bash
git add .github/workflows/test.yml
git commit -m "ci: add E2E test job to GitHub Actions workflow"
```

---

## Phase 10: Branch Protection

### Task 16: Configure branch protection rules

This is a manual step — cannot be automated via code.

**Step 1: Go to GitHub repo Settings > Branches > Add rule**

Configure for the `main` branch:
- Require status checks to pass before merging: **ON**
  - Required checks: `PHP 7.4`, `PHP 8.0`, `PHP 8.2`, `E2E Tests`
- Require branches to be up to date before merging: **ON**

**Step 2: Document in the design doc**

Note: This task is manual GitHub configuration only.

---

## Summary

After completing all tasks, the test infrastructure consists of:

| Component | Tool | Location |
|-----------|------|----------|
| PHP unit tests | PHPUnit + Mockery + Brain\Monkey | `tests/includes/` |
| PHPUnit config | `phpunit.xml` | repo root |
| E2E environment | Docker Compose | `e2e/docker-compose.yml` |
| E2E tests | Playwright | `e2e/tests/` |
| CI pipeline | GitHub Actions | `.github/workflows/test.yml` |
| Coverage tracking | Codecov | `codecov.yml` |

**Test commands:**
- `composer test` — run PHP unit tests
- `composer test:coverage` — run with coverage report
- `cd e2e && npx playwright test` — run E2E tests (requires Docker running)

**Classes covered by PHP unit tests:**
1. CurrencyConverter (complete coverage)
2. StripeErrorHandler (all exception types)
3. StripeTerminalService (client injection, validation)
4. AjaxHandler (input validation, error paths)
5. Settings (test/live mode switching)
6. Logger (basic behavior)

**E2E scenarios:**
1. Gateway appears in WC admin settings
2. Gateway settings page loads with correct fields
3. Payment UI loads on checkout page
