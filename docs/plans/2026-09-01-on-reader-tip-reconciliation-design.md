# On-reader tip reconciliation — design

**Date:** 2026-09-01
**Status:** Implemented (PR #97)

## Problem report

Merchant (USA, WisePOS E): customer selects a tip on the reader, the card is charged
total + tip, but the WooCommerce order — and therefore the POS receipt — still shows the
pre-tip total. Receipt doesn't match the charge. Turning tips off is not acceptable
(staff income), so the tip must flow back into the order.

## Diagnosis

The tip is never read back from Stripe. Verified in code (v0.0.28) and Stripe docs:

1. `API.php::create_payment_intent()` creates the PI for `$order->get_total()` — pre-tip.
2. On-reader tipping (enabled via the reader's Terminal Configuration in the Stripe
   dashboard, not by this plugin) adds the tip during collect. Per Stripe docs
   (<https://docs.stripe.com/terminal/features/collecting-tips/on-reader>): after
   confirmation, `payment_intent.amount` **includes** the tip, and the tip itself is at
   `payment_intent.amount_details.tip.amount` (`null` = tipping disabled, `0` = no tip
   selected, `>0` = tip). Same on the Charge.
3. `API.php::capture_payment_intent()` retrieves the PI server-side (trusted — store's own
   key) and saves charge meta, but ignores `amount_details.tip`.
4. The webhook handlers (`update_order_with_payment_intent` / `update_order_with_charge`)
   save `_stripe_terminal_payment_amount` (tip-inclusive) to meta but never touch the
   order's line items or total.
5. `Gateway.php::process_payment()` calls `$order->payment_complete()` with the order total
   unchanged. Receipt is rendered from the order ⇒ no tip line, total mismatch.

## Fix

When a succeeded PI is retrieved **server-side**, reconcile the tip into the order as a
fee line item before/at payment completion:

- **Where:** a single shared helper, `Utils\TipReconciler::maybe_add_tip_to_order( $order, $payment_intent )`
  (static utility, same pattern as `CurrencyConverter`), called from every server-side
  touchpoint that holds an authoritative PI:
  - `StripeTerminalService::update_order_from_payment_intent()` — the choke point for the
    **primary AJAX checkout flow** (confirm-payment polling, payment-status checks, and
    gateway recovery all funnel through it, each before `payment_complete()`). Review
    finding on the first cut: the REST capture endpoint is not called by the active
    jQuery/AJAX checkout, so hooking only the API paths would have left the immediate
    receipt pre-tip whenever the webhook lagged.
  - `API::capture_payment_intent()` and both webhook handlers (safety net; the webhook
    `update_order_with_charge` needs the PI — it already retrieves it — since
    `amount_details` lives there).
- **What:**
  - Read `$payment_intent->amount_details->tip->amount ?? 0`; bail if `<= 0`.
  - Convert back to decimal via `CurrencyConverter` (zero-decimal currency support —
    don't hardcode `/100`).
  - Add a `WC_Order_Item_Fee` named `Tip` (translatable), `tax_status = 'none'` (tips are
    not taxable sales in the US; keep it simple, no new setting), amount = tip.
  - `calculate_totals( false )` (don't re-tax), save, add an order note:
    "Stripe Terminal: on-reader tip of X added (PI pi_xxx)".
  - Also store `_stripe_terminal_tip_amount` meta for reporting/refund math.
- **Idempotency:** bail if `_stripe_terminal_tip_amount` meta already set for this PI —
  capture endpoint + two webhooks can all fire for one payment; the tip must be added
  exactly once. Key the guard on the PI id so a retried payment after a failed attempt
  isn't blocked.
- **Security:** tip amount comes only from the server-retrieved PI — never from the
  unauthenticated `/capture-payment-intent` request JSON (existing rule in that endpoint,
  see comment at API.php:314).
- **Ordering:** capture endpoint runs before the POS submits checkout, so the fee lands
  before `payment_complete()` and the paid total matches the charge. If only the webhook
  path fires and arrives after `payment_complete()`, the fee is still added and the order
  note explains the adjustment (totals on a paid order can be recalculated; receipt reads
  from the order either way).

## Testing

- Simulator already supports on-reader tips (`SimulatorPayment.tsx` — `tipAmount` passed
  to collect). E2E: pay with simulated tip, assert order gains a Tip fee line and
  `get_total()` equals the Stripe charge amount.
- Unit tests for the helper: tip > 0, tip = 0, tip null (tipping disabled), duplicate
  delivery (idempotent), zero-decimal currency (JPY).
- Refund interaction: full refund must now refund total + tip — verify the refund path
  (#95) uses the order total / charge amount, not a recomputed pre-tip sum.

## Out of scope (possible follow-ups)

- A gateway setting to configure/skip tipping per-transaction (`collectPaymentMethod`
  `config_override.skip_tipping`) — merchant opt-ins are settings on the gateway page.
- Surfacing tip totals in WooCommerce reports beyond the fee line.
- The sibling terminal plugins (square/sumup/…) share this problem shape; the helper is a
  candidate for the shared layer (roadmap#95 extraction).
