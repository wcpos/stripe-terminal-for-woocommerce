# Changelog

## 0.0.21 - 2026-05-01

### Fixed

- Generate Stripe Terminal AJAX nonces for the POS cashier on POS order-pay requests so terminal payments are no longer rejected before reaching Stripe
- Replace generic nonce failures with actionable missing/expired security token messages

## 0.0.20 - 2026-04-28

### Fixed

- Recalculate Stripe Terminal PaymentIntent amounts from the current order total so POS checkout retries after cart edits do not send stale totals to the reader

## 0.0.19 - 2026-04-22

### Changed

- Bump the plugin version to `0.0.19` for the next release

## 0.0.18 - 2026-04-21

### Changed

- Add a GitHub `Update URI` header so WordPress can identify the plugin for custom update checks
- Bump the plugin version to `0.0.18` for the update metadata release

## 0.0.17 - 2026-03-04

### Added

- MOTO (Mail Order/Telephone Order) payment support — merchants can take phone orders by keying card details on compatible readers (S700, S710, WisePOS E)
- Plugin setting to enable/disable MOTO payments under WooCommerce > Settings > Payments > Stripe Terminal
- "Phone Order" toggle on the payment screen, shown only for MOTO-compatible readers
- MOTO payment detection in webhooks with order metadata (`_stripe_terminal_moto`)
- Reader pickup verification — detects when a reader doesn't respond within 15 seconds and shows an actionable error instead of silently timing out

### Fixed

- Readers that go unresponsive after the first payment are now detected within 15 seconds instead of timing out after 5 minutes
- Pre-flight freshness gate blocks payments immediately when the reader hasn't been seen in 120+ seconds

## 0.0.16 - 2026-03-03

### Fixed

- Add description to enable/disable checkbox clarifying it's for web store checkout, not POS

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
