<?php
/**
 * Re-sends a POS refund create Stripe did not answer, under the same idempotency key.
 *
 * @package WCPOS\WooCommercePOS\StripeTerminal\Server
 */

namespace WCPOS\WooCommercePOS\StripeTerminal\Server;

use WCPOS\WooCommercePOS\StripeTerminal\Settings;
use WCPOS\WooCommercePOS\StripeTerminal\StripeTerminalService;

/**
 * A refund POST that went unanswered (a dropped connection, a Stripe 5xx) may or may not have
 * been taken. The adapter answers Pro `pending` with no reference, so the WooCommerce record
 * stands; this class sends the SAME request again under the SAME idempotency key a few times.
 * Stripe replays a taken refund rather than making a second one, and makes it now if it never
 * arrived, so either way the refund shows under its metadata for Pro's sweep to find. A refusal
 * of a byte-identical replay means it was never taken: that is recorded on the refund record for
 * `fetch_refund()` to report as failed, with Stripe's reason on the order.
 */
final class Refund_Reask {
	public const HOOK = 'stwc_refund_reask';

	/**
	 * Seconds before each re-send. A dropped connection is usually back within a minute; later
	 * tries space out so an outage is not hammered, and the last one still lands well inside the
	 * sweep's first look at the order. After these, the sweep's own give-up governs.
	 */
	public const DELAYS = array( 60, 300, 1800 );

	/**
	 * Seconds after the till's request within which a re-send may still be made. Stripe keeps an
	 * idempotency key for at least 24 hours; a re-send after that could make a second refund rather
	 * than replay the first. WP cron can run an event long after its time, so the age is checked
	 * when the event runs, and half of Stripe's floor leaves room for the clock skew between them.
	 */
	public const REPLAY_WINDOW = 12 * 3600;

	/** Refund-record meta: Stripe's reason for refusing the replay, so the refund was never made. */
	public const REFUSED_META = '_stwc_refund_refused';

	/** Hook the re-send onto its cron event. */
	public static function register(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ), 10, 1 );
	}

	/**
	 * Queue a re-send.
	 *
	 * @param array $request payment, amount (minor units), metadata, key, mode, refund_id, attempt.
	 */
	public static function schedule( array $request ): void {
		$attempt = (int) ( $request['attempt'] ?? 0 );
		if ( ! isset( self::DELAYS[ $attempt ] ) ) {
			return;
		}
		$request['attempt'] = $attempt;
		$request['requested_at'] = (int) ( $request['requested_at'] ?? time() );
		$scheduled = wp_schedule_single_event( time() + self::DELAYS[ $attempt ], self::HOOK, array( $request ) );
		if ( true !== $scheduled ) {
			// The refund stays pending either way; the sweep finds a taken one by its metadata and
			// gives up on the rest. Staff can see here why it was never sent again.
			wc_get_logger()->warning(
				'Stripe refund re-send could not be scheduled',
				array(
					'source'    => 'stripe-terminal-for-woocommerce',
					'refund_id' => $request['refund_id'] ?? null,
					'attempt'   => $attempt,
					'reason'    => is_wp_error( $scheduled ) ? $scheduled->get_error_message() : 'wp_schedule_single_event returned false',
				)
			);
		}
	}

	/**
	 * Send the request again, byte for byte, under the key the till used.
	 *
	 * @param array $request As scheduled.
	 */
	public static function run( array $request ): void {
		if ( time() - (int) ( $request['requested_at'] ?? 0 ) > self::REPLAY_WINDOW ) {
			// Too late to trust the key to replay rather than make a second refund: a taken refund
			// is the sweep's to find by its metadata, the rest ends at the sweep's give-up.
			return;
		}
		$api_key = Settings::get_api_key_for_mode( (string) ( $request['mode'] ?? '' ) );
		if ( '' === $api_key ) {
			// Nothing can be sent without that mode's key; the sweep's give-up tells staff.
			return;
		}
		$service = new StripeTerminalService( $api_key );
		$result  = $service->refund_payment(
			(string) $request['payment'],
			(int) $request['amount'],
			array(
				'metadata'        => $request['metadata'],
				'request_options' => array( 'idempotency_key' => (string) $request['key'] ),
			)
		);
		if ( ! is_wp_error( $result ) ) {
			// Taken (now, or the first time): the sweep finds it by its metadata.
			return;
		}
		if ( ! self::is_refusal( $result ) ) {
			// No answer, or an answer that says nothing about the refund (the key refused, Stripe
			// throttling, a key conflict Stripe may still resolve): ask again later.
			self::schedule( array( 'attempt' => (int) $request['attempt'] + 1 ) + $request );
			return;
		}
		// Refused on the request itself: the same body under the same key would have been replayed
		// had it been taken, so this refund was never made. Pro's sweep reports the allocation failed
		// from this mark.
		$refund = wc_get_order( (int) $request['refund_id'] );
		if ( ! $refund instanceof \WC_Order_Refund ) {
			return;
		}
		$refund->update_meta_data( self::REFUSED_META, $result->get_error_message() );
		$refund->save();
		$order = wc_get_order( $refund->get_parent_id() );
		if ( $order instanceof \WC_Order ) {
			/* translators: 1: refund amount, 2: Stripe's message. */
			$order->add_order_note( sprintf( __( 'Stripe refused the POS refund of %1$s when it was sent again: %2$s. The refund was not made.', 'stripe-terminal-for-woocommerce' ), $refund->get_amount() . ' ' . $refund->get_currency(), $result->get_error_message() ) );
		}
	}

	/**
	 * Whether Stripe looked at the refund request and said no. Only an answer to the REQUEST (an
	 * invalid request, 400 or 404; a card refusal, 402) proves the refund was never made. Anything
	 * else came before Stripe consulted the key and leaves the question open: a refusal of the key
	 * (401, 403), a key conflict (409), throttling (429, and the `rate_limit` and `lock_timeout`
	 * codes a 400 can carry), a server error, or no answer at all.
	 *
	 * @param \WP_Error $error The service's error.
	 */
	private static function is_refusal( \WP_Error $error ): bool {
		$data = (array) $error->get_error_data();
		if ( in_array( (string) ( $data['stripe_code'] ?? '' ), array( 'rate_limit', 'lock_timeout' ), true ) ) {
			return false;
		}
		return in_array( (int) ( $data['status'] ?? 0 ), array( 400, 402, 404 ), true );
	}
}
