# Terminal Recovery and Security Fixes Design

Date: 2026-05-01

## Context

After the POS nonce fix, Stripe Terminal payment requests now reach Stripe. Testing exposed a separate reader-state problem: a terminal can have an existing `in_progress` reader action for another PaymentIntent. Automatically cancelling that action is unsafe in production because another register/customer may be legitimately using the same reader.

This spec covers the safe recovery design plus three open issues:

- #44: permit restricted Stripe API keys
- #45: prevent viewing API keys after save
- #50: declined card must not approve/process the WooCommerce order

## Goals

1. Preserve active live payments on shared readers.
2. Allow intentional operator recovery from stale/abandoned reader actions.
3. Support restricted Stripe keys when permissions are sufficient.
4. Stop exposing saved API keys in clear text.
5. Prevent declined/failed Terminal payments from completing WooCommerce orders.

## Non-goals

- Do not automatically cancel another `in_progress` PaymentIntent during normal payment start.
- Do not build a full reader-management admin dashboard.
- Do not bypass Stripe test-mode decline behavior for simulated payments.

## Reader Busy and Force-Clear Recovery

### Current risk

If a reader action is `in_progress` for a different PaymentIntent, automatically calling `cancelAction` can cancel a legitimate payment started on another register. That is a production-facing regression.

### Desired behavior

During `process_payment_intent` preflight:

- If the reader has no action, proceed.
- If the reader has a failed or completed stale action, safe automatic cleanup may proceed.
- If the reader is `in_progress` for the same PaymentIntent, proceed/idempotently handle as appropriate.
- If the reader is `in_progress` for a different PaymentIntent, return a structured busy error and do not cancel automatically.

Suggested error payload:

```php
array(
    'code'                       => 'reader_busy',
    'message'                    => 'This terminal is already processing another payment. Complete or cancel that payment before starting a new one.',
    'reader_id'                  => $reader_id,
    'current_payment_intent_id'  => $action_pi,
    'can_force_cancel'           => true,
)
```

### Explicit force-clear endpoint

Add a separate AJAX endpoint:

```text
stripe_terminal_force_cancel_reader_action
```

Required request fields:

- `nonce`
- `reader_id`
- `expected_payment_intent_id`
- `order_id` and/or `order_key` for order-bound access validation where available

Server flow:

1. Verify nonce.
2. Verify reader ID and expected PaymentIntent ID are present.
3. Verify the current user/order can access the POS payment flow.
4. Fetch the reader state from Stripe again.
5. Confirm the reader is still processing exactly `expected_payment_intent_id`.
6. If it changed, refuse to cancel and ask the operator to refresh/retry.
7. If it matches, call `cancel_reader_action`.
8. Return success with reader status.

This compare-before-cancel step prevents cancelling a newer payment that started after the warning was shown.

### Frontend UX

When create/retry payment receives `reader_busy` with `can_force_cancel`:

- Show an actionable warning:

> This terminal is already processing another payment. If that payment is active, do not clear it. If it is stale or abandoned, you can force clear the terminal and then retry.

- Show a `Force clear terminal` button.
- On click, show a confirmation:

> Force clear this terminal? This may cancel a payment currently in progress on another register.

- After successful force clear, do not automatically start a new payment. Show:

> Terminal cleared. Click Pay with Terminal again.

Manual retry is safer than automatic takeover.

## Issue #44: Restricted Stripe API Keys

### Desired behavior

Allow restricted Stripe secret keys in addition to standard secret keys.

Accepted prefixes:

- Test mode: `sk_test_` or `rk_test_`
- Live mode: `sk_live_` or `rk_live_`

Validation should still verify mode consistency. If the restricted key lacks required permissions, existing Stripe API validation should surface that as a configuration error.

### Required permissions

Document the Stripe resources/actions the restricted key needs, based on actual plugin usage. At minimum this will involve Terminal readers/locations/connection tokens and PaymentIntent operations used by the plugin.

## Issue #45: Prevent Viewing API Keys After Save

### Desired behavior

Saved API keys must not be displayed in clear text in the WooCommerce payment settings screen.

Implementation shape:

- Render `secret_key` and `test_secret_key` as password-style fields or custom masked fields.
- After save, display a masked placeholder rather than the actual key.
- Leaving the field blank should keep the existing saved key.
- Entering a new key should replace the saved key.

This prevents shoulder-surfing or screenshot leakage from the settings page.

## Issue #50: Declined Card Must Not Approve Order

### Risk

A declined Terminal payment must never cause WooCommerce to process/complete the order. This is critical because it can create unpaid fulfilled orders.

### Desired server-side behavior

Status checks must only report payment success when Stripe confirms success.

Allowed success signals:

- Woo order is already paid from a previously confirmed successful Terminal payment; or
- Stripe PaymentIntent is `succeeded`; or
- Latest charge is paid/succeeded and belongs to the expected Terminal PaymentIntent.

Failure/decline signals must return non-success:

- `requires_payment_method` with `last_payment_error`
- `payment_failed`
- failed/canceled reader action
- canceled PaymentIntent

### Desired frontend behavior

The frontend should only submit/place the WooCommerce order after receiving a definite successful payment status. Declines should:

- stop polling
- show the decline message
- keep the order unpaid
- show retry/cancel options

### Tests

Add regression coverage that a declined PaymentIntent (`requires_payment_method` plus `last_payment_error`) does not return `is_paid: true` and does not trigger order completion behavior.

## Testing Strategy

Automated tests:

- Unit test reader busy response for different `in_progress` PaymentIntent.
- Unit test force-clear endpoint requires expected PaymentIntent ID.
- Unit test force-clear refuses when the reader action changed.
- Unit test force-clear cancels only when current reader action matches expected PaymentIntent ID.
- Unit tests for restricted key prefix validation.
- Unit tests for masked/blank API key save behavior.
- Regression test for declined PaymentIntent not marking order paid.

Manual/demo tests:

- Start a payment and leave reader in progress; second checkout should show busy + force-clear option, not auto-cancel.
- Force clear stale action and verify terminal can be used after clicking Pay again.
- Simulate a decline and verify WooCommerce order remains unpaid.
- Save Stripe keys and verify the settings screen no longer reveals them.

## Rollout

- Bump plugin version after implementation.
- Include changelog entries for reader recovery, restricted keys, masked keys, and declined-payment order safety.
- Keep the existing nonce fix separate from this recovery/security work in release notes.
