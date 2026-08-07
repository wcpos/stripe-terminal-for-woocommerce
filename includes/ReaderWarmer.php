<?php
/**
 * Best-effort Stripe Terminal reader keep-warm handling.
 *
 * @package WCPOS\WooCommercePOS\StripeTerminal
 */

namespace WCPOS\WooCommercePOS\StripeTerminal;

use Throwable;

/**
 * Exercises the last-used reader's command channel before payments reach it.
 *
 * Idle smart readers can hold a stale connection to Stripe while still
 * reporting "online"; the first command then stalls (see
 * docs/research/2026-08-07-stripe-terminal-s700-latency.md). Warming sends a
 * zero-total set_reader_display ping ahead of time so the payment dispatch
 * finds a fresh channel. Every warm is strictly best-effort and must never
 * block or fail the request that triggered it.
 */
class ReaderWarmer {
	/**
	 * Stripe service instance.
	 *
	 * @var null|StripeTerminalService
	 */
	private $stripe_service;

	/**
	 * Constructor.
	 *
	 * @param null|StripeTerminalService $stripe_service Optional service, primarily for tests and AJAX reuse.
	 */
	public function __construct( ?StripeTerminalService $stripe_service = null ) {
		$this->stripe_service = $stripe_service;
	}

	/**
	 * Whether keep-warm is enabled (gateway setting, filterable override).
	 *
	 * Disabled by default: verified against Stripe's API (2026-08-07, simulated
	 * reader) that set_reader_display returns HTTP 200 during an in-progress
	 * payment and REPLACES the payment action, killing collection. The guards
	 * in maybe_warm() narrow that race but cannot close it, so warming is
	 * opt-in via the "Reader Keep-Warm (Experimental)" gateway setting.
	 *
	 * @return bool
	 */
	private function is_enabled(): bool {
		$settings = (array) Settings::get_gateway_settings();
		$enabled  = 'yes' === ( $settings['enable_reader_keep_warm'] ?? 'no' );

		return (bool) apply_filters( 'stwc_enable_reader_keep_warm', $enabled );
	}

	/**
	 * Register warming hooks. Call once from the plugin bootstrap.
	 *
	 * Hooks always register; enablement is checked per event so toggling the
	 * setting takes effect without a new page-load race at bootstrap time.
	 */
	public function register(): void {

		add_action( 'woocommerce_new_order', array( $this, 'warm_new_order' ), 10, 2 );
		add_action( 'stwc_warm_reader', array( $this, 'warm_reader' ) );
		add_filter( 'rest_pre_dispatch', array( $this, 'track_pos_activity' ), 10, 3 );
	}

