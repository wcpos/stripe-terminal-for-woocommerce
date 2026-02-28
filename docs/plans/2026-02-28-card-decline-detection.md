# Card Decline Detection Implementation Plan

> **For Claude:** REQUIRED: Use /execute-plan to implement this plan task-by-task.

**Goal:** Make the POS UI automatically detect and display card declines during Stripe Terminal payments, with retry/cancel options for the cashier.

**Architecture:** Enhance the existing 2-second polling to also retrieve the payment intent from Stripe (single API call when intent ID exists on the order). When the intent shows `requires_payment_method` with a `last_payment_error`, the JS stops polling and shows a decline message with "Try Another Card" and "Cancel Payment" buttons. Separately, fix the webhook handler to process `payment_intent.payment_failed` for audit trail.

**Tech Stack:** PHP 7.4+ (WordPress/WooCommerce), Stripe PHP SDK v16, jQuery, Webpack 5

---

### Task 1: Backend - Enhance `check_payment_status` with Stripe API lookup

**Files:**
- Modify: `includes/AjaxHandler.php:372-438`

**Step 1: Add Stripe PI retrieval to `check_payment_status()`**

In `includes/AjaxHandler.php`, replace the `check_payment_status()` method. The key change is: after reading local order metadata, if a `_stripe_terminal_payment_intent_id` exists and the payment hasn't succeeded locally, retrieve the payment intent from Stripe and include its status and error info in the response.

```php
public function check_payment_status(): void {
    try {
        // Get and validate parameters
        $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;

        if ( ! $order_id ) {
            wp_send_json_error( 'Missing order ID' );

            return;
        }

        // Get the order
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( 'Order not found' );

            return;
        }

        // Verify access using order key
        if ( ! $this->can_access_order( $order ) ) {
            wp_send_json_error( 'Access denied - invalid order key or order does not need payment' );

            return;
        }

        // Check if order is paid or has successful payment metadata
        $is_paid = $order->is_paid();
        $status  = $order->get_status();

        // Also check for saved payment metadata (from webhooks)
        $payment_status         = $order->get_meta( '_stripe_terminal_payment_status' );
        $payment_intent_id      = $order->get_meta( '_stripe_terminal_payment_intent_id' );
        $has_successful_payment = ( 'succeeded' === $payment_status && ! empty( $payment_intent_id ) );

        // Consider payment successful if order is paid OR has successful payment metadata
        $payment_successful = $is_paid || $has_successful_payment;

        // If payment hasn't succeeded locally and we have an intent ID, check Stripe directly
        $payment_intent_status = null;
        $last_payment_error    = null;

        if ( ! $payment_successful && ! empty( $payment_intent_id ) && $this->stripe_service ) {
            try {
                \Stripe\Stripe::setApiKey( $this->stripe_service->get_api_key() );
                $stripe_intent         = \Stripe\PaymentIntent::retrieve( $payment_intent_id );
                $payment_intent_status = $stripe_intent->status;

                if ( $stripe_intent->last_payment_error ) {
                    $last_payment_error = array(
                        'message'      => $stripe_intent->last_payment_error->message ?? 'Card declined',
                        'code'         => $stripe_intent->last_payment_error->code ?? null,
                        'decline_code' => $stripe_intent->last_payment_error->decline_code ?? null,
                    );
                }

                // If Stripe says succeeded but local metadata didn't catch it yet
                if ( 'succeeded' === $payment_intent_status ) {
                    $payment_successful = true;
                }
            } catch ( \Exception $e ) {
                Logger::log( 'Stripe Terminal AJAX - Failed to retrieve payment intent: ' . $e->getMessage() );
                // Continue with local-only data; don't fail the poll
            }
        }

        // Get the return URL if payment is successful
        $return_url = null;
        if ( $payment_successful ) {
            $gateway = WC()->payment_gateways()->payment_gateways()['stripe_terminal_for_woocommerce'] ?? null;
            if ( $gateway ) {
                // Get the default return URL first
                $default_url = $gateway->get_return_url( $order );
                // Then apply our custom POS logic
                $return_url = $gateway->order_received_url( $default_url, $order );
            }
        }

        wp_send_json_success( array(
            'is_paid'               => $payment_successful,
            'status'                => $status,
            'order_id'              => $order_id,
            'transaction_id'        => $order->get_transaction_id(),
            'return_url'            => $return_url,
            'payment_intent_status' => $payment_intent_status,
            'last_payment_error'    => $last_payment_error,
            'payment_metadata'      => array(
                'payment_status'         => $payment_status,
                'payment_intent_id'      => $payment_intent_id,
                'has_successful_payment' => $has_successful_payment,
            ),
        ) );
    } catch ( Exception $e ) {
        Logger::log( 'Stripe Terminal AJAX - Exception in check_payment_status: ' . $e->getMessage() );
        wp_send_json_error( 'An error occurred while checking payment status: ' . $e->getMessage() );
    }
}
```

