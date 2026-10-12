<?php
/**
 * Stripe transport for Pro's shared server handler; Free owns settlement.
 *
 * @package WCPOS\WooCommercePOS\StripeTerminal
 */

namespace WCPOS\WooCommercePOS\StripeTerminal\Server;

use WCPOS\WooCommercePOS\StripeTerminal\Settings;
use WCPOS\WooCommercePOS\StripeTerminal\StripeTerminalService;
use WCPOS\WooCommercePOS\StripeTerminal\Utils\CurrencyConverter;

/** Smart-reader provider, independent of the legacy order-pay flow. */
class Stripe_Server_Provider extends \WCPOS\WooCommercePOSPro\Payments\Server\Abstract_Provider_Adapter {
	/**
	 * Refund-record meta: the Stripe mode (test or live) the refund was made in, written only when
	 * the row records none (a row a webhook captured before the till's own capture, or a
	 * historical order-pay row), so a test/live switch while the refund is pending does not send
	 * the sweep's read under the wrong key for thirty days.
	 */
	public const REFUND_MODE_META = '_stwc_refund_mode';

	/**
	 * Stripe transport.
	 *
	 * @var StripeTerminalService
	 */
	private $service;

	/**
	 * Use the existing service, optionally injected for tests.
	 *
	 * @param StripeTerminalService|null $service Stripe transport.
	 */
	public function __construct( ?StripeTerminalService $service = null ) {
		$this->service = $service ?? new StripeTerminalService( Settings::get_api_key() );
	}

