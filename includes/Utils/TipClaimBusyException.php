<?php
/**
 * TipClaimBusyException class.
 *
 * @package WCPOS\WooCommercePOS\StripeTerminal\Utils
 */

namespace WCPOS\WooCommercePOS\StripeTerminal\Utils;

/**
 * Another request holds the order's tip claim; the payment is still processing.
 *
 * A subclass so callers can tell it apart from stripe-php's exceptions, which
 * are RuntimeExceptions too.
 */
class TipClaimBusyException extends \RuntimeException {}
