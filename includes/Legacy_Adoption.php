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
	/** Statuses that are over: nothing to resume. */
	public const FINAL_STATUSES = array( 'succeeded', 'canceled' );

	/** Run the next page of adoption, until every eligible order has been seen. */
	public static function upgrade(): void {
		if ( version_compare( (string) get_option( 'stwc_adoption_version', '0' ), self::VERSION, '>=' ) ) {
			return;
		}
		$offset = (int) get_option( 'stwc_adoption_offset', 0 );
		$orders = wc_get_orders(
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
			$intent = (string) $order->get_meta( self::META_INTENT );
			if ( '' === $intent || in_array( (string) $order->get_meta( self::META_STATUS ), self::FINAL_STATUSES, true ) || wcpos_pro_payment_id_for_action( self::PROVIDER, $intent ) ) {
				continue;
			}
			$result = wcpos_pro_adopt_legacy_attempt( $order, Settings::GATEWAY_ID, $intent, (string) $order->get_total(), $order->get_currency() );
			if ( is_wp_error( $result ) ) {
				wc_get_logger()->error( 'Legacy Stripe Terminal adoption failed for order ' . $order->get_id() . ': ' . $result->get_error_code(), array( 'source' => 'stripe-terminal' ) );
			}
		}
		if ( count( $orders ) < self::PAGE_SIZE ) {
			delete_option( 'stwc_adoption_offset' );
			update_option( 'stwc_adoption_version', self::VERSION, false );
			return;
		}
		update_option( 'stwc_adoption_offset', $offset + count( $orders ), false );
	}
}