**Step 2: Add `get_api_key()` accessor to `StripeTerminalService`**

In `includes/StripeTerminalService.php`, add this public method after the existing `get_stripe_client()` method (around line 52):

```php
/**
 * Get the API key.
 *
 * @return string
 */
public function get_api_key(): string {
    return $this->api_key;
}
```

**Step 3: Commit**

```bash
git add includes/AjaxHandler.php includes/StripeTerminalService.php
git commit -m "feat: enhance check_payment_status to query Stripe API for decline detection"
```

---

### Task 2: Backend - Add `retry_payment` AJAX handler

**Files:**
- Modify: `includes/AjaxHandler.php`

**Step 1: Register the new AJAX action**

In `includes/AjaxHandler.php`, add these two lines inside the `__construct()` method, after the simulate payment actions (around line 58):

```php
// Retry payment on reader
add_action( 'wp_ajax_stripe_terminal_retry_payment', array( $this, 'retry_payment' ) );
add_action( 'wp_ajax_nopriv_stripe_terminal_retry_payment', array( $this, 'retry_payment' ) );
```

**Step 2: Add the `retry_payment()` method**

Add this method to the `AjaxHandler` class, after the `simulate_payment()` method (around line 579):

```php
/**
 * Retry a payment by re-processing the existing payment intent on the reader.
 */
public function retry_payment(): void {
    try {
        $order_id  = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
        $reader_id = isset( $_POST['reader_id'] ) ? sanitize_text_field( $_POST['reader_id'] ) : '';

        if ( ! $order_id || ! $reader_id ) {
            wp_send_json_error( 'Missing order ID or reader ID' );

            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( 'Order not found' );

            return;
        }

        if ( ! $this->can_access_order( $order ) ) {
            wp_send_json_error( 'Access denied - invalid order key or order does not need payment' );

            return;
        }

        if ( ! $this->stripe_service ) {
            wp_send_json_error( 'Stripe service not initialized - check API key configuration' );

            return;
        }

        $payment_intent_id = $order->get_meta( '_stripe_terminal_payment_intent_id' );
        if ( empty( $payment_intent_id ) ) {
            wp_send_json_error( 'No payment intent found for this order' );

            return;
        }

        Logger::log( 'Stripe Terminal AJAX - Retrying payment intent ' . $payment_intent_id . ' on reader ' . $reader_id );

        $reader_result = $this->stripe_service->process_payment_intent( $reader_id, $payment_intent_id );

        if ( is_wp_error( $reader_result ) ) {
            Logger::log( 'Stripe Terminal AJAX - Retry failed: ' . $reader_result->get_error_message() );
            wp_send_json_error( 'Failed to retry payment on reader: ' . $reader_result->get_error_message() );

            return;
        }

        Logger::log( 'Stripe Terminal AJAX - Retry successful on reader ' . $reader_id );

        $order->add_order_note(
            \sprintf(
                'Stripe Terminal: Payment retry sent to reader %s - Payment Intent: %s',
                $reader_id,
                $payment_intent_id
            )
        );

        wp_send_json_success( array(
            'payment_intent_id' => $payment_intent_id,
            'reader'            => $reader_result,
        ) );
    } catch ( Exception $e ) {
        Logger::log( 'Stripe Terminal AJAX - Exception in retry_payment: ' . $e->getMessage() );
        wp_send_json_error( 'An error occurred while retrying payment: ' . $e->getMessage() );
    }
}
```

**Step 3: Commit**

```bash
git add includes/AjaxHandler.php
git commit -m "feat: add retry_payment AJAX handler for re-processing declined payments"
```

