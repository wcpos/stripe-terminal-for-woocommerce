<?php
/**
 * WooCommerce's HPOS order cache, its opt-in HPOS data cache, and the
 * container that serves them, as in WooCommerce 11.1. Evictions go to test
 * callbacks in $GLOBALS['stwc_test_cache'] so a test can model each
 * request's caches.
 *
 * Loaded only by tests that run in a separate process: once these classes
 * and wc_get_container() exist, every later reload in the process uses them.
 */

namespace Automattic\WooCommerce\Caches {
	if ( ! class_exists( OrderCache::class ) ) {
		class OrderCache {
			public function remove( $id ) {
				( $GLOBALS['stwc_test_cache']['order_cache_remove'] )( (int) $id );

				return true;
			}
		}
	}
}

namespace Automattic\WooCommerce\Utilities {
	if ( ! class_exists( OrderUtil::class ) ) {
		class OrderUtil {
			public static function custom_orders_table_datastore_cache_enabled(): bool {
				return ! empty( $GLOBALS['stwc_test_cache']['data_caching'] );
			}
		}
	}
}

namespace Automattic\WooCommerce\Internal\DataStores\Orders {
	if ( ! class_exists( OrdersTableDataStore::class ) ) {
		class OrdersTableDataStore {
			/**
			 * Like WooCommerce 11.1: a no-op unless HPOS data caching is on, and
			 * the meta cache is cleared only for ids whose row-cache delete
			 * succeeded. 'row_delete_result' => false models a persistent cache
			 * whose delete fails for a row entry that has already expired.
			 */
			public function clear_cached_data( array $order_ids ): array {
				if ( ! \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_datastore_cache_enabled() ) {
					return array_fill_keys( $order_ids, true );
				}
				$deleted = array();
				foreach ( $order_ids as $order_id ) {
					( $GLOBALS['stwc_test_cache']['data_row_clear'] )( (int) $order_id );
					$deleted[ $order_id ] = $GLOBALS['stwc_test_cache']['row_delete_result'] ?? true;
				}
				$meta = ( new OrdersTableDataStoreMeta() )->clear_cached_data( array_keys( array_filter( $deleted ) ) );
				foreach ( $meta as $order_id => $meta_deleted ) {
					$deleted[ $order_id ] = $deleted[ $order_id ] && $meta_deleted;
				}

				return $deleted;
			}
		}
	}

	if ( ! class_exists( OrdersTableDataStoreMeta::class ) ) {
		class OrdersTableDataStoreMeta {
			public function clear_cached_data( array $object_ids ): array {
				if ( ! \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_datastore_cache_enabled() ) {
					return array_fill_keys( $object_ids, true );
				}
				foreach ( $object_ids as $object_id ) {
					( $GLOBALS['stwc_test_cache']['data_meta_clear'] )( (int) $object_id );
				}

				return array_fill_keys( $object_ids, true );
			}
		}
	}
}

namespace {
	if ( ! function_exists( 'wc_get_container' ) ) {
		function wc_get_container() {
			return new class() {
				public function get( $id ) {
					return new $id();
				}
			};
		}
	}
}
