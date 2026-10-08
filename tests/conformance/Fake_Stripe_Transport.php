<?php
/**
 * A scripted Stripe API behind the SDK's HTTP client interface: PaymentIntents, Terminal readers
 * and refunds, keyed by credential mode, so the real adapter and service run unchanged.
 *
 * @package WCPOS\WooCommercePOS\StripeTerminal\Tests\Conformance
 */

namespace WCPOS\WooCommercePOS\StripeTerminal\Tests\Conformance;

/** Only the fixture knows provider internals; the suite observes the money path. */
final class Fake_Stripe_Transport implements \Stripe\HttpClient\ClientInterface {
	/** Every request as the SDK sent it (method, path, params, mode), for debugging a transcript. */
	public $raw = array();
	/** Credential mode of the most recent request: the credentials actually used. */
	public $last_mode = 'test';
	/** The intent the most recent create allocated or resumed. */
	public $current;
	private $intents = array();
	private $by_key = array();
	private $charges = array();
	private $readers = array();
	private $states = array( 'created' );
	private $scenario = 'create_ok';
	private $cancel_action = 'idle';
	private $refund_status = 'succeeded';
	private $lost = false;
	private $seq = 0;

	public function __construct() {
		foreach ( array( 'tmr_online' => array( 'online', 'bbpos_wisepos_e', 'Front counter' ), 'tmr_offline' => array( 'offline', 'stripe_s700', 'Back office' ) ) as $id => $reader ) {
			$this->readers[ $id ] = array( 'id' => $id, 'object' => 'terminal.reader', 'device_type' => $reader[1], 'label' => $reader[2], 'serial_number' => strtoupper( $id ), 'status' => $reader[0], 'action' => null, 'last_seen_at' => time() );
		}
	}

	/**
	 * Arm a scenario: the intent states successive adapter fetches observe, how the reader answers
	 * cancel_action, and the status a refund comes back with.
	 */
	public function script( string $scenario, array $states, string $cancel_action = 'idle', string $refund_status = 'succeeded' ): void {
		$this->scenario      = $scenario;
		$this->states        = $states;
		$this->cancel_action = $cancel_action;
		$this->refund_status = $refund_status;
		$this->lost          = false;
	}

	/** Called by the recording adapter on entry to fetch(): the intent moves to its next scripted state. */
	public function advance( string $ref ): void {
		if ( ! isset( $this->intents[ $ref ] ) ) {
			return;
		}
		$entry = &$this->intents[ $ref ];
		$state = count( $entry['states'] ) > 1 ? array_shift( $entry['states'] ) : $entry['states'][0];
		$this->apply_state( $entry, $state );
	}

	/** A provider-side outcome (what a webhook reports); returns the intent as Stripe would send it. */
	public function observe( string $ref, string $state ): array {
		$this->apply_state( $this->intents[ $ref ], $state );
		return $this->intents[ $ref ]['data'];
	}

	/** Model an intent the old panel created: it carries only order_id. */
	public function strip_payment_metadata( string $ref ): void {
		unset( $this->intents[ $ref ]['data']['metadata']['wcpos_payment_id'], $this->intents[ $ref ]['data']['metadata']['wcpos_reader'] );
	}

	/** The intent a create keyed on this row id allocated, even when its response was lost. */
	public function action_for_key( string $key ): ?string {
		foreach ( $this->by_key as $mode_key => $ref ) {
			if ( substr( $mode_key, strpos( $mode_key, ':' ) + 1 ) === $key ) {
				return $ref;
			}
		}
		return null;
	}