---

### Task 3: Backend - Fix webhook to handle `payment_intent.payment_failed`

**Files:**
- Modify: `includes/API.php:352-373`
- Modify: `includes/StripeTerminalService.php:602-630`

**Step 1: Add `payment_intent.payment_failed` case to webhook handler**

In `includes/API.php`, in the `handle_webhook()` method, add a new case to the switch statement. Insert this between the `charge.succeeded` case and the `default` case (between lines 363 and 365):

```php
			case 'payment_intent.payment_failed':
				$payment_intent = $event->data->object;
				$this->update_order_with_failed_payment( $payment_intent );

				break;
```

**Step 2: Add `update_order_with_failed_payment()` private method to API class**

Add this method at the end of the `API` class, before the closing `}` (after the `update_order_with_charge()` method around line 512):

```php
/**
 * Update the order with failed payment intent details.
 *
 * @param object $payment_intent The failed payment intent object.
 */
private function update_order_with_failed_payment( $payment_intent ): void {
    $order_id = $payment_intent->metadata->order_id ?? null;
    if ( ! $order_id ) {
        Logger::log( 'Payment failed webhook: No order_id found in metadata', 'warning' );

        return;
    }

    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        Logger::log( 'Payment failed webhook: Order not found: ' . $order_id, 'error' );

        return;
    }

    // Save failure metadata
    $error_message = $payment_intent->last_payment_error->message ?? 'Unknown error';
    $error_code    = $payment_intent->last_payment_error->code ?? null;
    $decline_code  = $payment_intent->last_payment_error->decline_code ?? null;

    $order->update_meta_data( '_stripe_terminal_payment_status', 'failed' );
    $order->update_meta_data( '_stripe_terminal_payment_error', $error_message );
    $order->save();

    $order->add_order_note(
        \sprintf(
            __( 'Stripe Terminal: Payment declined - %s (code: %s, decline_code: %s). Payment Intent: %s', 'stripe-terminal-for-woocommerce' ),
            $error_message,
            $error_code ?? 'n/a',
            $decline_code ?? 'n/a',
            $payment_intent->id
        )
    );

    Logger::log( 'Payment failed webhook: Failure metadata saved for order ' . $order_id . ' - ' . $error_message, 'info' );
}
```

**Step 3: Commit**

```bash
git add includes/API.php
git commit -m "fix: handle payment_intent.payment_failed webhook for decline audit trail"
```

---

### Task 4: Frontend - Handle decline in polling, add retry/cancel UI

**Files:**
- Modify: `packages/payment-frontend/src/payment.js`
- Modify: `includes/Gateway.php:311-329` (add retry button HTML)

**Step 1: Add "Try Another Card" button to Gateway template**

In `includes/Gateway.php`, in the `payment_fields()` method, add a retry button after the cancel button (after line 319):

```php
echo '<button type="button" class="stripe-terminal-retry-button" data-order-id="' . esc_attr( $order_id ) . '" style="display: none;">';
echo esc_html__( 'Try Another Card', 'stripe-terminal-for-woocommerce' );
echo '</button>';
```

**Step 2: Add localized string for decline**

In `includes/Gateway.php`, in the `enqueue_payment_scripts()` method, add this to the `strings` array (around line 411, after `'paymentFailed'`):

```php
'cardDeclined'         => __( 'Card declined', 'stripe-terminal-for-woocommerce' ),
'tryAnotherCard'       => __( 'Try Another Card', 'stripe-terminal-for-woocommerce' ),
```

**Step 3: Update the JavaScript source**

Replace the full `packages/payment-frontend/src/payment.js` file. Key changes from the original:

1. `bindEvents()` - Add click handler for `.stripe-terminal-retry-button`
2. `pollPaymentStatus()` - Add decline detection via `payment_intent_status` and `last_payment_error`
3. New `handleDecline(data, button)` - Shows decline error, shows retry/cancel buttons
4. New `handleRetryPayment(event)` - Calls `stripe_terminal_retry_payment` AJAX, restarts polling
5. `updateButtonVisibility()` - Handle retry button visibility based on `isDeclined` state

The specific changes in `payment.js`:

**3a. Add `isDeclined` state to constructor (after line 13):**

```js
this.isDeclined = false;
```

