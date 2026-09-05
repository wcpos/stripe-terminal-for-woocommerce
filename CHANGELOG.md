# Changelog

## 0.0.31 - 2026-09-05

### Fixed

- The `charge.succeeded` webhook handler could never run: it called `Settings::get_secret_key()`, a method that does not exist, so PHP raised a fatal error before the handler reached its own error handling. It now uses `Settings::get_api_key()` like every other Stripe call
- The six terminal REST routes (`connection-token`, `list-locations`, `register-reader`, `create-payment-intent`, `capture-payment-intent`, `attach-payment-method-to-customer`) accepted anonymous requests. They now require `manage_woocommerce` (terminal management) or `publish_shop_orders` (payment intents). The `webhook` route is unchanged; it is authenticated by the Stripe signature check

## 0.0.30 - 2026-09-02

### Fixed

- Stop rejecting non-USD orders with "Currency X is not supported by Stripe Terminal" when the Stripe account lookup fails. The currency check compared the order currency against the account's registered country, but silently assumed a US account whenever `Account::retrieve` failed (typically a restricted API key without the Account read permission), so every EUR/GBP/etc. payment was blocked before Stripe was asked. The check is now skipped when the country is unknown and Stripe validates the currency itself
- Re-check the account country with Stripe before rejecting a currency that a cached country would not support, so a rotated key or a moved account cannot leave a stale seven-day cache blocking payments
- The rejection message now names the account country ("…for a Stripe account registered in US"), and the log records why the country lookup failed (exception, key type) and which country and currencies were resolved, so the next step is visible without guesswork
- Remove dead currency-code branches from the country-to-currency table

## 0.0.29 - 2026-09-01

### Fixed

- On-reader tips (WisePOS E and other smart readers) are now written back to the WooCommerce order as a non-taxable "Tip" fee line, so the order total and POS receipt match the amount charged to the card. Previously the tip was charged by Stripe but never recorded on the order
- Remove a duplicate `CurrencyConverter.php` tracked under two case-variant paths, which broke local git operations on macOS and skewed coverage reporting

## 0.0.28 - 2026-08-17

### Added

- Add WooCommerce admin refund support for Stripe Terminal payments

### Security

- Harden the capture-payment-intent endpoint: payment metadata (charge ID, live/test mode) is now derived from the payment intent retrieved server-side from Stripe and must belong to the given order, instead of trusting client-supplied JSON

## 0.0.27 - 2026-08-07

### Changed

- Reduce Stripe API round-trips before reader dispatch by caching the account country, dispatching before reader-state recovery, and skipping fresh-order status scans
- Add explicit, filterable Stripe API timeouts: 10s connect everywhere, 30s for read-only calls; reader commands keep the full 80s so a dispatch is never aborted while Stripe is delivering it to the reader

### Added

- Add click-path timing diagnostics to WooCommerce logs, and show the payment timing breakdown (create intent / reader dispatch) in the on-page terminal log the cashier can see
- Experimental "Reader Keep-Warm" gateway setting (off by default): a zero-total display ping exercises the reader's command channel when a POS order is created, periodically while the POS is active, and when the payment page connects a reader — so idle readers don't greet the first payment with a stale connection. Off by default because a display command sent mid-payment replaces the payment on the reader (verified against Stripe's API); guards suppress warms around dispatches but the race cannot be fully closed, so avoid enabling it when multiple registers share one reader

### Fixed

- Prevent overlapping payment-status polls when Stripe responses are slow

## 0.0.26 - 2026-07-15

### Added

- WooCommerce Blocks checkout support: Stripe Terminal appears when the web-checkout setting is enabled (requires WordPress 6.6+ for the Blocks JSX runtime; older WP installs keep classic/POS Terminal and simply omit Terminal from Blocks checkout)
- After Place order on Blocks (or classic) checkout, customers are redirected to the classic order-pay page to complete payment on the reader

### Fixed

- Classic shortcode main checkout no longer dead-ends with a missing order ID when Stripe Terminal is selected — Place order now redirects to order-pay for Terminal collection


## 0.0.25 - 2026-07-10

### Fixed

- Recover duplicate paid Stripe Terminal submissions through the normal POS order-received flow so checkout can finish its receipt handoff instead of showing an already-paid error

## 0.0.24 - 2026-05-14

### Fixed

- Allow desktop POS terminal payment requests to authenticate with the WooCommerce order key when no WordPress admin nonce session is available
- Include order-scoped authentication data when verifying reader pickup status so desktop POS polling does not depend on WP Admin cookies

## 0.0.23 - 2026-05-14

### Fixed

- Stop treating stale Stripe Terminal `last_seen_at` timestamps as a hard failure before dispatching payments to a reader
- Keep POS payment polling active when reader pickup verification is inconclusive so valid in-progress payments are not cancelled prematurely

## 0.0.22 - 2026-05-01

### Fixed

- Clear stale in-progress reader actions for previous payment intents before sending a new payment to the terminal
- Add explicit force-clear recovery for stale Terminal reader actions with expected PaymentIntent verification
- Add signed POS payment tokens so desktop/JWT checkout flows do not depend on WordPress cookie nonces
- Allow Stripe restricted API key prefixes and mask saved API keys in settings
- Prevent declined or non-succeeded Terminal PaymentIntents from reporting order payment success

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
