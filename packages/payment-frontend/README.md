# Payment Frontend

Simple jQuery-based frontend for Stripe Terminal payments in WooCommerce.

## Overview

This package provides a lightweight frontend solution for handling Stripe Terminal payments, replacing the complex React application with a simple jQuery-based approach that communicates with the server via WordPress AJAX.

## Features

- Simple jQuery-based implementation
- WordPress AJAX integration
- Stripe Terminal JS integration
- Minimal dependencies
- Easy to build and deploy

## Development

### Prerequisites

- Node.js
- npm

### Setup

1. Install dependencies:
   ```bash
   npm install
   ```

2. Start development build with watch mode:
   ```bash
   npm run dev
   ```

3. For one-time development build:
   ```bash
   npm run build:dev
   ```

### Building

To build the production version (minified):

```bash
npm run build
```

This will create `payment.js` in the `assets/js/` directory and `payment.css` in the `assets/css/` directory that can be included in your WordPress plugin.

## Usage

The built `payment.js` file should be enqueued in WordPress along with jQuery. The script will automatically initialize and bind to elements with the following classes:

- `.stripe-terminal-pay-button` - Payment buttons
- `.stripe-terminal-cancel-button` - Cancel buttons

### Required Data Attributes

Payment buttons should have the following data attributes:
- `data-order-id` - The WooCommerce order ID
- `data-amount` - The payment amount in cents

### WordPress AJAX Actions

The frontend expects the following WordPress AJAX actions to be available:

- `stripe_terminal_create_payment_intent` - Creates a payment intent for the order

## File Structure

```
payment-frontend/
├── src/
│   ├── payment.js          # Main payment frontend code
│   └── payment.css         # Payment interface styles
├── index.html              # Development HTML file
├── package.json            # Package configuration
├── webpack.config.js       # Webpack build configuration
└── README.md               # This file

Built files are output to:
├── assets/
│   ├── js/
│   │   └── payment.js      # Built JavaScript file
│   └── css/
│       └── payment.css     # Built CSS file (production only)
```

## Integration with WordPress

The built `payment.js` and `payment.css` files should be enqueued in your WordPress plugin:

```php
public function enqueue_payment_scripts(): void {
    // Only load on checkout pages or when our gateway is selected
    if ( ! is_checkout() && ! is_checkout_pay_page() ) {
        return;
    }

    global $wp;

    // Enqueue the payment CSS
    wp_enqueue_style(
        'stripe-terminal-payment',
        SUTWC_PLUGIN_URL . 'assets/css/payment.css',
        array(),
        SUTWC_VERSION
    );

    // Enqueue the payment script
    wp_enqueue_script(
        'stripe-terminal-payment',
        SUTWC_PLUGIN_URL . 'assets/js/payment.js',
        array( 'jquery' ),
        SUTWC_VERSION,
        true
    );

    // Check if we're on the order-pay page to get order ID
    $order_id = null;
    if ( is_checkout_pay_page() ) {
        $order_id = isset( $wp->query_vars['order-pay'] ) ? absint( $wp->query_vars['order-pay'] ) : 0;
    }

    // Localize script data for payment interface
    wp_localize_script(
        'stripe-terminal-payment',
        'stripeTerminalData',
        array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'orderId' => $order_id,
            'nonce' => wp_create_nonce('stripe_terminal_nonce'),
            'strings' => array(
                'startingPayment'    => __( 'Starting payment...', 'stripe-terminal-for-woocommerce' ),
                'paymentInProgress'  => __( 'Payment in progress...', 'stripe-terminal-for-woocommerce' ),
                'paymentCancelled'   => __( 'Cancellation request sent. Please check the reader.', 'stripe-terminal-for-woocommerce' ),
                'paymentSuccess'     => __( 'Payment successful!', 'stripe-terminal-for-woocommerce' ),
                'paymentFailed'      => __( 'Payment failed:', 'stripe-terminal-for-woocommerce' ),
                'networkError'       => __( 'Network error occurred', 'stripe-terminal-for-woocommerce' ),
                'systemNotInitialized' => __( 'Payment system not initialized', 'stripe-terminal-for-woocommerce' ),
                'missingData'        => __( 'Missing order ID or amount', 'stripe-terminal-for-woocommerce' ),
                'payWithTerminal'    => __( 'Pay with Terminal', 'stripe-terminal-for-woocommerce' ),
            ),
        )
    );
}
```
