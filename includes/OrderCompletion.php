<?php
/**
 * Shared Stripe Terminal order completion.
 *
 * @package WCPOS\WooCommercePOS\StripeTerminal
 */

namespace WCPOS\WooCommercePOS\StripeTerminal;

/**
 * Completes a freshly loaded order under a per-order claim.
 */
class OrderCompletion {
	/**
	 * This request completed the order.
	 */
	const COMPLETED = 'completed';
	/**
	 * The freshly loaded order was already paid.
	 */
	const ALREADY_PAID = 'already_paid';
	/**
	 * Another request holds the completion claim.
	 */
	const BUSY = 'busy';
	/**
	 * Completion claim lifetime in seconds.
	 */
	private const LOCK_TTL = 120; // Covers payment_complete() (status, stock, emails); a dead request's claim can be taken over after this interval.

	/**
	 * Complete an unpaid order while holding its claim.
	 *
	 * @param \WC_Order $order          Order to complete.
	 * @param string    $transaction_id Stripe transaction ID.
	 * @return string Completion outcome.
	 */
	public static function complete( \WC_Order $order, string $transaction_id ): string {
		$order_id = $order->get_id();
		if ( ! PaymentLock::acquire( $order_id, 'complete_payment', self::LOCK_TTL ) ) {
			Logger::log( 'Stripe Terminal: completion of order ' . $order_id . ' is already in progress in another request.', 'info' );
			return self::BUSY;
		}
		try {
			$fresh = self::reload_order( $order );
			if ( $fresh->is_paid() ) {
				return self::ALREADY_PAID;
			}
			$fresh->set_transaction_id( $transaction_id );
			Gateway::claim_order_gateway( $fresh );
			$fresh->payment_complete( $transaction_id );
			return self::COMPLETED;
		} finally {
			PaymentLock::release( $order_id, 'complete_payment' );
		}
	}

	/**
	 * Reload an order after clearing the posts and HPOS order caches.
	 *
	 * On the posts store, WC_Data keeps meta in its own object cache, which
	 * clean_post_cache() leaves alone, so the meta is re-read.
	 * With HPOS data caching on, the data store caches the row and its meta;
	 * the meta is cleared directly as well, because WooCommerce skips it when
	 * the row-cache delete fails (#131).
	 *
	 * @param \WC_Order $order Original order.
	 * @return \WC_Order Reloaded order, or the original when unavailable.
	 */
	public static function reload_order( \WC_Order $order ): \WC_Order {
		$id = $order->get_id();
		if ( function_exists( 'clean_post_cache' ) ) {
			clean_post_cache( $id );
		}
		if ( function_exists( 'wc_get_container' ) && class_exists( \Automattic\WooCommerce\Caches\OrderCache::class ) ) {
			wc_get_container()->get( \Automattic\WooCommerce\Caches\OrderCache::class )->remove( $id );
		}
		if (
			function_exists( 'wc_get_container' )
			&& class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
			&& method_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class, 'custom_orders_table_datastore_cache_enabled' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_datastore_cache_enabled()
			&& class_exists( \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::class )
		) {
			$data_store = wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::class );
			if ( method_exists( $data_store, 'clear_cached_data' ) ) {
				$data_store->clear_cached_data( array( $id ) );
			}
			if ( class_exists( \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStoreMeta::class ) ) {
				$meta_store = wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStoreMeta::class );
				if ( method_exists( $meta_store, 'clear_cached_data' ) ) {
					$meta_store->clear_cached_data( array( $id ) );
				}
			}
		}

		$fresh = function_exists( 'wc_get_order' ) ? wc_get_order( $id ) : null;
		if ( $fresh instanceof \WC_Order && method_exists( $fresh, 'read_meta_data' ) ) {
			$fresh->read_meta_data( true );
		}
		return $fresh instanceof \WC_Order ? $fresh : $order;
	}
}
