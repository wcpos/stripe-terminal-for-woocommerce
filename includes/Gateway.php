<?php
/**
 * Stripe Terminal gateway
 * Handles the gateway for Stripe Terminal.
 *
 * @package WCPOS\WooCommercePOS\StripeTerminal
 */

namespace WCPOS\WooCommercePOS\StripeTerminal;

use WC_Payment_Gateway;

/**
 * Class StripeTerminalGateway
 */
class Gateway extends WC_Payment_Gateway {
	use Abstracts\StripeErrorHandler; // Include the Stripe error handler trait.

	/**
	 * @var bool Whether test mode is enabled.
	 */
	protected $test_mode;

	/**
	 * @var string The API key to use.
	 */
	protected $api_key;

	/**
	 * Constructor for the gateway.
	 */
	public function __construct() {
		$this->id = 'stripe_terminal_for_woocommerce';
		$this->method_title = __( 'Stripe Terminal', 'stripe-terminal-for-woocommerce' );
		$this->method_description = __( 'Accept in-person payments using Stripe Terminal.', 'stripe-terminal-for-woocommerce' );

		// Load gateway settings.
		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->test_mode   = $this->get_option( 'test_mode' ) === 'yes';
		$this->api_key     = $this->test_mode ? $this->get_option( 'test_secret_key' ) : $this->get_option( 'secret_key' );

		// Save settings hook.
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

		add_action( 'init', array( $this, 'validate_and_set_webhook' ) );
		add_action( 'admin_init', array( $this, 'enforce_https_for_live_mode' ) );
	}