	/** A charge id resolves to its intent; an intent id is itself. */
	public function intent_id_for( string $ref ): string {
		return $this->charges[ $ref ] ?? $ref;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws \LogicException On a request no scenario expects.
	 */
	public function request( $method, $abs_url, $headers, $params, $has_file, $api_mode = 'v1', $max_network_retries = null ) {
		$method = strtolower( $method );
		$path   = (string) wp_parse_url( $abs_url, PHP_URL_PATH );
		$auth   = '';
		$key    = '';
		foreach ( $headers as $header ) {
			if ( 0 === stripos( $header, 'Authorization:' ) ) {
				$auth = trim( substr( $header, 14 ) );
			} elseif ( 0 === stripos( $header, 'Idempotency-Key:' ) ) {
				$key = trim( substr( $header, 16 ) );
			}
		}
		$mode            = 0 === strpos( $auth, 'Bearer sk_test_' ) ? 'test' : 'live';
		$this->last_mode = $mode;
		$this->raw[]     = array( 'method' => $method, 'path' => $path, 'params' => $params, 'mode' => $mode );
		$params          = is_array( $params ) ? $params : array();

		if ( '/v1/terminal/readers' === $path && 'get' === $method ) {
			return self::ok( array( 'object' => 'list', 'data' => array_values( $this->readers ), 'has_more' => false, 'url' => '/v1/terminal/readers' ) );
		}
		if ( preg_match( '#^/v1/terminal/readers/([^/]+)(?:/(process_payment_intent|cancel_action))?$#', $path, $m ) ) {
			if ( ! isset( $this->readers[ $m[1] ] ) ) {
				return self::error( 'resource_missing', 'No such reader: ' . $m[1], 404 );
			}
			$reader = &$this->readers[ $m[1] ];
			if ( 'process_payment_intent' === ( $m[2] ?? '' ) ) {
				$ref = (string) ( $params['payment_intent'] ?? '' );
				if ( ! isset( $this->intents[ $ref ] ) || $mode !== $this->intents[ $ref ]['mode'] ) {
					return self::error( 'resource_missing', 'No such payment_intent: ' . $ref, 404 );
				}
				$reader['action'] = array( 'type' => 'process_payment_intent', 'status' => 'in_progress', 'process_payment_intent' => array( 'payment_intent' => $ref ), 'failure_code' => null, 'failure_message' => null );
				$this->intents[ $ref ]['reader'] = $m[1];
			} elseif ( 'cancel_action' === ( $m[2] ?? '' ) ) {
				if ( 'busy' === $this->cancel_action ) {
					return self::error( 'terminal_reader_busy', 'The reader is busy.', 400 );
				}
				$reader['action'] = null;
			}
			return self::ok( $reader );
		}
		if ( '/v1/payment_intents' === $path && 'post' === $method ) {
			$mode_key = $mode . ':' . $key;
			if ( ! isset( $this->by_key[ $mode_key ] ) ) {
				$ref = 'pi_' . ( ++$this->seq );
				$this->by_key[ $mode_key ] = $ref;
				$this->intents[ $ref ]     = array(
					'mode'   => $mode,
					'states' => $this->states,
					'reader' => null,
					'data'   => array( 'id' => $ref, 'object' => 'payment_intent', 'status' => 'requires_payment_method', 'amount' => (int) $params['amount'], 'amount_received' => 0, 'currency' => (string) $params['currency'], 'livemode' => 'live' === $mode, 'description' => $params['description'] ?? '', 'metadata' => $params['metadata'] ?? array(), 'capture_method' => $params['capture_method'] ?? 'automatic', 'latest_charge' => null, 'last_payment_error' => null ),
				);
			}
			$this->current = $this->by_key[ $mode_key ];
			if ( 'create_indeterminate' === $this->scenario && ! $this->lost ) {
				$this->lost = true;
				throw \Stripe\Exception\ApiConnectionException::factory( 'Response lost after acceptance' );
			}
			return self::ok( $this->intents[ $this->current ]['data'] );
		}
		if ( preg_match( '#^/v1/payment_intents/([^/]+)(?:/(cancel|capture))?$#', $path, $m ) ) {
			$ref = $m[1];
			if ( ! isset( $this->intents[ $ref ] ) || $mode !== $this->intents[ $ref ]['mode'] ) {
				return self::error( 'resource_missing', 'No such payment_intent: ' . $ref, 404 );
			}
			$entry = &$this->intents[ $ref ];
			if ( 'cancel' === ( $m[2] ?? '' ) ) {
				if ( 'succeeded' === $entry['data']['status'] ) {
					return self::error( 'payment_intent_unexpected_state', 'This PaymentIntent has already succeeded.', 400 );
				}
				$this->apply_state( $entry, 'canceled' );
			} elseif ( 'capture' === ( $m[2] ?? '' ) ) {
				if ( 'requires_capture' !== $entry['data']['status'] ) {
					return self::error( 'payment_intent_unexpected_state', 'This PaymentIntent cannot be captured.', 400 );
				}
				$this->apply_state( $entry, 'succeeded' );
			}
			return self::ok( $entry['data'] );
		}
		if ( '/v1/refunds' === $path && 'post' === $method ) {
			$ref = $this->intent_id_for( (string) ( $params['payment_intent'] ?? $params['charge'] ?? '' ) );
			if ( ! isset( $this->intents[ $ref ] ) || $mode !== $this->intents[ $ref ]['mode'] ) {
				return self::error( 'resource_missing', 'No such payment_intent: ' . $ref, 404 );
			}
			$data = $this->intents[ $ref ]['data'];
			return self::ok( array( 'id' => 're_' . ( ++$this->seq ), 'object' => 'refund', 'status' => $this->refund_status, 'amount' => (int) ( $params['amount'] ?? $data['amount_received'] ), 'currency' => $data['currency'], 'payment_intent' => $ref, 'charge' => $data['latest_charge']['id'] ?? null, 'metadata' => $params['metadata'] ?? array() ) );
		}
		if ( '/v1/account' === $path ) {
			return self::ok( array( 'id' => 'acct_conformance', 'object' => 'account', 'country' => 'IE', 'default_currency' => 'eur' ) );
		}
		throw new \LogicException( 'Unexpected Stripe request: ' . strtoupper( $method ) . ' ' . $path );
	}

	/**
	 * Move an intent (and its reader's action) to a named state.
	 *
	 * @param array  $entry Intent entry, by reference.
	 * @param string $state created, succeeded, short, usd, requires_capture, declined, canceled.
	 */
	private function apply_state( array &$entry, string $state ): void {
		$data   = &$entry['data'];
		$action = null;
		$data['last_payment_error'] = null;
		switch ( $state ) {
			case 'created':
				$data['status']          = 'requires_payment_method';
				$data['amount_received'] = 0;
				$data['latest_charge']   = null;
				$action                  = array( 'status' => 'in_progress' );
				break;
			case 'succeeded':
			case 'short':
			case 'usd':
				$data['status']          = 'succeeded';
				$data['amount_received'] = 'short' === $state ? 100 : $data['amount'];
				if ( 'usd' === $state ) {
					$data['currency'] = 'usd';
				}
				$data['latest_charge'] = $data['latest_charge'] ?? $this->charge( $data['id'] );
				$action                = array( 'status' => 'succeeded' );
				break;
			case 'requires_capture':
				$data['status']          = 'requires_capture';
				$data['amount_received'] = 0;
				$data['latest_charge']   = $data['latest_charge'] ?? $this->charge( $data['id'] );
				$action                  = array( 'status' => 'succeeded' );
				break;
			case 'declined':
				$data['status']             = 'requires_payment_method';
				$data['last_payment_error'] = array( 'code' => 'card_declined', 'decline_code' => 'generic_decline', 'message' => 'Your card was declined.' );
				$action                     = array( 'status' => 'failed', 'failure_code' => 'card_declined', 'failure_message' => 'Your card was declined.' );
				break;
			case 'canceled':
				$data['status']              = 'canceled';
				$data['cancellation_reason'] = 'requested_by_customer';
				break;
			default:
				throw new \OutOfBoundsException( 'Unknown intent state: ' . $state );
		}
		if ( $entry['reader'] && isset( $this->readers[ $entry['reader'] ] ) ) {
			$this->readers[ $entry['reader'] ]['action'] = null === $action ? null : $action + array( 'type' => 'process_payment_intent', 'process_payment_intent' => array( 'payment_intent' => $data['id'] ), 'failure_code' => null, 'failure_message' => null );
		}
	}

	/** An expanded card-present charge, as `expand[]=latest_charge` returns it. */
	private function charge( string $ref ): array {
		$id                   = 'ch_' . ( ++$this->seq );
		$this->charges[ $id ] = $ref;
		return array( 'id' => $id, 'object' => 'charge', 'payment_intent' => $ref, 'payment_method_details' => array( 'type' => 'card_present', 'card_present' => array( 'brand' => 'visa', 'last4' => '4242', 'funding' => 'credit', 'read_method' => 'contactless_emv', 'receipt' => array( 'authorization_code' => '123456', 'application_preferred_name' => 'Visa Credit', 'dedicated_file_name' => 'A0000000031010', 'cardholder_verification_method' => 'none' ) ) ) );
	}

	/** An SDK response triple. */
	private static function ok( array $body ): array {
		return array( wp_json_encode( $body ), 200, array() );
	}

	/** A Stripe error envelope the SDK turns into an InvalidRequestException with this code. */
	private static function error( string $code, string $message, int $status ): array {
		return array( wp_json_encode( array( 'error' => array( 'type' => 'invalid_request_error', 'code' => $code, 'message' => $message ) ) ), $status, array() );
	}
}