**3b. Add retry button binding in `bindEvents()` (after line 42):**

```js
jQuery(document).on('click', '.stripe-terminal-retry-button', this.handleRetryPayment.bind(this));
```

**3c. Replace the `pollPaymentStatus()` method (lines 233-282) with this version that detects declines:**

```js
pollPaymentStatus(paymentIntentId, orderId, button) {
    // Clear any existing polling
    this.stopPolling();

    this.pollingInterval = setInterval(async () => {
      try {
        // Check payment status using lightweight endpoint
        const response = await jQuery.ajax({
          url: this.ajaxUrl,
          type: 'POST',
          data: {
            action: 'stripe_terminal_check_payment_status',
            order_id: orderId,
            order_key: this.config.orderKey
          }
        });

        if (response.success) {
          const data = response.data;

          if (data.is_paid) {
            // Payment successful, stop polling and trigger order processing
            this.stopPolling();
            this.handleSuccessfulPayment(data);
          } else if (data.payment_intent_status === 'requires_payment_method' && data.last_payment_error) {
            // Card was declined - stop polling and show decline UI
            this.stopPolling();
            this.handleDecline(data, button);
          } else if (data.status === 'failed' || data.status === 'cancelled') {
            this.stopPolling();
            this.showError(this.strings.paymentFailed || 'Payment failed');
            button.prop('disabled', false).text(this.strings.payWithTerminal || 'Pay with Terminal');
            this.currentPaymentIntent = null;
            this.isDeclined = false;
            this.updateButtonVisibility();
          }
          // If order is still pending/processing, continue polling
        } else {
          console.error('Payment status check failed:', response.data);
          // Continue polling on error, might be temporary
        }

      } catch (error) {
        console.error('Payment polling error:', error);
        // Continue polling on network errors, might be temporary
      }
    }, 2000); // Poll every 2 seconds

    // Stop polling after 5 minutes
    setTimeout(() => {
      this.stopPolling();
      if (button.prop('disabled')) {
        this.showError(this.strings.paymentTimeout || 'Payment timed out');
        button.prop('disabled', false).text(this.strings.payWithTerminal || 'Pay with Terminal');
        this.currentPaymentIntent = null;
        this.isDeclined = false;
        this.updateButtonVisibility();
      }
    }, 300000); // 5 minutes
  }
```

**3d. Add the `handleDecline()` method (after `pollPaymentStatus`):**

```js
handleDecline(data, button) {
    const errorMessage = data.last_payment_error.message || this.strings.cardDeclined || 'Card declined';
    this.showError(errorMessage);
    this.addToLog('Card declined: ' + errorMessage, 'warning');

    // Keep the payment intent alive for retry, but mark as declined
    this.isDeclined = true;

    // Update button states
    button.text(this.strings.payWithTerminal || 'Pay with Terminal');
    // Keep pay button disabled during decline - user must choose retry or cancel
    button.prop('disabled', true);

    this.updateButtonVisibility();
  }
```

**3e. Add the `handleRetryPayment()` method (after `handleDecline`):**

```js
handleRetryPayment(event) {
    event.preventDefault();

    if (!this.currentPaymentIntent || !this.currentPaymentIntent.id) {
      this.showError('No active payment to retry');
      return;
    }

    if (!this.connectedReader) {
      this.showError(this.strings.selectReader || 'Please select a reader to continue');
      return;
    }

    const button = jQuery(event.target);
    const orderId = button.data('order-id') || this.config.orderId;
    const payButton = jQuery('.stripe-terminal-pay-button');

    button.prop('disabled', true).text('Retrying...');
    this.isDeclined = false;

    jQuery.ajax({
      url: this.ajaxUrl,
      type: 'POST',
      data: {
        action: 'stripe_terminal_retry_payment',
        order_id: orderId,
        reader_id: this.connectedReader.id,
        order_key: this.config.orderKey
      }
    })
    .done((response) => {
      if (response.success) {
        this.addToLog('Payment retry sent to reader. Present a new card.', 'info');
        this.showMessage(this.strings.useTerminal || 'Please use the terminal to complete the payment');

        payButton.text(this.strings.paymentInProgress || 'Payment in progress...');
        this.updateButtonVisibility();

        // Restart polling
        this.pollPaymentStatus(this.currentPaymentIntent.id, orderId, payButton);
      } else {
        this.showError('Retry failed: ' + (response.data || 'Unknown error'));
        this.isDeclined = true;
        this.updateButtonVisibility();
      }
      button.prop('disabled', false).text(this.strings.tryAnotherCard || 'Try Another Card');
    })
    .fail((xhr, status, error) => {
      this.showError('Retry failed: ' + error);
      button.prop('disabled', false).text(this.strings.tryAnotherCard || 'Try Another Card');
      this.isDeclined = true;
      this.updateButtonVisibility();
    });
  }
```

