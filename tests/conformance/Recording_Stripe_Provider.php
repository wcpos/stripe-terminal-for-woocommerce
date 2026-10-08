<?php
/**
 * The real adapter with each operation recorded on the fixture's transcript: the lessons count
 * what Pro asked the adapter to do, not the wire messages behind it.
 *
 * @package WCPOS\WooCommercePOS\StripeTerminal\Tests\Conformance
 */

namespace WCPOS\WooCommercePOS\StripeTerminal\Tests\Conformance;

use WCPOS\WooCommercePOS\StripeTerminal\Server\Stripe_Server_Provider;

/** Registered in place of Stripe_Server_Provider for the suite; the service and SDK are untouched. */
final class Recording_Stripe_Provider extends Stripe_Server_Provider {
	/**
	 * The installed fixture; set before Pro constructs the adapter.
	 *
	 * @var Stripe_Conformance_Fixture|null
	 */
	public static $fixture;

	/**
	 * Every StripeTerminalService constructor (the gateway builds one too) installs the SDK's global
	 * curl client, so the scripted one is pinned again on entry to each operation.
	 */
	private static function pin(): void {
		\Stripe\ApiRequestor::setHttpClient( self::$fixture->transport );
	}

	/** {@inheritDoc} */
	public function list_readers() {
		self::pin();
		return parent::list_readers();
	}

	/** {@inheritDoc} */
	public function create_reader_action( array $row, string $reader_id ) {
		self::pin();
		$result = parent::create_reader_action( $row, $reader_id );
		self::$fixture->record( 'create', (string) self::$fixture->transport->action_for_key( $row['id'] ), 'amount=' . $row['amount'] . ' currency=' . $row['currency'] . ' reader=' . $reader_id . ' mode=' . self::$fixture->transport->last_mode );
		return $result;
	}

	/** {@inheritDoc} */
	public function fetch( string $ref ) {
		self::pin();
		self::$fixture->transport->advance( $ref );
		$result = parent::fetch( $ref );
		self::$fixture->record( 'fetch', $ref, 'mode=' . self::$fixture->transport->last_mode );
		return $result;
	}

	/** {@inheritDoc} */
	public function cancel( string $ref ) {
		self::pin();
		$result = parent::cancel( $ref );
		self::$fixture->record( 'cancel', $ref, 'mode=' . self::$fixture->transport->last_mode );
		return $result;
	}

	/** {@inheritDoc} */
	public function capture( string $ref ) {
		self::pin();
		$result = parent::capture( $ref );
		self::$fixture->record( 'capture', $ref, 'mode=' . self::$fixture->transport->last_mode );
		return $result;
	}

	/** {@inheritDoc} */
	public function refund( array $row, int $refund_id, string $amount ) {
		self::pin();
		$result = parent::refund( $row, $refund_id, $amount );
		$ref    = (string) ( $row['provider_refs']['action'] ?? $row['provider_refs']['stripe_payment_intent'] ?? $row['provider_refs']['transaction_id'] ?? '' );
		self::$fixture->record( 'refund', $ref, 'amount=' . $amount . ' currency=' . $row['currency'] . ' mode=' . self::$fixture->transport->last_mode . ' transaction_id=' . self::$fixture->alias( $ref ) );
		return $result;
	}

	/** {@inheritDoc} */
	public function verify_webhook( \WP_REST_Request $request ) {
		self::pin();
		$event = json_decode( (string) $request->get_body(), true );
		self::$fixture->record( 'webhook', (string) ( $event['data']['object']['id'] ?? '' ), 'event=' . (string) ( $event['type'] ?? '' ) );
		return parent::verify_webhook( $request );
	}
}