	/**
	 * Schedule warming when an order likely headed for the reader is created.
	 *
	 * POS orders are created as open orders before a gateway is chosen, so we
	 * warm on POS provenance (created_via) rather than payment method. Web
	 * checkout sets the gateway at creation, so it is matched directly.
	 *
	 * @param int       $order_id Order ID.
	 * @param \WC_Order $order    Order object.
	 */
	public function warm_new_order( $order_id, $order ): void {
		try {
			if ( ! $this->is_enabled() ) {
				return;
			}

			$pos_created = \in_array( $order->get_created_via(), (array) apply_filters( 'stwc_warm_on_created_via', array( 'woocommerce-pos' ) ), true )
				|| '1' === (string) $order->get_meta( '_pos' );
			$ours        = 'stripe_terminal_for_woocommerce' === $order->get_payment_method();

			if ( ! $pos_created && ! $ours ) {
				return;
			}

			// Skip when a warm ran moments ago; busy stores would otherwise ping per sale.
			if ( time() - (int) get_option( 'stwc_last_warm_at', 0 ) < 60 ) {
				return;
			}

			wp_schedule_single_event( time(), 'stwc_warm_reader' );
		} catch ( Throwable $e ) {
			Logger::log( 'Reader warm scheduling failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Execute an asynchronous reader warm.
	 */
	public function warm_reader(): void {
		try {
			$reader_id = $this->resolve_reader_target();
			if ( ! $reader_id ) {
				return;
			}

			$this->maybe_warm( $reader_id );
		} catch ( Throwable $e ) {
			Logger::log( 'Reader warm failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Warm a specific reader unless a payment could be disturbed.
	 *
	 * The reader has a single action slot shared by payments and display
	 * commands. Verified on Stripe's API: a display command sent mid-payment
	 * succeeds and replaces the payment action, killing collection. Two guards
	 * narrow that race — never warm while a non-display action is in progress,
	 * and never warm within 120s of a payment dispatch — but they cannot fully
	 * close it, which is why warming defaults off.
	 *
	 * @param string $reader_id The reader ID.
	 *
	 * @return bool Whether a warm ping was sent.
	 */
	public function maybe_warm( string $reader_id ): bool {
		if ( ! $this->is_enabled() ) {
			return false;
		}

		if ( time() - (int) get_option( 'stwc_payment_dispatch_at', 0 ) < 120 ) {
			Logger::log( 'Reader warm skipped: a payment was dispatched recently.' );

			return false;
		}

		$service = $this->get_stripe_service();
		if ( ! $service ) {
			return false;
		}

		$reader = $service->get_reader( $reader_id );
		$action = ! is_wp_error( $reader ) ? ( $reader['action'] ?? null ) : null;
		if ( $action && 'in_progress' === ( $action['status'] ?? null ) && 'set_reader_display' !== ( $action['type'] ?? null ) ) {
			Logger::log( 'Reader warm skipped: reader has an in-progress action.' );

			return false;
		}

		$result = $service->set_reader_display( $reader_id );
		if ( is_wp_error( $result ) ) {
			Logger::log( 'Reader warm failed: ' . $result->get_error_message() );
		}
		update_option( 'stwc_last_warm_at', time(), false );

		return ! is_wp_error( $result );
	}

	/**
	 * Track POS REST traffic and schedule elapsed ambient warms.
	 *
	 * @param mixed $result  REST pre-dispatch result.
	 * @param mixed $server  REST server.
	 * @param mixed $request REST request.
	 *
	 * @return mixed Unchanged result.
	 */
	public function track_pos_activity( $result, $server, $request ) {
		try {
			if ( ! $this->is_enabled() ) {
				return $result;
			}

			$route   = $request->get_route();
			$matches = false;
			foreach ( (array) apply_filters( 'stwc_pos_route_prefixes', array( '/wcpos/' ) ) as $prefix ) {
				if ( 0 === strpos( $route, $prefix ) ) {
					$matches = true;
					break;
				}
			}

			if ( $matches ) {
				$now = time();
				if ( $now - (int) get_option( 'stwc_pos_last_active', 0 ) > 60 ) {
					update_option( 'stwc_pos_last_active', $now, false );
				}

				$interval = (int) apply_filters( 'stwc_warm_interval', 600 );
				if ( $now - (int) get_option( 'stwc_last_warm_at', 0 ) > $interval ) {
					// Stamp before scheduling so concurrent requests cannot stampede.
					update_option( 'stwc_last_warm_at', $now, false );
					wp_schedule_single_event( $now, 'stwc_warm_reader' );
				}
			}
		} catch ( Throwable $e ) {
			Logger::log( 'Reader ambient warm scheduling failed: ' . $e->getMessage() );
		}

		return $result;
	}

	/**
	 * Resolve the stored reader, falling back only when Stripe has one reader.
	 *
	 * @return null|string Reader ID.
	 */
	public function resolve_reader_target(): ?string {
		$reader_id = (string) get_option( 'stwc_last_reader_id', '' );
		if ( '' !== $reader_id ) {
			return $reader_id;
		}

		$service = $this->get_stripe_service();
		$result  = $service ? $service->get_reader_status() : null;
		$readers = ! is_wp_error( $result ) ? ( $result['data'] ?? array() ) : array();
		if ( 1 === count( $readers ) && ! empty( $readers[0]['id'] ) ) {
			$reader_id = $readers[0]['id'];
			update_option( 'stwc_last_reader_id', $reader_id, false );

			return $reader_id;
		}

		Logger::log( 'Reader warm skipped: no unambiguous reader target.' );

		return null;
	}

	/**
	 * Lazily build the Stripe service from saved gateway settings.
	 *
	 * @return null|StripeTerminalService
	 */
	private function get_stripe_service(): ?StripeTerminalService {
		if ( $this->stripe_service ) {
			return $this->stripe_service;
		}

		$api_key = Settings::get_api_key();
		if ( $api_key ) {
			$this->stripe_service = new StripeTerminalService( $api_key );
		}

		return $this->stripe_service;
	}
}
