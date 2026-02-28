# Test Regime Design — stripe-terminal-for-woocommerce

## Overview

Introduce a thorough testing strategy for the plugin, which currently has zero test coverage. Two layers: PHP unit tests with heavy mocking (fast, isolated) and Playwright E2E tests against a Docker-based WordPress environment (realistic, browser-based). CI runs both on every push/PR with coverage tracking via Codecov.

## Layer 1: PHP Unit Tests

### Approach

Pure PHPUnit with mocked dependencies. No WordPress test bootstrap — Stripe SDK and WooCommerce classes/functions are mocked directly. This keeps tests fast and setup minimal.

The `composer.json` already has `wp-phpunit` and `yoast/phpunit-polyfills` as dev dependencies, and defines the test namespace:

```json
"autoload-dev": {
  "psr-4": {
    "WCPOS\\WooCommercePOS\\StripeTerminal\\Tests\\": "tests/includes/"
  }
}
```

### Priority Order

Coverage should be built incrementally, highest-value classes first:

1. **CurrencyConverter** — pure static logic, zero external dependencies. Covers zero-decimal currencies (JPY, KRW), special cases (ISK, HUF, TWD), rounding behavior. Easiest win.

2. **StripeErrorHandler** — the trait mapping Stripe exceptions to HTTP status codes and error messages. High value since incorrect error handling is hard to catch manually. Test every Stripe exception type.

3. **StripeTerminalService** — core business logic. Mock `\Stripe\StripeClient` and test:
   - Payment intent creation with various currencies
   - Currency validation (Terminal API limitations)
   - Reader listing
   - Transaction confirmation and cancellation
   - Error propagation

4. **AjaxHandler** — mock `StripeTerminalService`, test each of the 9 AJAX endpoints:
   - Input validation (nonce checks, required params)
   - Correct service method delegation
   - Response format (success/error JSON)

5. **API** — REST API controller. Mock service layer, test:
   - Request validation
   - Response formatting
   - Permission checks
   - Stripe exception handling via base class

6. **Gateway** — WooCommerce payment gateway. Mock `WC_Payment_Gateway` base, test:
   - Settings initialization
   - HTTPS enforcement logic
   - Payment processing flow

7. **Settings / Logger / Frontend** — thin wrappers, lowest priority. Cover if time permits.

### Test Structure

```
tests/
  includes/
    CurrencyConverterTest.php
    StripeErrorHandlerTest.php
    StripeTerminalServiceTest.php
    AjaxHandlerTest.php
    APITest.php
    GatewayTest.php
  bootstrap.php
phpunit.xml
```

### Running

```bash
composer test    # runs full PHPUnit suite
```

## Layer 2: E2E Tests (Playwright + Docker)

### Environment

A self-contained Docker stack that spins up a full WordPress + WooCommerce environment:

- **WordPress + PHP** container
- **MySQL/MariaDB** container
- Plugin source mounted as a volume
- WooCommerce installed and activated via WP-CLI on startup
- Plugin activated and configured with Stripe test-mode keys
- A provisioning script handles initial setup (create products, test orders, etc.)

### Tool Choice

Playwright — modern, fast, supports multiple browsers, built-in trace/screenshot capture for debugging failures, strong CI support.

### Test Scenarios

Starting lean, expand over time:

1. **Gateway visibility** — Stripe Terminal payment method appears on checkout when enabled, hidden when disabled.
2. **Simulated payment flow** — create an order, navigate to order-pay page, trigger a simulated terminal payment, verify payment completes and order status updates to processing/completed.
3. **Payment cancellation** — start a payment, cancel it, verify the UI reflects cancellation state.
4. **Reader management** — verify the POS reader list loads correctly on the terminal-ui page.
5. **Error handling** — trigger a declined card via Stripe's test scenarios, verify error messages display correctly in the UI.

### Stripe Simulation

Stripe Terminal supports a simulated reader mode that doesn't require physical hardware. The plugin already exposes a `simulate_payment` AJAX endpoint. Tests will:

- Configure the plugin in test mode with Stripe test API keys
- Use Stripe's simulated reader for terminal operations
- Intercept/mock Stripe JS SDK calls where the simulation doesn't fully cover browser-side behavior

### Structure

```
e2e/
  docker-compose.yml
  setup.sh                # WP-CLI provisioning (install WC, activate plugin, seed data)
  playwright.config.ts
  tests/
    checkout.spec.ts
    pos-payment.spec.ts
    reader-management.spec.ts
```

### Running

```bash
cd e2e
docker-compose up -d
npx playwright test
docker-compose down
```

## Layer 3: CI & Coverage

### GitHub Actions Workflow (`.github/workflows/test.yml`)

**Job 1: PHP Unit Tests**
- Triggers: push to any branch, pull requests
- Matrix: PHP 7.4, 8.0, 8.2
- Steps: checkout, composer install, run PHPUnit with coverage, upload to Codecov
- Expected duration: under 1 minute

**Job 2: E2E Tests**
- Triggers: push to any branch, pull requests
- Steps: checkout, docker-compose up, wait for WordPress health check, run Playwright, upload artifacts (screenshots/traces) on failure, docker-compose down
- Expected duration: 3-5 minutes

### Coverage Tracking

- **Codecov** integration (free for public repos, simple GitHub app)
- Coverage badge added to README
- No hard coverage thresholds initially — track the trend upward
- Add minimum thresholds once a baseline is established (e.g., after the first 4 test classes are covered)

### Branch Protection

- Require both CI jobs (PHP tests + E2E) to pass before merging PRs
- Configure on the `main` branch via GitHub repo settings

## Implementation Order

Recommended sequence to build this incrementally:

1. **PHPUnit scaffolding** — `phpunit.xml`, bootstrap, first test (CurrencyConverter)
2. **Core PHP tests** — StripeErrorHandler, StripeTerminalService
3. **Endpoint PHP tests** — AjaxHandler, API
4. **CI for PHP** — GitHub Actions workflow, Codecov integration
5. **Docker environment** — docker-compose.yml, setup.sh, verify WordPress boots
6. **Playwright scaffolding** — config, first E2E test (gateway visibility)
7. **E2E scenarios** — payment flow, cancellation, error handling
8. **CI for E2E** — add E2E job to workflow, branch protection rules