	/**
	 * Initialize gateway form fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled' => array(
				'title'       => __( 'Enable/Disable', 'stripe-terminal-for-woocommerce' ),
				'type'        => 'checkbox',
				'label' => sprintf(
					/* Translators: Placeholders %s is the link to WooCommerce POS. */
					__( 'Enable Stripe Terminal for web checkout (not necessary for %s)', 'stripe-terminal-for-woocommerce' ),
					'<a href="https://wcpos.com" target="_blank">WooCommerce POS</a>'
				),
				'default'     => 'no',
			),
			'title' => array(
				'title'       => __( 'Title', 'stripe-terminal-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'The title displayed to customers during checkout.', 'stripe-terminal-for-woocommerce' ),
				'default'     => __( 'Stripe Terminal', 'stripe-terminal-for-woocommerce' ),
			),
			'description' => array(
				'title'       => __( 'Description', 'stripe-terminal-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'The description displayed to customers during checkout.', 'stripe-terminal-for-woocommerce' ),
				'default'     => __( 'Pay in person using Stripe Terminal.', 'stripe-terminal-for-woocommerce' ),
			),
			'secret_key' => array(
				'title'       => __( 'Live Secret Key', 'stripe-terminal-for-woocommerce' ),
				'type'        => 'text',
				'description' => $this->check_key_status( 'live' ),
				'default'     => '',
			),
			'test_secret_key' => array(
				'title'       => __( 'Test Secret Key', 'stripe-terminal-for-woocommerce' ),
				'type'        => 'text',
				'description' => $this->check_key_status( 'test' ),
				'default'     => '',
			),
			'test_mode' => array(
				'title'       => __( 'Test Mode', 'stripe-terminal-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable Test Mode', 'stripe-terminal-for-woocommerce' ),
				'default'     => 'no',
			),
			'locations' => array(
				'title'       => __( 'Stripe Terminal Locations', 'stripe-terminal-for-woocommerce' ),
				'type'        => 'title',
				'description' => implode( '<br>', $this->fetch_terminal_locations() ),
			),
		);
	}

	/**
	 * Register the gateway with WooCommerce.
	 *
	 * @param array $methods Existing payment methods.
	 * @return array Updated payment methods.
	 */
	public static function register_gateway( $methods ) {
		$methods[] = __CLASS__;
		return $methods;
	}

	/**
	 * Process the payment.
	 *
	 * @param int $order_id Order ID.
	 * @return array Payment result or void on failure.
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		// Check if a transaction ID is recorded.
		$transaction_id = $order->get_transaction_id();
		if ( empty( $transaction_id ) ) {
			wc_add_notice( __( 'Payment error: No transaction ID recorded.', 'woocommerce' ), 'error' );
			return;
		}

		// Check if the order is already paid.
		if ( ! $order->is_paid() ) {
			$order->payment_complete();
		}

		// Return thank-you page URL.
		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * Payment fields displayed during checkout or order-pay page.
	 */
	public function payment_fields() {
		global $wp;

		// Description for the payment method.
		echo '<p>' . esc_html( $this->get_option( 'description' ) ) . '</p>';

		// React application container.
		echo '<div id="stripe-terminal-app"></div>';

		// Fallback message for users without JavaScript enabled.
		echo '<noscript>' . esc_html__( 'Please enable JavaScript to use the Stripe Terminal integration.', 'stripe-terminal-for-woocommerce' ) . '</noscript>';

		// Initialize variables.
		$charge_amount = 0;
		$tax_amount    = 0;
		$currency      = get_woocommerce_currency();

		// Check if we're on the order-pay page.
		if ( is_checkout_pay_page() ) {
			// Extract the order ID from the URL.
			$order_id = isset( $wp->query_vars['order-pay'] ) ? absint( $wp->query_vars['order-pay'] ) : 0;

			if ( $order_id ) {
				$order = wc_get_order( $order_id );

				if ( $order ) {
					$charge_amount = $this->convert_to_stripe_amount( $order->get_total(), $order->get_currency() );
					$tax_amount    = $this->convert_to_stripe_amount( $order->get_total_tax(), $order->get_currency() );
					$currency      = $order->get_currency();
				}
			}
		} else {
			// Default behavior for the main checkout page.
			$cart_total  = WC()->cart ? WC()->cart->get_total( 'edit' ) : 0;
			$cart_taxes  = WC()->cart ? WC()->cart->get_total_tax() : 0;
			$tax_included = get_option( 'woocommerce_prices_include_tax', 'no' ) === 'yes';
			$final_total = $tax_included ? $cart_total : $cart_total + $cart_taxes;

			$charge_amount = $this->convert_to_stripe_amount( $final_total, $currency );
			$tax_amount    = $this->convert_to_stripe_amount( $cart_taxes, $currency );
		}

		// REST URL for the Stripe Terminal endpoint.
		$rest_url = esc_url( rest_url( 'stripe-terminal/v1' ) );

		// Output the configuration script.
		echo '<script id="stripe-terminal-js-extra">';
		echo 'var stwcConfig = ' . wp_json_encode(
			array(
				'restUrl'      => $rest_url,
				'chargeAmount' => $charge_amount,
				'taxAmount'    => $tax_amount,
				'currency'     => strtolower( $currency ),
				'orderId'      => $order_id,
			)
		) . ';';
		echo '</script>';
	}

	/**
	 * Convert an amount to the correct smallest currency unit for Stripe.
	 *
	 * @param float  $amount   The amount in standard currency format.
	 * @param string $currency The ISO 4217 currency code.
	 * @return int The amount in the smallest currency unit.
	 */
	private function convert_to_stripe_amount( $amount, $currency ) {
		// List of zero-decimal currencies.
		$zero_decimal_currencies = array(
			'BIF',
			'CLP',
			'DJF',
			'GNF',
			'JPY',
			'KMF',
			'KRW',
			'MGA',
			'PYG',
			'RWF',
			'UGX',
			'VND',
			'VUV',
			'XAF',
			'XOF',
			'XPF',
		);

		// Special cases for certain currencies.
		$special_cases = array(
			'ISK' => 2, // Always treat as two-decimal but no fractional amounts allowed.
			'HUF' => 0, // Payouts in HUF require integer amounts divisible by 100.
			'TWD' => 0, // Payouts in TWD require integer amounts divisible by 100.
		);

		if ( in_array( strtoupper( $currency ), $zero_decimal_currencies, true ) ) {
			// Zero-decimal currency: no multiplication needed.
			return intval( round( $amount ) );
		}

		if ( isset( $special_cases[ strtoupper( $currency ) ] ) ) {
			$decimals = $special_cases[ strtoupper( $currency ) ];

			if ( $decimals === 0 ) {
				// Enforce integer amounts divisible by 100 (Stripe handles the rounding).
				return intval( round( $amount ) );
			}
			// Multiply by the defined decimal factor (e.g., ISK is treated as 2 decimals).
			return intval( round( $amount * pow( 10, $decimals ) ) );
		}

		// Default to two-decimal currency.
		return intval( round( $amount * 100 ) );
	}

	/**
	 * Enforce HTTPS for live mode.
	 */
	public function enforce_https_for_live_mode() {
		if ( ! $this->test_mode && ! is_ssl() ) {
			update_option( 'woocommerce_' . $this->id . '_settings', array_merge( $this->settings, array( 'test_mode' => 'yes' ) ) );
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error">
					<p>' . esc_html__( 'Stripe Terminal requires HTTPS for live mode. Test mode has been enabled.', 'woocommerce' ) . '</p>
				</div>';
				}
			);
			$this->test_mode = true;
		}
	}

	/**
	 *
	 */
	private function check_key_status( $mode = 'live' ) {
		$api_key = $this->get_option( $mode === 'live' ? 'secret_key' : 'test_secret_key' );

		if ( empty( $api_key ) ) {
			if ( $mode === 'test' ) {
				return __( 'Your Stripe test secret API key.', 'stripe-terminal-for-woocommerce' );
			}
			return __( 'Your Stripe live secret API key.', 'stripe-terminal-for-woocommerce' );
		}

		return $this->validate_api_key( $api_key, $mode ) . '<br>' . $this->validate_and_set_webhook( $api_key, $mode );
	}

	/**
	 * Validate the Stripe API key.
	 *
	 * @param string $api_key The Stripe API key to validate.
	 * @param string $mode The mode of the key (live/test).
	 * @return string Returns success message, or an error message.
	 */
	private function validate_api_key( $api_key, $mode = 'live' ) {
		// Check the API key prefix based on the mode.
		$is_test_key = str_starts_with( $api_key, 'sk_test_' );
		$is_live_key = str_starts_with( $api_key, 'sk_live_' );

		if ( $mode === 'test' && ! $is_test_key ) {
			return '<span style="color: red;">&#10060; ' .
			__( 'Invalid test API key. Test keys must start with sk_test_.', 'stripe-terminal-for-woocommerce' ) .
			'</span>';
		}

		if ( $mode === 'live' && ! $is_live_key ) {
			return '<span style="color: red;">&#10060; ' .
			__( 'Invalid live API key. Live keys must start with sk_live_.', 'stripe-terminal-for-woocommerce' ) .
			'</span>';
		}

		try {
			\Stripe\Stripe::setApiKey( $api_key );

			// Test the API key by fetching account details.
			$account = \Stripe\Account::retrieve();

			if ( $mode === 'test' && $account->livemode ) {
				return '<span style="color: red;">&#10060; ' .
				__( 'Test key provided, but it is being used in live mode.', 'stripe-terminal-for-woocommerce' ) .
				'</span>';
			}

			if ( $mode === 'live' && ! $account->livemode ) {
				return '<span style="color: red;">&#10060; ' .
				__( 'Live key provided, but it is being used in test mode.', 'stripe-terminal-for-woocommerce' ) .
				'</span>';
			}

			return '<span style="color: green;">&#10003; ' .
			__( 'Stripe API key is valid.', 'stripe-terminal-for-woocommerce' ) .
			'</span>';
		} catch ( \Stripe\Exception\ApiErrorException $e ) {
			return '<span style="color: red;">&#10060; ' .
			$this->handle_stripe_exception( $e, 'admin' ) .
			'</span>';
		}
	}

	/**
	 * Validate and set the Stripe webhook for the plugin.
	 *
	 * @param string $api_key The Stripe API key to use.
	 * @param string $mode The mode of the key (live/test).
	 * @return string Returns success message or an error message.
	 */
	public function validate_and_set_webhook( $api_key, $mode = 'live' ) {
		$webhook_url = rest_url( 'stripe-terminal/v1/webhook' );

		try {
			\Stripe\Stripe::setApiKey( $api_key );
			$webhooks = \Stripe\WebhookEndpoint::all();

			$exists = false;
			foreach ( $webhooks->data as $webhook ) {
				if ( $webhook->url === $webhook_url ) {
						$exists = true;
						break;
				}
			}

			if ( ! $exists ) {
					\Stripe\WebhookEndpoint::create(
						array(
							'url'            => $webhook_url,
							'enabled_events' => array( 'payment_intent.succeeded', 'payment_intent.payment_failed' ),
						)
					);
					return '<span style="color: green;">&#10003; ' .
							sprintf( __( 'Stripe webhook successfully created: %s', 'stripe-terminal-for-woocommerce' ), esc_html( $webhook_url ) ) .
							'</span>';
			} else {
				return '<span style="color: green;">&#10003; ' .
					__( 'Stripe webhook active.', 'stripe-terminal-for-woocommerce' ) .
					'</span>';
			}
		} catch ( \Exception $e ) {
			return '<span style="color: red;">&#10060; ' .
					__( 'Error setting Stripe webhook: ', 'stripe-terminal-for-woocommerce' ) .
					esc_html( $e->getMessage() ) .
					'</span>';
		}
	}

	/**
	 * Fetch Stripe Terminal locations for the account and check for associated readers.
	 *
	 * @return array List of formatted Terminal locations and reader information or a fallback message.
	 */
	private function fetch_terminal_locations() {
		try {
			$api_key = $this->get_option( 'api_key' );

			if ( empty( $api_key ) ) {
				return array(
					__( 'No API key provided. Please enter your Stripe API key and save the settings.', 'stripe-terminal-for-woocommerce' ),
				);
			}

			\Stripe\Stripe::setApiKey( $api_key );

			// Fetch locations.
			$locations = \Stripe\Terminal\Location::all();

			if ( empty( $locations->data ) ) {
				return array(
					sprintf(
						__( 'No Stripe Terminal locations found. Please <a href="%s" target="_blank">set up locations</a> in your Stripe Dashboard.', 'stripe-terminal-for-woocommerce' ),
						'https://docs.stripe.com/terminal/fleet/register-readers'
					),
				);
			}

			// Format location and reader data.
			$location_list = array();
			foreach ( $locations->data as $location ) {
				$readers = \Stripe\Terminal\Reader::all( array( 'location' => $location->id ) );

				$address = $location->address;
				$formatted_address = sprintf(
					'%s, %s, %s %s, %s',
					$address['line1'],
					$address['city'],
					$address['state'],
					$address['postal_code'],
					$address['country']
				);

				$readers_info = empty( $readers->data )
					? __( 'No readers associated with this location.', 'stripe-terminal-for-woocommerce' )
					: sprintf( __( '%d reader(s) available.', 'stripe-terminal-for-woocommerce' ), count( $readers->data ) );

				$location_list[] = sprintf(
					__( '<strong>%1$s</strong> (ID: %2$s)<br>Address: %3$s<br>%4$s', 'stripe-terminal-for-woocommerce' ),
					esc_html( $location->display_name ),
					esc_html( $location->id ),
					esc_html( $formatted_address ),
					esc_html( $readers_info )
				);
			}

			return $location_list;

		} catch ( \Stripe\Exception\ApiErrorException $e ) {
			return array( $this->handle_stripe_exception( $e, 'admin' ) ); // Use the trait for error handling.
		}
	}
}
