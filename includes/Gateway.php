<?php
/**
 * Stripe Terminal gateway
 * Handles the gateway for Stripe Terminal.
 */

namespace WCPOS\WooCommercePOS\StripeTerminal;

use WC_Payment_Gateway;

/**
 * Class StripeTerminalGateway.
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
	 * @var StripeTerminalService The Stripe Terminal service instance.
	 */
	protected $stripe_service;

	/**
	 * Constructor for the gateway.
	 */
	public function __construct() {
		$this->id                 = 'stripe_terminal_for_woocommerce';
		$this->method_title       = __( 'Stripe Terminal', 'stripe-terminal-for-woocommerce' );
		$this->method_description = __( 'Accept in-person payments using Stripe Terminal.', 'stripe-terminal-for-woocommerce' );

		// Load gateway settings.
		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->test_mode   = 'yes' === $this->get_option( 'test_mode' );
		$this->api_key     = $this->test_mode ? $this->get_option( 'test_secret_key' ) : $this->get_option( 'secret_key' );

		// Save settings hook.
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'admin_init', array( $this, 'enforce_https_for_live_mode' ) );
		
		// Enqueue scripts on checkout pages.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_payment_scripts' ) );
		
		// Initialize the Stripe service
		$this->init_stripe_service();
	}

	/**
	 * Initialize gateway form fields.
	 */
	public function init_form_fields(): void {
		$this->form_fields = array(
			'enabled' => array(
				'title'       => __( 'Enable/Disable', 'stripe-terminal-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => \sprintf(
					// Translators: Placeholders %s is the link to WooCommerce POS.
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
				'title'             => __( 'Live Secret Key', 'stripe-terminal-for-woocommerce' ),
				'type'              => 'text',
				'description'       => '',
				'default'           => '',
				'custom_attributes' => array(
					'id' => 'secret_key',
				),
			),
			'test_secret_key' => array(
				'title'             => __( 'Test Secret Key', 'stripe-terminal-for-woocommerce' ),
				'type'              => 'text',
				'description'       => '',
				'default'           => '',
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
	 *
	 * @return array Updated payment methods.
	 */
	public static function register_gateway( $methods ) {
		$methods[] = __CLASS__;

		return $methods;
	}

	/**
	 * Output the settings in the admin area.
	 */
	public function admin_options(): void {
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
							<?php if ( ! empty( $locations ) ) { ?>
									<ul style="list-style-type: disc; margin-left: 20px; margin-top: 0;">
											<?php foreach ( $locations as $location ) { ?>
													<li><?php echo wp_kses_post( $location ); ?></li>
											<?php } ?>
									</ul>
							<?php } else { ?>
									<p><?php esc_html_e( 'No locations found. Please connect your Stripe account first.', 'stripe-terminal-for-woocommerce' ); ?></p>
							<?php } ?>
					</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Output the descriptions for the gateway settings.
	 *
	 * @param array $data Data for the description.
	 *
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
	 *
	 * @return array Payment result or void on failure.
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		// Check if the order is already paid.
		if ( $order->is_paid() ) {
			// Return thank-you page URL.
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}

		// Check for Stripe Terminal payment metadata
		$payment_intent_id = $order->get_meta( '_stripe_terminal_payment_intent_id' );
		$charge_id         = $order->get_meta( '_stripe_terminal_charge_id' );
		$payment_status    = $order->get_meta( '_stripe_terminal_payment_status' );

		if ( $payment_intent_id && $charge_id && 'succeeded' === $payment_status ) {
			// We have successful payment metadata, complete the order
			$order->set_transaction_id( $charge_id );
			$order->payment_complete( $charge_id );
			
			// Add order note
			$order->add_order_note(
				\sprintf(
					__( 'Order processed via Stripe Terminal. Payment Intent: %s, Charge: %s', 'stripe-terminal-for-woocommerce' ),
					$payment_intent_id,
					$charge_id
				)
			);

			// Return thank-you page URL.
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}

		// No payment metadata found, check Stripe API directly
		if ( $this->stripe_service ) {
			$status_result = $this->stripe_service->check_payment_status_from_stripe( $order );
			
			if ( ! is_wp_error( $status_result ) && isset( $status_result['charge'] ) && $status_result['charge']['paid'] ) {
				// Found successful payment in Stripe, complete the order
				$charge_id         = $status_result['charge']['id'];
				$payment_intent_id = $status_result['payment_intent']['id'];
				
				$order->set_transaction_id( $charge_id );
				$order->payment_complete( $charge_id );
				
				// Add order note
				$order->add_order_note(
					\sprintf(
						__( 'Order processed via Stripe Terminal (API check). Payment Intent: %s, Charge: %s', 'stripe-terminal-for-woocommerce' ),
						$payment_intent_id,
						$charge_id
					)
				);

				// Return thank-you page URL.
				return array(
					'result'   => 'success',
					'redirect' => $this->get_return_url( $order ),
				);
			}
		}

		// No payment found, show error
		wc_add_notice( __( 'Payment error: No successful payment found for this order.', 'stripe-terminal-for-woocommerce' ), 'error' );

		return array(
			'result' => 'failure',
		);
	}

	/**
	 * Payment fields displayed during checkout or order-pay page.
	 */
	public function payment_fields(): void {
		global $wp;

		// Description for the payment method.
		echo '<p>' . esc_html( $this->get_option( 'description' ) ) . '</p>';

		// Show loading state initially - readers will be loaded via AJAX
		echo '<div class="stripe-terminal-loading">';
		echo '<p>' . esc_html__( 'Loading Stripe Terminal...', 'stripe-terminal-for-woocommerce' ) . '</p>';
		echo '</div>';

		// Check if we're on the order-pay page.
		if ( is_checkout_pay_page() ) {
			// Extract the order ID from the URL.
			$order_id = isset( $wp->query_vars['order-pay'] ) ? absint( $wp->query_vars['order-pay'] ) : 0;
			$order    = wc_get_order( $order_id );
			$amount   = $order ? $order->get_total() * 100 : 0; // Convert to cents
		} else {
			// Default behavior for the main checkout page.
			$order_id = null;
			$amount   = WC()->cart ? WC()->cart->get_total( 'raw' ) * 100 : 0; // Convert to cents
		}

		// Payment interface with reader management (hidden initially, shown after AJAX load)
		echo '<div class="stripe-terminal-payment-section" style="display: none;">';

		// Readers list (hidden by default)
		echo '<div class="stripe-terminal-readers-section" style="display: none;">';
		echo '<h4>' . esc_html__( 'Available Readers', 'stripe-terminal-for-woocommerce' ) . '</h4>';
		echo '<div class="stripe-terminal-readers-list">';
		// Readers will be populated via AJAX
		echo '</div>';
		echo '</div>';

		// Connected reader info (hidden by default)
		echo '<div class="stripe-terminal-connected-reader" style="display: none;">';
		echo '<div class="stripe-terminal-connected-header">';
		echo '<h4>' . esc_html__( 'Connected Reader', 'stripe-terminal-for-woocommerce' ) . '</h4>';
		echo '<button type="button" class="stripe-terminal-disconnect-button">' . esc_html__( 'Disconnect', 'stripe-terminal-for-woocommerce' ) . '</button>';
		echo '</div>';
		echo '<div class="stripe-terminal-reader-details"></div>';
		echo '</div>';

		// Payment buttons (hidden until reader is connected)
		echo '<div class="stripe-terminal-payment-buttons" style="display: none;">';
		echo '<button type="button" class="stripe-terminal-pay-button" data-order-id="' . esc_attr( $order_id ) . '" data-amount="' . esc_attr( $amount ) . '">';
		echo esc_html__( 'Pay with Terminal', 'stripe-terminal-for-woocommerce' );
		echo '</button>';
		
		echo '<button type="button" class="stripe-terminal-cancel-button" style="display: none;">';
		echo esc_html__( 'Cancel Payment', 'stripe-terminal-for-woocommerce' );
		echo '</button>';
		
		echo '<button type="button" class="stripe-terminal-simulate-button" data-order-id="' . esc_attr( $order_id ) . '" style="display: none;">';
		echo esc_html__( 'Simulate Payment', 'stripe-terminal-for-woocommerce' );
		echo '</button>';
		
		echo '<button type="button" class="stripe-terminal-check-status-button" data-order-id="' . esc_attr( $order_id ) . '">';
		echo esc_html__( 'Check Payment Status', 'stripe-terminal-for-woocommerce' );
		echo '</button>';
		
		echo '</div>';

		// Logging area (moved to bottom)
		echo '<div class="stripe-terminal-logging-section">';
		echo '<div class="stripe-terminal-logging-header">';
		echo '<h4>' . esc_html__( 'Logs', 'stripe-terminal-for-woocommerce' ) . '</h4>';
		echo '<div class="stripe-terminal-logging-actions">';
		echo '<button type="button" class="stripe-terminal-toggle-log" data-expanded="false">' . esc_html__( 'Show logs', 'stripe-terminal-for-woocommerce' ) . '</button>';
		echo '<button type="button" class="stripe-terminal-clear-log">' . esc_html__( 'CLEAR', 'stripe-terminal-for-woocommerce' ) . '</button>';
		echo '</div>';
		echo '</div>';
		echo '<div class="stripe-terminal-log-content" style="display: none;">';
		echo '<textarea class="stripe-terminal-log-textarea" readonly placeholder="' . esc_attr__( 'Payment activity will appear here...', 'stripe-terminal-for-woocommerce' ) . '"></textarea>';
		echo '</div>';
		echo '</div>';


		echo '</div>';

		// Fallback message for users without JavaScript enabled.
		echo '<noscript>' . esc_html__( 'Please enable JavaScript to use the Stripe Terminal integration.', 'stripe-terminal-for-woocommerce' ) . '</noscript>';
	}

	/**
	 * Enqueue payment scripts on checkout pages.
	 */
	public function enqueue_payment_scripts(): void {
		// Only load on checkout pages or when our gateway is selected
		if ( ! is_checkout() && ! is_checkout_pay_page() ) {
			return;
		}

		global $wp;

		// Enqueue the payment CSS
		wp_enqueue_style(
			'stripe-terminal-payment',
			STWC_PLUGIN_URL . 'assets/css/payment.css',
			array(),
			STWC_VERSION
		);

		// Enqueue the payment script
		wp_enqueue_script(
			'stripe-terminal-payment',
			STWC_PLUGIN_URL . 'assets/js/payment.js',
			array( 'jquery' ),
			STWC_VERSION,
			true
		);

		// Check if we're on the order-pay page to get order ID and key
		$order_id  = null;
		$order_key = null;
		if ( is_checkout_pay_page() ) {
			$order_id = isset( $wp->query_vars['order-pay'] ) ? absint( $wp->query_vars['order-pay'] ) : 0;
			if ( $order_id ) {
				$order     = wc_get_order( $order_id );
				$order_key = $order ? $order->get_order_key() : null;
			}
		}

		// Localize script data for payment interface
		wp_localize_script(
			'stripe-terminal-payment',
			'stripeTerminalData',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'orderId'  => $order_id,
				'orderKey' => $order_key,
				'strings'  => array(
					'startingPayment'      => __( 'Starting payment...', 'stripe-terminal-for-woocommerce' ),
					'paymentInProgress'    => __( 'Payment in progress...', 'stripe-terminal-for-woocommerce' ),
					'paymentCancelled'     => __( 'Cancellation request sent. Please check the reader.', 'stripe-terminal-for-woocommerce' ),
					'paymentSuccess'       => __( 'Payment successful!', 'stripe-terminal-for-woocommerce' ),
					'paymentFailed'        => __( 'Payment failed:', 'stripe-terminal-for-woocommerce' ),
					'networkError'         => __( 'Network error occurred', 'stripe-terminal-for-woocommerce' ),
					'systemNotInitialized' => __( 'Payment system not initialized', 'stripe-terminal-for-woocommerce' ),
					'missingData'          => __( 'Missing order ID or amount', 'stripe-terminal-for-woocommerce' ),
					'payWithTerminal'      => __( 'Pay with Terminal', 'stripe-terminal-for-woocommerce' ),
					'useTerminal'          => __( 'Please use the terminal to complete the payment', 'stripe-terminal-for-woocommerce' ),
					'paymentTimeout'       => __( 'Payment timed out', 'stripe-terminal-for-woocommerce' ),
					'noActivePayment'      => __( 'No active payment to cancel', 'stripe-terminal-for-woocommerce' ),
					'connecting'           => __( 'Connecting...', 'stripe-terminal-for-woocommerce' ),
					'connected'            => __( 'Connected', 'stripe-terminal-for-woocommerce' ),
					'disconnected'         => __( 'Disconnected', 'stripe-terminal-for-woocommerce' ),
					'selectReader'         => __( 'Please select a reader to continue', 'stripe-terminal-for-woocommerce' ),
					'loading'              => __( 'Loading Stripe Terminal...', 'stripe-terminal-for-woocommerce' ),
					'loadingReaders'       => __( 'Loading readers...', 'stripe-terminal-for-woocommerce' ),
					'noReaders'            => __( 'No readers available. Please register a reader in your Stripe Dashboard.', 'stripe-terminal-for-woocommerce' ),
					'serviceError'         => __( 'Stripe Terminal service is not properly configured. Please check your API key settings.', 'stripe-terminal-for-woocommerce' ),
					'readersError'         => __( 'Unable to retrieve available readers. Please try again later.', 'stripe-terminal-for-woocommerce' ),
				),
			)
		);
	}

	/**
	 * Enforce HTTPS for live mode.
	 */
	public function enforce_https_for_live_mode(): void {
		if ( ! $this->test_mode && ! is_ssl() ) {
			update_option( 'woocommerce_' . $this->id . '_settings', array_merge( $this->settings, array( 'test_mode' => 'yes' ) ) );
			add_action(
				'admin_notices',
				function (): void {
					echo '<div class="notice notice-warning">
					<p><span style="font-weight: bold; margin-right: 5px;">⚠</span>' . esc_html__( 'Stripe Terminal requires HTTPS for live mode. Test mode has been enabled.', 'woocommerce' ) . '</p>
				</div>';
				}
			);
			$this->test_mode = true;
		}
	}

	/**
	 * Validate and set the Stripe webhook for the plugin.
	 *
	 * @param string $api_key The Stripe API key to use.
	 * @param string $mode    The mode of the key (live/test).
	 *
	 * @return string Returns success message or an error message.
	 */
	public function validate_and_set_webhook( $api_key, $mode = 'live' ) {
		$webhook_url = rest_url( 'stripe-terminal/v1/webhook' );

		try {
			\Stripe\Stripe::setApiKey( $api_key );
			$webhooks = \Stripe\WebhookEndpoint::all();

			$exists         = false;
			$webhook_secret = null;

			foreach ( $webhooks->data as $webhook ) {
				if ( $webhook->url === $webhook_url ) {
					$exists = true;

					break;
				}
			}

			/*
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
					$settings                                                                = get_option( 'woocommerce_stripe_terminal_for_woocommerce_settings', array() );
					$settings[ 'test' === $mode ? 'test_webhook_secret' : 'webhook_secret' ] = $webhook_secret;
					update_option( 'woocommerce_stripe_terminal_for_woocommerce_settings', $settings );
				}
			}

			return '<span style="color: #00a32a; background-color: #edfaef; padding: 5px 10px; border-radius: 3px; display: inline-block;"><span style="font-weight: bold; margin-right: 5px;">✓</span>' .
					( $exists
							? __( 'Stripe webhook active.', 'stripe-terminal-for-woocommerce' )
							: \sprintf( __( 'Stripe webhook successfully created: %s', 'stripe-terminal-for-woocommerce' ), esc_html( $webhook_url ) )
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
	 * Get the order received URL for POS.
	 *
	 * @param string            $order_received_url The default order received URL.
	 * @param WC_Abstract_Order $order              The order object.
	 *
	 * @return string The custom order received URL.
	 */
	public function order_received_url( string $order_received_url, \WC_Abstract_Order $order ): string {
		global $wp;


		// check is pos
		if ( ! woocommerce_pos_request() || ! isset( $wp->query_vars['order-pay'] ) ) {
			return $order_received_url;
		}

		$redirect = add_query_arg(
			array(
				'key' => $order->get_order_key(),
			),
			get_home_url( null, '/wcpos-checkout/order-received/' . $order->get_id() )
		);


		return $redirect;
	}

	/**
	 * Initialize the Stripe Terminal service.
	 */
	private function init_stripe_service(): void {
		if ( empty( $this->api_key ) ) {
			return;
		}

		try {
			$this->stripe_service = new StripeTerminalService( $this->api_key );
		} catch ( Exception $e ) {
			Logger::log( 'Stripe Terminal Gateway - Failed to initialize service: ' . $e->getMessage() );
		}
	}

	/**
	 * Check if the Stripe service is properly initialized and API key is valid.
	 *
	 * @return bool True if service is ready, false otherwise.
	 */
	private function is_service_ready(): bool {
		if ( ! $this->stripe_service ) {
			return false;
		}

		// Test the API key by trying to list locations
		try {
			$result = $this->stripe_service->list_locations();

			return ! is_wp_error( $result );
		} catch ( Exception $e ) {
			Logger::log( 'Stripe Terminal Gateway - API key validation failed: ' . $e->getMessage() );

			return false;
		}
	}

	/**
	 * Get available readers from Stripe Terminal.
	 *
	 * @return array|false Array of readers or false if error.
	 */
	private function get_available_readers() {
		if ( ! $this->is_service_ready() ) {
			return false;
		}

		$result = $this->stripe_service->get_reader_status();
		
		if ( is_wp_error( $result ) ) {
			Logger::log( 'Stripe Terminal Gateway - Failed to get readers: ' . $result->get_error_message() );

			return false;
		}

		return $result['data'] ?? array();
	}

	/**
	 * Render a reader card for selection.
	 *
	 * @param array $reader The reader data from Stripe.
	 */
	private function render_reader_card( array $reader ): void {
		$reader_id     = $reader['id']            ?? '';
		$label         = $reader['label']         ?? $reader_id;
		$device_type   = $reader['device_type']   ?? 'unknown';
		$status        = $reader['status']        ?? 'unknown';
		$serial_number = $reader['serial_number'] ?? '';
		$last_seen     = $reader['last_seen_at']  ?? null;

		// Format last seen time
		$last_seen_text = '';
		if ( $last_seen ) {
			$last_seen_text = \sprintf(
				// translators: %s: Human readable time
				__( 'Last seen: %s', 'stripe-terminal-for-woocommerce' ),
				human_time_diff( $last_seen )
			);
		}

		// Status indicator
		$status_class = 'offline';
		$status_text  = __( 'Offline', 'stripe-terminal-for-woocommerce' );
		if ( 'online' === $status ) {
			$status_class = 'online';
			$status_text  = __( 'Online', 'stripe-terminal-for-woocommerce' );
		}

		echo '<div class="stripe-terminal-reader-card" data-reader-id="' . esc_attr( $reader_id ) . '">';
		echo '<div class="stripe-terminal-reader-header">';
		echo '<h5 class="stripe-terminal-reader-label">' . esc_html( $label ) . '</h5>';
		echo '<span class="stripe-terminal-reader-status ' . esc_attr( $status_class ) . '">' . esc_html( $status_text ) . '</span>';
		echo '</div>';
		
		echo '<div class="stripe-terminal-reader-details">';
		echo '<p><strong>' . esc_html__( 'Device:', 'stripe-terminal-for-woocommerce' ) . '</strong> ' . esc_html( $device_type ) . '</p>';
		if ( $serial_number ) {
			echo '<p><strong>' . esc_html__( 'Serial:', 'stripe-terminal-for-woocommerce' ) . '</strong> ' . esc_html( $serial_number ) . '</p>';
		}
		if ( $last_seen_text ) {
			echo '<p><strong>' . esc_html( $last_seen_text ) . '</strong></p>';
		}
		echo '</div>';

		echo '<div class="stripe-terminal-reader-actions">';
		if ( 'online' === $status ) {
			echo '<button type="button" class="stripe-terminal-connect-button" data-reader-id="' . esc_attr( $reader_id ) . '">';
			echo esc_html__( 'Connect', 'stripe-terminal-for-woocommerce' );
			echo '</button>';
		} else {
			echo '<button type="button" class="stripe-terminal-connect-button" disabled>';
			echo esc_html__( 'Offline', 'stripe-terminal-for-woocommerce' );
			echo '</button>';
		}
		echo '</div>';
		echo '</div>';
	}

	/**
	 * @param mixed $mode
	 */
	private function check_key_status( $mode = 'live' ) {
		$api_key = $this->get_option( 'live' === $mode ? 'secret_key' : 'test_secret_key' );

		if ( empty( $api_key ) ) {
			if ( 'test' === $mode ) {
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
	 * @param string $mode    The mode of the key (live/test).
	 *
	 * @return string Returns success message, or an error message.
	 */
	private function validate_api_key( $api_key, $mode = 'live' ) {
		// Check the API key prefix based on the mode.
		$is_test_key = str_starts_with( $api_key, 'sk_test_' );
		$is_live_key = str_starts_with( $api_key, 'sk_live_' );

		if ( 'test' === $mode && ! $is_test_key ) {
			return '<span style="color: #d63638; background-color: #fcf0f1; padding: 5px 10px; border-radius: 3px; display: inline-block;"><span style="font-weight: bold; margin-right: 5px;">✕</span>' .
			__( 'Invalid test API key. Test keys must start with sk_test_.', 'stripe-terminal-for-woocommerce' ) .
			'</span>';
		}

		if ( 'live' === $mode && ! $is_live_key ) {
			return '<span style="color: #d63638; background-color: #fcf0f1; padding: 5px 10px; border-radius: 3px; display: inline-block;"><span style="font-weight: bold; margin-right: 5px;">✕</span>' .
			__( 'Invalid live API key. Live keys must start with sk_live_.', 'stripe-terminal-for-woocommerce' ) .
			'</span>';
		}

		try {
			\Stripe\Stripe::setApiKey( $api_key );

			// Test the API key by fetching account details.
			$account = \Stripe\Account::retrieve();

			if ( 'test' === $mode && ! $account->charges_enabled ) {
				return '<span style="color: #d63638; background-color: #fcf0f1; padding: 5px 10px; border-radius: 3px; display: inline-block;"><span style="font-weight: bold; margin-right: 5px;">✕</span>' .
				__( 'Test key provided, but charges are not enabled for the account.', 'stripe-terminal-for-woocommerce' ) .
				'</span>';
			}

			if ( 'live' === $mode && ! $account->charges_enabled ) {
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
					\sprintf(
						__( 'No Stripe Terminal locations found. Please <a href="%s" target="_blank">set up locations</a> in your Stripe Dashboard.', 'stripe-terminal-for-woocommerce' ),
						'https://docs.stripe.com/terminal/fleet/register-readers'
					),
				);
			}

			// Format location and reader data.
			$location_list = array();
			foreach ( $locations->data as $location ) {
				$readers = \Stripe\Terminal\Reader::all( array( 'location' => $location->id ) );

				$address           = $location->address;
				$formatted_address = \sprintf(
					'%s, %s, %s %s, %s',
					$address['line1'],
					$address['city'],
					$address['state'],
					$address['postal_code'],
					$address['country']
				);

				$readers_info = empty( $readers->data )
					? __( 'No readers associated with this location.', 'stripe-terminal-for-woocommerce' )
					: \sprintf( __( '%d reader(s) available.', 'stripe-terminal-for-woocommerce' ), \count( $readers->data ) );

				$location_list[] = \sprintf(
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
