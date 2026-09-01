<?php
/**
 * TipReconciler Utility Class.
 *
 * Writes on-reader tips from a Stripe Payment Intent back to the WooCommerce order.
 *
 * @package WCPOS\WooCommercePOS\StripeTerminal\Utils
 */

namespace WCPOS\WooCommercePOS\StripeTerminal\Utils;

/**
 * TipReconciler class.
 */
class TipReconciler {
	/**
	 * Add an on-reader tip from an authoritative Payment Intent to the order.
	 *
	 * The Payment Intent must come from Stripe directly (retrieved with the
	 * store's own key, or delivered by a verified webhook), never from
	 * client-supplied request data.
	 *
	 * @param \WC_Order $order          The WooCommerce order.
	 * @param object    $payment_intent The server-retrieved Payment Intent.
	 */
	public static function maybe_add_tip_to_order( $order, $payment_intent ): void {
		$tip = $payment_intent->amount_details->tip->amount ?? 0;
		if ( $tip <= 0 || $payment_intent->id === $order->get_meta( '_stripe_terminal_tip_payment_intent_id' ) ) {
			return;
		}

		$tip_amount = CurrencyConverter::convert_from_stripe_amount( $tip, $payment_intent->currency );
		$fee        = new \WC_Order_Item_Fee();
		$fee->set_name( __( 'Tip', 'stripe-terminal-for-woocommerce' ) );
		$fee->set_amount( $tip_amount );
		$fee->set_total( $tip_amount );
		$fee->set_tax_status( 'none' );
		$order->add_item( $fee );
		$order->update_meta_data( '_stripe_terminal_tip_amount', $tip_amount );
		$order->update_meta_data( '_stripe_terminal_tip_payment_intent_id', $payment_intent->id );
		$order->calculate_totals( false );
		$order->save();

		$order->add_order_note(
			\sprintf(
				/* translators: 1: tip amount, 2: payment currency, 3: Payment Intent ID. */
				__( 'Stripe Terminal: on-reader tip of %1$s %2$s added (Payment Intent %3$s).', 'stripe-terminal-for-woocommerce' ),
				number_format( $tip_amount, CurrencyConverter::get_decimal_places( $payment_intent->currency ) ),
				strtoupper( $payment_intent->currency ),
				$payment_intent->id
			)
		);
	}
}
