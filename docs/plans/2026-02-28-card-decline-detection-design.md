# Card Decline Detection in POS UI

**Issue:** https://github.com/wcpos/stripe-terminal-for-woocommerce/issues/17
**Date:** 2026-02-28

## Problem

When a card is declined on the Stripe Terminal reader (e.g., WisePOS E), the POS UI stays stuck on "Payment in progress..." while the reader itself shows "Card declined." The decline only surfaces when the cashier manually clicks "Check Payment Status."

### Root Cause

Two gaps working together:

1. **Polling only checks local order metadata.** The `check_payment_status` AJAX handler (`AjaxHandler.php:372-438`) checks `$order->is_paid()`, `$order->get_status()`, and `_stripe_terminal_payment_status` meta. It never queries the Stripe API, so it can't detect a decline that happened on the reader.

2. **Webhook handler doesn't process failures.** The webhook endpoint in `API.php:353-367` only handles `payment_intent.succeeded` and `charge.succeeded`. There's no case for `payment_intent.payment_failed`, so failure metadata never gets written to the order. The `StripeTerminalService` has a `handle_payment_intent_failed()` method but the API webhook handler never calls it.

## Solution

Three coordinated changes:

### 1. Upgrade Polling to Check Stripe API

Enhance `AjaxHandler::check_payment_status()` to also retrieve the payment intent from Stripe when one exists on the order.

- Check for `_stripe_terminal_payment_intent_id` in order meta
- If present, call `\Stripe\PaymentIntent::retrieve($id)` (single lightweight API call)
- Include the payment intent `status` and `last_payment_error` in the response
- This is much lighter than `check_stripe_status` which searches all recent payment intents

The polling interval stays at 2 seconds. Active payments typically last 10-30 seconds, so we're talking about 5-15 API calls total per transaction.

### 2. Handle Decline in Frontend

Update `pollPaymentStatus()` to detect declines:

- When response includes `payment_intent_status === 'requires_payment_method'` and `last_payment_error` is present, treat it as a card decline
- Stop polling
- Show the decline reason from `last_payment_error.message` (e.g., "Your card was declined")
- Present two action buttons:
  - **Try Another Card** - Re-processes the same payment intent on the reader, restarts polling
  - **Cancel Payment** - Cancels the payment intent, resets UI to initial state

New AJAX action `stripe_terminal_retry_payment` powers the retry button by calling `process_payment_intent()` with the existing intent ID and reader ID.

### 3. Fix Webhook Handler

Add `payment_intent.payment_failed` to the webhook switch in `API.php`:

- Call the existing `StripeTerminalService::handle_payment_intent_failed()` method
- Update that method to also save `_stripe_terminal_payment_status` = `'failed'` and error details to order meta (currently it only sets order status)
- This provides an audit trail and acts as a safety net if the browser disconnects

## Files Changed

| File | Change |
|------|--------|
| `includes/AjaxHandler.php` | Enhance `check_payment_status()` with Stripe API lookup; add `retry_payment()` action |
| `includes/API.php` | Add `payment_intent.payment_failed` case to webhook handler |
| `includes/StripeTerminalService.php` | Update `handle_payment_intent_failed()` to save failure metadata to order meta |
| `packages/payment/src/index.js` | Handle decline in polling, add retry/cancel UI, new button handlers |
| `assets/css/payment.css` | Styles for decline error state and retry/cancel buttons |

## What This Doesn't Do

- No changes to the reader connection flow
- No changes to the success path
- No retry limits (Stripe handles that at the intent level)
- No changes to the order-pay form submission flow
