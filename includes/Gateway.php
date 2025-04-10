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
				'description' => '',
				'default'     => '',
				'custom_attributes' => array(
					'id' => 'secret_key',
				),
			),
			'test_secret_key' => array(
				'title'       => __( 'Test Secret Key', 'stripe-terminal-for-woocommerce' ),
				'type'        => 'text',
				'description' => '',
				'default'     => '',
				'custom_attributes' => array(
					'id' => 'test_secret_key',
				),
			),
			'test_mode' => array(
				'title'       => __( 'Test Mode', 'stripe-terminal-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable Test Mode', 'stripe-terminal-for-woocommerce' ),
				'default'     => 'no',
				'description' => ! is_ssl() ? '<span style="color: #996b00; background-color: #fcf9e8; padding: 5px 10px; border-radius: 3px; display: inline-block;"><span style="font-weight: bold; margin-right: 5px;">⚠</span>' . __( 'Test Mode is enforced when not using SSL.', 'stripe-terminal-for-woocommerce' ) . '</span>' : '',
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
	 * Output the settings in the admin area.
	 */
	public function admin_options() {
		parent::admin_options();

		// Add the Stripe Terminal Locations section.
		$locations = $this->fetch_terminal_locations();
		?>
		<table class="form-table">
			<tr valign="top">
					<th scope="row" class="titledesc">
							<label><?php esc_html_e( 'Stripe Terminal Locations', 'stripe-terminal-for-woocommerce' ); ?></label>
					</th>
					<td class="forminp">
							<?php if ( ! empty( $locations ) ) : ?>
									<ul style="list-style-type: disc; margin-left: 20px; margin-top: 0;">
											<?php foreach ( $locations as $location ) : ?>
													<li><?php echo wp_kses_post( $location ); ?></li>
											<?php endforeach; ?>
									</ul>
							<?php else : ?>
									<p><?php esc_html_e( 'No locations found. Please connect your Stripe account first.', 'stripe-terminal-for-woocommerce' ); ?></p>
							<?php endif; ?>
					</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Output the descriptions for the gateway settings.
	 *
	 * @param  array $data Data for the description.
	 * @return string
	 */
	public function get_description_html( $data ) {
		// Check if custom_attributes are set.
		if ( isset( $data['custom_attributes'] ) && isset( $data['custom_attributes']['id'] ) ) {
			switch ( $data['custom_attributes']['id'] ) {
				case 'secret_key':
					return '<p class="description">' . $this->check_key_status( 'live' ) . '</p>';
				case 'test_secret_key':
					return '<p class="description">' . $this->check_key_status( 'test' ) . '</p>';
				case 'locations':
					return '<p class="description">' . $this->fetch_terminal_locations() . '</p>';
			}
		}

		return parent::get_description_html( $data );
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

		// Check if we're on the order-pay page.
		if ( is_checkout_pay_page() ) {
			// Extract the order ID from the URL.
			$order_id = isset( $wp->query_vars['order-pay'] ) ? absint( $wp->query_vars['order-pay'] ) : 0;
		} else {
			// Default behavior for the main checkout page.
			// @TODO - Do we use the WC Cart ID?
			$order_id = null;
		}

		// REST URL for the Stripe Terminal endpoint.
		$rest_url = esc_url( rest_url( 'stripe-terminal/v1' ) );

		// Output the configuration script.
		echo '<script id="stripe-terminal-js-extra">';
		echo 'var stwcConfig = ' . wp_json_encode(
			array(
				'restUrl'      => $rest_url,
				'orderId'      => $order_id,
			)
		) . ';';
		echo '</script>';
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
					echo '<div class="notice notice-warning">
					<p><span style="font-weight: bold; margin-right: 5px;">⚠</span>' . esc_html__( 'Stripe Terminal requires HTTPS for live mode. Test mode has been enabled.', 'woocommerce' ) . '</p>
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
			return '<span style="color: #d63638; background-color: #fcf0f1; padding: 5px 10px; border-radius: 3px; display: inline-block;"><span style="font-weight: bold; margin-right: 5px;">✕</span>' .
			__( 'Invalid test API key. Test keys must start with sk_test_.', 'stripe-terminal-for-woocommerce' ) .
			'</span>';
		}

		if ( $mode === 'live' && ! $is_live_key ) {
			return '<span style="color: #d63638; background-color: #fcf0f1; padding: 5px 10px; border-radius: 3px; display: inline-block;"><span style="font-weight: bold; margin-right: 5px;">✕</span>' .
			__( 'Invalid live API key. Live keys must start with sk_live_.', 'stripe-terminal-for-woocommerce' ) .
			'</span>';
		}

		try {
			\Stripe\Stripe::setApiKey( $api_key );

			// Test the API key by fetching account details.
			$account = \Stripe\Account::retrieve();

			if ( $mode === 'test' && ! $account->charges_enabled ) {
				return '<span style="color: #d63638; background-color: #fcf0f1; padding: 5px 10px; border-radius: 3px; display: inline-block;"><span style="font-weight: bold; margin-right: 5px;">✕</span>' .
				__( 'Test key provided, but charges are not enabled for the account.', 'stripe-terminal-for-woocommerce' ) .
				'</span>';
			}

			if ( $mode === 'live' && ! $account->charges_enabled ) {
				return '<span style="color: #d63638; background-color: #fcf0f1; padding: 5px 10px; border-radius: 3px; display: inline-block;"><span style="font-weight: bold; margin-right: 5px;">✕</span>' .
				__( 'Live key provided, but charges are not enabled for the account.', 'stripe-terminal-for-woocommerce' ) .
				'</span>';
			}

			return '<span style="color: #00a32a; background-color: #edfaef; padding: 5px 10px; border-radius: 3px; display: inline-block;"><span style="font-weight: bold; margin-right: 5px;">✓</span>' .
			__( 'Stripe API key is valid.', 'stripe-terminal-for-woocommerce' ) .
			'</span>';
		} catch ( \Stripe\Exception\ApiErrorException $e ) {
			return '<span style="color: #d63638; background-color: #fcf0f1; padding: 5px 10px; border-radius: 3px; display: inline-block;"><span style="font-weight: bold; margin-right: 5px;">✕</span>' .
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
			$webhook_secret = null;

			foreach ( $webhooks->data as $webhook ) {
				if ( $webhook->url === $webhook_url ) {
						$exists = true;
						break;
				}
			}

			/**
			 * The webhook secret is only available when creating a new webhook.
			 *
			 * @TODO - add a way to manually update the webhook secret if the webhook already exists.
			 * Or should we just delete the webhook and create a new one?
			 */
			if ( ! $exists ) {
				$new_webhook = \Stripe\WebhookEndpoint::create(
					array(
						'url'            => $webhook_url,
						'enabled_events' => array( 'payment_intent.succeeded', 'payment_intent.payment_failed' ),
					)
				);
				$webhook_secret = $new_webhook->secret ?? null; // Get the secret for the new webhook.

				// Save the secret in the plugin settings.
				if ( $webhook_secret ) {
					$settings = get_option( 'woocommerce_stripe_terminal_for_woocommerce_settings', array() );
					$settings[ $mode === 'test' ? 'test_webhook_secret' : 'webhook_secret' ] = $webhook_secret;
					update_option( 'woocommerce_stripe_terminal_for_woocommerce_settings', $settings );
				}
			}

			return '<span style="color: #00a32a; background-color: #edfaef; padding: 5px 10px; border-radius: 3px; display: inline-block;"><span style="font-weight: bold; margin-right: 5px;">✓</span>' .
					( $exists
							? __( 'Stripe webhook active.', 'stripe-terminal-for-woocommerce' )
							: sprintf( __( 'Stripe webhook successfully created: %s', 'stripe-terminal-for-woocommerce' ), esc_html( $webhook_url ) )
					) .
					'</span>';
		} catch ( \Exception $e ) {
			return '<span style="color: #d63638; background-color: #fcf0f1; padding: 5px 10px; border-radius: 3px; display: inline-block;"><span style="font-weight: bold; margin-right: 5px;">✕</span>' .
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
			if ( empty( $this->api_key ) ) {
				return array(
					__( 'No API key provided. Please enter your Stripe API key and save the settings.', 'stripe-terminal-for-woocommerce' ),
				);
			}

			\Stripe\Stripe::setApiKey( $this->api_key );

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