	/** {@inheritDoc} */
	public function provider(): string {
		return 'stripe';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param \WC_Payment_Gateway $gateway Gateway instance.
	 */
	public function describe( \WC_Payment_Gateway $gateway ): array {
		// Dashboard-configured reader tips are included in amount_received; Free adds the fee.
		return array(
			'capabilities'  => array(
				'tips' => 'on_reader',
				'refunds' => array(
					'via' => 'provider',
					'partial' => true,
				),
				'void' => true,
			),
			'provider_data' => array( 'mode' => Settings::is_test_mode() ? 'test' : 'live' ),
		);
	}

	/** {@inheritDoc} */
	public function list_readers() {
		try {
			$response = $this->service->list_all_readers();
			if ( is_wp_error( $response ) ) {
				return self::error( $response );
			}
			$readers = array();
			foreach ( $response as $reader ) {
				$type = $reader['device_type'] ?? '';
				if ( ! in_array( $type, array( 'bbpos_wisepos_e', 'stripe_s700', 'stripe_s710', 'verifone_P400' ), true ) && 0 !== strpos( $type, 'simulated_' ) ) {
					continue;
				}
				$readers[] = array(
					'id'     => $reader['id'],
					'label'  => ! empty( $reader['label'] ) ? $reader['label'] : ( ! empty( $reader['serial_number'] ) ? $reader['serial_number'] : $reader['id'] ),
					'status' => $reader['status'],
				);
			}
			return $readers;
		} catch ( \Throwable $e ) {
			return self::error( $e );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array  $row Ledger row.
	 * @param string $reader_id Selected reader.
	 */
	public function create_reader_action( array $row, string $reader_id ) {
		try {
			$order = wc_get_order( (int) $row['order_id'] );
			if ( ! $order ) {
				return new \WP_Error( 'wcpos_order_not_found', __( 'Order not found.', 'stripe-terminal-for-woocommerce' ), array( 'status' => 404 ) );
			}
			$intent = $this->service->create_server_payment_intent(
				CurrencyConverter::convert_to_stripe_amount( $row['amount'], $row['currency'] ),
				strtolower( $row['currency'] ),
				sprintf( 'Order #%s', $order->get_order_number() ),
				array(
					'order_id' => (string) $order->get_id(),
					'wcpos_payment_id' => $row['id'],
					'wcpos_reader' => $reader_id,
				),
				$row['id'],
				( 'cad' === strtolower( $row['currency'] ) )
			);
			if ( is_wp_error( $intent ) ) {
				// Stripe may have accepted a create it did not answer, and an idempotency conflict on a
				// replay means the intent exists under this row's key (a retry on another reader);
				// Pro replays the row under the same key, so it must stay pending, never be dropped.
				return self::unanswered( $intent ) || self::idempotency_conflict( $intent ) ? $this->indeterminate( 'stripe_create_unanswered', $intent->get_error_message() ) : self::error( $intent );
			}
			// Written BEFORE the dispatch: a warm scheduled during this request must not replace the action.
			update_option( 'stwc_payment_dispatch_at', time(), false );
			$result = $this->service->process_payment_intent( $reader_id, $intent['id'], array( 'enable_customer_cancellation' => true ) );
			if ( is_wp_error( $result ) ) {
				// Dispatch may have landed despite the error, and on a replay the intent may already be
				// paid: read before deciding, and never retire an intent that may carry money.
				$reader = $this->service->get_reader( $reader_id );
				if ( is_wp_error( $reader ) && self::unanswered( $result ) ) {
					return $this->indeterminate( 'stripe_dispatch_unanswered', $result->get_error_message() );
				}
				// An unreadable reader after a refusal still gets the intent read below: on a replay
				// the intent may be live or paid.
				$action = is_wp_error( $reader ) ? array() : ( $reader['action'] ?? array() );
				if ( ( $action['process_payment_intent']['payment_intent'] ?? null ) === $intent['id']
					&& in_array( $action['status'] ?? '', array( 'in_progress', 'succeeded' ), true ) ) {
					return array(
						'ref' => $intent['id'],
						'expires_at' => null,
					);
				}
				$again = $this->service->retrieve_payment_intent( $intent['id'] );
				if ( is_wp_error( $again ) ) {
					return $this->indeterminate( 'stripe_dispatch_unanswered', $again->get_error_message() );
				}
				if ( in_array( $again['status'], array( 'succeeded', 'requires_capture', 'processing' ), true ) ) {
					// The reader has moved on but this intent was paid: polling settles it.
					return array(
						'ref' => $intent['id'],
						'expires_at' => null,
					);
				}
				if ( self::unanswered( $result ) ) {
					return $this->indeterminate( 'stripe_dispatch_unanswered', $result->get_error_message() );
				}
				// A reader read that got no answer may hide a reader still collecting this unpaid
				// intent: keep the leg pending for the replay rather than fail it. A reader Stripe
				// answers for (not holding the intent, or no longer existing) lets it be retired.
				if ( is_wp_error( $reader ) && self::unanswered( $reader ) ) {
					return $this->indeterminate( 'stripe_dispatch_unanswered', $result->get_error_message() );
				}
				$this->cancel_best_effort( $intent['id'] );
				return self::error( $result );
			}
			return array(
				'ref' => $intent['id'],
				'expires_at' => null,
			);
		} catch ( \Throwable $e ) {
			if ( isset( $intent['id'] ) ) {
				$this->cancel_best_effort( $intent['id'] );
			}
			return self::error( $e );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $ref Intent ID.
	 */
	public function fetch( string $ref ) {
		try {
			$intent = $this->service->retrieve_payment_intent( $ref );
			if ( is_wp_error( $intent ) ) {
				return self::error( $intent );
			}
			$reader = array();
			if ( 'requires_payment_method' === $intent['status'] && ! empty( $intent['metadata']['wcpos_reader'] ) ) {
				$reader = $this->service->get_reader( $intent['metadata']['wcpos_reader'] );
				if ( is_wp_error( $reader ) ) {
					return self::error( $reader );
				}
			}
			$result = self::normalize( $intent, $reader['action'] ?? null );
			if ( 'failed' === $result['status'] && 'superseded' === ( $result['failure_reason'] ?? '' ) ) {
				// The intent and reader reads are not atomic: the card may have been approved between them.
				$again = $this->service->retrieve_payment_intent( $ref );
				if ( is_wp_error( $again ) ) {
					return self::error( $again );
				}
				if ( 'requires_payment_method' !== $again['status'] ) {
					$result = self::normalize( $again, null );
				}
			}
			if ( in_array( $result['status'], array( 'pending', 'in_progress' ), true ) ) {
				update_option( 'stwc_payment_dispatch_at', time(), false );
			}
			// A declined or superseded intent can never be charged again by us; retire it so nothing else can.
			if ( 'failed' === $result['status'] ) {
				$this->cancel_best_effort( $ref );
			}
			return $result;
		} catch ( \Throwable $e ) {
			return self::error( $e );
		}
	}

	/**
	 * Project Stripe observations without writing the ledger or order.
	 *
	 * @param array      $intent        Stripe intent.
	 * @param array|null $reader_action Current reader action.
	 * @return array Normalized provider observation.
	 */
	public static function normalize( array $intent, ?array $reader_action = null ): array {
		$states = array(
			'succeeded' => 'completed',
			'requires_capture' => 'completed',
			'canceled' => 'cancelled',
			'processing' => 'in_progress',
			'requires_confirmation' => 'in_progress',
			'requires_action' => 'in_progress',
		);
		$state  = $intent['status'];
		$result = array(
			'status'        => $states[ $state ] ?? 'pending',
			'amount'        => self::amount( $intent ),
			'currency'      => strtoupper( $intent['currency'] ),
			'provider_refs' => array(
				'stripe_payment_intent' => $intent['id'],
				'stripe_charge' => $intent['latest_charge']['id'] ?? null,
				'stripe_mode' => ! empty( $intent['livemode'] ) ? 'live' : 'test',
				// The reference a historical webview row refunds by; the old panel stored the intent
				// id (webhook completions) or the charge id, and refund() accepts either.
				'transaction_id' => $intent['id'],
			),
			'receipt'       => self::receipt( $intent ),
		);
		if ( 'requires_capture' === $state ) {
			$result['authorized'] = true;
		}
		if ( 'requires_payment_method' === $state ) {
			$action_ref = $reader_action['process_payment_intent']['payment_intent'] ?? null;
			if ( ! empty( $intent['last_payment_error'] ) ) {
				$result['status']         = 'failed';
				$result['failure_reason'] = $intent['last_payment_error']['decline_code'] ?? $intent['last_payment_error']['code'] ?? 'provider_error';
			} elseif ( $action_ref === $intent['id'] ) {
				if ( 'failed' === ( $reader_action['status'] ?? '' ) ) {
					$code             = $reader_action['failure_code'] ?? 'provider_error';
					$result['status'] = 'customer_canceled' === $code ? 'cancelled' : 'failed';
					if ( 'failed' === $result['status'] ) {
						$result['failure_reason'] = $code;
					}
				} elseif ( in_array( $reader_action['status'] ?? '', array( 'in_progress', 'succeeded' ), true ) ) {
					$result['status'] = 'in_progress';
				}
			} elseif ( $action_ref ) {
				$result['status']         = 'failed';
				$result['failure_reason'] = 'superseded';
			}
		}
		return $result;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $ref Intent ID.
	 */
	public function cancel( string $ref ) {
		try {
			$intent = $this->service->retrieve_payment_intent( $ref );
			if ( is_wp_error( $intent ) ) {
				return self::error( $intent );
			}
			if ( in_array( $intent['status'], array( 'succeeded', 'requires_capture' ), true ) ) {
				return 'requested';
			}
			if ( 'canceled' === $intent['status'] ) {
				return 'final';
			}
			if ( ! empty( $intent['metadata']['wcpos_reader'] ) ) {
				// cancel_action resets whatever the reader is doing; only reset it while it is still
				// on THIS intent, never a later leg's action on the same reader.
				$reader = $this->service->get_reader( $intent['metadata']['wcpos_reader'] );
				if ( is_wp_error( $reader ) ) {
					return self::error( $reader );
				}
				$active = $reader['action']['process_payment_intent']['payment_intent'] ?? null;
				if ( $active === $ref && 'in_progress' === ( $reader['action']['status'] ?? '' ) ) {
					$reader = $this->service->cancel_reader_action( $intent['metadata']['wcpos_reader'] );
					if ( is_wp_error( $reader ) ) {
						return self::error( $reader );
					}
					if ( 'busy' === ( $reader['status'] ?? '' ) ) {
						return 'requested';
					}
				}
			}
			try {
				$cancelled = $this->service->cancel_payment_intent_by_id( $ref );
				if ( ! is_wp_error( $cancelled ) ) {
					return 'final';
				}
				if ( ! in_array( $cancelled->get_error_data()['stripe_code'] ?? '', array( 'intent_invalid_state', 'payment_intent_unexpected_state' ), true ) ) {
					return self::error( $cancelled );
				}
			} catch ( \Throwable $e ) { // A completion race needs a fresh money observation.
				$cancelled = self::error( $e );
			}
			$intent = $this->service->retrieve_payment_intent( $ref );
			if ( is_wp_error( $intent ) ) {
				return self::error( $intent );
			}
			return 'canceled' === $intent['status'] ? 'final' : 'requested';
		} catch ( \Throwable $e ) {
			return self::error( $e );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $ref Intent ID.
	 */
	public function capture( string $ref ) {
		try {
			$result = $this->service->capture_payment_intent( $ref );
			return is_wp_error( $result ) ? self::error( $result ) : self::normalize( $result, null );
		} catch ( \Throwable $e ) {
			return self::error( $e );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array  $row Ledger row.
	 * @param int    $refund_id WooCommerce refund ID.
	 * @param string $amount Decimal major units.
	 */
	public function refund( array $row, int $refund_id, string $amount ) {
		try {
			$ref = self::payment_of( $row );
			if ( '' === $ref ) {
				return self::error( __( 'No Stripe payment found for refund.', 'stripe-terminal-for-woocommerce' ), 'missing_payment_ref' );
			}
			// The row's payment was made in a recorded mode; everything about its refund happens under
			// that mode's key, whatever mode the gateway is in now.
			$key = $this->key_for( $row, $refund_id );
			if ( is_wp_error( $key ) ) {
				return $key;
			}
			$mode = self::mode_of( $row, $refund_id );
			if ( ! in_array( $row['provider_refs']['stripe_mode'] ?? null, array( 'test', 'live' ), true ) ) {
				$refund = wc_get_order( $refund_id );
				if ( $refund instanceof \WC_Order_Refund ) {
					$refund->update_meta_data( self::REFUND_MODE_META, $mode );
					$refund->save();
				}
			}
			if ( 'CAD' === strtoupper( $row['currency'] ) ) {
				// A historical row may name the charge rather than the intent; read whichever it names.
				$is_charge = 0 === strpos( $ref, 'ch_' );
				$paid      = $is_charge ? $this->service->retrieve_charge( $ref, $key ) : $this->service->retrieve_payment_intent( $ref, $key );
				if ( is_wp_error( $paid ) ) {
					return self::error( $paid );
				}
				$type = $is_charge ? ( $paid['payment_method_details']['type'] ?? '' ) : ( $paid['latest_charge']['payment_method_details']['type'] ?? '' );
				if ( 'interac_present' === $type ) {
					return self::error( __( 'Interac refunds must be run on the reader', 'stripe-terminal-for-woocommerce' ), 'interac_refund_on_reader' );
				}
			}
			$request = array(
				'payment'   => $ref,
				'amount'    => CurrencyConverter::convert_to_stripe_amount( $amount, $row['currency'] ),
				'metadata'  => array(
					'wcpos_payment_id' => $row['id'],
					'wcpos_refund_id' => (string) $refund_id,
				),
				'key'       => 'wcpos_refund_' . $row['id'] . '_' . $refund_id,
				'mode'      => $mode,
				'refund_id' => $refund_id,
			);
			// The time of the till's own request: a re-send is only safe while Stripe still holds the key.
			$request['requested_at'] = time();
			$result  = $this->service->refund_payment(
				$request['payment'],
				$request['amount'],
				array(
					'metadata'        => $request['metadata'],
					'request_options' => array( 'idempotency_key' => $request['key'] ) + ( null === $key ? array() : array( 'api_key' => $key ) ),
				)
			);
			if ( is_wp_error( $result ) && ! self::unanswered( $result ) ) {
				// Stripe refused: nothing was made, and the record may go.
				return self::error( $result );
			}
			if ( is_wp_error( $result ) || empty( $result['id'] ) ) {
				// Unanswered (the connection dropped, a Stripe 5xx), or a 200 that did not carry the
				// refund: Stripe may have taken it. An error here would make WooCommerce delete the
				// record, and a retry would refund again under a new key; a pending with no reference
				// keeps the record, the same request is re-sent under the same key, and
				// `fetch_refund()` finds the refund by the metadata the create carried.
				if ( is_wp_error( $result ) ) {
					Refund_Reask::schedule( $request );
				}
				return array(
					'status' => 'pending',
					'provider_ref' => null,
				);
			}
			return array(
				'status' => self::refund_status( (string) ( $result['status'] ?? '' ) ),
				'provider_ref' => $result['id'],
			);
		} catch ( \Throwable $e ) {
			return self::error( $e );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * Reads the refund `refund()` made, by the id it returned, or (when Stripe's answer to the
	 * create did not carry the id) finds it among the payment's refunds by the metadata the
	 * create carried; nothing is created. The read uses the key of the mode the refund was made
	 * in (the row's, else the one stamped on the refund record). Stripe answers most refunds
	 * `succeeded` at once; the sweep asks about the ones it
	 * answered `pending` or `requires_action`, whatever the payment method, until Stripe settles
	 * them.
	 *
	 * @param array $row        Ledger row (or the historical stand-in) the refund was allocated to.
	 * @param array $allocation The pending allocation, with the refund id `refund()` returned.
	 * @return array|\WP_Error Status and reference, or an error that keeps the question open.
	 */
	public function fetch_refund( array $row, array $allocation ) {
		try {
			$ref     = (string) ( $allocation['provider_ref'] ?? '' );
			$refused = '' === $ref && '' !== self::refused_reason( (int) ( $allocation['id'] ?? 0 ) );
			$key     = $this->key_for( $row, (int) ( $allocation['id'] ?? 0 ) );
			if ( is_wp_error( $key ) ) {
				// The re-sent create was refused and there is no key to look under: the record is
				// the only word, and it says never made.
				return $refused ? array(
					'status' => 'failed',
					'provider_ref' => null,
				) : $key;
			}
			$payment = self::payment_of( $row );
			if ( '' !== $ref ) {
				$refund = $this->service->retrieve_refund( $ref, $key );
			} elseif ( '' !== $payment ) {
				$refund = $this->find_refund( $payment, $row, $allocation, $key );
				if ( is_array( $refund ) && isset( $refund['amount'], $allocation['amount'], $row['currency'] ) && CurrencyConverter::convert_to_stripe_amount( (string) $allocation['amount'], (string) $row['currency'] ) !== (int) $refund['amount'] ) {
					// This allocation's metadata on a refund of another amount: not the refund the till
					// asked for, so it settles nothing here.
					return self::error( __( 'The Stripe refund carrying this allocation\'s reference is for another amount.', 'stripe-terminal-for-woocommerce' ), 'refund_amount_mismatch' );
				}
				if ( null === $refund ) {
					// Nothing of this allocation's among the payment's refunds. With the re-sent
					// create refused under the same key and body, that is the answer: never made.
					// Otherwise Stripe took the create and the refund should show; until it does,
					// the question stays open and the sweep looks again.
					return array(
						'status' => $refused ? 'failed' : 'pending',
						'provider_ref' => null,
					);
				}
			} elseif ( $refused ) {
				return array(
					'status' => 'failed',
					'provider_ref' => null,
				);
			} else {
				// Neither a refund id nor a payment to look under: nothing this plugin can read.
				return self::error( __( 'No Stripe refund reference to read.', 'stripe-terminal-for-woocommerce' ), 'missing_refund_ref' );
			}
			if ( is_wp_error( $refund ) ) {
				// A read Stripe refused (a key it rejects, a refund it cannot show under it) proves
				// nothing about the refund: the question stays open, as it does when nothing answered.
				return self::unanswered( $refund ) ? $this->indeterminate( 'stripe_refund_unanswered', $refund->get_error_message() ) : self::error( $refund );
			}
			if ( empty( $refund['id'] ) || empty( $refund['status'] ) ) {
				// A success without the refund in it is no answer.
				return $this->indeterminate( 'stripe_refund_unanswered', __( 'Stripe answered without the refund.', 'stripe-terminal-for-woocommerce' ) );
			}
			// The refund must belong to the row's payment: a reference bound to another sale, or to
			// no payment this row can name, settles nothing here. The create named the intent for a
			// `pi_` reference and the charge for any other, and a refund always carries that field.
			$paid = (string) ( 0 === strpos( $payment, 'pi_' ) ? ( $refund['payment_intent'] ?? '' ) : ( $refund['charge'] ?? '' ) );
			if ( '' !== $payment && $payment !== $paid ) {
				return self::error( __( 'The Stripe refund belongs to another payment.', 'stripe-terminal-for-woocommerce' ), 'refund_payment_mismatch' );
			}
			return array(
				'status' => self::refund_status( (string) $refund['status'] ),
				'provider_ref' => $refund['id'],
			);
		} catch ( \Throwable $e ) {
			return self::error( $e );
		}
	}

	/**
	 * The refund `refund()` made for this allocation, among the payment's refunds, by the
	 * metadata the create carried (this row's id and the WooCommerce refund id).
	 *
	 * @param string      $payment    Intent or charge id.
	 * @param array       $row        Ledger row.
	 * @param array       $allocation The pending allocation.
	 * @param null|string $key        The other mode's key, when the row's payment was made in it.
	 * @return array|\WP_Error|null The refund, an error from the list, or null when none is this one.
	 */
	private function find_refund( string $payment, array $row, array $allocation, ?string $key ) {
		$after = '';
		// Each page is judged as it arrives, so a refund already seen is not lost to a later page's
		// failure, and no page is asked for past the one that holds it.
		for ( $page = 0; $page < StripeTerminalService::REFUND_PAGES_MAX; $page++ ) {
			$list = $this->service->list_refunds( $payment, $key, $after );
			if ( is_wp_error( $list ) ) {
				return $list;
			}
			foreach ( $list['data'] as $refund ) {
				$meta = $refund['metadata'] ?? array();
				if ( (string) ( $meta['wcpos_refund_id'] ?? '' ) === (string) ( $allocation['id'] ?? '' ) && (string) ( $meta['wcpos_payment_id'] ?? '' ) === (string) ( $row['id'] ?? '' ) ) {
					return $refund;
				}
			}
			if ( ! $list['has_more'] || empty( $list['data'] ) ) {
				break;
			}
			$after = (string) ( end( $list['data'] )['id'] ?? '' );
			if ( '' === $after ) {
				break;
			}
		}
		return null;
	}

	/**
	 * Stripe's reason for refusing the re-sent create, when `Refund_Reask` recorded one.
	 *
	 * @param int $refund_id WooCommerce refund id.
	 */
	private static function refused_reason( int $refund_id ): string {
		if ( $refund_id <= 0 ) {
			return '';
		}
		$refund = wc_get_order( $refund_id );
		return $refund instanceof \WC_Order_Refund ? (string) $refund->get_meta( Refund_Reask::REFUSED_META ) : '';
	}

	/**
	 * The mode the refund was made in, as far as anything recorded it: the row's `stripe_mode`,
	 * else the stamp `refund()` left on the refund record, else null (nothing recorded; the
	 * gateway's current mode is the only guess).
	 *
	 * @param array $row       Ledger row.
	 * @param int   $refund_id WooCommerce refund id.
	 */
	private static function recorded_mode( array $row, int $refund_id ): ?string {
		$mode = $row['provider_refs']['stripe_mode'] ?? null;
		if ( in_array( $mode, array( 'test', 'live' ), true ) ) {
			return $mode;
		}
		$refund = 0 < $refund_id ? wc_get_order( $refund_id ) : false;
		$mode   = $refund instanceof \WC_Order_Refund ? $refund->get_meta( self::REFUND_MODE_META ) : null;
		return in_array( $mode, array( 'test', 'live' ), true ) ? $mode : null;
	}

	/**
	 * The row's mode, else the refund record's stamp, else the gateway's current mode.
	 *
	 * @param array $row       Ledger row.
	 * @param int   $refund_id WooCommerce refund id.
	 */
	private static function mode_of( array $row, int $refund_id = 0 ): string {
		return self::recorded_mode( $row, $refund_id ) ?? ( Settings::is_test_mode() ? 'test' : 'live' );
	}

	/**
	 * The key for the row's mode, else the refund record's stamp, else the gateway's current
	 * mode. Stripe keeps test and live objects apart, so a refund made in one mode cannot be read
	 * with the other mode's key once the gateway is switched; the device adapter refunds under the
	 * recorded mode the same way. Null when the recorded mode is the gateway's current one, or
	 * when neither the row nor the record recorded a mode.
	 *
	 * @param array $row       Ledger row.
	 * @param int   $refund_id WooCommerce refund id.
	 * @return string|null|\WP_Error The key, null for the current one, an error when that mode has none.
	 */
	private function key_for( array $row, int $refund_id = 0 ) {
		$mode = self::recorded_mode( $row, $refund_id );
		if ( ! in_array( $mode, array( 'test', 'live' ), true ) || ( 'test' === $mode ) === Settings::is_test_mode() ) {
			return null;
		}
		$key = Settings::get_api_key_for_mode( $mode );
		if ( '' === $key ) {
			/* translators: %s: test or live. */
			return self::error( sprintf( __( 'The refund was made in Stripe %s mode, which has no API key configured.', 'stripe-terminal-for-woocommerce' ), $mode ), 'stripe_mode_key_missing' );
		}
		return $key;
	}

	/**
	 * Pro's three refund states from Stripe's five. `requires_action` is a refund waiting on the
	 * customer (some bank-based methods); `canceled` is one Stripe gave up on. An unknown state is
	 * still pending, never money returned.
	 *
	 * @param string $status Stripe refund status.
	 */
	private static function refund_status( string $status ): string {
		$states = array(
			'succeeded' => 'succeeded',
			'pending' => 'pending',
			'requires_action' => 'pending',
			'failed' => 'failed',
			'canceled' => 'failed',
		);
		return $states[ $status ] ?? 'pending';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param \WP_REST_Request $request Signed Stripe request.
	 */
	public function verify_webhook( \WP_REST_Request $request ) {
		try {
			$secret = Settings::get_pos_webhook_secret();
			if ( '' === $secret ) {
				return new \WP_Error( 'stripe_pos_webhook_unconfigured', __( 'POS webhook secret not configured.', 'stripe-terminal-for-woocommerce' ), array( 'status' => 500 ) );
			}
			try {
				$event = \Stripe\Webhook::constructEvent( $request->get_body(), (string) $request->get_header( 'stripe-signature' ), $secret );
			} catch ( \Stripe\Exception\SignatureVerificationException $e ) {
				return new \WP_Error( 'stripe_webhook_bad_signature', $e->getMessage(), array( 'status' => 401 ) );
			} catch ( \Throwable $e ) {
				return new \WP_Error( 'stripe_webhook_invalid', $e->getMessage(), array( 'status' => 400 ) );
			}
			$intent = $event->data->object->toArray();
			if ( 0 === strpos( $event->type, 'terminal.reader.action_' ) ) {
				$ref = $intent['action']['process_payment_intent']['payment_intent'] ?? '';
				if ( '' === $ref ) {
					return new \WP_Error( 'stripe_webhook_no_intent', __( 'No payment intent in reader event.', 'stripe-terminal-for-woocommerce' ), array( 'status' => 404 ) );
				}
				$intent = $this->service->retrieve_payment_intent( $ref );
				if ( is_wp_error( $intent ) ) {
					return self::error( $intent );
				}
			} elseif ( 0 !== strpos( $event->type, 'payment_intent.' ) ) {
				return new \WP_Error( 'stripe_webhook_ignored', __( 'Event type not handled.', 'stripe-terminal-for-woocommerce' ), array( 'status' => 200 ) );
			}
			$id = $intent['metadata']['wcpos_payment_id'] ?? '';
			if ( ! is_string( $id ) || ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id ) ) {
				// An intent the old panel created carries only order_id; if the upgrade adopted
				// it, Pro resolves the row from the intent id. Otherwise it is not ours (a legacy
				// or online intent on the same account): a 2xx so Stripe stops redelivering it.
				$id = \function_exists( 'wcpos_pro_payment_id_for_action' ) ? (string) wcpos_pro_payment_id_for_action( $this->provider(), (string) ( $intent['id'] ?? '' ) ) : '';
				if ( '' === $id ) {
					return new \WP_Error( 'stripe_webhook_unknown_payment', __( 'Unknown POS payment.', 'stripe-terminal-for-woocommerce' ), array( 'status' => 200 ) );
				}
			}
			if ( ( $intent['livemode'] ?? null ) !== ! Settings::is_test_mode() ) {
				return new \WP_Error( 'stripe_webhook_mode_mismatch', __( 'Stripe payment mode mismatch.', 'stripe-terminal-for-woocommerce' ), array( 'status' => 200 ) );
			}
			$patch = self::webhook_patch( $intent, $event->id );
			if ( 'failed' === ( $patch['status'] ?? '' ) ) {
				$patch = $this->decline_patch( $intent, $event->id );
			}
			return array(
				'payment_id' => strtolower( $id ),
				'patch' => $patch,
			);
		} catch ( \Throwable $e ) {
			return self::error( $e );
		}
	}

	/**
	 * Money and declines are final and reported; cancellation is left to polling, which alone can
	 * tell a requested void from a failure, so a webhook never races a legitimate void.
	 *
	 * @param array  $intent   Verified intent.
	 * @param string $event_id Stripe event ID, not the intent ID.
	 * @return array Settlement patch without Pro-owned references.
	 */
	public static function webhook_patch( array $intent, string $event_id ): array {
		$patch = array( 'event_id' => $event_id );
		if ( 'succeeded' === $intent['status'] ) {
			$patch += array(
				'status' => 'captured',
				'amount' => self::amount( $intent ),
				'currency' => strtoupper( $intent['currency'] ),
				'receipt' => self::receipt( $intent ),
			);
		} elseif ( 'requires_payment_method' === $intent['status'] && ! empty( $intent['last_payment_error'] ) ) {
			// A decline, as fetch() reports it; one that arrives after capture reaches Free's
			// transition guard instead of being swallowed. verify_webhook() enforces it only
			// against a fresh read (decline_patch()).
			$patch['status'] = 'failed';
		}
		return $patch;
	}

	/**
	 * A decline is enforced only against a fresh read: the event is a snapshot, and the same intent
	 * may since have been retried on the reader (in progress, or paid). What fetch() would observe
	 * decides; a confirmed decline is retired as fetch() retires it, a paid intent reports its money,
	 * and anything in between is left to polling.
	 *
	 * @param array  $intent   The event's intent.
	 * @param string $event_id Stripe event ID.
	 * @return array Settlement patch.
	 */
	private function decline_patch( array $intent, string $event_id ): array {
		$fresh = $this->service->retrieve_payment_intent( (string) $intent['id'] );
		if ( is_wp_error( $fresh ) ) {
			return array( 'event_id' => $event_id );
		}
		if ( 'requires_payment_method' === $fresh['status'] && ! empty( $fresh['metadata']['wcpos_reader'] ) ) {
			$reader = $this->service->get_reader( $fresh['metadata']['wcpos_reader'] );
			if ( is_wp_error( $reader ) ) {
				return array( 'event_id' => $event_id );
			}
			$action = $reader['action'] ?? array();
			if ( ( $action['process_payment_intent']['payment_intent'] ?? null ) === $fresh['id'] && 'in_progress' === ( $action['status'] ?? '' ) ) {
				return array( 'event_id' => $event_id ); // A retry of this intent is on the reader.
			}
		}
		$patch = self::webhook_patch( $fresh, $event_id );
		if ( 'failed' === ( $patch['status'] ?? '' ) ) {
			$this->cancel_best_effort( (string) $fresh['id'] );
		}
		return $patch;
	}

	/** Return the Pro webhook URL, separate from legacy checkout. */
	public static function webhook_url(): string {
		return add_query_arg( 'provider', 'stripe', rest_url( 'wcpos/v2/payments/webhook' ) );
	}

	/**
	 * Format confirmed money including on-reader tips.
	 *
	 * @param array $intent Stripe intent.
	 * @return string Decimal major units.
	 */
	private static function amount( array $intent ): string {
		$value = CurrencyConverter::convert_from_stripe_amount( ! empty( $intent['amount_received'] ) ? $intent['amount_received'] : $intent['amount'], $intent['currency'] );
		return number_format( $value, CurrencyConverter::get_decimal_places( $intent['currency'] ), '.', '' );
	}

	/**
	 * Extract flat, non-empty receipt strings from an expanded charge.
	 *
	 * @param array $intent Stripe intent.
	 * @return array Receipt fields.
	 */
	private static function receipt( array $intent ): array {
		$charge  = is_array( $intent['latest_charge'] ?? null ) ? $intent['latest_charge'] : array();
		$details = $charge['payment_method_details']['card_present'] ?? $charge['payment_method_details']['interac_present'] ?? array();
		return array_filter(
			array(
				'card_brand'   => $details['brand'] ?? null,
				'card_last4'   => $details['last4'] ?? null,
				'card_funding' => $details['funding'] ?? null,
				'auth_code'    => $details['receipt']['authorization_code'] ?? null,
				'application'  => $details['receipt']['application_preferred_name'] ?? null,
				'aid'          => $details['receipt']['dedicated_file_name'] ?? null,
				'verification' => $details['receipt']['cardholder_verification_method'] ?? null,
				'read_method'  => $details['read_method'] ?? null,
				'stripe_charge' => $charge['id'] ?? null,
			),
			static function ( $value ) {
				return is_string( $value ) && '' !== $value;
			}
		);
	}

	/**
	 * The Stripe payment a row's refund is made against, or '' when the row names none.
	 *
	 * Pro's smart-reader rows carry the intent under `action`; the fetch refs (`normalize()`) carry
	 * it as `stripe_payment_intent`, for a row restored without one; a historical webview row carries
	 * only the order's transaction id (intent or charge). A Bluetooth or Tap to Pay row keeps the
	 * intent under Pro's own `payment_intent`, and a webhook that captured it before the till's own
	 * capture left it with nothing else.
	 *
	 * @param array $row Ledger row.
	 */
	private static function payment_of( array $row ): string {
		$refs = $row['provider_refs'] ?? array();
		return (string) ( $refs['action'] ?? $refs['stripe_payment_intent'] ?? $refs['transaction_id'] ?? $refs['payment_intent'] ?? '' );
	}

	/**
	 * Whether the service got no usable answer (a transport failure, a timeout, or a Stripe 5xx)
	 * rather than a refusal.
	 *
	 * @param \WP_Error $error Service error.
	 */
	private static function unanswered( \WP_Error $error ): bool {
		return (int) ( $error->get_error_data()['status'] ?? 0 ) >= 500 || 'http_request_failed' === $error->get_error_code();
	}

	/**
	 * Whether Stripe refused a replayed create because the row's idempotency key already holds an
	 * intent made with other parameters (a retry on another reader): the first dispatch may be live.
	 *
	 * @param \WP_Error $error Service error.
	 */
	private static function idempotency_conflict( \WP_Error $error ): bool {
		return 409 === (int) ( $error->get_error_data()['status'] ?? 0 );
	}

	/**
	 * Retire failed attempts so a fresh retry cannot also charge them.
	 *
	 * @param string $ref Intent ID.
	 */
	private function cancel_best_effort( string $ref ): void {
		try {
			$this->service->cancel_payment_intent_by_id( $ref );
		} catch ( \Throwable $e ) { // Cleanup must not replace the original observation.
			return;
		}
	}

	/**
	 * Preserve Stripe detail while using Pro's provider error envelope.
	 *
	 * @param \WP_Error|\Throwable|string $error Service error, exception or message.
	 * @param string                      $code  Explicit local error code.
	 * @return \WP_Error Provider error.
	 */
	private static function error( $error, string $code = 'stripe_api_error' ): \WP_Error {
		if ( $error instanceof \WP_Error ) {
			$code    = $error->get_error_data()['stripe_code'] ?? $code;
			$message = $error->get_error_message();
		} elseif ( $error instanceof \Throwable ) {
			$code    = $error instanceof \Stripe\Exception\ApiErrorException ? ( $error->getStripeCode() ? $error->getStripeCode() : $code ) : $code;
			$message = $error->getMessage();
		} else {
			$message = $error;
		}
		return new \WP_Error(
			'wcpos_provider_error',
			$message,
			array(
				'status' => 502,
				'detail' => array(
					'code' => $code,
					'message' => $message,
				),
			)
		);
	}
}