**3f. Replace `updateButtonVisibility()` (lines 878-897) to handle the decline state:**

```js
updateButtonVisibility() {
    if (!this.connectedReader) return;

    const simulateButton = jQuery('.stripe-terminal-simulate-button');
    const cancelButton = jQuery('.stripe-terminal-cancel-button');
    const retryButton = jQuery('.stripe-terminal-retry-button');

    // Simulate button: only show for simulated readers when payment intent is active and not declined
    if (this.connectedReader.device_type && this.connectedReader.device_type.includes('simulated') && this.currentPaymentIntent && !this.isDeclined) {
      simulateButton.show();
    } else {
      simulateButton.hide();
    }

    // Cancel button: show when payment intent is active (during payment or after decline)
    if (this.currentPaymentIntent) {
      cancelButton.show();
    } else {
      cancelButton.hide();
    }

    // Retry button: only show when card is declined
    if (this.isDeclined && this.currentPaymentIntent) {
      retryButton.show();
    } else {
      retryButton.hide();
    }
  }
```

**3g. Update `handleCancel()` to reset `isDeclined` (add after line 303: `this.currentPaymentIntent = null;`):**

```js
this.isDeclined = false;
```

**Step 4: Commit**

```bash
git add includes/Gateway.php packages/payment-frontend/src/payment.js
git commit -m "feat: add card decline detection and retry/cancel UI to payment frontend"
```

---

### Task 5: Frontend - Add CSS for decline/retry state

**Files:**
- Modify: `packages/payment-frontend/src/payment.css`

**Step 1: Add retry button styles**

Append these styles at the end of `packages/payment-frontend/src/payment.css`, before the closing responsive media query (before line 449):

```css
/* Retry Button */
.stripe-terminal-retry-button {
    background: #ffc107;
    color: #212529;
    border: none;
    padding: 12px 24px;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: background-color 0.2s ease;
    min-width: 160px;
}

.stripe-terminal-retry-button:hover:not(:disabled) {
    background: #e0a800;
}

.stripe-terminal-retry-button:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}
```

**Step 2: Commit**

```bash
git add packages/payment-frontend/src/payment.css
git commit -m "style: add retry button styles for card decline state"
```

---

### Task 6: Build frontend assets

**Step 1: Install dependencies if needed and build**

```bash
cd packages/payment-frontend && npm install && npm run build
```

Verify the build output exists:
- `assets/js/payment.js` (updated)
- `assets/css/payment.css` (updated)

**Step 2: Commit built assets**

```bash
git add assets/js/payment.js assets/css/payment.css
git commit -m "build: compile frontend assets with decline detection changes"
```

---

### Task 7: Manual test plan

This task is for manual verification. No code changes.

**Test 1: Card decline detection**
1. Connect a reader (simulated or physical)
2. Start a payment
3. Present a declined card (Stripe test card `4000000000000002` for generic decline)
4. Verify: UI shows decline error message within ~2-4 seconds
5. Verify: "Try Another Card" and "Cancel Payment" buttons appear
6. Verify: "Pay with Terminal" button is disabled

**Test 2: Retry after decline**
1. After a decline (from Test 1), click "Try Another Card"
2. Verify: reader activates again for a new card presentation
3. Present a valid card (Stripe test card `4242424242424242`)
4. Verify: payment succeeds and order processes

**Test 3: Cancel after decline**
1. Trigger another decline
2. Click "Cancel Payment"
3. Verify: payment intent is cancelled, UI resets to initial state
4. Verify: "Pay with Terminal" button is re-enabled

**Test 4: Success path unchanged**
1. Start a payment, present a valid card
2. Verify: payment succeeds normally (no regression)
