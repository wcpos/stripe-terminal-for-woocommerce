<?php
/**
 * Minimal Pro and WordPress contracts; production classes win when present.
 *
 * @package WCPOS\WooCommercePOS\StripeTerminal
 */

// phpcs:disable Universal.Namespaces, Generic.Files.OneObjectStructurePerFile, Universal.Files.SeparateFunctionsFromOO -- Requested single bootstrap stub bundles Pro and WordPress contracts.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Mirror upstream WordPress and Pro contract names.
namespace WCPOS\WooCommercePOSPro\Payments\Server {
	if ( ! interface_exists( Provider_Adapter_Interface::class ) ) {
		interface Provider_Adapter_Interface {
			/** Identify the provider. */
			public function provider(): string;
			/**
			 * Describe gateway capabilities.
			 *
			 * @param \WC_Payment_Gateway $gateway Gateway instance.
			 */
			public function describe( \WC_Payment_Gateway $gateway ): array;
			/** List available readers. */
			public function list_readers();
			/**
			 * Create a reader action.
			 *
			 * @param array  $row Ledger row.
			 * @param string $reader_id Reader ID.
			 */
			public function create_reader_action( array $row, string $reader_id );
			/**
			 * Fetch the provider observation.
			 *
			 * @param string $ref Payment reference.
			 */
			public function fetch( string $ref );
			/**
			 * Request cancellation.
			 *
			 * @param string $ref Payment reference.
			 */
			public function cancel( string $ref );
			/**
			 * Request capture.
			 *
			 * @param string $ref Payment reference.
			 */
			public function capture( string $ref );
			/**
			 * Refund the payment.
			 *
			 * @param array  $row Ledger row.
			 * @param int    $refund_id WooCommerce refund ID.
			 * @param string $amount Decimal major amount.
			 */
			public function refund( array $row, int $refund_id, string $amount );
			/**
			 * Verify the signed webhook.
			 *
			 * @param \WP_REST_Request $request Webhook request.
			 */
			public function verify_webhook( \WP_REST_Request $request );
		}
	}
	if ( ! class_exists( Abstract_Provider_Adapter::class ) ) {
		/** Test double for Abstract_Provider_Adapter. */
		abstract class Abstract_Provider_Adapter implements Provider_Adapter_Interface {}
	}
	if ( ! class_exists( Event_Log::class ) ) {
		/** Test double for Event_Log. */
		class Event_Log {
			/**
			 * Return the unchanged test ledger row.
			 *
			 * @param array  $row Ledger row.
			 * @param string $level Log level.
			 * @param string $message Log message.
			 */
			public static function append( array $row, string $level, string $message ): array {
				return $row;
			}
		}
	}
	if ( ! class_exists( Reader_Curation::class ) ) {
		/** Test double for Reader_Curation. */
		class Reader_Curation {
			/**
			 * Forgotten.
			 *
			 * @var mixed
			 */
			public static $forgotten = array();
			/**
			 * Record the forgotten reader.
			 *
			 * @param string $id Reader ID.
			 */
			public static function forget( string $id ): void {
				self::$forgotten[] = $id;
			}
		}
	}
}

namespace WCPOS\WooCommercePOSPro\Payments\Device {

	if ( ! class_exists( Abstract_Device_Provider_Adapter::class ) ) {
		/** Minimal SDK-driven provider contract. */
		abstract class Abstract_Device_Provider_Adapter {
			/** Provider identity. */
			abstract public function provider(): string;
			/**
			 * Hardware description.
			 *
			 * @param \WC_Payment_Gateway $gateway Gateway instance.
			 */
			abstract public function describe( \WC_Payment_Gateway $gateway ): array;
			/**
			 * Connection handoff.
			 *
			 * @param \WC_Payment_Gateway $gateway Gateway instance.
			 * @param array               $context Device context.
			 */
			abstract public function bootstrap( \WC_Payment_Gateway $gateway, array $context );
			/**
			 * Intent handoff.
			 *
			 * @param array $row Ledger row.
			 * @param array $context Device context.
			 */
			abstract public function create_intent( array $row, array $context );
			/**
			 * Provider observation.
			 *
			 * @param string $ref Intent ID.
			 */
			abstract public function fetch( string $ref );
			/**
			 * Cancellation outcome.
			 *
			 * @param string $ref Intent ID.
			 */
			abstract public function cancel( string $ref );
			/**
			 * Refund outcome.
			 *
			 * @param array  $row Ledger row.
			 * @param int    $refund_id WooCommerce refund ID.
			 * @param string $amount Decimal major amount.
			 */
			abstract public function refund( array $row, int $refund_id, string $amount );
			/**
			 * Automatic capture does not accept a server capture request.
			 *
			 * @param string $ref Intent ID.
			 */
			public function capture( string $ref ) {
				return new \WP_Error( 'wcpos_capture_mode_unsupported', 'Manual capture is unsupported.', array( 'status' => 501 ) );
			}
		}
	}
}

namespace {

	if ( ! function_exists( 'wcpos_pro_register_device_provider' ) ) {
		/**
		 * Record device registrations for integration assertions.
		 *
		 * @param string $gateway_id Gateway ID.
		 * @param string $adapter_class Adapter class.
		 */
		function wcpos_pro_register_device_provider( string $gateway_id, string $adapter_class ): void {
			$GLOBALS['stwc_device_provider_registrations'][] = array( $gateway_id, $adapter_class );
		}
	}
	if ( ! class_exists( 'WP_REST_Request' ) ) {
		/** Test double for WP_REST_Request. */
		class WP_REST_Request {
			/**
			 * Body.
			 *
			 * @var mixed
			 */
			private $body    = '';
			/**
			 * Headers.
			 *
			 * @var mixed
			 */
			private $headers = array();
			/**
			 * Params.
			 *
			 * @var mixed
			 */
			private $params  = array();
			/**
			 * Set the test request body.
			 *
			 * @param string $body Request body.
			 */
			public function set_body( $body ) {
				$this->body = $body;
			}
			/** Read the test request body. */
			public function get_body() {
				return $this->body;
			}
			/**
			 * Set a test request header.
			 *
			 * @param string $key Header name.
			 * @param string $value Header value.
			 */
			public function set_header( $key, $value ) {
				$this->headers[ strtolower( $key ) ] = $value;
			}
			/**
			 * Read a test request header.
			 *
			 * @param string $key Header name.
			 */
			public function get_header( $key ) {
				return $this->headers[ strtolower( $key ) ] ?? null;
			}
			/**
			 * Read a test request parameter.
			 *
			 * @param string $key Parameter name.
			 */
			public function get_param( $key ) {
				return $this->params[ $key ] ?? null;
			}
		}
	}
}
