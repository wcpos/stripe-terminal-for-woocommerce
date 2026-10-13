## Stripe Terminal for WooCommerce

### Instructions

1. Check [releases](https://github.com/wcpos/stripe-terminal-for-woocommerce/releases) for the latest version of the plugin.
2. Download the **stripe-terminal-for-woocommerce.zip** file.
3. Install & activate the plugin via `WP Admin > Plugins > Add New > Upload Plugin`.
<img width="909" alt="Gateway Settings" src="https://github.com/user-attachments/assets/ef6858f6-79a2-4436-8411-8bf80a617437" />

4. Go to `WP Admin > WooCommerce > Settings > Payments > Stripe Terminal` and enter your [Stripe secret key](https://docs.stripe.com/keys). The plugin requires WooCommerce POS Pro 2.0.0 or newer. The gateway is enabled for the POS in the next step.
<img width="901" alt="Screenshot 2024-12-25 at 7 48 08 PM" src="https://github.com/user-attachments/assets/18465660-4a74-42f6-bd3a-5485628d6d7e" />

5. Go to `WP Admin > WooCommerce POS > Settings > Checkout > enable` the Stripe Terminal gateway.
<img width="739" alt="Enable in POS" src="https://github.com/user-attachments/assets/cadf6c97-27c7-4197-8783-2ba05ffee9ad" />

### WooCommerce POS 2.0 checkout

WooCommerce POS Pro 2.0.0 adds a native Stripe Terminal checkout tile for smart readers: WisePOS E, S700, S710 and P400 (plus simulated readers).
An administrator saves the Stripe Terminal gateway settings with a Stripe key to auto-register `wcpos/v2/payments/webhook?provider=stripe` and store its separate signing secret for the selected test/live mode.
The gateway settings show the POS webhook URL and whether its signing secret is stored; the legacy webhook is not replaced.
Dashboard-configured on-reader tips are recorded by POS as a Tip order fee.
The POS Legacy tab (the order-pay page) runs through Pro's shared order-pay panel, so a payment taken there is a ledger row like a keypad payment: it refunds from the till, counts in reports and drives the customer display. With **Phone Order** (MOTO) enabled, Stripe's own order-pay panel stays, because keyed entry has no home in the shared panel yet.
Bluetooth readers (Stripe Reader M2, WisePad 3) and Tap to Pay on iPhone and Android are driven by the WooCommerce POS 2.0 app for iOS and Android through the app-side `device` mode; the web and desktop apps keep the smart-reader tile above.
Both need a Terminal **Location** chosen in the gateway settings for the selected test/live mode; without one the till reports `stripe_location_missing` when it connects a reader.
A Bluetooth reader queues payments taken offline and forwards them when the connection returns; Tap to Pay needs a connection.
Tap to Pay on iPhone also asks the merchant to sign in with an Apple ID and accept Apple's terms in the app, once per Stripe account.

### Screenshots

1. Selecting the Stripe Terminal gateway will allow you to connect a reader, or use a simulator if you don't have hardware.
<img width="629" alt="Screenshot 2024-12-25 at 7 49 54 PM" src="https://github.com/user-attachments/assets/6aa4e96f-3a86-4019-b8ee-1d81223912f1" />

2. Using the simulator you can test various payment methods.
<img width="631" alt="Screenshot 2024-12-25 at 7 50 14 PM" src="https://github.com/user-attachments/assets/be242993-e6df-4bbb-af48-d060e4962a1e" />
