# Changelog

## 0.0.15 - 2026-03-03

### Added

- Pre-flight reader check before processing payment intents — detects stale reader actions and clears them automatically
- Timeout retry logic for S700 reader ER400 errors (reader busy/timeout)
- `cancel_reader_action()` and `get_reader()` methods on StripeTerminalService
- Frontend now passes `reader_id` when cancelling payments for targeted cancellation

### Fixed

- S700 readers could get stuck with stale actions, causing ER400 timeout errors on subsequent payments
- CI: Update POT workflow now has correct permissions to push commits
- CI: E2E tests wait for WordPress setup to complete before running
- CI: Bumped WordPress Docker image to 6.8 for WooCommerce 10.5 compatibility
- CI: Fixed Docker volume permissions for wp-cli setup container

## 0.0.14 - 2026-02-28

### Added

- Card decline detection in POS UI — declines are now detected automatically within 2-4 seconds via payment intent polling, no manual status check needed
- "Try Another Card" and "Cancel Payment" buttons when a card is declined
- `payment_intent.payment_failed` webhook handler with order notes for audit trail
- Guard against stale webhook events overwriting successful payment state
- Comprehensive test suite: 204 PHPUnit unit tests covering CurrencyConverter, StripeErrorHandler, StripeTerminalService, AjaxHandler, Settings, and Logger
- E2E test infrastructure with Docker Compose (WordPress + WooCommerce + MariaDB) and Playwright
- GitHub Actions CI workflow with PHP 7.4/8.0/8.2 matrix and Codecov coverage tracking
- `retry_payment` AJAX handler for re-processing a payment intent on the reader

### Changed

- Updated `stripe/stripe-php` from ^16.0 to ^19.4
- Payment status polling now checks the Stripe payment intent directly for faster decline detection

### Fixed

- Stale polling timeout callbacks could fire during payment retries, incorrectly resetting the UI

## 0.0.13 - 2025-10-07

### Fixed

- Namespace error for WP_Error
- Lint all files for errors

## 0.0.12 - 2025-09-23

### Fixed

- Add `process_payment_intent` step
- Enable `customer_cancellation` for `process_payment_intent`
