<?php
/**
 * Fold in-flight attempts from the old order-pay panel into Pro's ledger on upgrade.
 *
 * @package WCPOS\WooCommercePOS\StripeTerminal
 */

namespace WCPOS\WooCommercePOS\StripeTerminal;

/** One pass per plugin version, 25 orders at a time, resumable by offset. */
final class Legacy_Adoption {
	/** Plugin version this adoption belongs to. */
	public const VERSION = '1.0.0';
	/** Orders per `init` request; keeps the admin request that triggers it short. */
	public const PAGE_SIZE = 25;
	/** Provider family, as Stripe_Server_Provider::provider() reports it. */
	public const PROVIDER = 'stripe';
	/** The old panel's PaymentIntent id; the same id is Pro's action reference. */
	public const META_INTENT = '_stripe_terminal_payment_intent_id';
	/** The old panel's last observed status. */
	public const META_STATUS = '_stripe_terminal_payment_status';
	/**
	 * Whether Pro adopted this intent from the old panel; its events then belong to Pro.
	 *
	 * @param string $intent_id Stripe PaymentIntent id.
	 */
	public static function is_adopted( string $intent_id ): bool {
		return '' !== $intent_id && \function_exists( 'wcpos_pro_payment_id_for_action' ) && null !== wcpos_pro_payment_id_for_action( self::PROVIDER, $intent_id );
	}

	/** Run the next page of adoption, until every eligible order has been seen. */
	public static function upgrade(): void {
		if ( version_compare( (string) get_option( 'stwc_adoption_version', '0' ), self::VERSION, '>=' ) ) {
			return;
		}
		// The old panel keeps writing intents while Phone Order is on; only orders that existed
		// when the pass began are candidates, so a sale taken after the upgrade stays on the old
		// path and never gets a row.
		$boundary = (int) get_option( 'stwc_adoption_boundary', 0 );
		$started  = (int) get_option( 'stwc_adoption_started', 0 );
		if ( 0 === $boundary ) {
			// An attempt the old panel starts on an older order after this moment moves the
			// order's modified time past it; such orders are skipped too.
			$started = time();
			update_option( 'stwc_adoption_started', $started, false );
			$latest   = wc_get_orders(
				array(
					'type' => 'shop_order',
					'limit' => 1,
					'orderby' => 'ID',
					'order' => 'DESC',
					'return' => 'ids',
				)
			);
			$boundary = $latest ? (int) $latest[0] : -1;
			update_option( 'stwc_adoption_boundary', $boundary, false );
		}
		$offset = (int) get_option( 'stwc_adoption_offset', 0 );
		$orders = $boundary < 0 ? array() : wc_get_orders(
			array(
				'type'         => 'shop_order',
				'limit'        => self::PAGE_SIZE,
				'offset'       => $offset,
				'orderby'      => 'ID',
				'order'        => 'ASC',
				'meta_key'     => self::META_INTENT, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One-off upgrade pass.
				'meta_compare' => 'EXISTS',
			)
		);
		foreach ( $orders as $order ) {
			$modified = $order->get_date_modified();
			if ( $order->get_id() > $boundary || ( $modified && $modified->getTimestamp() > $started ) ) {
				continue;
			}
			// The old panel writes the status meta only at an outcome (`succeeded`, `failed`);
			// an attempt still in flight has an intent and no status, on an order still
			// waiting for payment. Anything else is over and gets no row.
			$intent = (string) $order->get_meta( self::META_INTENT );
			if ( '' === $intent || '' !== (string) $order->get_meta( self::META_STATUS ) || $order->is_paid() || ! $order->needs_payment() ) {
				continue;
			}
			$result = self::with_order_lock(
				$order->get_id(),
				static function () use ( $order, $intent ) {
					// The page was loaded before the lock: re-read the order under it, and repeat
					// the checks on that copy, so a leg a till recorded meanwhile is kept.
					$fresh = wc_get_order( $order->get_id() );
					if ( ! $fresh || self::is_adopted( $intent ) || $intent !== (string) $fresh->get_meta( self::META_INTENT ) || '' !== (string) $fresh->get_meta( self::META_STATUS ) || $fresh->is_paid() || ! $fresh->needs_payment() ) {
						return null;
					}
					return wcpos_pro_adopt_legacy_attempt( $fresh, Settings::GATEWAY_ID, $intent, (string) $fresh->get_total(), $fresh->get_currency() );
				}
			);
			if ( is_wp_error( $result ) ) {
				wc_get_logger()->error( 'Legacy Stripe Terminal adoption failed for order ' . $order->get_id() . ': ' . $result->get_error_code(), array( 'source' => 'stripe-terminal' ) );
			}
		}
		$last = $orders ? end( $orders ) : null;
		if ( count( $orders ) < self::PAGE_SIZE || ( $last && $last->get_id() >= $boundary ) ) {
			delete_option( 'stwc_adoption_offset' );
			delete_option( 'stwc_adoption_boundary' );
			delete_option( 'stwc_adoption_started' );
			update_option( 'stwc_adoption_version', self::VERSION, false );
			return;
		}
		update_option( 'stwc_adoption_offset', $offset + count( $orders ), false );
	}

	/**
	 * Run under Free's per-order lock, the one every ledger write takes.
	 *
	 * @param int      $order_id Order id.
	 * @param callable $callback Work to run while the lock is held.
	 * @return mixed The callback's result, or a WP_Error when the lock could not be taken.
	 */
	private static function with_order_lock( int $order_id, callable $callback ) {
		if ( ! class_exists( '\WCPOS\WooCommercePOS\Payments\Contract\Order_Lock' ) ) {
			return new \WP_Error( 'stwc_adoption_no_lock' );
		}
		return \WCPOS\WooCommercePOS\Payments\Contract\Order_Lock::instance()->with_lock( $order_id, $callback );
	}
}
